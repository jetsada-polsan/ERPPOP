<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderPartialReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_can_be_received_in_multiple_rounds_without_overstating_stock(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class);
        DocumentType::create(['code' => 'PURCHASE', 'name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-HQ', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-PO', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'PO-ITEM', 'name_th' => 'สินค้ารับบางส่วน', 'base_unit_id' => $unit->id,
            'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $order = PurchaseOrder::create([
            'doc_number' => 'PO-HQ-001', 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'doc_date' => now()->toDateString(), 'status' => 'ordered', 'is_credit' => true, 'total_amount' => 1000,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'product_id' => $product->id,
            'qty' => 10, 'unit_price' => 100,
        ]);
        $this->assertSame('ordered', $order->fresh()->status);
        $this->assertSame("http://localhost/purchase-orders/{$order->id}/receive", route('purchase-orders.receive', $order));

        $this->post(route('purchase-orders.receive', $order), [
            'receive_qty' => [$item->id => 4],
        ])->assertRedirect();

        $this->assertSame('partially_received', $order->fresh()->status);
        $this->assertSame(4.0, (float) $item->fresh()->received_qty);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 4,
        ]);
        $this->assertDatabaseCount('purchase_order_receipts', 1);

        $this->post(route('purchase-orders.receive', $order), [
            'receive_qty' => [$item->id => 6],
        ])->assertRedirect();

        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame(10.0, (float) $item->fresh()->received_qty);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 10,
        ]);
        $this->assertDatabaseCount('purchase_order_receipts', 2);
        $this->assertDatabaseCount('supplier_ledger', 2);
    }
}
