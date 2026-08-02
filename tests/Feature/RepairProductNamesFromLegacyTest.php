<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RepairProductNamesFromLegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_a_product_name_that_was_saved_as_its_sku(): void
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $missingName = Product::create([
            'sku_code' => '204320', 'name_th' => '204320', 'base_unit_id' => $unit->id,
            'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $validName = Product::create([
            'sku_code' => '204321', 'name_th' => 'ชื่อที่ผู้ใช้แก้แล้ว', 'base_unit_id' => $unit->id,
            'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'legacy-product-names-');
        File::put($path, json_encode([
            'source' => 'legacy_product_master',
            'rows' => [
                ['SKU_CODE' => '204320', 'SKU_NAME' => 'ปูอัด ป๊อบอัพ TVI แพ็ค 450g.'],
                ['SKU_CODE' => '204321', 'SKU_NAME' => 'ชื่อจากข้อมูลเดิมที่ไม่ควรทับ'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $exit = Artisan::call('erp:repair-product-names', ['file' => $path]);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('Repaired 1 product names.', Artisan::output());
            $this->assertSame('ปูอัด ป๊อบอัพ TVI แพ็ค 450g.', $missingName->fresh()->name_th);
            $this->assertSame('ชื่อที่ผู้ใช้แก้แล้ว', $validName->fresh()->name_th);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_does_not_replace_a_name_with_a_legacy_sku_placeholder(): void
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => '204320', 'name_th' => '204320', 'base_unit_id' => $unit->id,
            'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'legacy-product-names-');
        File::put($path, json_encode([
            'source' => 'legacy_product_master',
            'rows' => [['SKU_CODE' => '204320', 'SKU_NAME' => '204320']],
        ], JSON_THROW_ON_ERROR));

        try {
            $exit = Artisan::call('erp:repair-product-names', ['file' => $path]);

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('no usable product name', Artisan::output());
            $this->assertSame('204320', $product->fresh()->name_th);
        } finally {
            File::delete($path);
        }
    }
}
