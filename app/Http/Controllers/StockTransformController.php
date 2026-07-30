<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Document;
use App\Models\ProductionRecipe;
use App\Models\WarehouseLocation;
use App\Services\Inventory\ScaleBarcodeService;
use App\Services\Inventory\StockTransformService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class StockTransformController extends Controller
{
    private const LOSS_REASONS = [
        'trim' => 'ตัดแต่ง / เศษวัตถุดิบ',
        'cooking' => 'น้ำหนักหายจากปรุง / ละลาย',
        'drip' => 'น้ำหยด / ละลายน้ำแข็ง',
        'spill' => 'หก / ตกหล่น',
        'quality_reject' => 'คัดทิ้งจากคุณภาพ',
        'weight_variance' => 'ส่วนต่างจากการชั่ง',
        'other' => 'อื่น ๆ',
        'unclassified' => 'ข้อมูลเดิมยังไม่จำแนก',
    ];

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $documents = Document::with(['branch', 'stockDocument', 'productionBatch'])
            ->whereHas('documentType', fn ($query) => $query->where('code', 'STOCK_TRANSFORM'))
            ->when($q !== '', fn ($query) => $query->where('doc_number', 'ilike', "%{$q}%"))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $recipes = ProductionRecipe::where('is_active', true)
            ->with(['finishedProduct:id,sku_code,name_th', 'items.product:id,sku_code,name_th,average_cost'])
            ->orderBy('code')->get()
            ->map(fn (ProductionRecipe $recipe) => [
                'id' => $recipe->id,
                'label' => $recipe->code.' - '.$recipe->name,
                'output_qty' => (float) $recipe->output_qty,
                'finished_product_id' => $recipe->finished_product_id,
                'finished_product_label' => $recipe->finishedProduct
                    ? $recipe->finishedProduct->sku_code.' - '.$recipe->finishedProduct->name_th
                    : '',
                'items' => $recipe->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'qty' => (float) $item->qty,
                    'label' => $item->product ? $item->product->sku_code.' - '.$item->product->name_th : '',
                    'average_cost' => (float) ($item->product?->average_cost ?? 0),
                ])->values(),
            ])->values();

        return view('stock-transforms.index', [
            'documents' => $documents,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'locations' => WarehouseLocation::orderBy('code')->get(['id', 'code', 'name']),
            'recipes' => $recipes,
            'lossReasons' => self::LOSS_REASONS,
            'q' => $q,
        ]);
    }

    public function store(Request $request, StockTransformService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'batch_mode' => ['nullable', 'boolean'],
            'production_recipe_id' => ['nullable', 'integer', 'exists:production_recipes,id'],
            'input_weight_qty' => ['nullable', 'numeric', 'min:0.0001'],
            'loss_reason_code' => ['nullable', 'in:trim,cooking,drip,spill,quality_reject,weight_variance,other'],
            'expected_loss_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'loss_note' => ['nullable', 'string', 'max:500'],
            'raw_items' => ['required', 'array', 'min:1'],
            'raw_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'raw_items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'output_items' => ['required', 'array', 'min:1'],
            'output_items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'output_items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'output_items.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $data['batch_mode'] = $request->boolean('batch_mode');

        try {
            $document = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['raw_items' => $e->getMessage()]);
        }

        return redirect()->route('stock-transforms.show', $document)
            ->with('success', "บันทึกการแปรรูปและข้อมูลสูญเสีย {$document->doc_number} แล้ว");
    }

    public function show(Document $stockTransform): View
    {
        $stockTransform->load([
            'branch', 'stockDocument.items.product.baseUnit',
            'productionBatch.outputProduct', 'productionBatch.packages',
        ]);

        return view('stock-transforms.show', [
            'document' => $stockTransform,
            'lossReasons' => self::LOSS_REASONS,
        ]);
    }

    public function addPackages(Request $request, Document $stockTransform, StockTransformService $service): RedirectResponse
    {
        $data = $request->validate([
            'weights' => ['required', 'array', 'min:1'],
            'weights.*' => ['required', 'numeric', 'min:0.001'],
        ]);
        $batch = $stockTransform->productionBatch;
        abort_unless($batch, 404);
        try {
            $service->addPackages($batch, $data['weights']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['weights' => $e->getMessage()]);
        }

        return back()->with('success', 'สร้างป้ายถุงจากน้ำหนักจริงแล้ว');
    }

    public function labels(Document $stockTransform, ScaleBarcodeService $barcodes): View
    {
        $stockTransform->load(['productionBatch.outputProduct', 'productionBatch.packages']);
        abort_unless($stockTransform->productionBatch, 404);

        return view('stock-transforms.labels', [
            'document' => $stockTransform,
            'batch' => $stockTransform->productionBatch,
            'barcodes' => $barcodes,
        ]);
    }
}
