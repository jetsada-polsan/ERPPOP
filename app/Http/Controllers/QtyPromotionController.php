<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\QtyPromotion;
use Illuminate\Http\RedirectResponse;
use App\Support\AutoCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QtyPromotionController extends Controller
{
    public function index(): View
    {
        $promotions = QtyPromotion::with(['product', 'freeProduct', 'branch'])
            ->orderByDesc('id')->paginate(50);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);

        return view('qty-promotions.index', compact('promotions', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        AutoCode::fill($request, 'qty_promotions', 'QP');
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
        $discountValueRules = ['nullable', 'required_if:promo_type,discount,bill_discount', 'numeric', 'min:0.01'];
        if ($request->input('discount_type') === 'percent') {
            $discountValueRules[] = 'max:100';
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:qty_promotions,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'promo_type' => ['required', 'string', 'in:free_item,discount,bundle_price,bill_discount,bill_free_item'],
            'product_id' => ['nullable', 'required_unless:promo_type,bill_discount,bill_free_item', 'integer', 'exists:products,id'],
            'min_qty' => ['nullable', 'required_unless:promo_type,bill_discount,bill_free_item', 'numeric', 'min:0.0001'],
            'min_amount' => ['nullable', 'required_if:promo_type,bill_discount,bill_free_item', 'numeric', 'min:0.01'],
            'free_product_id' => ['nullable', 'required_if:promo_type,free_item,bill_free_item', 'integer', 'exists:products,id'],
            'free_qty' => ['nullable', 'required_if:promo_type,free_item,bill_free_item', 'numeric', 'min:0.0001'],
            'discount_type' => ['nullable', 'required_if:promo_type,discount,bill_discount', 'string', 'in:percent,baht'],
            'discount_value' => $discountValueRules,
            'max_discount' => ['nullable', 'numeric', 'min:0.01'],
            'bundle_price' => ['nullable', 'required_if:promo_type,bundle_price', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $type = $data['promo_type'];
        $bill = in_array($type, QtyPromotion::BILL_TYPES, true);
        if ($bill) {
            // โปรต่อบิลไม่ผูกสินค้าที่ต้องซื้อ เงื่อนไขคือยอดบิล
            $data['product_id'] = null;
            $data['min_qty'] = 1;
        } else {
            $data['min_amount'] = null;
        }
        if (! in_array($type, ['free_item', 'bill_free_item'], true)) {
            $data['free_product_id'] = null;
            $data['free_qty'] = null;
        }
        if (! in_array($type, ['discount', 'bill_discount'], true)) {
            $data['discount_type'] = null;
            $data['discount_value'] = null;
        }
        if ($type !== 'bill_discount') {
            $data['max_discount'] = null;
        }
        if ($data['promo_type'] !== 'bundle_price') {
            $data['bundle_price'] = null;
        }

        return $data;
    }
}
