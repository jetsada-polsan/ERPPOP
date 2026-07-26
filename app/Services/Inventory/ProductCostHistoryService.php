<?php

namespace App\Services\Inventory;

use App\Models\InventoryCostClose;
use App\Models\InventoryCostClosePeriod;
use App\Models\Product;
use App\Support\DecimalMath;
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
        $periodCostQty = DecimalMath::add($opening['qty'], $costReceivedQty);
        $periodCostValue = DecimalMath::add($opening['value'], $costReceivedValue);
        $periodAverage = DecimalMath::compare($periodCostQty, 0) > 0
            ? DecimalMath::divide($periodCostValue, $periodCostQty)
            : '0';
        $endingAverage = DecimalMath::compare($ending['qty'], 0) > 0
            ? DecimalMath::divide($ending['value'], $ending['qty'])
            : '0';

        return [
            'period' => $period,
            'label' => $from->locale('th')->translatedFormat('M Y'),
            'is_current' => $period === now()->format('Y-m'),
            'status' => $periodStatus === 'closed' || $closed ? 'closed' : 'open',
            'opening_qty' => (float) DecimalMath::round($opening['qty'], DecimalMath::QUANTITY_SCALE),
            'opening_value' => (float) DecimalMath::round($opening['value'], DecimalMath::COST_SCALE),
            'received_qty' => (float) DecimalMath::round($receivedQty, DecimalMath::QUANTITY_SCALE),
            'received_value' => (float) DecimalMath::round($costReceivedValue, DecimalMath::COST_SCALE),
            'issued_qty' => (float) DecimalMath::round($issuedQty, DecimalMath::QUANTITY_SCALE),
            'purchase_qty' => (float) DecimalMath::round($purchaseQty, DecimalMath::QUANTITY_SCALE),
            'purchase_value' => (float) DecimalMath::round($purchaseValue, DecimalMath::COST_SCALE),
            'purchase_average_cost' => $purchaseQty > 0.00000001 ? (float) DecimalMath::divide($purchaseValue, $purchaseQty) : null,
            'last_purchase_cost' => $lastPurchaseCost !== null ? (float) DecimalMath::round($lastPurchaseCost, DecimalMath::COST_SCALE) : null,
            'period_average_cost' => (float) DecimalMath::round($periodAverage, DecimalMath::COST_SCALE),
            'ending_qty' => (float) DecimalMath::round($ending['qty'], DecimalMath::QUANTITY_SCALE),
            'ending_value' => (float) DecimalMath::round($ending['value'], DecimalMath::COST_SCALE),
            'ending_average_cost' => (float) DecimalMath::round($endingAverage, DecimalMath::COST_SCALE),
            'moving_average_cost' => $period === now()->format('Y-m')
                ? (float) DecimalMath::round($product->average_cost, DecimalMath::COST_SCALE)
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
