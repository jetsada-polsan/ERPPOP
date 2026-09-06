<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\PurchaseCostAdjustment;
use App\Support\DecimalMath;
use App\Services\Inventory\PurchaseCostAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * ปรับต้นทุนซื้อย้อนหลัง (ดู PurchaseCostAdjustmentService สำหรับขอบเขต/ข้อจำกัดทาง
 * ธุรกิจแบบละเอียด) - เข้าถึงได้เฉพาะผู้มีสิทธิ์ inventory.cost.close ซึ่งเป็นสิทธิ์ที่
 * บล็อกแม้ผู้ดูแลระบบก็ต้องได้รับมอบสิทธิ์ชัดเจนก่อน (ดู User::NON_BYPASS_PERMISSIONS)
 * เพราะกระทบมูลค่าสต๊อกคงเหลือของสินค้าโดยตรง
 */
class PurchaseCostAdjustmentController extends Controller
{
    public function create(Document $purchase, PurchaseCostAdjustmentService $service): View|RedirectResponse
    {
        try {
            $rows = $service->preview($purchase);
        } catch (RuntimeException $e) {
            return redirect()->route('purchases.show', $purchase)->with('error', $e->getMessage());
        }

        $purchase->load('supplier');
        $history = PurchaseCostAdjustment::where('document_id', $purchase->id)
            ->with('createdBy:id,name')
            ->orderByDesc('id')
            ->get();

        $rows = $rows->map(fn ($row) => $row + [
            'partially_consumed' => DecimalMath::compare($row['lot']->remaining_qty, $row['lot']->initial_qty) !== 0,
        ]);

        return view('purchases.cost-adjustments.create', [
            'purchase' => $purchase,
            'rows' => $rows,
            'history' => $history,
        ]);
    }

    public function store(Request $request, Document $purchase, PurchaseCostAdjustmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_lot_id' => ['required', 'integer'],
            'items.*.new_unit_cost' => ['required', 'numeric', 'min:0'],
        ], [
            'reason.required' => 'กรุณาระบุเหตุผลที่ปรับต้นทุน',
        ]);

        try {
            $adjustments = $service->adjust($purchase, $data['items'], $data['reason'], auth()->id());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('purchases.cost-adjustments.create', $purchase)
            ->with('success', "ปรับต้นทุนแล้ว {$adjustments->count()} รายการ");
    }
}
