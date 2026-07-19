<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockIssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StockIssueController extends Controller
{
    private const TYPE_LABELS = [
        'requisition' => 'ใบเบิกสินค้า',
        'requisition_return' => 'ใบคืนสินค้าจากการเบิก',
        'damage' => 'ใบตัดสินค้าชำรุด',
    ];

    public function index(Request $request): View
    {
        $type = $request->query('type', 'requisition');
        if (! isset(self::TYPE_LABELS[$type])) {
            $type = 'requisition';
        }
        $q = trim((string) $request->query('q', ''));

        $documents = Document::with(['branch', 'stockDocument'])
            ->whereHas('documentType', fn ($query) => $query->where('code', StockIssueService::TYPES[$type]))
            ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // ใบเบิกล่าสุดให้เลือกอ้างอิงตอนทำใบคืน (ตามคู่มือ: ควรอ่านจากใบเบิกเดิม)
        $recentRequisitions = Document::whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_REQUISITION'))
            ->orderByDesc('id')->limit(50)->get(['id', 'doc_number', 'doc_date']);

        return view('stock-issues.index', [
            'documents' => $documents,
            'type' => $type,
            'typeLabels' => self::TYPE_LABELS,
            'purposes' => StockIssueService::PURPOSES,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'locations' => WarehouseLocation::orderBy('code')->get(['id', 'code', 'name']),
            'recentRequisitions' => $recentRequisitions,
            'q' => $q,
        ]);
    }

    public function store(Request $request, StockIssueService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:requisition,requisition_return,damage'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'purpose' => ['nullable', 'string', 'in:office,production,sample'],
            'reference' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['items' => $e->getMessage()]);
        }

        $label = self::TYPE_LABELS[$data['type']];

        return redirect()->route('stock-issues.show', $document)
            ->with('success', "บันทึก{$label} {$document->doc_number} แล้ว");
    }

    public function show(Document $stockIssue): View
    {
        $stockIssue->load(['branch', 'documentType', 'stockDocument.items.product.baseUnit', 'stockDocument.items.warehouseLocation']);

        return view('stock-issues.show', ['document' => $stockIssue]);
    }

    public function approve(Document $stockIssue, StockIssueService $service): RedirectResponse
    {
        try {
            $service->approveDamage($stockIssue);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'อนุมัติและตัดสินค้าชำรุดออกจากสต๊อกแล้ว');
    }

    public function reject(Request $request, Document $stockIssue): RedirectResponse
    {
        abort_unless($stockIssue->status === 'pending_approval', 422);
        abort_if($stockIssue->created_by === auth()->id(), 403, 'ผู้สร้างไม่สามารถปฏิเสธรายการตนเอง');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $stockIssue->update(['status' => 'rejected', 'remark' => trim(($stockIssue->remark ? $stockIssue->remark.' | ' : '').'ไม่อนุมัติ: '.$data['reason'])]);

        return back()->with('success', 'ปฏิเสธใบตัดชำรุดแล้ว');
    }
}
