<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StockTransferController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $transfers = Document::with(['branch', 'stockDocument.toWarehouseLocation', 'createdBy'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_TRANSFER'))
            ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();
        $locations = WarehouseLocation::orderBy('code')->get();

        return view('stock-transfers.index', compact('transfers', 'branches', 'locations', 'q'));
    }

    public function store(Request $request, StockTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from_warehouse_location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'to_warehouse_location_id' => ['required', 'integer', 'exists:warehouse_locations,id', 'different:from_warehouse_location_id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.show', $document)
            ->with('success', "บันทึกใบโอนย้ายสต็อก {$document->doc_number} แล้ว");
    }

    /**
     * หน้า "ขอโอนสินค้า" สำหรับพนักงานสาขา (stock.request): สร้างใบขอโอนเข้าสาขาตัวเอง.
     */
    public function requestForm(): View
    {
        $branchId = auth()->user()?->branchScopeId();
        $branch = $branchId ? Branch::find($branchId) : null;
        $locations = WarehouseLocation::orderBy('code')->get();

        $myRequests = Document::with(['stockDocument.toWarehouseLocation', 'createdBy'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_TRANSFER'))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('stock-transfers.request', compact('branch', 'locations', 'myRequests'));
    }

    public function requestStore(Request $request, StockTransferService $service): RedirectResponse
    {
        $user = auth()->user();
        $branchId = $user?->branchScopeId();
        if (! $branchId) {
            return back()->with('error', 'บัญชีของคุณยังไม่ได้กำหนดสาขาประจำ - ติดต่อผู้ดูแลระบบ');
        }

        $branch = Branch::find($branchId);
        $toLocationId = $branch?->default_warehouse_location_id;
        if (! $toLocationId) {
            return back()->with('error', 'สาขาของคุณยังไม่ได้ตั้งคลังปลายทาง (default) - ติดต่อผู้ดูแลระบบ');
        }

        $data = $request->validate([
            'from_warehouse_location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        if ((int) $data['from_warehouse_location_id'] === (int) $toLocationId) {
            return back()->withInput()->with('error', 'คลังต้นทางต้องไม่ใช่คลังปลายทางของสาขาคุณ');
        }

        try {
            $document = $service->createRequest([
                'branch_id' => $branchId,
                'from_warehouse_location_id' => $data['from_warehouse_location_id'],
                'to_warehouse_location_id' => $toLocationId,
                'remark' => $data['remark'] ?? null,
                'items' => $data['items'],
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-transfers.request')
            ->with('success', "ส่งคำขอโอนสินค้า {$document->doc_number} แล้ว - รอผู้มีสิทธิ์อนุมัติ");
    }

    public function approve(Document $stockTransfer, StockTransferService $service): RedirectResponse
    {
        $this->assertTransfer($stockTransfer);
        if ($stockTransfer->status !== 'pending') {
            return back()->with('error', 'ใบนี้ไม่ได้อยู่สถานะรออนุมัติ');
        }

        try {
            $service->approve($stockTransfer);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "อนุมัติและโอนสต็อกใบ {$stockTransfer->doc_number} แล้ว");
    }

    public function reject(Request $request, Document $stockTransfer, StockTransferService $service): RedirectResponse
    {
        $this->assertTransfer($stockTransfer);
        if ($stockTransfer->status !== 'pending') {
            return back()->with('error', 'ใบนี้ไม่ได้อยู่สถานะรออนุมัติ');
        }

        $service->reject($stockTransfer, (string) $request->input('reason', ''));

        return back()->with('success', "ปฏิเสธคำขอโอน {$stockTransfer->doc_number} แล้ว");
    }

    public function show(Document $stockTransfer): View
    {
        $this->assertTransfer($stockTransfer);
        $stockTransfer->load(['branch', 'createdBy', 'stockDocument.toWarehouseLocation', 'stockDocument.items.product', 'stockDocument.items.warehouseLocation']);

        return view('stock-transfers.show', ['transfer' => $stockTransfer]);
    }

    private function assertTransfer(Document $document): void
    {
        abort_unless($document->documentType?->code === 'STOCK_TRANSFER', 404);
    }
}
