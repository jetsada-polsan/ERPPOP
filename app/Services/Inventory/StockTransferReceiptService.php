<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\StockTransferReceipt;
use App\Models\StockTransferReceiptItem;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Receives transit stock once; short/damaged quantities remain in transit
 * until the separately created damage document is approved. Legacy transfers
 * (no transit_location_id) retain the original evidence-only behavior.
 */
class StockTransferReceiptService
{
    /**
     * เปิดใบตรวจรับใหม่ (snapshot จำนวนตามเอกสาร) หรือคืนใบที่เปิดค้างอยู่แล้ว/ปิดแล้ว.
     */
    public function openOrGet(Document $document): StockTransferReceipt
    {
        if ($document->documentType?->code !== 'STOCK_TRANSFER') {
            throw new RuntimeException('ไม่ใช่เอกสารโอนย้ายสต็อก');
        }
        if (! in_array($document->status, ['active', 'in_transit'], true)) {
            throw new RuntimeException('ต้องเป็นใบโอนย้ายที่อนุมัติ/โอนสต๊อกแล้วเท่านั้นถึงจะตรวจรับได้');
        }

        return DB::transaction(function () use ($document) {
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! in_array($document->status, ['active', 'in_transit'], true)) {
                throw new RuntimeException('เอกสารโอนไม่อยู่ในสถานะตรวจรับ');
            }
            $existing = StockTransferReceipt::where('document_id', $document->id)->first();
            if ($existing) {
                return $existing;
            }

            $receipt = StockTransferReceipt::create([
                'document_id' => $document->id,
                'status' => 'checking',
                'created_by' => auth()->id(),
            ]);

            $items = $document->stockDocument()->with('items')->first()?->items ?? collect();
            if ($items->isEmpty()) {
                throw new RuntimeException('ไม่พบรายการสินค้าของใบโอนย้ายนี้');
            }

            // รวมจำนวนต่อสินค้า เผื่อใบโอนมีสินค้าเดียวกันหลายแถว (คนละ lot/แถว)
            $grouped = $items->groupBy('product_id')
                ->map(fn ($rows) => DecimalMath::sum($rows->pluck('qty'), DecimalMath::QUANTITY_SCALE));

            foreach ($grouped as $productId => $qty) {
                StockTransferReceiptItem::create([
                    'stock_transfer_receipt_id' => $receipt->id,
                    'product_id' => $productId,
                    'expected_qty' => $qty,
                ]);
            }

            return $receipt->fresh();
        });
    }

    /**
     * บันทึกยอดที่สแกนได้ (client ส่งค่าล่าสุดของแต่ละแถวมาทั้งชุด เหมือนใบตรวจนับ).
     *
     * @param  array<int, array{id:int, scanned_qty: ?float}>  $rows
     */
    public function saveScans(StockTransferReceipt $receipt, array $rows): int
    {
        return DB::transaction(function () use ($receipt, $rows) {
            $receipt = StockTransferReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! $receipt->isEditable()) {
                throw new RuntimeException('ใบนี้ยืนยันปิดแล้ว แก้ไขไม่ได้');
            }

            $updated = 0;
            foreach ($rows as $row) {
                $updated += StockTransferReceiptItem::where('stock_transfer_receipt_id', $receipt->id)
                    ->where('id', $row['id'])
                    ->update(['scanned_qty' => $row['scanned_qty'] ?? null]);
            }

            return $updated;
        });
    }

    /**
     * ปิดใบตรวจรับ - ต้องสแกน/กรอกครบทุกรายการก่อน คืนรายการที่ยอดไม่ตรง (ถ้ามี)
     * ให้ผู้เรียกไปแจ้งเตือน/ชี้ทางให้สร้างใบปรับสต๊อกต่อเอง.
     *
     * @return Collection<int, StockTransferReceiptItem>
     */
    public function complete(StockTransferReceipt $receipt, ?string $note = null): Collection
    {
        return DB::transaction(function () use ($receipt, $note) {
            $document = Document::whereKey($receipt->document_id)->lockForUpdate()->firstOrFail();
            $receipt = StockTransferReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! $receipt->isEditable()) {
                throw new RuntimeException('ใบนี้ยืนยันปิดแล้ว');
            }

            $items = $receipt->items()->with('product:id,sku_code')->get();
            $unscanned = $items->filter(fn ($i) => $i->scanned_qty === null);
            if ($unscanned->isNotEmpty()) {
                $skus = $unscanned->map(fn ($i) => $i->product->sku_code)->implode(', ');
                throw new RuntimeException("ยังสแกน/กรอกไม่ครบ ({$unscanned->count()} รายการ): {$skus}");
            }

            $stock = $document->stockDocument;
            // NULL marks legacy transfers whose destination was already credited. Never move them again.
            if ($stock->transit_location_id) {
                $fifo = app(FifoStockService::class);
                $damage = [];
                foreach ($items as $item) {
                    if (DecimalMath::compare($item->scanned_qty, 0) < 0 || DecimalMath::compare($item->scanned_qty, $item->expected_qty) > 0) {
                        throw new RuntimeException('ยอดตรวจรับต้องอยู่ระหว่าง 0 ถึงจำนวนที่ส่ง');
                    }
                    $missing = DecimalMath::subtract($item->expected_qty, $item->scanned_qty);
                    if (DecimalMath::compare($missing, 0) > 0) {
                        if (! filled($note)) {
                            throw new RuntimeException('รับไม่ครบ/เสียหายต้องระบุหลักฐานและเหตุผลในหมายเหตุ');
                        }
                        $damage[] = ['product_id' => $item->product_id, 'qty' => $missing];
                    }
                    if (DecimalMath::compare($item->scanned_qty, 0) <= 0) {
                        continue;
                    }
                    $allocations = $fifo->issue($item->product_id, $stock->transit_location_id, $item->scanned_qty,
                        $document->id, 'transfer_out', allowExpired: true, allowRestricted: true, allowInTransit: true);
                    foreach ($allocations as $allocation) {
                        $lot = $allocation['lot'];
                        $source = $lot->sourceLot;
                        $received = $fifo->receive($item->product_id, $stock->to_warehouse_location_id, $allocation['qty'],
                            $document->id, 'transfer_in', $lot->lot_number, now()->toDateString(),
                            $lot->expiry_date?->toDateString(), $lot->unit_cost, $lot->manufacture_date?->toDateString());
                        $received->update(['source_lot_id' => $lot->id, 'received_date' => $lot->received_date, 'quality_status' => $source?->quality_status ?? 'available',
                            'quality_reason' => $source?->quality_reason]);
                    }
                }
                if ($damage !== []) {
                    $evidence = app(StockIssueService::class)->create([
                        'type' => 'damage', 'branch_id' => $document->branch_id,
                        'warehouse_location_id' => $stock->transit_location_id,
                        'reference' => $document->doc_number, 'remark' => $note, 'items' => $damage,
                    ]);
                    $note .= ' | ใบผลต่างรออนุมัติ '.$evidence->doc_number;
                }
                $document->update(['status' => 'active']);
            }

            $receipt->update([
                'status' => 'completed',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
                'note' => $note,
            ]);

            return $items->filter(fn ($i) => DecimalMath::compare($i->scanned_qty, $i->expected_qty) !== 0)->values();
        });
    }
}
