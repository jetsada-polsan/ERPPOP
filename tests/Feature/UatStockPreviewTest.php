<?php

namespace Tests\Feature;

use App\Models\{Branch, Permission, Product, ProductUnit, Role, StockBalance, User, Warehouse, WarehouseLocation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatStockPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_selected_or_default_location_and_real_product_cost(): void
    {
        [$user, $branch, $location, $product] = $this->masters();
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => '12.34567890', 'reserved_qty' => 2]);
        $this->actingAs($user)->getJson(route('search.stock-balance', ['branch_id' => $branch->id, 'product_id' => $product->id]))
            ->assertOk()->assertJsonPath('on_hand_qty', 12.3456789)->assertJsonPath('average_cost', 37.25)
            ->assertJsonPath('warehouse_location_id', $location->id);
        $other = WarehouseLocation::create(['warehouse_id' => $location->warehouse_id, 'code' => 'EMPTY', 'name' => 'ไม่มีสินค้า']);
        $this->getJson(route('search.stock-balance', ['branch_id' => $branch->id, 'warehouse_location_id' => $other->id, 'product_id' => $product->id]))
            ->assertOk()->assertJsonPath('on_hand_qty', 0)->assertJsonPath('average_cost', 37.25);
        $this->assertSame(1, StockBalance::count()); // preview is read only
    }

    public function test_stock_preview_cannot_cross_branch_permissions(): void
    {
        [$user, $branch, $location, $product] = $this->masters();
        $other = Branch::create(['code' => 'OTHER', 'name_th' => 'สาขาอื่น']);
        $warehouse = Warehouse::create(['branch_id' => $other->id, 'code' => 'OTHER', 'name' => 'คลังอื่น']);
        $foreign = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'OTHER', 'name' => 'ที่เก็บอื่น']);
        $this->actingAs($user)->getJson(route('search.stock-balance', ['warehouse_location_id' => $foreign->id, 'product_id' => $product->id]))->assertForbidden();
    }

    public function test_stock_request_permission_does_not_disclose_cost(): void
    {
        [$user, $branch, $location, $product] = $this->masters();
        $role = $user->roles()->first();
        $role->permissions()->sync([Permission::firstOrCreate(['code' => 'stock.request'], ['name' => 'เบิก'])->id]);
        $this->actingAs($user->fresh())->getJson(route('search.stock-balance', ['branch_id' => $branch->id, 'product_id' => $product->id]))
            ->assertOk()->assertJsonPath('average_cost', null)->assertJsonPath('on_hand_qty', 0);
    }

    public function test_all_three_forms_refresh_preview_for_branch_and_location_changes(): void
    {
        foreach (['stock-adjustments', 'stock-issues', 'stock-transforms'] as $page) {
            $source = file_get_contents(resource_path('views/'.$page.'/index.blade.php'));
            $this->assertStringContainsString('...erpStockPreview(', $source);
            $this->assertStringContainsString('x-model="branchId"', $source);
            $this->assertStringContainsString('x-model="locationId"', $source);
            $this->assertStringContainsString('this.stockPreviewReady()', $source);
        }
    }

    private function masters(): array
    {
        $branch = Branch::create(['code' => 'PREVIEW', 'name_th' => 'สาขา']);
        $wh = Warehouse::create(['branch_id' => $branch->id, 'code' => 'PREVIEW', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $wh->id, 'code' => 'PREVIEW', 'name' => 'ที่เก็บ']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'PREVIEW', 'name' => 'ชิ้น']);
        $product = Product::create(['sku_code' => 'PREVIEW', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'average_cost' => '37.25']);
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'PREVIEW', 'name' => 'คลัง']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'stock.manage'], ['name' => 'คลัง'])->id);
        $user->roles()->attach($role);
        return [$user, $branch, $location, $product];
    }
}
