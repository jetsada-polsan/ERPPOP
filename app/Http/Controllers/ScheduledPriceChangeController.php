<?php

namespace App\Http\Controllers;

use App\Models\ScheduledPriceChange;
use App\Services\Pricing\ScheduledPriceChangeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ScheduledPriceChangeController extends Controller
{
    public function index(Request $request): View
    {
        $pending = ScheduledPriceChange::with(['product.baseUnit', 'creator', 'posPriceSchedule'])
            ->where('status', 'pending')
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get();

        $applied = ScheduledPriceChange::with(['product.baseUnit', 'creator'])
            ->where('status', 'applied')
            ->orderByDesc('applied_at')
            ->limit(50)
            ->get();

        $failed = ScheduledPriceChange::with(['product.baseUnit', 'creator'])
            ->where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        // ค่าเริ่มต้น = 00:00 ของพรุ่งนี้ เวลาไทย (Asia/Bangkok)
        $defaultEffectiveAt = now('Asia/Bangkok')->addDay()->startOfDay()->format('Y-m-d\TH:i');

        return view('products.scheduled-prices.index', [
            'pending' => $pending,
            'applied' => $applied,
            'failed' => $failed,
            'defaultEffectiveAt' => $defaultEffectiveAt,
        ]);
    }

    public function store(Request $request, ScheduledPriceChangeService $service): RedirectResponse
    {
        $data = $request->validate([
            'effective_at' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.new_price' => ['required', 'numeric', 'min:0.0001'],
        ], [
            'effective_at.after' => 'วัน-เวลาที่มีผลต้องอยู่ในอนาคต',
            'items.required' => 'ต้องระบุสินค้าอย่างน้อย 1 รายการ',
            'items.min' => 'ต้องระบุสินค้าอย่างน้อย 1 รายการ',
            'items.*.new_price.min' => 'ราคาใหม่ต้องมากกว่า 0',
        ]);

        try {
            $effectiveCarbon = Carbon::parse($data['effective_at']);
            $created = $service->schedule(
                items: $data['items'],
                effectiveAt: $effectiveCarbon,
                note: $data['note'] ?? null,
                userId: auth()->id(),
            );

            return redirect()->route('products.scheduled-prices.index')
                ->with('success', 'บันทึกนัดเปลี่ยนราคาขายแล้ว '.count($created).' รายการ และส่งช่วงราคาล่วงหน้าให้เครื่อง POS แล้ว');
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function cancel(ScheduledPriceChange $scheduledPriceChange, ScheduledPriceChangeService $service): RedirectResponse
    {
        try {
            $service->cancel($scheduledPriceChange, auth()->id());

            return redirect()->route('products.scheduled-prices.index')
                ->with('success', 'ยกเลิกรายการนัดเปลี่ยนราคาแล้ว (รวมทั้งยกเลิกช่วงราคาล่วงหน้าของ POS)');
        } catch (Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function parsePaste(Request $request, ScheduledPriceChangeService $service): JsonResponse
    {
        $text = (string) $request->input('text', '');
        $rows = $service->parseQuickPaste($text);

        return response()->json([
            'success' => true,
            'rows' => $rows,
        ]);
    }
}
