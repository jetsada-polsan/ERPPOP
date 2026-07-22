<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClose;
use App\Models\InventoryCostClosePeriod;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryCostCloseService
{
    public function close(string $period, ?int $userId = null): int
    {
        $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        if ($to->isFuture()) {
            throw new RuntimeException('ยังไม่สามารถปิดต้นทุนงวดที่ยังไม่สิ้นสุด');
        }

        $periodRow = InventoryCostClosePeriod::firstOrCreate(['period' => $period], ['status' => 'open']);
        if ($periodRow->isClosed()) {
            throw new RuntimeException('งวดนี้ปิดต้นทุนไปแล้ว ต้องเปิดงวดก่อน (--reopen) ถึงจะปิดซ้ำได้');
        }

        return DB::transaction(function () use ($period, $from, $to, $userId, $periodRow): int {
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
                    $received = (float) $rows->whereIn('movement_type', ['in', 'transfer_in', 'return_in', 'transform_in', 'adjust_in', 'void_in'])->sum('qty');
                    $issued = (float) $rows->whereIn('movement_type', ['out', 'transfer_out', 'transform_out', 'adjust_out'])->sum('qty');
                    $endingRow = $ending->get($product->id);
                    $endingQty = (float) ($endingRow?->qty ?? 0);
                    $endingValue = (float) ($endingRow?->value ?? 0);
                    $averageCost = $endingQty > 0.0001 ? $endingValue / $endingQty : (float) $product->average_cost;
                    InventoryCostClose::updateOrCreate(
                        ['period' => $period, 'product_id' => $product->id],
                        [
                            'opening_qty' => round((float) ($opening->get($product->id)?->qty ?? 0), 4),
                            'received_qty' => $received, 'issued_qty' => $issued, 'ending_qty' => $endingQty,
                            'average_cost' => round($averageCost, 4),
                            'ending_value' => round($endingValue, 4),
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

    private function lotBalances(array $productIds, string $to)
    {
        $issued = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type in ('out','transfer_out','transform_out','adjust_out') and sm.movement_date <= ?),0)";
        $voided = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type = 'void_in' and sm.movement_date <= ?),0)";

        return DB::table('stock_lots')->whereIn('product_id', $productIds)->whereDate('received_date', '<=', $to)
            ->selectRaw("product_id, sum(initial_qty - {$issued} + {$voided}) as qty, sum((initial_qty - {$issued} + {$voided}) * unit_cost) as value", [$to, $to, $to, $to])
            ->groupBy('product_id')->get()->keyBy('product_id');
    }
}
