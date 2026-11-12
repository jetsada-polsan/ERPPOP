<?php

namespace App\Http\Controllers;

use App\Models\DiscountCard;
use App\Models\FlashSale;
use App\Models\MemberPointRule;
use App\Models\Promotion;
use App\Models\QtyPromotion;
use Illuminate\View\View;

/**
 * ศูนย์โปรโมชั่น: ทางเข้าเดียวของทุกอย่างที่ "ลดราคา/ให้สิทธิ์" ลูกค้า
 * หน้าเดิมแต่ละประเภทยังอยู่ที่ route เดิม (บุ๊กมาร์กไม่พัง) ที่นี่แค่รวมทางเข้าและโปรที่กำลังใช้อยู่
 */
class PromotionCenterController extends Controller
{
    public function index(): View
    {
        $today = now()->toDateString();
        $now = now();

        $running = collect();

        Promotion::with('product:id,sku_code,name_th')->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today))
            ->orderBy('code')->get()
            ->each(fn (Promotion $p) => $running->push([
                'type' => 'ลดราคาสินค้ารายตัว',
                'code' => $p->code,
                'name' => $p->name,
                'detail' => trim(($p->product ? $p->product->sku_code.' ' : '').((float) $p->discount_amount > 0
                    ? 'ลด '.number_format((float) $p->discount_amount, 2).' บาท'
                    : ((float) $p->discount_percent > 0 ? 'ลด '.rtrim(rtrim(number_format((float) $p->discount_percent, 2), '0'), '.').'%' : ''))),
                'branch' => 'ทุกสาขา',
                'period' => ($p->starts_at?->format('d/m/Y') ?? 'ไม่ระบุ').' – '.($p->ends_at?->format('d/m/Y') ?? 'ไม่ระบุ'),
                'url' => route('promotions.index'),
            ]));

        FlashSale::with('branch:id,code,name_th')->withCount('items')->where('is_active', true)
            ->whereDate('starts_date', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ends_date')->orWhereDate('ends_date', '>=', $today))
            ->orderBy('starts_date')->get()
            ->each(fn (FlashSale $sale) => $running->push([
                'type' => 'นาทีทอง',
                'code' => $sale->code,
                'name' => $sale->name,
                'detail' => $sale->items_count.' สินค้า'.($sale->start_time ? ' · '.$sale->start_time->format('H:i').'–'.($sale->end_time?->format('H:i') ?? '') : '')
                    .($sale->isRunningAt($now) ? ' · เปิดอยู่ตอนนี้' : ' · รอช่วงเวลา'),
                'branch' => $sale->branch ? $sale->branch->code.' '.$sale->branch->name_th : 'ทุกสาขา',
                'period' => $sale->starts_date->format('d/m/Y').' – '.($sale->ends_date?->format('d/m/Y') ?? 'ไม่ระบุ'),
                'url' => route('flash-sales.show', $sale),
            ]));

        QtyPromotion::with('branch:id,code,name_th')->runningToday()->orderBy('code')->get()
            ->each(fn (QtyPromotion $q) => $running->push([
                'type' => $q->isBillLevel() ? 'โปรทั้งบิล' : 'โปรซื้อครบ / ราคาชุด / แถม',
                'code' => $q->code,
                'name' => $q->name,
                'detail' => $q->label(),
                'branch' => $q->branch ? $q->branch->code.' '.$q->branch->name_th : 'ทุกสาขา',
                'period' => ($q->starts_at?->format('d/m/Y H:i') ?? 'ไม่ระบุ').' – '.($q->ends_at?->format('d/m/Y H:i') ?? 'ไม่ระบุ'),
                'url' => route('qty-promotions.index'),
            ]));

        $counts = [
            'promotions' => Promotion::where('is_active', true)->count(),
            'flash' => FlashSale::where('is_active', true)->count(),
            'qty' => QtyPromotion::notEnded()->whereNotIn('promo_type', QtyPromotion::BILL_TYPES)->count(),
            'bill' => QtyPromotion::notEnded()->whereIn('promo_type', QtyPromotion::BILL_TYPES)->count(),
            'cards' => DiscountCard::where('is_active', true)->count(),
            'points' => MemberPointRule::where('is_active', true)->count(),
        ];

        return view('promotion-center.index', compact('running', 'counts'));
    }
}
