<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ตั้งราคาตามที่เจ้าของกำหนด: ราคาเดิมเป็นราคาลัง -> ต้นทุนต้องหารตัวคูณด้วย
 */
class SetProductPricesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $this->file = storage_path('app/set-prices-test.tsv');
        File::put($this->file, "sku_code\tprice\n777243\t199\n");

        $unit = ProductUnit::create(['code' => 'KG-SP', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $category = ProductCategory::create(['code' => '777', 'name_th' => 'หมวดทดสอบ']);
        $product = Product::create([
            'sku_code' => '777243', 'name_th' => 'เนื้อทดสอบ', 'product_category_id' => $category->id, 'base_unit_id' => $unit->id,
            'default_price' => 3980, 'average_cost' => 3300, 'is_vat' => false, 'is_active' => true,
        ]);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '0000007772430', 'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $unit->id, 'unit_factor' => 20, 'price' => 3980, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        File::delete($this->file);
        parent::tearDown();
    }

    public function test_it_sets_the_price_and_turns_the_case_cost_into_a_base_cost(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));

        $this->artisan('erp:set-product-prices', ['file' => $this->file, '--force' => true])->assertSuccessful();

        $product = Product::where('sku_code', '777243')->first();
        $this->assertEquals(199, $product->default_price);
        $this->assertEquals(165, $product->average_cost);
    }

    public function test_dry_run_and_missing_backup_change_nothing(): void
    {
        $this->artisan('erp:set-product-prices', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();
        $this->artisan('erp:set-product-prices', ['file' => $this->file, '--force' => true])->assertFailed();

        $this->assertEquals(3980, Product::where('sku_code', '777243')->value('default_price'));
    }
}
