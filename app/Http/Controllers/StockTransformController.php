<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockTransformService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StockTransformController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $documents = Document::with(['branch', 'stockDocument'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_TRANSFORM'))
            ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('stock-transforms.index', [
            'documents' => $documents,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'locations' => WarehouseLocation::orderBy('code')->get(['id', 'code', 'name']),
            'q' => $q,
        ]);
    }

    public function store(Request $request, StockTransformService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'raw_items' => ['required', 'array', 'min:1'],
            'raw_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'raw_items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'raw_items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'output_items' => ['required', 'array', 'min:1'],
            'output_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'output_items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'output_items.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['raw_items' => $e->getMessage()]);
        }

        return redirect()->route('stock-transforms.show', $document)
            ->with('success', "บันทึกใบแปรรูปสินค้า {$document->doc_number} แล้ว");
    }

    public function show(Document $stockTransform): View
    {
        $stockTransform->load(['branch', 'stockDocument.items.product.baseUnit']);

        return view('stock-transforms.show', ['document' => $stockTransform]);
    }
}
