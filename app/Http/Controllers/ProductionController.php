<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\WarehouseLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function index(): View
    {
        $recipes = ProductionRecipe::with('finishedProduct')->withCount('items')->orderBy('code')->paginate(25, ['*'], 'recipes_page');
        $orders = ProductionOrder::with(['recipe', 'finishedProduct', 'branch', 'warehouseLocation'])->orderByDesc('doc_date')->paginate(25, ['*'], 'orders_page');
        $products = Product::where('is_active', true)->orderBy('sku_code')->limit(300)->get();
        $branches = Branch::where('is_active', true)->orderBy('code')->get();
        $locations = WarehouseLocation::with('warehouse')->orderBy('code')->get();

        return view('production.index', compact('recipes', 'orders', 'products', 'branches', 'locations'));
    }

    public function storeRecipe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:production_recipes,code'],
            'name' => ['required', 'string', 'max:150'],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'output_qty' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        ProductionRecipe::create($data);

        return redirect()->route('production.index')->with('success', "เพิ่มสูตรผลิต {$data['code']} แล้ว");
    }

    public function updateRecipe(Request $request, ProductionRecipe $recipe): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:production_recipes,code,'.$recipe->id],
            'name' => ['required', 'string', 'max:150'],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'output_qty' => ['required', 'numeric', 'min:0.0001'],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $recipe->update($data);

        return redirect()->route('production.index')->with('success', 'บันทึกสูตรผลิตแล้ว');
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'doc_no' => ['required', 'string', 'max:40', 'unique:production_orders,doc_no'],
            'doc_date' => ['required', 'date'],
            'production_recipe_id' => ['nullable', 'integer', 'exists:production_recipes,id'],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'planned_qty' => ['required', 'numeric', 'min:0.0001'],
            'produced_qty' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['produced_qty'] = $data['produced_qty'] ?? 0;
        ProductionOrder::create($data);

        return redirect()->route('production.index')->with('success', "เพิ่มใบสั่งผลิต {$data['doc_no']} แล้ว");
    }

    public function updateOrder(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'doc_no' => ['required', 'string', 'max:40', 'unique:production_orders,doc_no,'.$order->id],
            'doc_date' => ['required', 'date'],
            'production_recipe_id' => ['nullable', 'integer', 'exists:production_recipes,id'],
            'finished_product_id' => ['required', 'integer', 'exists:products,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'planned_qty' => ['required', 'numeric', 'min:0.0001'],
            'produced_qty' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['produced_qty'] = $data['produced_qty'] ?? 0;
        $order->update($data);

        return redirect()->route('production.index')->with('success', 'บันทึกใบสั่งผลิตแล้ว');
    }

    // ใบรับสินค้าจากการผลิต (IP): รับผลผลิตเข้าสต๊อกจากใบสั่งผลิต
    public function receiveOrder(Request $request, ProductionOrder $order, \App\Services\Inventory\ProductionReceiptService $service): RedirectResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'remark' => ['nullable', 'string', 'max:500'],
        ], ['qty.required' => 'กรุณาระบุจำนวนที่รับเข้า']);

        try {
            $document = $service->receive($order, (float) $data['qty'], $data['remark'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('production.index')
            ->with('success', "รับสินค้าเข้าคลังแล้ว เอกสาร {$document->doc_number} (ใบสั่งผลิต {$order->doc_no})");
    }
}
