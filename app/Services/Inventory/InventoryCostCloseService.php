<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClose;
use App\Models\InventoryCostClosePeriod;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockCount;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryCostCloseService
{
    public function close(string $period, ?int $userId = null): int
    {
        $from = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        if ($to->isFuture()) {
            throw new RuntimeException('ยังไม่สามารถปิดต้นทุนงวดที่ยังไม่สิ้นสุด');
        }

        return DB::transaction(function () use ($period, $from, $to, $userId): int {
            $periodRow = InventoryCostClosePeriod::firstOrCreate(['period' => $period], ['status' => 'open']);
            $periodRow = InventoryCostClosePeriod::query()->whereKey($periodRow->id)->lockForUpdate()->firstOrFail();
            if ($periodRow->isClosed()) {
                throw new RuntimeException('งวดนี้ปิดต้นทุนไปแล้ว ต้องเปิดงวดก่อน (--reopen) ถึงจะปิดซ้ำได้');
            }
            $this->assertReadyToClose($from->toDateString(), $to->toDateString());

            $count = 0;
            Product::where('is_active', true)->orderBy('id')->chunkById(500, function ($products) use ($period, $from, $to, $userId, &$count): void {
                $ids = $products->pluck('id');
                $movements = DB::table('stock_movements')->whereIn('product_id', $ids)
                    ->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])
                    ->selectRaw('product_id, movement_type, sum(qty) as qty')
                    ->groupBy('product_id', 'movement_type')->get()->groupBy('product_id');
                $opening = $this->lotBalances($ids->all(), $from->copy()->subDay()->toDateString());
                $ending = $this->lotBalances($ids->all(), $to->toDateString());
                foreach ($products as $product) {
                    $rows = $movements->get($product->id, collect());
                    $received = DecimalMath::sum($rows->whereIn('movement_type', ['in', 'transfer_in', 'return_in', 'transform_in', 'adjust_in', 'void_in'])->pluck('qty'), DecimalMath::QUANTITY_SCALE);
                    $issued = DecimalMath::sum($rows->whereIn('movement_type', ['out', 'transfer_out', 'transform_out', 'adjust_out'])->pluck('qty'), DecimalMath::QUANTITY_SCALE);
                    $endingRow = $ending->get($product->id);
                    $endingQty = DecimalMath::round($endingRow?->qty ?? 0, DecimalMath::QUANTITY_SCALE);
                    $endingValue = DecimalMath::round($endingRow?->value ?? 0, DecimalMath::COST_SCALE);
                    $averageCost = DecimalMath::compare($endingQty, 0) > 0
                        ? DecimalMath::divide($endingValue, $endingQty, DecimalMath::COST_SCALE)
                        : $product->average_cost;
                    InventoryCostClose::updateOrCreate(
                        ['period' => $period, 'product_id' => $product->id],
                        [
                            'opening_qty' => DecimalMath::round($opening->get($product->id)?->qty ?? 0, DecimalMath::QUANTITY_SCALE),
                            'received_qty' => $received, 'issued_qty' => $issued, 'ending_qty' => $endingQty,
                            'average_cost' => DecimalMath::round($averageCost, DecimalMath::COST_SCALE),
                            'ending_value' => $endingValue,
                            'closed_by' => $userId, 'closed_at' => now(),
                        ],
                    );
                    $count++;
                }
            });

            $periodRow->update(['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now()]);

            return $count;
        });
    }

    public function reopen(string $period): void
    {
        $periodRow = InventoryCostClosePeriod::where('period', $period)->first();
        if (! $periodRow || ! $periodRow->isClosed()) {
            throw new RuntimeException('งวดนี้ยังไม่ได้ปิดต้นทุน');
        }

        $periodRow->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
    }

    private function assertReadyToClose(string $from, string $to): void
    {
        $unfinishedCounts = StockCount::query()
            ->whereDate('count_date', '>=', $from)
            ->whereDate('count_date', '<=', $to)
            ->whereIn('status', ['counting', 'review', 'pending_adjustment'])
            ->orderBy('doc_number')
            ->pluck('doc_number');
        if ($unfinishedCounts->isNotEmpty()) {
            throw new RuntimeException('ยังปิดต้นทุนไม่ได้ มีใบตรวจนับค้างในงวดนี้: '.$unfinishedCounts->take(5)->implode(', '));
        }

        $locationsToVerify = DB::table('stock_balances')->distinct()->pluck('warehouse_location_id')
            ->merge(DB::table('stock_movements')->whereBetween('movement_date', [$from, $to])->distinct()->pluck('warehouse_location_id'))
            ->unique()->values();
        $catalogProductCount = DB::table('products')->whereNull('deleted_at')->count();
        $missingCountLocations = [];
        foreach ($locationsToVerify as $locationId) {
            $hasCompleteCount = DB::table('stock_counts')
                ->join('stock_count_items', 'stock_count_items.stock_count_id', '=', 'stock_counts.id')
                ->where('stock_counts.warehouse_location_id', $locationId)
                ->whereDate('stock_counts.count_date', '>=', $from)
                ->whereDate('stock_counts.count_date', '<=', $to)
                ->where('stock_counts.status', 'posted')
                ->where('stock_counts.count_mode', 'full_zero_missing')
                ->where('stock_counts.product_scope', 'all')
                ->whereNotNull('stock_count_items.counted_qty')
                ->groupBy('stock_counts.id')
                ->havingRaw('COUNT(DISTINCT stock_count_items.product_id) = ?', [$catalogProductCount])
                ->exists();
            if (! $hasCompleteCount) {
                $missingCountLocations[] = (string) DB::table('warehouse_locations')->where('id', $locationId)->value('code');
            }
        }
        if ($missingCountLocations !== []) {
            throw new RuntimeException('ยังปิดต้นทุนไม่ได้ ต้องมีใบตรวจนับเต็มที่กรอกครบและยืนยันแล้วสำหรับคลัง: '.implode(', ', array_slice($missingCountLocations, 0, 5)));
        }

        $pendingStockDocuments = Document::query()
            ->whereBetween('doc_date', [$from, $to])
            ->where('status', 'pending_approval')
            ->whereHas('documentType', fn ($query) => $query->where('affects_stock', true))
            ->orderBy('doc_number')
            ->pluck('doc_number');
        if ($pendingStockDocuments->isNotEmpty()) {
            throw new RuntimeException('ยังปิดต้นทุนไม่ได้ มีเอกสารสต็อกรออนุมัติ: '.$pendingStockDocuments->take(5)->implode(', '));
        }
    }

    private function lotBalances(array $productIds, string $to)
    {
        $issued = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type in ('out','transfer_out','transform_out','adjust_out') and sm.movement_date <= ?),0)";
        $voided = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type = 'void_in' and sm.movement_date <= ?),0)";

        return DB::table('stock_lots')->whereIn('product_id', $productIds)->whereDate('received_date', '<=', $to)
            ->selectRaw("product_id, sum(initial_qty - {$issued} + {$voided}) as qty, sum((initial_qty - {$issued} + {$voided}) * unit_cost) as value", [$to, $to, $to, $to])
            ->groupBy('product_id')->get()->keyBy('product_id');
    }
}
