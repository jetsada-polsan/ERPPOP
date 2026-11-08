<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\WarehouseLocation;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
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
     * @param  array{branch_id:int, warehouse_location_id:?int, product_scope?:string, note:?string}  $data
     */
    public function create(array $data): StockCount
    {
        $branch = Branch::findOrFail($data['branch_id']);
        $locationId = $data['warehouse_location_id'] ?? $branch->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น กรุณาเลือกตำแหน่งเก็บ");
        }

        return DB::transaction(function () use ($branch, $locationId, $data) {
            $location = WarehouseLocation::query()->whereKey($locationId)->lockForUpdate()->first();
            if (! $location || (int) $location->warehouse()->value('branch_id') !== (int) $branch->id) {
                throw new RuntimeException('ตำแหน่งเก็บต้องอยู่ในสาขาเดียวกับใบตรวจนับ');
            }
            if (StockCount::query()->where('warehouse_location_id', $locationId)
                ->whereIn('status', ['counting', 'review', 'pending_adjustment'])->exists()) {
                throw new RuntimeException('ตำแหน่งเก็บนี้มีใบตรวจนับที่ยังไม่ปิดอยู่ กรุณาดำเนินการใบเดิมให้เสร็จก่อน');
            }

            $count = StockCount::create([
                'doc_number' => $this->numbers->nextStockCount($branch->id),
                'branch_id' => $branch->id,
                'warehouse_location_id' => $locationId,
                'status' => 'counting',
                'count_mode' => $data['count_mode'] ?? 'partial',
                'product_scope' => $data['product_scope'] ?? 'active',
                'count_date' => now()->toDateString(),
                'note' => $data['note'] ?? null,
            ]);

            // Snapshot the selected product catalog, including products with no balance row.
            $products = DB::table('products')
                ->leftJoin('stock_balances', function ($join) use ($locationId): void {
                    $join->on('stock_balances.product_id', '=', 'products.id')
                        ->where('stock_balances.warehouse_location_id', '=', $locationId);
                })
                ->whereNull('products.deleted_at')
                ->when(($data['product_scope'] ?? 'active') === 'active', fn ($q) => $q->where('products.is_active', true))
                ->when(($data['product_scope'] ?? 'active') === 'inactive', fn ($q) => $q->where('products.is_active', false))
                ->orderBy('products.sku_code')
                ->get(['products.id as product_id', 'stock_balances.on_hand_qty']);

            $rows = $products
                ->map(fn ($b) => [
                    'stock_count_id' => $count->id,
                    'product_id' => $b->product_id,
                    'system_qty' => DecimalMath::round($b->on_hand_qty ?? 0, DecimalMath::QUANTITY_SCALE),
                    'counted_qty' => null,
                    'note' => null,
                ]);

            if ($rows->isEmpty()) {
                throw new RuntimeException('ไม่พบสินค้าในขอบเขตที่เลือกสำหรับตรวจนับ');
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
        return DB::transaction(function () use ($count, $remark) {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'review') {
                throw new RuntimeException('ใบตรวจนับต้องอยู่ในสถานะรอตรวจก่อนยืนยัน');
            }

            $counted = $locked->items()->whereNotNull('counted_qty')->get();
            if ($locked->count_mode === 'full_zero_missing' && $locked->items()->whereNull('counted_qty')->exists()) {
                throw new RuntimeException('โหมดนับเต็มต้องกรอกหรือ import จำนวนจริงให้ครบทุกสินค้า รวมรายการที่นับได้ 0');
            }
            if ($counted->isEmpty()) {
                throw new RuntimeException('ยังไม่มียอดนับจริงในใบนี้ กรอกหรือ import ยอดก่อนปรับปรุง');
            }

            $diffItems = $counted->filter(fn ($i) => DecimalMath::compare($i->counted_qty, $i->system_qty) !== 0);

            if ($diffItems->isEmpty()) {
                $locked->update(['status' => 'posted']);

                return null;
            }

            $document = $this->adjustments->create([
                'branch_id' => $locked->branch_id,
                'warehouse_location_id' => $locked->warehouse_location_id,
                'doc_date' => $locked->count_date,
                'remark' => 'ปรับปรุงจากใบตรวจนับ '.$locked->doc_number.($remark ? ' | '.$remark : ''),
                'items' => $diffItems->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'counted_qty' => $i->counted_qty,
                ])->values()->all(),
            ]);

            $locked->update(['status' => 'pending_adjustment', 'posted_document_id' => $document->id]);

            return $document;
        });
    }
}
