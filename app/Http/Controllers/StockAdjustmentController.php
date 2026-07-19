<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Document;
use App\Models\StockCount;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $adjustments = Document::with(['branch', 'stockDocument.items'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_ADJUSTMENT'))
            ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();
        $locations = WarehouseLocation::orderBy('code')->get();

        return view('stock-adjustments.index', compact('adjustments', 'branches', 'locations', 'q'));
    }

    public function store(Request $request, StockAdjustmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.counted_qty' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-adjustments.show', $document)
            ->with('success', "ส่งใบปรับปรุงสต็อก {$document->doc_number} รออนุมัติแล้ว");
    }

    public function show(Document $stockAdjustment): View
    {
        $stockAdjustment->load(['branch', 'stockDocument.items.product', 'stockDocument.items.warehouseLocation']);

        return view('stock-adjustments.show', ['adjustment' => $stockAdjustment]);
    }

    public function approve(Document $stockAdjustment, StockAdjustmentService $service): RedirectResponse
    {
        try {
            $service->approve($stockAdjustment);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        AuditLog::create([
            'user_id' => auth()->id(), 'branch_id' => $stockAdjustment->branch_id,
            'action' => 'approve', 'table_name' => 'documents', 'record_id' => $stockAdjustment->id,
            'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'active'],
        ]);

        return back()->with('success', 'อนุมัติและปรับยอดสต๊อกแล้ว');
    }

    public function reject(Request $request, Document $stockAdjustment): RedirectResponse
    {
        abort_unless($stockAdjustment->status === 'pending_approval', 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        abort_if($stockAdjustment->created_by === auth()->id(), 403, 'ผู้สร้างไม่สามารถปฏิเสธรายการตนเอง');
        $stockAdjustment->update([
            'status' => 'rejected',
            'remark' => trim(($stockAdjustment->remark ? $stockAdjustment->remark.' | ' : '').'ไม่อนุมัติ: '.$data['reason']),
        ]);
        StockCount::where('posted_document_id', $stockAdjustment->id)->update([
            'status' => 'review', 'posted_document_id' => null, 'confirmed_by' => null, 'confirmed_at' => null,
        ]);
        AuditLog::create([
            'user_id' => auth()->id(), 'branch_id' => $stockAdjustment->branch_id,
            'action' => 'reject', 'table_name' => 'documents', 'record_id' => $stockAdjustment->id,
            'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'rejected', 'reason' => $data['reason']],
        ]);

        return back()->with('success', 'ปฏิเสธใบปรับสต๊อกแล้ว');
    }
}
