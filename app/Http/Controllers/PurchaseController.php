<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\SupplierLedger;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PurchaseController extends Controller
{
    public function index(): View
    {
        $purchases = Document::with(['supplier', 'branch'])
            ->whereHas('documentType', fn ($q) => $q->where('code', 'PURCHASE'))
            ->orderByDesc('id')
            ->paginate(50);

        $branches = Branch::orderBy('code')->get();

        return view('purchases.index', compact('purchases', 'branches'));
    }

    // Creation happens via the modal on index() - this just covers a direct link.
    public function create(): RedirectResponse
    {
        return redirect()->route('purchases.index');
    }

    public function store(Request $request, PurchaseService $service): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'is_credit' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.lot_number' => ['nullable', 'string', 'max:80'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ]);
        $data['is_credit'] = $request->boolean('is_credit', true);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $document)
            ->with('success', "บันทึกใบซื้อ {$document->doc_number} แล้ว รับสินค้าเข้าคลังเรียบร้อย");
    }

    public function show(Document $purchase): View
    {
        $purchase->load(['supplier', 'branch', 'stockDocument.items.product']);

        $ledgerEntry = SupplierLedger::where('document_id', $purchase->id)->first();

        return view('purchases.show', compact('purchase', 'ledgerEntry'));
    }
}
