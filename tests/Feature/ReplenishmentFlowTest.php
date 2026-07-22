<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\ReplenishmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplenishmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_replenishment_uses_available_stock_open_po_sales_and_supplier_moq(): void
    {
        [$branch, $location, $supplier, $product] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 8, 'reserved_qty' => 2,
        ]);
        $saleType = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด', 'stock_effect' => 'out']);
        $sale = Document::create([
            'document_type_id' => $saleType->id, 'branch_id' => $branch->id,
            'doc_number' => 'CS-REPLENISH', 'doc_date' => now()->toDateString(),
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'document_id' => $sale->id, 'movement_type' => 'out', 'qty' => 20,
            'movement_date' => now()->toDateString(),
        ]);
        $po = PurchaseOrder::create([
            'doc_number' => 'PO-INCOMING', 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'doc_date' => now()->toDateString(), 'status' => 'partially_received',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id,
            'qty' => 10, 'received_qty' => 6, 'unit_price' => 10,
        ]);

        $row = app(ReplenishmentService::class)->suggestions($branch->id, 30, 7)->first();

        $this->assertSame(6.0, $row['available']);
        $this->assertSame(4.0, $row['incoming']);
        $this->assertSame(15.0, $row['reorder_point']);
        $this->assertSame(30.0, $row['target_stock']);
        $this->assertSame(6.0, $row['moq']);
        $this->assertSame(24.0, $row['suggested_qty']);
    }

    public function test_selected_suggestions_create_a_purchase_requisition(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class);
        [$branch, , $supplier, $product] = $this->masters();

        $this->post(route('bplus.purchase-planning.generate-requisitions'), [
            'branch_id' => $branch->id,
            'items' => [[
                'product_id' => $product->id, 'supplier_id' => $supplier->id,
                'qty' => 24, 'unit_price' => 10,
            ]],
        ])->assertRedirect(route('purchase-orders.index'));

        $order = PurchaseOrder::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame('requested', $order->status);
        $this->assertSame(240.0, (float) $order->total_amount);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order->id, 'product_id' => $product->id,
            'qty' => 24, 'unit_price' => 10,
        ]);
    }

    private function masters(): array
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-R', 'name_th' => 'Supplier หลัก', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'R-001', 'name_th' => 'สินค้าเติมเต็ม', 'base_unit_id' => $unit->id,
            'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
            'reorder_point' => 15, 'minimum_stock' => 5, 'maximum_stock' => 30,
        ]);
        ProductSupplier::create([
            'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'last_purchase_price' => 10, 'minimum_order_qty' => 6, 'lead_time_days' => 3, 'is_primary' => true,
        ]);

        return [$branch->fresh(), $location, $supplier, $product];
    }
}
