<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Salesman;
use App\Models\StockBalance;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead search endpoints backing the Alpine.js customer/supplier/product
 * pickers on the booking/sale/purchase forms - these tables are too large for a
 * plain <select> (4.6k customers, 3.6k products, 231 suppliers).
 */
class SearchController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('name_th')
            ->limit(20)
            ->get(['id', 'code', 'name_th', 'credit_limit']);

        return response()->json($customers);
    }

    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->with(['baseUnit:id,name,qty_per_base_unit', 'barcodes' => fn ($query) => $query
                ->where('is_active', true)->orderBy('id')])
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
                ->orWhereHas('barcodes', fn ($barcode) => $barcode
                    ->where('is_active', true)
                    ->where('barcode', 'ilike', "%{$q}%"))
            ))
            ->orderByRaw('case when sku_code = ? then 0 when sku_code ilike ? then 1 else 2 end', [$q, $q.'%'])
            ->orderBy('name_th')
            ->limit(20)
            ->get(['id', 'sku_code', 'name_th', 'base_unit_id', 'default_price', 'average_cost', 'tracks_expiry', 'shelf_life_days'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku_code' => $product->sku_code,
                'name_th' => $product->name_th,
                'default_price' => (float) ($product->default_price ?? 0),
                'average_cost' => (float) ($product->average_cost ?? 0),
                'tracks_expiry' => $product->tracks_expiry,
                'shelf_life_days' => $product->shelf_life_days,
                'unit_name' => $product->baseUnit?->displayLabel() ?? '-',
                'barcode' => $product->barcodes->first()?->barcode,
            ]);

        return response()->json($products);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('name_th')
            ->limit(20)
            ->get(['id', 'code', 'name_th']);

        return response()->json($suppliers);
    }

    public function salesmen(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $salesmen = Salesman::where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
            ))
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'name']);

        return response()->json($salesmen);
    }

    public function branches(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $branches = Branch::where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('code')
            ->limit(20)
            ->get(['id', 'code', 'name_th']);

        return response()->json($branches);
    }

    // Used by the stock transfer/adjustment forms to show the current on_hand_qty
    // for a product at a specific location right after it's picked.
    public function stockBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_location_id' => ['required', 'integer'],
        ]);

        $onHandQty = (float) (StockBalance::where('product_id', $data['product_id'])
            ->where('warehouse_location_id', $data['warehouse_location_id'])
            ->value('on_hand_qty') ?? 0);

        return response()->json(['on_hand_qty' => $onHandQty]);
    }
}
