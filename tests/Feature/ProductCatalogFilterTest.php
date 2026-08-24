<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogFilterTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $user = User::factory()->create([
            'username' => 'product-filter-manager',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'PRODUCT_FILTER', 'name' => 'Product filter']);
        $permission = Permission::firstOrCreate(['code' => 'masterdata.manage'], ['name' => 'จัดการข้อมูลหลัก']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_product_page_can_show_all_active_and_inactive_products(): void
    {
        $category = ProductCategory::create(['code' => '101', 'name_th' => 'เนื้อสัตว์']);
        $unit = ProductUnit::create(['code' => 'KG', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        Product::create(['sku_code' => '101001', 'name_th' => 'สินค้าใช้งาน', 'product_category_id' => $category->id, 'base_unit_id' => $unit->id, 'default_price' => 10, 'is_active' => true]);
        Product::create(['sku_code' => '101002', 'name_th' => 'สินค้าพักไว้', 'product_category_id' => $category->id, 'base_unit_id' => $unit->id, 'default_price' => 10, 'is_active' => false]);
        $user = $this->manager();

        $this->actingAs($user)->get('/products?status=active')
            ->assertOk()->assertSee('สินค้าใช้งาน')->assertDontSee('สินค้าพักไว้');
        $this->actingAs($user)->get('/products?status=inactive')
            ->assertOk()->assertSee('สินค้าพักไว้')->assertDontSee('สินค้าใช้งาน');
        $this->actingAs($user)->get('/products?status=all&category_id='.$category->id)
            ->assertOk()->assertSee('สินค้าใช้งาน')->assertSee('สินค้าพักไว้')
            ->assertSee('สินค้าทั้งหมด')->assertSee('กำลังใช้งาน')->assertSee('ไม่ใช้งาน / พักไว้');
    }
}
