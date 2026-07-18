<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
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
            ->with('success', "บันทึกใบปรับปรุงสต็อก {$document->doc_number} แล้ว");
    }

    public function show(Document $stockAdjustment): View
    {
        $stockAdjustment->load(['branch', 'stockDocument.items.product', 'stockDocument.items.warehouseLocation']);

        return view('stock-adjustments.show', ['adjustment' => $stockAdjustment]);
    }
}
