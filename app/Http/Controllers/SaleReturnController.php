<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Services\Sales\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class SaleReturnController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $returns = Document::with(['branch', 'customer'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'SALE_RETURN'))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();

        return view('sale-returns.index', compact('returns', 'branches', 'q'));
    }

    public function store(Request $request, SaleReturnService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_open_item_id' => ['nullable', 'integer', 'exists:customer_open_items,id'],
            'source_doc_number' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.source_stock_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'items.*.return_disposition' => ['required', 'in:quarantine,available,damage'],
        ]);

        // รับคืน = รับของกลับเข้าคลังเริ่มต้นของสาขา ซึ่งต้องมีอยู่จริงก่อน
        if ($redirect = $this->guardBranchWarehouse($data['branch_id'])) {
            return $redirect;
        }

        // คืนโดยไม่อ้างใบขายต้นทาง ได้เฉพาะผู้ถือสิทธิ์พิเศษ
        $data['allow_unreferenced'] = (bool) $request->user()?->hasPermission('sales.return.unreferenced');

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sale-returns.show', $document)
            ->with('success', "บันทึกใบรับคืน {$document->doc_number} แล้ว");
    }

    /** รายการในใบขายต้นทางที่ยังคืนได้ - ฟอร์มรับคืนใช้เติมลูกค้าและสินค้าให้ */
    public function source(Request $request, SaleReturnService $service): JsonResponse
    {
        $docNumber = trim((string) $request->query('doc_number', ''));
        $sale = $docNumber !== '' ? $service->findSourceSale($docNumber) : null;
        if (! $sale) {
            return response()->json(['message' => "ไม่พบใบขายเลขที่ {$docNumber}"], 404);
        }
        $sale->loadMissing('customer');

        return response()->json([
            'doc_number' => $sale->doc_number,
            'doc_date' => $sale->doc_date?->toDateString(),
            'branch_id' => $sale->branch_id,
            'customer_id' => $sale->customer_id,
            'customer_name' => $sale->customer?->name_th,
            'lines' => $service->returnableLines($sale)->values(),
        ]);
    }

    public function show(Document $saleReturn): View
    {
        $saleReturn->load(['branch', 'customer', 'stockDocument.items.product']);

        return view('sale-returns.show', ['saleReturn' => $saleReturn]);
    }

    public function approve(Document $saleReturn, SaleReturnService $service): RedirectResponse
    {
        try {
            $service->approve($saleReturn, (int) auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'อนุมัติใบรับคืนและปรับสต๊อก/บัญชีแล้ว');
    }
}
