<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\Inventory\BranchReplenishmentService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * เครื่องมือแนะนำเติมสินค้าระหว่างสาขา (คลังต้นทาง -> สาขาปลายทาง) ตาม Min/Max
 * ของสาขานั้น (branch_stock_policies) ผลลัพธ์ที่เลือกจะสร้างเป็น "ใบขอโอน" ผ่าน
 * StockTransferService::createRequest() เหมือนที่พนักงานสาขากรอกเองที่หน้า
 * stock-transfers.request ทุกประการ - สต๊อกยังไม่ขยับจนกว่าผู้มีสิทธิ์ stock.manage
 * จะกดอนุมัติที่หน้า stock-transfers ตามปกติ (ไม่ตัดสต๊อกอัตโนมัติจากเครื่องมือนี้)
 */
class BranchReplenishmentController extends Controller
{
    public function index(Request $request, BranchReplenishmentService $service): View
    {
        $branches = Branch::where('is_active', true)->orderBy('code')->get();
        $sourceBranchId = $request->integer('source_branch_id') ?: null;
        $destinationBranchId = $request->integer('destination_branch_id') ?: null;
        $salesDays = max(1, (int) $request->integer('sales_days', 30));
        $safetyDays = max(0, (int) $request->integer('safety_days', 3));

        $suggestions = collect();
        $error = null;
        if ($sourceBranchId && $destinationBranchId) {
            try {
                $suggestions = $service->suggestions((int) $sourceBranchId, (int) $destinationBranchId, $salesDays, $safetyDays);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('stock-transfers.replenishment', [
            'branches' => $branches,
            'sourceBranchId' => $sourceBranchId,
            'destinationBranchId' => $destinationBranchId,
            'salesDays' => $salesDays,
            'safetyDays' => $safetyDays,
            'suggestions' => $suggestions,
            'error' => $error,
        ]);
    }

    public function store(Request $request, StockTransferService $transferService): RedirectResponse
    {
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['required', 'integer', 'exists:branches,id', 'different:source_branch_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $source = Branch::findOrFail($data['source_branch_id']);
        $destination = Branch::findOrFail($data['destination_branch_id']);
        if (! $source->default_warehouse_location_id || ! $destination->default_warehouse_location_id) {
            return back()->withInput()->with('error', 'สาขาต้นทาง/ปลายทางยังไม่ได้ตั้งคลัง default - ติดต่อผู้ดูแลระบบ');
        }

        try {
            $document = $transferService->createRequest([
                'branch_id' => $destination->id,
                'from_warehouse_location_id' => $source->default_warehouse_location_id,
                'to_warehouse_location_id' => $destination->default_warehouse_location_id,
                'remark' => 'สร้างจากคำแนะนำเติมสินค้าอัตโนมัติ (branch replenishment)',
                'items' => $data['items'],
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $document)
            ->with('success', "สร้างใบขอโอน {$document->doc_number} จากคำแนะนำเติมสินค้าแล้ว - รอผู้มีสิทธิ์อนุมัติ");
    }
}
