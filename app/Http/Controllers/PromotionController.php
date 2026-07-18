<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(): View
    {
        $promotions = Promotion::with('product')->orderByDesc('starts_at')->orderBy('code')->paginate(50);
        $products = Product::where('is_active', true)->orderBy('sku_code')->limit(300)->get();

        return view('promotions.index', compact('promotions', 'products'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePromotion($request);
        Promotion::create($data);

        return redirect()->route('promotions.index')->with('success', "เพิ่มโปรโมชั่น {$data['code']} แล้ว");
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $data = $this->validatePromotion($request, $promotion->id);
        $promotion->update($data);

        return redirect()->route('promotions.index')->with('success', 'บันทึกโปรโมชั่นแล้ว');
    }

    private function validatePromotion(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:promotions,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'promotion_type' => ['required', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'min_qty' => ['nullable', 'numeric', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
