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
        $promotions = QtyPromotion::with(['product.baseUnit', 'freeProduct.baseUnit', 'branch', 'groupProducts:id,sku_code,name_th'])
            ->orderByDesc('id')->paginate(50);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);

        return view('qty-promotions.index', compact('promotions', 'branches'));
    }

    public function store(Request $request): RedirectResponse
    {
        AutoCode::fill($request, 'qty_promotions', 'QP');
        $data = $this->validatePromotion($request);
        $groupIds = $data['_group'] ?? [];
        unset($data['_group']);
        QtyPromotion::create($data)->groupProducts()->sync($groupIds);

        return redirect()->route('qty-promotions.index')->with('success', "เพิ่มแคมเปญ {$data['code']} แล้ว");
    }

    public function update(Request $request, QtyPromotion $qtyPromotion): RedirectResponse
    {
        $data = $this->validatePromotion($request, $qtyPromotion->id);
        $groupIds = $data['_group'] ?? [];
        unset($data['_group']);
        $qtyPromotion->update($data);
        $qtyPromotion->groupProducts()->sync($groupIds);

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
            'promo_type' => ['required', 'string', 'in:free_item,discount,bundle_price,mix_bundle,mix_free,bill_discount,bill_free_item,bill_addon'],
            'product_id' => ['nullable', 'required_if:promo_type,free_item,discount,bundle_price,bill_addon', 'integer', 'exists:products,id'],
            'product_ids' => ['nullable', 'required_if:promo_type,mix_bundle,mix_free', 'array', 'min:2'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'min_qty' => ['nullable', 'required_unless:promo_type,bill_discount,bill_free_item,bill_addon', 'numeric', 'min:0.0001'],
            'min_amount' => ['nullable', 'required_if:promo_type,bill_discount,bill_free_item,bill_addon', 'numeric', 'min:0.01'],
            'free_product_id' => ['nullable', 'required_if:promo_type,free_item,bill_free_item', 'integer', 'exists:products,id'],
            'free_qty' => ['nullable', 'required_if:promo_type,free_item,bill_free_item,mix_free', 'numeric', 'min:0.0001'],
            'discount_type' => ['nullable', 'required_if:promo_type,discount,bill_discount', 'string', 'in:percent,baht'],
            'discount_value' => $discountValueRules,
            'max_discount' => ['nullable', 'numeric', 'min:0.01'],
            'bundle_price' => ['nullable', 'required_if:promo_type,bundle_price,mix_bundle,bill_addon', 'numeric', 'min:0'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'กรุณากรอกชื่อแคมเปญ',
            'promo_type.required' => 'กรุณาเลือกประเภทโปรโมชัน',
            'promo_type.in' => 'ประเภทโปรโมชันไม่ถูกต้อง',
            'product_id.required_if' => 'กรุณาเลือกสินค้า',
            'product_id.exists' => 'ไม่พบข้อมูลสินค้าที่เลือก',
            'product_ids.required_if' => 'กรุณาเลือกสินค้าในกลุ่มอย่างน้อย 2 ตัว',
            'product_ids.min' => 'กรุณาเลือกสินค้าในกลุ่มอย่างน้อย 2 ตัว',
            'min_qty.required_unless' => 'กรุณาระบุจำนวนสินค้าขั้นต่ำ',
            'min_qty.min' => 'จำนวนสินค้าขั้นต่ำต้องมากกว่า 0',
            'min_amount.required_if' => 'กรุณาระบุยอดซื้อขั้นต่ำ',
            'min_amount.min' => 'ยอดซื้อขั้นต่ำต้องมากกว่า 0',
            'free_product_id.required_if' => 'กรุณาเลือกสินค้าของแถม',
            'free_product_id.exists' => 'ไม่พบข้อมูลสินค้าของแถม',
            'free_qty.required_if' => 'กรุณาระบุจำนวนของแถม',
            'free_qty.min' => 'จำนวนของแถมต้องมากกว่า 0',
            'discount_type.required_if' => 'กรุณาเลือกประเภทส่วนลด',
            'discount_value.required_if' => 'กรุณาระบุมูลค่าส่วนลด',
            'discount_value.min' => 'มูลค่าส่วนลดต้องมากกว่า 0',
            'discount_value.max' => 'ส่วนลดเปอร์เซ็นต์ต้องไม่เกิน 100%',
            'bundle_price.required_if' => 'กรุณาระบุราคาชุด/ราคาแลกซื้อ',
            'bundle_price.min' => 'ราคาชุด/ราคาแลกซื้อต้องไม่ติดลบ',
            'ends_at.after_or_equal' => 'เวลาสิ้นสุดต้องไม่ก่อนเวลาเริ่มต้น',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $type = $data['promo_type'];
        $data['_group'] = in_array($type, QtyPromotion::MIX_TYPES, true) ? array_values(array_unique($data['product_ids'] ?? [])) : [];
        unset($data['product_ids']);
        if (in_array($type, QtyPromotion::MIX_TYPES, true)) {
            // ซื้อคละไม่ผูกสินค้าตัวเดียว ใช้กลุ่มสินค้าแทน
            $data['product_id'] = null;
        }
        if (in_array($type, QtyPromotion::BILL_TYPES, true)) {
            // โปรต่อบิล เงื่อนไขคือยอดบิล (แลกซื้อใช้ product_id เป็นสินค้าที่แลก)
            if ($type !== 'bill_addon') {
                $data['product_id'] = null;
            }
            $data['min_qty'] = 1;
        } else {
            $data['min_amount'] = null;
        }
        if ($type === 'bill_addon') {
            // จำนวนที่แลกได้ต่อบิล ไม่ใส่ = 1
            $data['free_qty'] = $data['free_qty'] ?? 1;
        }
        if (! in_array($type, ['free_item', 'bill_free_item', 'mix_free', 'bill_addon'], true)) {
            $data['free_qty'] = null;
        }
        if (! in_array($type, ['free_item', 'bill_free_item'], true)) {
            $data['free_product_id'] = null;
        }
        if (! in_array($type, ['discount', 'bill_discount'], true)) {
            $data['discount_type'] = null;
            $data['discount_value'] = null;
        }
        if ($type !== 'bill_discount') {
            $data['max_discount'] = null;
        }
        if (! in_array($type, ['bundle_price', 'mix_bundle', 'bill_addon'], true)) {
            $data['bundle_price'] = null;
        }

        return $data;
    }
}
