<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\ProductionReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อ 14: สถานะภาษาไทย, ปิดงานโดยผลิต 0 ไม่ขึ้นว่าครบ, วัตถุดิบไม่พอบอกครบทุกตัว, เลือกสูตรเติมสินค้าให้
 */
class ProductionUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_labels_are_thai_and_a_job_closed_with_nothing_made_is_not_complete(): void
    {
        $order = new ProductionOrder(['status' => 'planned', 'planned_qty' => 10, 'produced_qty' => 0]);
        $this->assertSame('วางแผน', $order->statusLabel());
        $order->status = 'in_progress';
        $this->assertSame('กำลังผลิต', $order->statusLabel());

        $order->status = 'completed';
        $this->assertSame('ปิดงาน (ไม่ได้ผลิต)', $order->statusLabel());
        $order->produced_qty = 4;
        $this->assertSame('ปิดงาน (ผลิตไม่ครบแผน)', $order->statusLabel());
        $order->produced_qty = 10;
        $this->assertSame('ผลิตครบแล้ว', $order->statusLabel());
    }

    public function test_a_shortage_names_every_missing_raw_material(): void
    {
        [$order, $flour, $sugar, $location] = $this->order();
        app(FifoStockService::class)->receive($flour->id, $location->id, 1, null, 'in', unitCost: 10);

        try {
            app(ProductionReceiptService::class)->receive($order, 2);
            $this->fail('ต้องปฏิเสธเพราะวัตถุดิบไม่พอ');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('วัตถุดิบไม่พอผลิต', $e->getMessage());
            $this->assertStringContainsString('RM-FLOUR', $e->getMessage());
            $this->assertStringContainsString('RM-SUGAR', $e->getMessage());
        }
    }

    public function test_the_order_form_carries_each_recipes_finished_product(): void
    {
        [$order] = $this->order();
        $this->withoutVite();

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('production.index'))->assertOk()
            ->assertSee('data-product="'.$order->finished_product_id.'"', false)
            ->assertSee('x-ref="finishedProduct"', false)
            ->assertSee('วางแผน');
    }

    /** @return array{0: ProductionOrder, 1: Product, 2: Product, 3: WarehouseLocation} */
    private function order(): array
    {
        DocumentType::firstOrCreate(['code' => 'PRODUCTION_RECEIPT'], ['name_th' => 'ใบรับจากการผลิต', 'stock_effect' => 'in']);
        $branch = Branch::create(['code' => 'PD', 'name_th' => 'สาขาผลิต', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-PD', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $make = fn ($sku) => Product::create(['sku_code' => $sku, 'name_th' => $sku, 'base_unit_id' => $unit->id, 'average_cost' => 0, 'is_active' => true, 'negative_stock_policy' => 'block']);
        [$flour, $sugar, $cake] = [$make('RM-FLOUR'), $make('RM-SUGAR'), $make('FG-CAKE')];
        $recipe = ProductionRecipe::create(['code' => 'BOM-CAKE', 'name' => 'เค้ก', 'finished_product_id' => $cake->id, 'output_qty' => 1, 'is_active' => true]);
        $recipe->items()->create(['product_id' => $flour->id, 'qty' => 2]);
        $recipe->items()->create(['product_id' => $sugar->id, 'qty' => 1]);
        $order = ProductionOrder::create([
            'doc_no' => 'MO-CAKE', 'doc_date' => now()->toDateString(), 'production_recipe_id' => $recipe->id,
            'finished_product_id' => $cake->id, 'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'planned_qty' => 2, 'produced_qty' => 0, 'status' => 'planned',
        ]);

        return [$order, $flour, $sugar, $location];
    }
}
