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
 * เติมบาร์โค้ดจากระบบเดิม: รหัส 6 หลักสแกน/พิมพ์ได้, ไม่แตะของเดิม, ชนสินค้าอื่นต้องหยุด
 */
class AddMissingBarcodesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private Product $main;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $this->file = storage_path('app/missing-barcodes-test.tsv');

        $category = ProductCategory::create(['code' => '777', 'name_th' => 'หมวดทดสอบ']);
        $unit = ProductUnit::create(['code' => 'KG-AB', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $make = fn ($sku) => Product::create([
            'sku_code' => $sku, 'name_th' => $sku, 'product_category_id' => $category->id, 'base_unit_id' => $unit->id,
            'default_price' => 149, 'average_cost' => 100, 'is_vat' => false, 'is_active' => true,
        ]);
        $this->main = $make('777115');
        $other = $make('777116');
        ProductBarcode::create(['product_id' => $this->main->id, 'barcode' => '7771150000009', 'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $unit->id, 'unit_factor' => 1, 'price' => 149, 'is_active' => true]);
        ProductBarcode::create(['product_id' => $other->id, 'barcode' => '777999', 'barcode_type' => BarcodePolicy::CUSTOM, 'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        File::delete($this->file);
        parent::tearDown();
    }

    public function test_it_adds_the_six_digit_code_and_leaves_existing_barcodes_alone(): void
    {
        $this->freshBackup();
        File::put($this->file, "sku_code\tbarcode\tunit\tfactor\n777115\t777115\tกิโลกรัม\t1\n777115\t7771150000009\tกิโลกรัม\t1\n777115\t801082\tกิโลกรัม\t1\n");

        $this->artisan('erp:add-missing-barcodes', ['file' => $this->file, '--force' => true])->assertSuccessful();

        $code = ProductBarcode::where('barcode', '777115')->first();
        $this->assertSame($this->main->id, $code->product_id);
        $this->assertSame(BarcodePolicy::CUSTOM, $code->barcode_type);
        $this->assertNull($code->price); // ราคาจากสินค้า ไม่ตั้งราคาบาร์โค้ด
        $this->assertSame(BarcodePolicy::SCALE_PLU, ProductBarcode::where('barcode', '801082')->value('barcode_type'));
        // POS Python คิดน้ำหนักจากราคาบาร์โค้ด PLU ถ้าว่างจะขายของชั่งไม่ได้
        $this->assertEquals(149, ProductBarcode::where('barcode', '801082')->value('price'));
        $this->assertEquals(149, ProductBarcode::where('barcode', '7771150000009')->value('price'));
        $this->assertSame(1, ProductBarcode::where('barcode', '7771150000009')->count());
    }

    public function test_a_barcode_owned_by_another_product_stops_the_whole_run(): void
    {
        $this->freshBackup();
        File::put($this->file, "sku_code\tbarcode\tunit\tfactor\n777115\t777115\tกิโลกรัม\t1\n777115\t777999\tกิโลกรัม\t1\n");

        $this->artisan('erp:add-missing-barcodes', ['file' => $this->file, '--force' => true])
            ->expectsOutputToContain('777999')
            ->assertFailed();

        $this->assertFalse(ProductBarcode::where('barcode', '777115')->exists());
    }

    public function test_dry_run_saves_nothing(): void
    {
        File::put($this->file, "sku_code\tbarcode\tunit\tfactor\n777115\t777115\tกิโลกรัม\t1\n");

        $this->artisan('erp:add-missing-barcodes', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();

        $this->assertFalse(ProductBarcode::where('barcode', '777115')->exists());
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
