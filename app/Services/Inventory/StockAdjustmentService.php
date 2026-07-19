<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reconciles physical stock counts against the system's on_hand_qty
 * (ตรวจนับสต็อก). Only items whose counted qty differs from the system qty
 * produce a line; the diff is signed (+found extra / -shortage) and recorded as
 * a single 'adjust' stock_movement per item, matching the movement_type already
 * reserved for this in stock_movements' design (see schema.sql comment).
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{branch_id:int, warehouse_location_id:int, remark:?string, items: array<int, array{product_id:int, counted_qty:float}>}  $data
     */
    public function create(array $data): Document
    {
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $locationId = (int) $data['warehouse_location_id'];

        $systemQtyByProduct = StockBalance::whereIn('product_id', collect($data['items'])->pluck('product_id'))
            ->where('warehouse_location_id', $locationId)
            ->pluck('on_hand_qty', 'product_id');

        $diffs = collect($data['items'])
            ->map(function ($item) use ($systemQtyByProduct) {
                $systemQty = (float) ($systemQtyByProduct[$item['product_id']] ?? 0);
                $countedQty = (float) $item['counted_qty'];

                return [
                    'product_id' => $item['product_id'],
                    'system_qty' => $systemQty,
                    'counted_qty' => $countedQty,
                    'diff' => round($countedQty - $systemQty, 4),
                ];
            })
            ->filter(fn ($i) => abs($i['diff']) > 0.0001)
            ->values();

        if ($diffs->isEmpty()) {
            throw new RuntimeException('ยอดนับตรงกับระบบทั้งหมด ไม่มีรายการที่ต้องปรับปรุง');
        }

        $documentType = DocumentType::where('code', 'STOCK_ADJUSTMENT')->firstOrFail();

        return DB::transaction(function () use ($data, $diffs, $locationId, $documentType) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('STOCK_ADJUSTMENT', $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'status' => 'pending_approval',
                'total_items' => $diffs->count(),
                'total_amount' => 0,
                'remark' => $data['remark'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $diffs->sum(fn ($i) => abs($i['diff'])),
                'total_items' => $diffs->count(),
            ]);

            $seq = 1;
            foreach ($diffs as $diff) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $diff['product_id'],
                    'warehouse_location_id' => $locationId,
                    'qty' => $diff['diff'],
                    'system_qty' => $diff['system_qty'],
                    'counted_qty' => $diff['counted_qty'],
                ]);
            }

            return $document->fresh();
        });
    }

    public function approve(Document $document): Document
    {
        if ($document->status !== 'pending_approval') {
            throw new RuntimeException('เอกสารนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }
        if ($document->created_by && $document->created_by === auth()->id()) {
            throw new RuntimeException('ผู้สร้างใบปรับสต๊อกไม่สามารถอนุมัติรายการของตนเอง');
        }

        return DB::transaction(function () use ($document) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_approval') {
                throw new RuntimeException('เอกสารนี้ถูกดำเนินการไปแล้ว');
            }
            $locked->load('stockDocument.items');
            foreach ($locked->stockDocument->items as $item) {
                $balance = StockBalance::where('product_id', $item->product_id)
                    ->where('warehouse_location_id', $item->warehouse_location_id)
                    ->lockForUpdate()->first();
                $current = (float) ($balance?->on_hand_qty ?? 0);
                if (abs($current - (float) $item->system_qty) > 0.0001) {
                    throw new RuntimeException('ยอดสต๊อกเปลี่ยนหลังส่งอนุมัติ กรุณายกเลิกและตรวจนับใหม่');
                }
                $balance ??= StockBalance::create([
                    'product_id' => $item->product_id,
                    'warehouse_location_id' => $item->warehouse_location_id,
                    'on_hand_qty' => 0,
                    'reserved_qty' => 0,
                ]);
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'warehouse_location_id' => $item->warehouse_location_id,
                    'document_id' => $locked->id,
                    'movement_type' => 'adjust',
                    'qty' => $item->qty,
                    'movement_date' => now()->toDateString(),
                ]);
                $balance->update(['on_hand_qty' => $item->counted_qty]);
            }
            $locked->update([
                'status' => 'active',
                'remark' => trim(($locked->remark ? $locked->remark.' | ' : '').'อนุมัติโดย '.auth()->user()?->name),
            ]);
            DB::table('stock_counts')->where('posted_document_id', $locked->id)->update([
                'status' => 'posted', 'confirmed_by' => auth()->id(), 'confirmed_at' => now(), 'updated_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
