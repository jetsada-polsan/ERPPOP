<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\StockLot;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockExpiryController extends Controller
{
    public function index(Request $request): View
    {
        $today = now()->startOfDay();
        $branchId = $request->query('branch_id');
        $warehouseId = $request->query('warehouse_id');
        $window = $request->query('window', 'all');
        $q = trim((string) $request->query('q', ''));

        // Base query: lots with positive remaining qty and having an expiry_date
        $baseQuery = StockLot::query()
            ->with(['product.baseUnit', 'warehouseLocation.warehouse.branch'])
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('warehouseLocation.warehouse', fn ($w) => $w->where('branch_id', $branchId));
            })
            ->when($warehouseId, function ($query) use ($warehouseId) {
                $query->whereHas('warehouseLocation', fn ($wl) => $wl->where('warehouse_id', $warehouseId));
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('lot_number', 'like', "%{$q}%")
                        ->orWhereHas('product', fn ($p) => $p->where('sku_code', 'like', "%{$q}%")->orWhere('name_th', 'like', "%{$q}%"));
                });
            });

        // Summary counts and values across the 4 buckets (independent of current $window filter)
        $summaryQuery = clone $baseQuery;
        $lotsForSummary = (clone $summaryQuery)->get(['id', 'expiry_date', 'remaining_qty', 'unit_cost']);

        $summary = [
            'expired' => [
                'count' => 0,
                'qty' => 0.0,
                'cost' => 0.0,
            ],
            '3days' => [
                'count' => 0,
                'qty' => 0.0,
                'cost' => 0.0,
            ],
            '7days' => [
                'count' => 0,
                'qty' => 0.0,
                'cost' => 0.0,
            ],
            '15days' => [
                'count' => 0,
                'qty' => 0.0,
                'cost' => 0.0,
            ],
            'total_at_risk_cost' => 0.0,
        ];

        foreach ($lotsForSummary as $lot) {
            $diff = (int) $today->diffInDays($lot->expiry_date->startOfDay(), false);
            $qty = (float) $lot->remaining_qty;
            $cost = $qty * (float) $lot->unit_cost;

            if ($diff < 0) {
                $summary['expired']['count']++;
                $summary['expired']['qty'] += $qty;
                $summary['expired']['cost'] += $cost;
                $summary['total_at_risk_cost'] += $cost;
            } elseif ($diff <= 3) {
                $summary['3days']['count']++;
                $summary['3days']['qty'] += $qty;
                $summary['3days']['cost'] += $cost;
                $summary['total_at_risk_cost'] += $cost;
            } elseif ($diff <= 7) {
                $summary['7days']['count']++;
                $summary['7days']['qty'] += $qty;
                $summary['7days']['cost'] += $cost;
                $summary['total_at_risk_cost'] += $cost;
            } elseif ($diff <= 15) {
                $summary['15days']['count']++;
                $summary['15days']['qty'] += $qty;
                $summary['15days']['cost'] += $cost;
                $summary['total_at_risk_cost'] += $cost;
            }
        }

        // Apply window filter to the main table query
        $tableQuery = clone $baseQuery;
        match ($window) {
            'expired' => $tableQuery->whereDate('expiry_date', '<', $today),
            '3days' => $tableQuery->whereBetween('expiry_date', [$today, $today->copy()->addDays(3)]),
            '7days' => $tableQuery->whereBetween('expiry_date', [$today->copy()->addDays(4), $today->copy()->addDays(7)]),
            '15days' => $tableQuery->whereBetween('expiry_date', [$today->copy()->addDays(8), $today->copy()->addDays(15)]),
            'all_future' => $tableQuery->whereDate('expiry_date', '>=', $today),
            'everything' => $tableQuery, // ทุกล็อตที่ระบุวันหมดอายุ
            default => $tableQuery->whereDate('expiry_date', '<=', $today->copy()->addDays(15)), // 'all': หมดอายุแล้ว + ใกล้หมดอายุ <= 15 วัน
        };

        $lots = $tableQuery->orderBy('expiry_date', 'asc')
            ->orderByDesc('remaining_qty')
            ->paginate(30)
            ->withQueryString();

        $branches = Branch::where('is_active', true)->orderBy('code')->get();
        $warehouses = Warehouse::where('is_active', true)
            ->when($branchId, fn ($w) => $w->where('branch_id', $branchId))
            ->orderBy('name')
            ->get();

        return view('stock-expiry.index', compact(
            'lots',
            'summary',
            'branches',
            'warehouses',
            'branchId',
            'warehouseId',
            'window',
            'q'
        ));
    }
}
