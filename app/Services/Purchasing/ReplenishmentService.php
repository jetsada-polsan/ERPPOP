<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReplenishmentService
{
    /** @return Collection<int, array<string, mixed>> */
    public function suggestions(int $branchId, int $salesDays = 30, int $safetyDays = 7, int $reviewDays = 7): Collection
    {
        $from = now()->subDays(max(1, $salesDays))->toDateString();
        $stock = DB::table('stock_balances as sb')
            ->join('warehouse_locations as wl', 'wl.id', '=', 'sb.warehouse_location_id')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->where('w.branch_id', $branchId)
            ->selectRaw('sb.product_id, sum(sb.on_hand_qty) as on_hand, sum(sb.reserved_qty) as reserved')
            ->groupBy('sb.product_id')->get()->keyBy('product_id');
        $sales = DB::table('stock_movements as sm')
            ->join('documents as d', 'd.id', '=', 'sm.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('d.branch_id', $branchId)->where('sm.movement_type', 'out')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->whereDate('sm.movement_date', '>=', $from)
            ->selectRaw('sm.product_id, sum(sm.qty) as sold_qty')
            ->groupBy('sm.product_id')->pluck('sold_qty', 'product_id');
        $incoming = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.branch_id', $branchId)->whereIn('po.status', ['ordered', 'partially_received'])
            ->selectRaw('poi.product_id, sum(poi.qty - poi.received_qty) as incoming_qty')
            ->groupBy('poi.product_id')->pluck('incoming_qty', 'product_id');

        return Product::query()->where('is_active', true)
            ->with(['baseUnit:id,code,name', 'suppliers' => fn ($query) => $query->with('supplier:id,code,name_th')->orderByDesc('is_primary')->orderBy('id')])
            ->orderBy('sku_code')->get()
            ->map(function (Product $product) use ($stock, $sales, $incoming, $salesDays, $safetyDays, $reviewDays): ?array {
                $balance = $stock->get($product->id);
                $onHand = (float) ($balance?->on_hand ?? 0);
                $reserved = (float) ($balance?->reserved ?? 0);
                $available = $onHand - $reserved;
                $incomingQty = (float) ($incoming[$product->id] ?? 0);
                $soldQty = (float) ($sales[$product->id] ?? 0);
                $dailySales = $soldQty / max(1, $salesDays);
                $supplierLink = $product->suppliers->first();
                $leadDays = (int) ($supplierLink?->lead_time_days ?? 7);
                $minimum = (float) ($product->minimum_stock ?? 0);
                $reorderPoint = $product->reorder_point !== null
                    ? (float) $product->reorder_point
                    : max($minimum, $dailySales * ($leadDays + $safetyDays));
                $target = $product->maximum_stock !== null
                    ? (float) $product->maximum_stock
                    : max($minimum, $reorderPoint, $dailySales * ($leadDays + $safetyDays + $reviewDays));
                $stockPosition = $available + $incomingQty;
                $rawSuggestion = $stockPosition <= $reorderPoint + 0.0001 ? max(0, $target - $stockPosition) : 0;
                $moq = max(0, (float) ($supplierLink?->minimum_order_qty ?? 0));
                $suggested = $rawSuggestion > 0 && $moq > 0
                    ? ceil($rawSuggestion / $moq) * $moq
                    : $rawSuggestion;
                if ($suggested <= 0.0001) {
                    return null;
                }

                return [
                    'product_id' => $product->id,
                    'sku_code' => $product->sku_code,
                    'name_th' => $product->name_th,
                    'unit' => $product->baseUnit?->cleanName() ?? '-',
                    'supplier_id' => $supplierLink?->supplier_id,
                    'supplier' => $supplierLink?->supplier?->name_th,
                    'unit_price' => (float) ($supplierLink?->last_purchase_price ?? $product->last_purchase_cost ?? 0),
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'available' => $available,
                    'incoming' => $incomingQty,
                    'sold_qty' => $soldQty,
                    'daily_sales' => $dailySales,
                    'lead_days' => $leadDays,
                    'reorder_point' => $reorderPoint,
                    'target_stock' => $target,
                    'moq' => $moq,
                    'suggested_qty' => round($suggested, 4),
                ];
            })->filter()->sortByDesc(fn ($row) => $row['suggested_qty'] * max(0, $row['unit_price']))->values();
    }
}
