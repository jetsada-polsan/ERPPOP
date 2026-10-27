<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeps_the_best_n_per_category_and_deactivates_the_rest(): void
    {
        [$category, $products] = $this->products(5);
        // ตัวสุดท้ายขายดีสุด ต้องติดอันดับแม้รหัสจะมาท้าย
        DB::table('stock_movements')->insert([
            'product_id' => $products[4]->id, 'warehouse_location_id' => $this->location()->id,
            'movement_type' => 'out', 'qty' => 50, 'movement_date' => now()->toDateString(), 'created_at' => now(),
        ]);

        $this->artisan('erp:prune-products', ['--per-category' => 2])->assertSuccessful();

        $active = Product::where('is_active', true)->orderBy('sku_code')->pluck('sku_code')->all();
        $this->assertSame(['PR-0', 'PR-4'], $active);
        $this->assertStringContainsString('[pruned-by-erp:prune-products]', (string) Product::where('sku_code', 'PR-1')->value('note'));
    }

    public function test_products_with_stock_on_hand_are_never_deactivated(): void
    {
        [, $products] = $this->products(3);
        StockBalance::create(['product_id' => $products[2]->id, 'warehouse_location_id' => $this->location()->id, 'on_hand_qty' => 5, 'reserved_qty' => 0]);

        $this->artisan('erp:prune-products', ['--per-category' => 1])->assertSuccessful();

        $this->assertTrue(Product::find($products[2]->id)->is_active);
        $this->assertFalse(Product::find($products[1]->id)->is_active);
    }

    public function test_dry_run_changes_nothing_and_restore_brings_them_back(): void
    {
        $this->products(4);

        $this->artisan('erp:prune-products', ['--per-category' => 1, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(4, Product::where('is_active', true)->count());

        $this->artisan('erp:prune-products', ['--per-category' => 1])->assertSuccessful();
        $this->assertSame(1, Product::where('is_active', true)->count());

        // ตัวที่ปิดเองด้วยมือ (ไม่มีเครื่องหมายของคำสั่ง) ต้องไม่ถูกเปิดกลับ
        Product::where('sku_code', 'PR-0')->update(['is_active' => false]);
        $this->artisan('erp:prune-products', ['--restore' => true])->assertSuccessful();
        $this->assertSame(3, Product::where('is_active', true)->count());
        $this->assertFalse(Product::where('sku_code', 'PR-0')->value('is_active'));
        $this->assertStringNotContainsString('[pruned', (string) Product::where('sku_code', 'PR-1')->value('note'));
    }

    private function location(): WarehouseLocation
    {
        return WarehouseLocation::first() ?? WarehouseLocation::create([
            'warehouse_id' => Warehouse::create([
                'branch_id' => Branch::create(['code' => 'PR', 'name_th' => 'สาขา', 'is_active' => true])->id,
                'code' => 'WH-PR', 'name' => 'คลัง',
            ])->id,
            'code' => 'M-PR', 'name' => 'หลัก',
        ]);
    }

    /** @return array{0: ProductCategory, 1: array<int, Product>} */
    private function products(int $count): array
    {
        Product::query()->update(['is_active' => false]); // ไม่ให้สินค้าที่ migration สร้างไว้มาปน
        $category = ProductCategory::create(['code' => 'CAT-PR', 'name_th' => 'หมวดทดสอบ']);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $products = [];
        for ($i = 0; $i < $count; $i++) {
            $products[] = Product::create([
                'sku_code' => "PR-{$i}", 'name_th' => "สินค้า {$i}", 'base_unit_id' => $unit->id,
                'product_category_id' => $category->id, 'default_price' => 10, 'average_cost' => 0,
                'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'allow',
            ]);
        }

        return [$category, $products];
    }
}
