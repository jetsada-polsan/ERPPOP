<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\QtyPromotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QtyPromotionController extends Controller
{
    public function index(): View
    {
        $promotions = QtyPromotion::with(['product', 'freeProduct', 'branch'])
            ->orderByDesc('id')->paginate(50);
        $products = Product::where('is_active', true)->orderBy('sku_code')->limit(300)->get(['id', 'sku_code', 'name_th']);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);

        return view('qty-promotions.index', compact('promotions', 'products', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePromotion($request);
        QtyPromotion::create($data);

        return redirect()->route('qty-promotions.index')->with('success', "เพิ่มแคมเปญ {$data['code']} แล้ว");
    }

    public function update(Request $request, QtyPromotion $qtyPromotion): RedirectResponse
    {
        $data = $this->validatePromotion($request, $qtyPromotion->id);
        $qtyPromotion->update($data);

        return redirect()->route('qty-promotions.index')->with('success', 'บันทึกแคมเปญแล้ว');
    }

    private function validatePromotion(Request $request, ?int $ignoreId = null): array
    {
        $discountValueRules = ['nullable', 'required_if:promo_type,discount', 'numeric', 'min:0.01'];
        if ($request->input('discount_type') === 'percent') {
            $discountValueRules[] = 'max:100';
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:qty_promotions,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'promo_type' => ['required', 'string', 'in:free_item,discount'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'min_qty' => ['required', 'numeric', 'min:0.0001'],
            'free_product_id' => ['nullable', 'required_if:promo_type,free_item', 'integer', 'exists:products,id'],
            'free_qty' => ['nullable', 'required_if:promo_type,free_item', 'numeric', 'min:0.0001'],
            'discount_type' => ['nullable', 'required_if:promo_type,discount', 'string', 'in:percent,baht'],
            'discount_value' => $discountValueRules,
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_date' => ['nullable', 'date'],
            'ends_date' => ['nullable', 'date', 'after_or_equal:starts_date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($data['promo_type'] === 'free_item') {
            $data['discount_type'] = null;
            $data['discount_value'] = null;
        } else {
            $data['free_product_id'] = null;
            $data['free_qty'] = null;
        }

        return $data;
    }
}
