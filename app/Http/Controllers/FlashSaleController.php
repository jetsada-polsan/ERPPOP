<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FlashSaleController extends Controller
{
    public function index(): View
    {
        $flashSales = FlashSale::with('branch')->withCount('items')
            ->orderByDesc('starts_date')->orderBy('code')->paginate(50);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);

        return view('flash-sales.index', compact('flashSales', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateFlashSale($request);
        $flashSale = FlashSale::create($data);

        return redirect()->route('flash-sales.show', $flashSale)->with('success', "สร้างแคมเปญนาทีทอง {$flashSale->code} แล้ว");
    }

    public function show(Request $request, FlashSale $flashSale): View
    {
        $q = trim((string) $request->query('q', ''));

        $items = FlashSaleItem::with('product.baseUnit')
            ->join('products', 'products.id', '=', 'flash_sale_items.product_id')
            ->where('flash_sale_id', $flashSale->id)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('products.sku_code', 'ilike', "%{$q}%")
                ->orWhere('products.name_th', 'ilike', "%{$q}%")
            ))
            ->orderBy('products.sku_code')
            ->select('flash_sale_items.*')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);

        return view('flash-sales.show', compact('flashSale', 'items', 'q', 'branches'));
    }

    public function update(Request $request, FlashSale $flashSale): RedirectResponse
    {
        $data = $this->validateFlashSale($request, $flashSale->id);
        $flashSale->update($data);

        return redirect()->route('flash-sales.show', $flashSale)->with('success', 'บันทึกแคมเปญนาทีทองแล้ว');
    }

    // Save / update a single product's flash price (inline edit in show page)
    public function upsertItem(Request $request, FlashSale $flashSale): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'flash_price' => ['required', 'numeric', 'min:0'],
            'max_qty_per_bill' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $item = FlashSaleItem::updateOrCreate(
            ['flash_sale_id' => $flashSale->id, 'product_id' => $data['product_id']],
            ['flash_price' => $data['flash_price'], 'max_qty_per_bill' => $data['max_qty_per_bill'] ?? null]
        );

        return response()->json(['success' => true, 'id' => $item->id]);
    }

    public function destroyItem(FlashSale $flashSale, FlashSaleItem $item): RedirectResponse
    {
        abort_unless($item->flash_sale_id === $flashSale->id, 404);
        $item->delete();

        return redirect()->route('flash-sales.show', $flashSale)->with('success', 'ลบสินค้าออกจากแคมเปญแล้ว');
    }

    // Quick product search for adding to this flash sale (code / name / barcode)
    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::where('is_active', true)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('sku_code', 'ilike', "%{$q}%")
                ->orWhere('name_th', 'ilike', "%{$q}%")
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'ilike', "%{$q}%"))
            ))
            ->orderBy('sku_code')
            ->limit(20)
            ->get(['id', 'sku_code', 'name_th', 'default_price']);

        return response()->json($products);
    }

    private function validateFlashSale(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:flash_sales,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_date' => ['required', 'date'],
            'ends_date' => ['nullable', 'date', 'after_or_equal:starts_date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'code.required' => 'กรุณาระบุรหัสกลุ่มนาทีทอง',
            'code.unique' => 'รหัสนี้ถูกใช้ไปแล้ว กรุณาใช้รหัสอื่น',
            'name.required' => 'กรุณาระบุชื่อกลุ่มนาทีทอง',
            'starts_date.required' => 'กรุณาระบุวันที่เริ่มแคมเปญ',
            'ends_date.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม',
            'end_time.after' => 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่ม',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['days_of_week'] = ! empty($data['days_of_week']) ? implode(',', $data['days_of_week']) : null;

        return $data;
    }
}
