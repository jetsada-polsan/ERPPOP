<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ปิดสินค้าตามรายการ: ปิดเฉพาะที่อยู่ในไฟล์ ประเภทเดิมคงอยู่ เพื่อเปิดกลับได้ทันที
 */
class DeactivateProductsFromListTest extends TestCase
{
    use RefreshDatabase;

    private string $list;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $this->list = storage_path('app/deactivate-test.txt');
        File::put($this->list, "sku_code\nOFF-1\nOFF-2\nNOT-IN-ERP\n");

        $category = ProductCategory::create(['code' => '777', 'name_th' => 'หมวดทดสอบ']);
        $unit = ProductUnit::create(['code' => 'EA-DP', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        foreach (['OFF-1', 'OFF-2', 'KEEP-1'] as $sku) {
            Product::create([
                'sku_code' => $sku, 'name_th' => $sku, 'product_category_id' => $category->id, 'base_unit_id' => $unit->id,
                'default_price' => 10, 'average_cost' => 5, 'is_vat' => false, 'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        File::delete($this->list);
        parent::tearDown();
    }

    public function test_it_switches_off_only_listed_products_and_keeps_their_category(): void
    {
        $this->freshBackup();
        $category = Product::where('sku_code', 'OFF-1')->value('product_category_id');

        $this->artisan('erp:deactivate-products', ['file' => $this->list, '--force' => true])->assertSuccessful();

        $this->assertFalse(Product::where('sku_code', 'OFF-1')->value('is_active'));
        $this->assertFalse(Product::where('sku_code', 'OFF-2')->value('is_active'));
        $this->assertTrue(Product::where('sku_code', 'KEEP-1')->value('is_active'));
        $this->assertSame($category, Product::where('sku_code', 'OFF-1')->value('product_category_id'));
    }

    public function test_dry_run_and_missing_backup_change_nothing(): void
    {
        $this->artisan('erp:deactivate-products', ['file' => $this->list, '--dry-run' => true])->assertSuccessful();
        $this->artisan('erp:deactivate-products', ['file' => $this->list, '--force' => true])->assertFailed();

        $this->assertSame(3, Product::where('is_active', true)->count());
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
