<?php

namespace App\Http\Controllers;

use App\Models\DiscountCard;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscountCardController extends Controller
{
    public function index(): View
    {
        $cards = DiscountCard::with('member')->orderByDesc('id')->paginate(50);
        $members = Member::where('is_active', true)->orderBy('member_code')->limit(300)->get();

        return view('discount-cards.index', compact('cards', 'members'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCard($request);
        $card = DiscountCard::create($data);

        return redirect()->route('discount-cards.index')->with('success', "เพิ่มบัตรส่วนลด {$card->card_code} แล้ว");
    }

    public function update(Request $request, DiscountCard $discountCard): RedirectResponse
    {
        $data = $this->validateCard($request, $discountCard->id);
        $discountCard->update($data);

        return redirect()->route('discount-cards.index')->with('success', 'บันทึกบัตรส่วนลดแล้ว');
    }

    // Called from POS to preview whether a scanned/typed card code applies
    // to the current bill subtotal. Does not consume the card's usage count
    // - that only happens when the sale is actually posted at checkout.
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'card_code' => ['required', 'string', 'max:30'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $card = DiscountCard::where('card_code', $data['card_code'])->first();
        if (! $card) {
            return response()->json(['success' => false, 'message' => 'ไม่พบบัตรส่วนลดนี้'], 404);
        }

        if (! $card->isValidAt(now())) {
            return response()->json(['success' => false, 'message' => 'บัตรนี้หมดอายุ ปิดใช้งาน หรือใช้ครบจำนวนแล้ว'], 422);
        }

        $discount = $card->computeDiscount((float) $data['subtotal']);
        if ($discount === null) {
            $minText = $card->min_amount ? number_format((float) $card->min_amount, 2) : '0.00';

            return response()->json(['success' => false, 'message' => "ยอดซื้อไม่ถึงขั้นต่ำ ({$minText} บาท)"], 422);
        }

        return response()->json([
            'success' => true,
            'card_code' => $card->card_code,
            'name' => $card->name,
            'discount_amount' => $discount,
            'discount_type' => $card->discount_type,
            'discount_value' => (float) $card->discount_value,
            'min_amount' => $card->min_amount !== null ? (float) $card->min_amount : null,
            'max_discount_amount' => $card->max_discount_amount !== null ? (float) $card->max_discount_amount : null,
        ]);
    }

    private function validateCard(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'card_code' => ['required', 'string', 'max:30', 'unique:discount_cards,card_code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
            'discount_type' => ['required', 'string', 'in:percent,amount'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_date' => ['nullable', 'date'],
            'ends_date' => ['nullable', 'date', 'after_or_equal:starts_date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
