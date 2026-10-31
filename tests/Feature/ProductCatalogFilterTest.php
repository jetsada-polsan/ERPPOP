<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
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

    public function test_product_page_can_find_a_product_by_its_existing_barcode(): void
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => '301261', 'name_th' => '100พลัส เกรปเบอรี่ 370 มล.',
            'base_unit_id' => $unit->id, 'default_price' => 20, 'average_cost' => 12,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '8851952143282',
            'barcode_type' => 'CUSTOM', 'unit_id' => $unit->id, 'unit_factor' => 1,
            'price' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->manager())
            ->get('/products?q=8851952143282&status=all')
            ->assertOk()
            ->assertSee('100พลัส เกรปเบอรี่ 370 มล.')
            ->assertSee('8851952143282')
            ->assertSee('<th>บาร์โค้ด</th>', false)
            ->assertSee('@input.debounce.500ms="$refs.filterForm.requestSubmit()"', false)
            ->assertSee('placeholder="รหัส ชื่อ หรือบาร์โค้ด"', false);
    }

    public function test_product_search_matches_sku_thai_and_english_names_and_shows_all_barcodes(): void
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => '777123', 'name_th' => 'น้ำผลไม้ทดสอบ', 'name_en' => 'Demo Juice',
            'base_unit_id' => $unit->id, 'default_price' => 20, 'is_active' => true,
        ]);
        foreach ([['8850000000123', true], ['OLD-777123', false]] as [$barcode, $isActive]) {
            ProductBarcode::create([
                'product_id' => $product->id, 'barcode' => $barcode,
                'barcode_type' => 'CUSTOM', 'unit_id' => $unit->id, 'unit_factor' => 1,
                'price' => 20, 'is_active' => $isActive,
            ]);
        }
        $user = $this->manager();

        foreach (['777123', 'น้ำผลไม้ทดสอบ', 'Demo Juice', 'OLD-777123'] as $term) {
            $this->actingAs($user)->get('/products?q='.urlencode($term).'&status=all')
                ->assertOk()
                ->assertSee('น้ำผลไม้ทดสอบ')
                ->assertSee('8850000000123')
                ->assertSee('OLD-777123');
        }
    }
}
