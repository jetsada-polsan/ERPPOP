<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_taxonomy_can_be_created_edited_and_deleted_when_unused(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create(['username' => 'module-admin-1']));

        $this->post(route('settings.module-controls.taxonomies.store', 'category'), [
            'code' => 'FRESH',
            'name_th' => 'อาหารสด',
            'name_en' => 'Fresh food',
        ])->assertSessionHasNoErrors();

        $category = ProductCategory::where('code', 'FRESH')->firstOrFail();
        $this->put(route('settings.module-controls.taxonomies.update', ['category', $category]), [
            'code' => 'FRESH-01',
            'name_th' => 'อาหารสดแช่เย็น',
            'name_en' => 'Chilled food',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'code' => 'FRESH-01',
            'name_th' => 'อาหารสดแช่เย็น',
        ]);

        $this->delete(route('settings.module-controls.taxonomies.destroy', ['category', $category]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_used_taxonomy_is_protected_from_deletion(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create(['username' => 'module-admin-2']));
        $category = ProductCategory::create(['code' => 'USED', 'name_th' => 'ใช้งานแล้ว']);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        Product::create([
            'sku_code' => 'SKU-USED',
            'name_th' => 'สินค้าทดสอบ',
            'product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        $this->delete(route('settings.module-controls.taxonomies.destroy', ['category', $category]))
            ->assertSessionHasErrors('taxonomy');

        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
    }

    public function test_control_center_lists_master_and_document_lifecycle_rules(): void
    {
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs(User::factory()->create(['username' => 'module-admin-3']))
            ->get(route('settings.module-controls'))
            ->assertOk()
            ->assertSee('ข้อมูลตั้งต้น')
            ->assertSee('วงจรเอกสาร')
            ->assertSee('อนุมัติแล้วใช้ยกเลิกหรือเอกสารกลับรายการ')
            ->assertSee('หมวด / แผนก / ยี่ห้อสินค้า');
    }
}
