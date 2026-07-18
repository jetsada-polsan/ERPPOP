<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\PriceTable;
use App\Models\FlashSale;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PriceTableController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $tables = PriceTable::withCount('productPrices')->where('is_default', true)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
            ))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::with('priceTable')->orderBy('code')->get();

        $now = now();
        $pricingSummary = [
            'normal_tables' => PriceTable::where('is_active', true)->count(),
            'assigned_branches' => $branches->whereNotNull('price_table_id')->count(),
            'total_branches' => $branches->count(),
            'active_promotions' => Promotion::where('is_active', true)
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now->toDateString()))
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now->toDateString()))->count(),
            'active_flash_sales' => FlashSale::where('is_active', true)
                ->where('starts_date', '<=', $now->toDateString())
                ->where(fn ($w) => $w->whereNull('ends_date')->orWhere('ends_date', '>=', $now->toDateString()))->count(),
            'scale_products' => Product::whereHas('barcodes', fn ($q) => $q
                ->where('is_active', true)
                ->where(fn ($w) => $w->where('barcode', 'like', '800%')->orWhere('barcode', 'like', '801%')))->count(),
        ];

        return view('price-tables.index', compact('tables', 'branches', 'q', 'pricingSummary'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:price_tables,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active', true);

        if ($data['is_default']) {
            PriceTable::where('is_default', true)->update(['is_default' => false]);
        }

        $table = PriceTable::create($data);

        return redirect()->route('price-tables.show', $table)->with('success', "สร้างตารางราคา {$table->name} แล้ว");
    }

    public function show(Request $request, PriceTable $priceTable): View
    {
        $q = trim((string) $request->query('q', ''));

        $productPrices = ProductPrice::with(['product', 'unit'])
            ->join('products', 'products.id', '=', 'product_prices.product_id')
            ->where('price_table_id', $priceTable->id)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('products.sku_code', 'ilike', "%{$q}%")
                ->orWhere('products.name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('products.sku_code')
            ->select('product_prices.*')
            ->paginate(50)
            ->withQueryString();

        $units = ProductUnit::orderBy('name')->get(['id', 'name', 'code']);
        $branches = Branch::where('price_table_id', $priceTable->id)->orderBy('code')->get(['id', 'code', 'name_th']);

        return view('price-tables.show', compact('priceTable', 'productPrices', 'units', 'branches', 'q'));
    }

    public function update(Request $request, PriceTable $priceTable): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:price_tables,code,'.$priceTable->id],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active', true);

        if ($data['is_default'] && ! $priceTable->is_default) {
            PriceTable::where('is_default', true)->update(['is_default' => false]);
        }

        $priceTable->update($data);

        return redirect()->route('price-tables.show', $priceTable)->with('success', 'บันทึกตารางราคาแล้ว');
    }

    // Save / update a single product-price row (called via inline edit in show page)
    public function upsertPrice(Request $request, PriceTable $priceTable): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $pp = ProductPrice::updateOrCreate(
            [
                'product_id' => $data['product_id'],
                'price_table_id' => $priceTable->id,
                'unit_id' => $data['unit_id'] ?? null,
            ],
            [
                'price' => $data['price'],
                'cost_price' => $data['cost_price'] ?? 0,
                'min_qty' => $data['min_qty'] ?? 1,
                'is_active' => true,
            ]
        );

        return response()->json(['success' => true, 'id' => $pp->id]);
    }

    // Assign a branch to this price table
    public function assignBranch(Request $request, PriceTable $priceTable): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        Branch::whereKey($data['branch_id'])->update(['price_table_id' => $priceTable->id]);

        return redirect()->route('price-tables.show', $priceTable)->with('success', 'กำหนดสาขาแล้ว');
    }

    // Quick product search for adding to this price table
    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::where('is_active', true)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('sku_code')
            ->limit(20)
            ->get(['id', 'sku_code', 'name_th', 'default_price']);

        return response()->json($products);
    }
}
