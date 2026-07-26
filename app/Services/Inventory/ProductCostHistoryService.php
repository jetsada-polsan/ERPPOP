<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClose;
use App\Models\InventoryCostClosePeriod;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductCostHistoryService
{
    /**
     * @return Collection<int, array<string, float|int|string|bool|null>>
     */
    public function history(Product $product, int $months = 12): Collection
    {
        $months = max(1, min($months, 60));
        $periods = collect(range(0, $months - 1))
            ->map(fn (int $offset) => now()->startOfMonth()->subMonths($offset)->format('Y-m'));
        $closedRows = InventoryCostClose::where('product_id', $product->id)
            ->whereIn('period', $periods)->get()->keyBy('period');
        $periodStatuses = InventoryCostClosePeriod::whereIn('period', $periods)
            ->get()->keyBy('period');

        return $periods->map(fn (string $period) => $this->summarize(
            $product,
            $period,
            $closedRows->get($period),
            $periodStatuses->get($period)?->status,
        ));
    }

    /**
     * Periodic weighted average:
     * (opening inventory value + non-transfer receipts value)
     * / (opening inventory quantity + non-transfer receipts quantity).
     *
     * @return array<string, float|int|string|bool|null>
     */
    public function summarize(
        Product $product,
        string $period,
        ?InventoryCostClose $closed = null,
        ?string $periodStatus = null,
    ): array {
        $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd = $from->copy()->endOfMonth();
        $effectiveTo = $periodEnd->isFuture() ? now()->endOfDay() : $periodEnd;
        $opening = $this->lotBalanceAt((int) $product->id, $from->copy()->subDay());
        $ending = $closed
            ? ['qty' => (float) $closed->ending_qty, 'value' => (float) $closed->ending_value]
            : $this->lotBalanceAt((int) $product->id, $effectiveTo);

        $base = DB::table('stock_movements as sm')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'sm.stock_lot_id')
            ->where('sm.product_id', $product->id)
            ->whereBetween('sm.movement_date', [$from->toDateString(), $effectiveTo->toDateString()]);

        $movementRows = (clone $base)
            ->selectRaw('sm.movement_type, sum(sm.qty) as qty, sum(sm.qty * coalesce(sl.unit_cost, 0)) as value')
            ->groupBy('sm.movement_type')
            ->get()
            ->keyBy('movement_type');

        $costReceiptTypes = ['in', 'return_in', 'transform_in', 'adjust_in', 'void_in'];
        $operationalReceiptTypes = [...$costReceiptTypes, 'transfer_in'];
        $issueTypes = ['out', 'transfer_out', 'transform_out', 'adjust_out'];
        $costReceivedQty = $this->sumRows($movementRows, $costReceiptTypes, 'qty');
        $costReceivedValue = $this->sumRows($movementRows, $costReceiptTypes, 'value');
        $receivedQty = $closed
            ? (float) $closed->received_qty
            : $this->sumRows($movementRows, $operationalReceiptTypes, 'qty');
        $issuedQty = $closed
            ? (float) $closed->issued_qty
            : $this->sumRows($movementRows, $issueTypes, 'qty');

        $purchaseBase = (clone $base)
            ->join('documents as d', 'd.id', '=', 'sm.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('dt.code', 'PURCHASE')
            ->where('sm.movement_type', 'in');
        $purchase = (clone $purchaseBase)
            ->selectRaw('coalesce(sum(sm.qty), 0) as qty, coalesce(sum(sm.qty * coalesce(sl.unit_cost, 0)), 0) as value')
            ->first();
        $lastPurchaseCost = (clone $purchaseBase)
            ->orderByDesc('sm.movement_date')
            ->orderByDesc('sm.id')
            ->value('sl.unit_cost');

        $purchaseQty = (float) ($purchase?->qty ?? 0);
        $purchaseValue = (float) ($purchase?->value ?? 0);
        $periodCostQty = (float) $opening['qty'] + $costReceivedQty;
        $periodCostValue = (float) $opening['value'] + $costReceivedValue;
        $periodAverage = $periodCostQty > 0.0001 ? $periodCostValue / $periodCostQty : 0.0;
        $endingAverage = (float) $ending['qty'] > 0.0001
            ? (float) $ending['value'] / (float) $ending['qty']
            : 0.0;

        return [
            'period' => $period,
            'label' => $from->locale('th')->translatedFormat('M Y'),
            'is_current' => $period === now()->format('Y-m'),
            'status' => $periodStatus === 'closed' || $closed ? 'closed' : 'open',
            'opening_qty' => round((float) $opening['qty'], 4),
            'opening_value' => round((float) $opening['value'], 4),
            'received_qty' => round($receivedQty, 4),
            'received_value' => round($costReceivedValue, 4),
            'issued_qty' => round($issuedQty, 4),
            'purchase_qty' => round($purchaseQty, 4),
            'purchase_value' => round($purchaseValue, 4),
            'purchase_average_cost' => $purchaseQty > 0.0001 ? round($purchaseValue / $purchaseQty, 4) : null,
            'last_purchase_cost' => $lastPurchaseCost !== null ? round((float) $lastPurchaseCost, 4) : null,
            'period_average_cost' => round($periodAverage, 4),
            'ending_qty' => round((float) $ending['qty'], 4),
            'ending_value' => round((float) $ending['value'], 4),
            'ending_average_cost' => round($endingAverage, 4),
            'moving_average_cost' => $period === now()->format('Y-m')
                ? round((float) $product->average_cost, 4)
                : null,
        ];
    }

    /**
     * @return array{qty: float, value: float}
     */
    private function lotBalanceAt(int $productId, Carbon $to): array
    {
        $date = $to->toDateString();
        $issued = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type in ('out','transfer_out','transform_out','adjust_out') and sm.movement_date <= ?),0)";
        $voided = "coalesce((select sum(sm.qty) from stock_movements sm where sm.stock_lot_id = stock_lots.id and sm.movement_type = 'void_in' and sm.movement_date <= ?),0)";
        $row = DB::table('stock_lots')
            ->where('product_id', $productId)
            ->whereDate('received_date', '<=', $date)
            ->selectRaw("coalesce(sum(initial_qty - {$issued} + {$voided}), 0) as qty, coalesce(sum((initial_qty - {$issued} + {$voided}) * unit_cost), 0) as value", [$date, $date, $date, $date])
            ->first();

        return ['qty' => (float) ($row?->qty ?? 0), 'value' => (float) ($row?->value ?? 0)];
    }

    /**
     * @param  Collection<string, object>  $rows
     * @param  array<int, string>  $types
     */
    private function sumRows(Collection $rows, array $types, string $field): float
    {
        return (float) collect($types)->sum(fn (string $type) => (float) ($rows->get($type)?->{$field} ?? 0));
    }
}
