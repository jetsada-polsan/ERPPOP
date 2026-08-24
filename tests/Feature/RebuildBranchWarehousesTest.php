<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildBranchWarehousesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replaces_shared_legacy_warehouses_and_preserves_every_stock_balance(): void
    {
        $unit = ProductUnit::create(['code' => 'EA-REBUILD', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'P-REBUILD', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id, 'default_price' => 1, 'is_active' => true]);
        $oldWarehouse = Warehouse::updateOrCreate(['code' => 'SHOP'], ['name' => 'คลังเก่า']);
        $hqOld = WarehouseLocation::create(['warehouse_id' => $oldWarehouse->id, 'code' => 'HQ-02', 'name' => 'สำนักงานใหญ่เดิม']);
        $b001Old = WarehouseLocation::create(['warehouse_id' => $oldWarehouse->id, 'code' => 'OLD-001', 'name' => 'หน้าร้านเดิม']);
        $hq = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true, 'default_warehouse_location_id' => $hqOld->id]);
        $b001 = Branch::create(['code' => 'B001', 'name_th' => 'หน้าร้าน', 'is_active' => true, 'default_warehouse_location_id' => $b001Old->id]);
        DB::table('stock_balances')->insert([
            ['product_id' => $product->id, 'warehouse_location_id' => $hqOld->id, 'on_hand_qty' => 10, 'reserved_qty' => 0],
            ['product_id' => $product->id, 'warehouse_location_id' => $b001Old->id, 'on_hand_qty' => 20, 'reserved_qty' => 0],
        ]);

        $this->artisan('erp:rebuild-branch-warehouses', [
            '--confirm-database' => DB::connection()->getDatabaseName(), '--force' => true,
        ])->assertSuccessful();

        foreach ([$hq, $b001] as $branch) {
            $location = WarehouseLocation::findOrFail($branch->fresh()->default_warehouse_location_id);
            $this->assertSame('MAIN', $location->code);
            $this->assertSame('WH-'.$branch->code, Warehouse::findOrFail($location->warehouse_id)->code);
        }
        $this->assertSame(2, DB::table('stock_balances')->count());
        $this->assertSame(0, Warehouse::where('code', 'SHOP')->count(), 'คลังเก่าต้องถูกลบหลังย้ายยอดครบ');
        $this->assertSame(30.0, (float) DB::table('stock_balances')->sum('on_hand_qty'));
    }

    public function test_it_refuses_to_delete_a_warehouse_with_stock_movement_history(): void
    {
        $warehouse = Warehouse::updateOrCreate(['code' => 'SHOP'], ['name' => 'คลังเก่า']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'HQ-02', 'name' => 'สำนักงานใหญ่เดิม']);
        Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true, 'default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'EA-HISTORY', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'P-HISTORY', 'name_th' => 'สินค้าประวัติ', 'base_unit_id' => $unit->id, 'default_price' => 1, 'is_active' => true]);
        DB::table('stock_movements')->insert(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'movement_type' => 'ADJUSTMENT', 'qty' => 1, 'movement_date' => now()->toDateString()]);

        $this->artisan('erp:rebuild-branch-warehouses', [
            '--confirm-database' => DB::connection()->getDatabaseName(), '--force' => true,
        ])->assertFailed();
        $this->assertDatabaseHas('warehouses', ['code' => 'SHOP']);
    }
}
