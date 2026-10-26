<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ทุกหน้าที่ต้องเลือกสินค้าลงตารางต้องใช้ product picker ตัวเดียวกัน (partials.product-picker)
 * ซึ่งถูกวางไว้นอกตาราง/modal เพราะ dropdown แบบ absolute ที่อยู่ใน <td> จะถูก overflow
 * ของ .table-responsive บังจนมองไม่เห็น.
 */
class ProductPickerSharedTest extends TestCase
{
    use RefreshDatabase;

    /** หน้าที่ต้องใช้ picker ร่วมกัน */
    private const ITEM_ENTRY_PAGES = [
        '/purchase-orders',
        '/stock-transfers',
        '/stock-transfers/request',
        '/stock-issues',
    ];

    public function test_every_item_entry_page_renders_the_shared_product_picker(): void
    {
        $this->signIn();

        foreach (self::ITEM_ENTRY_PAGES as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $response->assertSee('product-picker-backdrop', escape: false);
            $response->assertSee('erpProductPicker()', escape: false);
            $response->assertSee('openProductPicker(', escape: false);
        }
    }

    public function test_item_entry_pages_no_longer_render_the_clipped_inline_dropdown(): void
    {
        $this->signIn();

        foreach (self::ITEM_ENTRY_PAGES as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            // typeahead-list / item.results คือ dropdown ตัวเก่าที่ถูก overflow บัง
            $this->assertStringNotContainsString('typeahead-list', $html, "[{$uri}] ยังมี dropdown ตัวเก่าที่ถูกบังอยู่");
            $this->assertStringNotContainsString('item.results', $html, "[{$uri}] ยังผูกกับ item.results ของ dropdown ตัวเก่า");
        }
    }

    public function test_picker_supports_mouse_and_keyboard_selection(): void
    {
        $this->signIn();

        $html = $this->get('/stock-transfers')->assertOk()->getContent();

        // เมาส์
        $this->assertStringContainsString('@click="selectPickerProduct(product)"', $html);
        // คีย์บอร์ด: ขึ้น/ลง/Enter/Esc
        $this->assertStringContainsString('@keydown.arrow-down.prevent="movePickerActive(1)"', $html);
        $this->assertStringContainsString('@keydown.arrow-up.prevent="movePickerActive(-1)"', $html);
        $this->assertStringContainsString('@keydown.enter.prevent="selectActivePickerProduct()"', $html);
        $this->assertStringContainsString('@keydown.escape.window="closeProductPicker()"', $html);
        // แถวที่โฟกัสด้วยคีย์บอร์ดต้องถูกไฮไลต์
        $this->assertStringContainsString("'is-active': pickerRow === pickerActiveIndex", $html);
    }

    public function test_shared_picker_css_and_script_are_emitted_only_once_per_page(): void
    {
        $this->signIn();

        $html = $this->get('/stock-issues')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'window.erpProductPicker = function'), 'mixin ของ picker ถูกฝังซ้ำ');
        // .product-picker-backdrop ใช้ไม่ได้: ถูกประกาศซ้ำใน @media ของ partial เอง
        $this->assertSame(1, substr_count($html, '.selected-product-grid {'), 'CSS ของ picker ถูกฝังซ้ำ');
    }

    public function test_search_endpoint_returns_every_field_the_picker_renders(): void
    {
        $this->signIn();
        $this->masters();

        // ค้นหาด้วยคำว่าง: เลี่ยง ilike ที่ SQLite ของชุดทดสอบไม่รองรับ
        $payload = $this->getJson('/search/products?q=')->assertOk()->json();

        $this->assertNotEmpty($payload);
        foreach (['id', 'sku_code', 'name_th', 'unit_name', 'barcode', 'default_price'] as $field) {
            $this->assertArrayHasKey($field, $payload[0], "ผลค้นหาไม่มี field [{$field}] ที่ picker ต้องใช้แสดง");
        }
        $this->assertSame('SKU-PICK', $payload[0]['sku_code']);
        $this->assertSame('ชิ้น', $payload[0]['unit_name']);
    }

    private function signIn(): void
    {
        // /search/products ตรวจสิทธิ์เองใน controller (ไม่ผ่าน ErpAuthorize) จึงต้องถือสิทธิ์คลังจริง
        $user = User::factory()->create(['username' => 'picker_tester']);
        $role = Role::create(['code' => 'PICKER_TEST', 'name' => 'Picker test']);
        $role->permissions()->sync([
            Permission::firstOrCreate(['code' => 'stock.manage'], ['name' => 'stock.manage'])->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }

    private function masters(): void
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        Product::create([
            'sku_code' => 'SKU-PICK', 'name_th' => 'สินค้าทดสอบ picker', 'base_unit_id' => $unit->id,
            'default_price' => 25, 'average_cost' => 10, 'is_active' => true, 'is_vat' => false,
            'negative_stock_policy' => 'block',
        ]);
    }
}
