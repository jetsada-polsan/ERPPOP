<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_page_exposes_the_three_workflow_tabs_to_receiving_users(): void
    {
        $user = $this->userWith(['stock.manage', 'purchasing.manage']);

        $this->actingAs($user)->get(route('wh.index'))
            ->assertOk()
            ->assertSee('รับเข้า')
            ->assertSee('รับตาม PO')
            ->assertSee('เช็คสต๊อก')
            ->assertSee("tab: 'receive',", false)
            ->assertSee('x-show="tab === \'receive\'"', false)
            ->assertSee('x-show="tab === \'po\'"', false)
            ->assertSee('x-show="tab === \'stock\'"', false);
    }

    public function test_stock_only_user_starts_on_stock_with_valid_javascript(): void
    {
        $this->actingAs($this->userWith(['stock.manage']))->get(route('wh.index'))
            ->assertOk()
            ->assertSee("tab: 'stock',", false)
            ->assertDontSee("switchTab('receive')", false)
            ->assertDontSee("switchTab('po')", false);
    }

    public function test_stock_lookup_is_scoped_to_the_selected_branch(): void
    {
        $user = $this->userWith(['stock.manage']);
        [$branchA, $locationA] = $this->branchWithLocation('A');
        [$branchB, $locationB] = $this->branchWithLocation('B');
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น']);
        $product = Product::create([
            'sku_code' => 'WH-001', 'name_th' => 'สินค้าทดสอบคลังมือถือ',
            'base_unit_id' => $unit->id, 'is_active' => true,
        ]);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $locationA->id, 'on_hand_qty' => 12]);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $locationB->id, 'on_hand_qty' => 99]);

        $this->actingAs($user)->getJson(route('wh.stock', [
            'product_id' => $product->id, 'branch_id' => $branchA->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.location_id', $locationA->id)
            ->assertJsonPath('total', 12);
    }

    public function test_branch_bound_user_cannot_widen_stock_lookup_to_another_branch(): void
    {
        [$branchA, $locationA] = $this->branchWithLocation('C');
        [$branchB, $locationB] = $this->branchWithLocation('D');
        $user = $this->userWith(['stock.manage'], $branchA);
        $unit = ProductUnit::create(['code' => 'EA-BOUND', 'name' => 'ชิ้น']);
        $product = Product::create([
            'sku_code' => 'WH-002', 'name_th' => 'สินค้าทดสอบสิทธิ์สาขา',
            'base_unit_id' => $unit->id, 'is_active' => true,
        ]);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $locationA->id, 'on_hand_qty' => 7]);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $locationB->id, 'on_hand_qty' => 88]);

        $this->actingAs($user)->getJson(route('wh.stock', [
            'product_id' => $product->id, 'branch_id' => $branchB->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.location_id', $locationA->id)
            ->assertJsonPath('total', 7);
    }

    private function userWith(array $permissionCodes, ?Branch $branch = null): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'warehouse-mobile-'.(++$sequence),
            'name' => 'Warehouse Mobile Test',
            'branch_id' => $branch?->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'WH_MOBILE_'.($sequence), 'name' => 'Warehouse Mobile']);
        $ids = collect($permissionCodes)->map(fn (string $code) =>
            Permission::firstOrCreate(['code' => $code], ['name' => $code])->id
        );
        $role->permissions()->sync($ids->all());
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /** @return array{0: Branch, 1: WarehouseLocation} */
    private function branchWithLocation(string $suffix): array
    {
        $branch = Branch::create(['code' => 'WH-'.$suffix, 'name_th' => 'คลัง '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$suffix, 'name' => 'คลัง '.$suffix]);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        return [$branch->fresh(), $location];
    }
}
