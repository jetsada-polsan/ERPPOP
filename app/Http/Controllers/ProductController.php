<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PriceChange;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductDepartment;
use App\Models\ProductPrice;
use App\Models\ProductSupplier;
use App\Models\ProductUnit;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = $request->integer('category_id') ?: null;
        $productType = trim((string) $request->query('product_type', ''));

        $products = Product::query()
            ->with(['category', 'brand', 'baseUnit'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
            ))
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->when($productType === 'scale', fn ($query) => $query->whereHas('barcodes', fn ($b) => $b
                ->where('is_active', true)
                ->where(fn ($w) => $w->where('barcode', 'like', '800%')->orWhere('barcode', 'like', '801%'))))
            ->orderBy('name_th')
            ->paginate(30)
            ->withQueryString();

        $maxScalePlu = ProductBarcode::where('is_active', true)
            ->where('barcode', 'like', '800%')
            ->pluck('barcode')
            ->filter(fn ($barcode) => preg_match('/^800[0-9]{3}$/', (string) $barcode) === 1)
            ->map(fn ($barcode) => (int) $barcode)
            ->max();
        $nextScalePlu = str_pad((string) (($maxScalePlu ?: 800000) + 1), 6, '0', STR_PAD_LEFT);

        return view('products.index', [
            'products' => $products,
            'q' => $q,
            'categoryId' => $categoryId,
            'productType' => $productType,
            'maxScalePlu' => $maxScalePlu ? str_pad((string) $maxScalePlu, 6, '0', STR_PAD_LEFT) : '-',
            'nextScalePlu' => $nextScalePlu,
            'categories' => ProductCategory::orderBy('name_th')->get(),
            'departments' => ProductDepartment::orderBy('name_th')->get(),
            'brands' => ProductBrand::orderBy('name_th')->get(),
            'units' => ProductUnit::orderBy('name')->get()
                ->reject(fn (ProductUnit $unit) => $unit->isCorrupted())
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request);

        $product = Product::create($data);

        return redirect()->route('products.show', $product)->with('success', "เพิ่มสินค้า {$product->sku_code} แล้ว");
    }

    public function show(Product $product): View
    {
        $product->load([
            'category', 'brand', 'department', 'baseUnit',
            'barcodes.unit',
            'stockBalances.warehouseLocation.warehouse',
            'stockLots' => fn ($query) => $query->with('warehouseLocation.warehouse')
                ->where('remaining_qty', '>', 0)->orderBy('expiry_date')->orderBy('received_date'),
            'suppliers.supplier',
        ]);

        // Load all price tables with this product's prices + which branches use each table
        $priceTables = PriceTable::where('is_active', true)->where('is_default', true)->orderBy('code')->get();

        $productPrices = ProductPrice::with(['unit', 'priceTable'])
            ->where('product_id', $product->id)
            ->get()
            ->groupBy('price_table_id');

        // Map branches to their price tables
        $branchesByTable = Branch::whereNotNull('price_table_id')
            ->get(['id', 'code', 'name_th', 'price_table_id'])
            ->groupBy('price_table_id');

        $defaultPriceTable = PriceTable::where('is_default', true)->first();
        $currentVatRate = (float) (DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7);

        // เลข PLU เครื่องชั่งถัดไป (รันต่อจากรหัสสูงสุดในช่วง 800xxx +1) — DB-agnostic
        $maxScalePlu = ProductBarcode::where('barcode', 'like', '800%')
            ->pluck('barcode')
            ->filter(fn ($b) => preg_match('/^800[0-9]{3}$/', (string) $b) === 1)
            ->map(fn ($b) => (int) $b)
            ->max();
        $nextScalePlu = str_pad((string) (($maxScalePlu ?: 800000) + 1), 6, '0', STR_PAD_LEFT);

        $legacyUnitIds = collect([$product->base_unit_id])
            ->merge($product->barcodes->pluck('unit_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return view('products.show', [
            'product' => $product,
            'units' => ProductUnit::orderBy('name')->get()
                ->filter(fn (ProductUnit $unit) => ! $unit->isCorrupted() || $legacyUnitIds->contains($unit->id))
                ->values(),
            'categories' => ProductCategory::orderBy('name_th')->get(),
            'departments' => ProductDepartment::orderBy('name_th')->get(),
            'brands' => ProductBrand::orderBy('name_th')->get(),
            'priceTables' => $priceTables,
            'productPrices' => $productPrices,
            'branchesByTable' => $branchesByTable,
            'defaultPriceTable' => $defaultPriceTable,
            'currentVatRate' => $currentVatRate,
            'nextScalePlu' => $nextScalePlu,
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']),
        ]);
    }

    // Upsert price from product show page
    public function upsertPrice(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'price_table_id' => ['required', 'integer', 'exists:price_tables,id'],
            'unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $identity = [
            'product_id' => $product->id,
            'price_table_id' => $data['price_table_id'],
            'unit_id' => $data['unit_id'] ?? null,
        ];
        $oldPrice = ProductPrice::where($identity)->value('price');
        $pp = ProductPrice::updateOrCreate(
            $identity,
            [
                'price' => $data['price'],
                'cost_price' => $data['cost_price'] ?? 0,
                'is_active' => true,
            ]
        );
        if ($oldPrice === null || round((float) $oldPrice, 4) !== round((float) $data['price'], 4)) {
            PriceChange::create([
                'product_id' => $product->id,
                'old_price' => $oldPrice,
                'new_price' => $data['price'],
                'effective_date' => now()->toDateString(),
                'changed_by' => auth()->id(),
            ]);
        }

        if ($request->boolean('compact_form')) {
            return redirect()->route('products.show', [
                'product' => $product,
                'popup' => $request->boolean('popup') ? 1 : null,
            ])->with('success', 'บันทึกราคาขายแล้ว');
        }

        return response()->json(['success' => true, 'id' => $pp->id, 'price' => (float) $pp->price]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateProduct($request, $product->id);

        $product->update($data);

        return redirect()->route('products.show', ['product' => $product, 'popup' => $request->boolean('popup') ? 1 : null])->with('success', 'บันทึกข้อมูลสินค้าแล้ว');
    }

    public function addBarcode(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'barcode_mode' => ['nullable', 'in:manual,auto_ean13'],
            'barcode' => [$request->input('barcode_mode') === 'auto_ean13' ? 'nullable' : 'required', 'string', 'max:50', 'unique:product_barcodes,barcode'],
            'unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'unit_factor' => ['required', 'numeric', 'min:0.0001'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        unset($data['barcode_mode']);
        if ($request->input('barcode_mode') === 'auto_ean13') {
            DB::transaction(function () use ($product, $data): void {
                // กันเลขชนกันกรณีผู้ใช้หลายคนกดสร้างพร้อมกัน
                DB::statement('SELECT pg_advisory_xact_lock(299013)');
                $data['barcode'] = $this->nextInternalEan13();
                $product->barcodes()->create($data + ['is_active' => true]);
            });
        } else {
            $product->barcodes()->create($data + ['is_active' => true]);
        }

        return redirect()->route('products.show', [
            'product' => $product,
            'popup' => $request->boolean('popup') ? 1 : null,
        ])->with('success', 'เพิ่มบาร์โค้ดแล้ว');
    }

    /** สร้าง EAN-13 ภายในร้าน ช่วง 299 + running 9 หลัก + check digit */
    private function nextInternalEan13(): string
    {
        $last = ProductBarcode::query()
            ->where('barcode', 'like', '299%')
            ->whereRaw('LENGTH(barcode) = 13')
            ->orderByDesc('barcode')
            ->lockForUpdate()
            ->value('barcode');

        $sequence = $last ? ((int) substr((string) $last, 3, 9)) + 1 : 1;
        while ($sequence <= 999999999) {
            $body = '299'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);
            $barcode = $body.$this->ean13CheckDigit($body);
            if (! ProductBarcode::where('barcode', $barcode)->exists()) {
                return $barcode;
            }
            $sequence++;
        }

        throw new \RuntimeException('เลขบาร์โค้ด EAN-13 ภายในร้านเต็มแล้ว');
    }

    private function ean13CheckDigit(string $body): int
    {
        $sum = 0;
        foreach (str_split($body) as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function updateBarcode(Request $request, Product $product, ProductBarcode $productBarcode): RedirectResponse
    {
        abort_unless($productBarcode->product_id === $product->id, 404);

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:50', 'unique:product_barcodes,barcode,'.$productBarcode->id],
            'unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'unit_factor' => ['required', 'numeric', 'min:0.0001'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        $productBarcode->update($data);

        return redirect()->route('products.show', [
            'product' => $product,
            'popup' => $request->boolean('popup') ? 1 : null,
        ])->with('success', 'แก้ไขบาร์โค้ดแล้ว');
    }

    public function upsertSupplier(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_sku' => ['nullable', 'string', 'max:80'],
            'last_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_qty' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_primary' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $primary = $request->boolean('is_primary');
        if ($primary) {
            $product->suppliers()->update(['is_primary' => false]);
        }
        ProductSupplier::updateOrCreate(
            ['product_id' => $product->id, 'supplier_id' => $data['supplier_id']],
            [...$data, 'is_primary' => $primary],
        );

        return back()->with('success', 'บันทึกผู้จำหน่ายของสินค้าแล้ว');
    }

    public function removeSupplier(Product $product, ProductSupplier $productSupplier): RedirectResponse
    {
        abort_unless($productSupplier->product_id === $product->id, 404);
        $productSupplier->delete();

        return back()->with('success', 'นำผู้จำหน่ายออกจากแฟ้มสินค้าแล้ว');
    }

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'sku_code' => ['required', 'string', 'max:30', 'unique:products,sku_code,'.($ignoreId ?? 'NULL').',id'],
            'name_th' => ['required', 'string', 'max:250'],
            'name_en' => ['nullable', 'string', 'max:250'],
            'note' => ['nullable', 'string', 'max:2000'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'product_department_id' => ['nullable', 'integer', 'exists:product_departments,id'],
            'product_brand_id' => ['nullable', 'integer', 'exists:product_brands,id'],
            'base_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'default_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_vat' => ['nullable', 'boolean'],
            'tracks_expiry' => ['nullable', 'boolean'],
            'expiry_warning_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'expiry_sale_policy' => ['nullable', 'in:block,allow'],
            'negative_stock_policy' => ['required', 'in:block,allow'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0', 'gte:minimum_stock'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_vat'] = $request->boolean('is_vat');
        $data['tracks_expiry'] = $request->boolean('tracks_expiry');
        $data['expiry_warning_days'] = $data['expiry_warning_days'] ?? 30;
        $data['expiry_sale_policy'] = $data['expiry_sale_policy'] ?? 'block';

        return $data;
    }
}
