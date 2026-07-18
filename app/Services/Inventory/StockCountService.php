<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ใบตรวจนับสินค้า: snapshots per-location system qty into a working sheet,
 * accepts counted quantities (typed or CSV-imported), and posts differences
 * through StockAdjustmentService as a normal STOCK_ADJUSTMENT document.
 */
class StockCountService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly StockAdjustmentService $adjustments,
    ) {}

    /**
     * @param  array{branch_id:int, warehouse_location_id:?int, note:?string}  $data
     */
    public function create(array $data): StockCount
    {
        $branch = Branch::findOrFail($data['branch_id']);
        $locationId = $data['warehouse_location_id'] ?? $branch->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น กรุณาเลือกตำแหน่งเก็บ");
        }

        return DB::transaction(function () use ($branch, $locationId, $data) {
            $count = StockCount::create([
                'doc_number' => $this->numbers->nextStockCount($branch->id),
                'branch_id' => $branch->id,
                'warehouse_location_id' => $locationId,
                'status' => 'counting',
                'count_mode' => $data['count_mode'] ?? 'partial',
                'note' => $data['note'] ?? null,
            ]);

            // Snapshot ยอดระบบ ณ ตอนเปิดใบ: ทุกสินค้า active ที่มีแถวสต๊อกในตำแหน่งนี้
            $rows = StockBalance::where('warehouse_location_id', $locationId)
                ->join('products', 'products.id', '=', 'stock_balances.product_id')
                ->where('products.is_active', true)
                ->orderBy('products.sku_code')
                ->get(['stock_balances.product_id', 'stock_balances.on_hand_qty'])
                ->map(fn ($b) => [
                    'stock_count_id' => $count->id,
                    'product_id' => $b->product_id,
                    'system_qty' => (float) $b->on_hand_qty,
                    'counted_qty' => null,
                    'note' => null,
                ]);

            if ($rows->isEmpty()) {
                throw new RuntimeException('ตำแหน่งเก็บนี้ยังไม่มีข้อมูลสต๊อกให้ตรวจนับ');
            }

            foreach ($rows->chunk(500) as $chunk) {
                StockCountItem::insert($chunk->values()->all());
            }

            return $count;
        });
    }

    /**
     * Post counted differences as a stock adjustment. Returns the created
     * adjustment document, or null when every counted qty matched the system.
     */
    public function post(StockCount $count, ?string $remark = null): ?Document
    {
        if ($count->status !== 'review') {
            throw new RuntimeException('ใบตรวจนับต้องอยู่ในสถานะรอตรวจก่อนยืนยัน');
        }

        $counted = $count->count_mode === 'full_zero_missing'
            ? $count->items()->get()->each(fn ($item) => $item->counted_qty ??= 0)
            : $count->items()->whereNotNull('counted_qty')->get();
        if ($counted->isEmpty()) {
            throw new RuntimeException('ยังไม่มียอดนับจริงในใบนี้ กรอกหรือ import ยอดก่อนปรับปรุง');
        }

        $diffItems = $counted->filter(fn ($i) => abs((float) $i->counted_qty - (float) $i->system_qty) > 0.0001);

        if ($diffItems->isEmpty()) {
            $count->update(['status' => 'posted']);

            return null;
        }

        $document = $this->adjustments->create([
            'branch_id' => $count->branch_id,
            'warehouse_location_id' => $count->warehouse_location_id,
            'remark' => 'ปรับปรุงจากใบตรวจนับ '.$count->doc_number.($remark ? ' | '.$remark : ''),
            'items' => $diffItems->map(fn ($i) => [
                'product_id' => $i->product_id,
                'counted_qty' => (float) $i->counted_qty,
            ])->values()->all(),
        ]);

        $count->update(['status' => 'posted', 'posted_document_id' => $document->id]);

        return $document;
    }
}
