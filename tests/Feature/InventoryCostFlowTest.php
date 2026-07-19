<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockDocumentItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\StockTransformService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCostFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_separates_recoverable_vat_and_non_vat_cost(): void
    {
        [$branch, $supplier, $vatProduct, $nonVatProduct] = $this->masters();

        $document = app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => true,
            'prices_include_vat' => true,
            'claim_input_vat' => true,
            'items' => [
                ['product_id' => $vatProduct->id, 'qty' => 10, 'unit_price' => 107],
                ['product_id' => $nonVatProduct->id, 'qty' => 2, 'unit_price' => 50],
            ],
        ]);

        $this->assertSame(1100.0, (float) $document->subtotal_amount);
        $this->assertSame(70.0, (float) $document->vat_amount);
        $this->assertSame(1170.0, (float) $document->total_amount);
        $this->assertSame(100.0, (float) $vatProduct->fresh()->average_cost);
        $this->assertSame(100.0, (float) $vatProduct->fresh()->last_purchase_cost);
        $this->assertSame(50.0, (float) $nonVatProduct->fresh()->average_cost);

        $lines = $document->stockDocument()->first()->items()->orderBy('seq')->get();
        $this->assertSame(100.0, (float) $lines[0]->unit_cost);
        $this->assertSame(70.0, (float) $lines[0]->vat_amount);
        $this->assertSame(0.0, (float) $lines[1]->vat_amount);
    }

    public function test_sale_cost_is_frozen_when_a_later_purchase_changes_average_cost(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $purchases = app(PurchaseService::class);
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 107]],
        ]);

        $sale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 160.50]],
        ]);
        $saleLine = $sale->stockDocument()->first()->items()->first();
        $this->assertSame(100.0, (float) $saleLine->unit_cost);
        $this->assertSame(100.0, (float) $saleLine->cost_amount);
        $this->assertSame(10.5, (float) $saleLine->vat_amount);

        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 214]],
        ]);

        $this->assertEqualsWithDelta(152.6316, (float) $product->fresh()->average_cost, 0.0001);
        $this->assertSame(100.0, app(CostingService::class)->cogsForDocument($sale->fresh()));
        $this->assertSame(100.0, (float) StockDocumentItem::find($saleLine->id)->cost_amount);
    }

    public function test_non_recoverable_input_vat_is_included_in_inventory_cost(): void
    {
        [$branch, $supplier, $product] = $this->masters();

        $document = app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 107]],
        ]);

        $this->assertSame(535.0, (float) $document->subtotal_amount);
        $this->assertSame(0.0, (float) $document->vat_amount);
        $this->assertSame(535.0, (float) $document->total_amount);
        $this->assertSame(107.0, (float) $product->fresh()->average_cost);
    }

    public function test_weighed_set_allocates_all_actual_input_cost_to_actual_output_weight(): void
    {
        [$branch, $supplier, $meatA, $meatB] = $this->masters();
        $purchases = app(PurchaseService::class);
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [
                ['product_id' => $meatA->id, 'qty' => 10, 'unit_price' => 107],
                ['product_id' => $meatB->id, 'qty' => 10, 'unit_price' => 50],
            ],
        ]);
        $output = Product::create([
            'sku_code' => 'SET-1', 'name_th' => 'ชุดหมูกระทะ', 'base_unit_id' => $meatA->base_unit_id,
            'default_price' => 200, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        ProductBarcode::create([
            'product_id' => $output->id, 'barcode' => '800999', 'unit_id' => $output->base_unit_id,
            'unit_factor' => 1, 'price' => 200, 'is_active' => true,
        ]);

        $document = app(StockTransformService::class)->create([
            'branch_id' => $branch->id, 'batch_mode' => true, 'input_weight_qty' => 8,
            'raw_items' => [
                ['product_id' => $meatA->id, 'qty' => 3],
                ['product_id' => $meatB->id, 'qty' => 5],
            ],
            'output_items' => [['product_id' => $output->id, 'qty' => 5, 'percent' => 100]],
        ]);

        $batch = $document->productionBatch;
        $this->assertSame(550.0, (float) $batch->total_input_cost);
        $this->assertSame(110.0, (float) $batch->output_unit_cost);
        $this->assertSame(3.0, (float) $batch->loss_weight_qty);
        $this->assertSame(62.5, (float) $batch->yield_percent);
        $this->assertSame(110.0, (float) $output->fresh()->average_cost);
        $this->assertSame(200.0, (float) $batch->selling_unit_price);
        $this->assertEqualsWithDelta(76.9159, (float) $batch->estimated_profit_per_unit, 0.0001);

        app(StockTransformService::class)->addPackages($batch, [0.5, 1.25]);
        $packages = $batch->packages()->get();
        $this->assertCount(2, $packages);
        $this->assertSame(100.0, (float) $packages[0]->total_price);
        $this->assertMatchesRegularExpression('/^800999[0-9]{7}$/', $packages[0]->barcode);
    }

    /** @return array{Branch,Supplier,Product,Product} */
    private function masters(): array
    {
        DocumentType::create(['code' => 'PURCHASE', 'name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFORM'], ['name_th' => 'ใบแปรรูป']);
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-HQ', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-1', 'name_th' => 'ผู้จำหน่ายทดสอบ', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $vatProduct = Product::create([
            'sku_code' => 'VAT-1', 'name_th' => 'สินค้า VAT', 'base_unit_id' => $unit->id,
            'default_price' => 160.50, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $nonVatProduct = Product::create([
            'sku_code' => 'NOVAT-1', 'name_th' => 'สินค้าไม่คิด VAT', 'base_unit_id' => $unit->id,
            'default_price' => 50, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);

        return [$branch->fresh(), $supplier, $vatProduct, $nonVatProduct];
    }
}
