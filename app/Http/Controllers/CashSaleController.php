<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Sales\CashSaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class CashSaleController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $sales = Document::with(['branch', 'customer'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'CASH_SALE'))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('doc_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_th', 'ilike', "%{$q}%"))
            ))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();

        return view('cash-sales.index', compact('sales', 'branches', 'q'));
    }

    public function store(Request $request, CashSaleService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cash-sales.show', $document)
            ->with('success', "บันทึกใบขายสด {$document->doc_number} แล้ว");
    }

    public function show(Document $cashSale): View
    {
        $cashSale->load(['branch', 'customer', 'stockDocument.items.product']);

        return view('cash-sales.show', ['sale' => $cashSale]);
    }
}
