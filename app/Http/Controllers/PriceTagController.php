<?php

namespace App\Http\Controllers;

use App\Models\FlashSaleItem;
use App\Models\PriceTable;
use App\Models\PriceTagTemplate;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PriceTagController extends Controller
{
    public function index(): View
    {
        $templates = PriceTagTemplate::with('priceTable')->orderBy('code')->paginate(50);
        $priceTables = PriceTable::orderBy('name')->get(['id', 'code', 'name']);

        return view('price-tags.index', compact('templates', 'priceTables'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTemplate($request);
        PriceTagTemplate::create($data);

        return redirect()->route('price-tags.index')->with('success', "เพิ่มป้ายราคา {$data['code']} แล้ว");
    }

    public function update(Request $request, PriceTagTemplate $priceTag): RedirectResponse
    {
        $data = $this->validateTemplate($request, $priceTag->id);
        $priceTag->update($data);

        return redirect()->route('price-tags.index')->with('success', 'บันทึกป้ายราคาแล้ว');
    }

    // Picker page: choose a tag template, search/add products with a print
    // quantity each, then submit to preview() to generate the label sheet.
    public function print(): View
    {
        $templates = PriceTagTemplate::where('is_active', true)->orderBy('code')->get();

        return view('price-tags.print', compact('templates'));
    }

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

    // Renders a standalone, print-ready sheet of labels (no app layout).
    public function preview(Request $request): View
    {
        $data = $request->validate([
            'price_tag_template_id' => ['required', 'integer', 'exists:price_tag_templates,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $template = PriceTagTemplate::findOrFail($data['price_tag_template_id']);
        $productIds = collect($data['items'])->pluck('product_id')->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $priceTableValues = collect();
        if ($template->price_source === PriceTagTemplate::SOURCE_PRICE_TABLE && $template->price_table_id) {
            $priceTableValues = ProductPrice::where('price_table_id', $template->price_table_id)
                ->whereIn('product_id', $productIds)
                ->whereNull('unit_id')
                ->pluck('price', 'product_id');
        }

        $flashPrices = collect();
        if ($template->price_source === PriceTagTemplate::SOURCE_FLASH_SALE) {
            $now = now();
            $flashPrices = FlashSaleItem::with('flashSale')
                ->whereIn('product_id', $productIds)
                ->whereHas('flashSale', fn ($q) => $q->where('is_active', true)
                    ->where('starts_date', '<=', $now->toDateString())
                    ->where(fn ($w) => $w->whereNull('ends_date')->orWhere('ends_date', '>=', $now->toDateString())))
                ->get()
                ->filter(fn ($item) => $item->flashSale->isRunningAt($now))
                ->groupBy('product_id')
                ->map(fn ($items) => $items->sortBy('flash_price')->first()->flash_price);
        }

        $labels = [];
        foreach ($data['items'] as $line) {
            $product = $products->get($line['product_id']);
            if (! $product) {
                continue;
            }

            $price = match ($template->price_source) {
                PriceTagTemplate::SOURCE_PRICE_TABLE => (float) ($priceTableValues[$product->id] ?? $product->default_price),
                PriceTagTemplate::SOURCE_FLASH_SALE => isset($flashPrices[$product->id]) ? (float) $flashPrices[$product->id] : null,
                PriceTagTemplate::SOURCE_NO_PRICE => null,
                default => (float) $product->default_price,
            };

            for ($i = 0; $i < (int) $line['qty']; $i++) {
                $labels[] = ['product' => $product, 'price' => $price];
            }
        }

        return view('price-tags.preview', compact('template', 'labels'));
    }

    private function validateTemplate(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:price_tag_templates,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'price_source' => ['required', 'string', 'in:default,price_table,flash_sale,no_price'],
            'price_table_id' => ['nullable', 'required_if:price_source,price_table', 'integer', 'exists:price_tables,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        if ($data['price_source'] !== 'price_table') {
            $data['price_table_id'] = null;
        }

        return $data;
    }
}
