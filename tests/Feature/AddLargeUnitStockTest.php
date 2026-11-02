<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddLargeUnitStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_changes_nothing(): void
    {
        $warehouse = Warehouse::create(['code' => 'WL', 'name' => 'w']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'm']);
        Branch::create(['code' => 'BIG', 'name_th' => 'x', 'is_active' => true, 'default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'LU', 'name' => 'u', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => '880001', 'name_th' => 'x', 'base_unit_id' => $unit->id, 'is_active' => true]);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'unit_id' => $unit->id, 'barcode' => 'B1', 'barcode_type' => 'CUSTOM', 'unit_factor' => 12, 'is_active' => true]);

        $this->artisan('erp:add-large-unit-stock', ['--branch' => 'BIG', '--dry-run' => true])
            ->expectsOutputToContain('เพิ่มรวม 120.00')->assertSuccessful();
        $this->assertSame(0, Document::count());
    }
}
