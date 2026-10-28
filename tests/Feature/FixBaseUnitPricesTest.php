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
 * ราคาหลักต้องเป็นราคาหน่วยฐาน: รหัส 6 หลักไม่มีราคาเอง ถ้าราคาหลักเป็นราคาลัง จะคิดราคาลังต่อ กก.
 */
class FixBaseUnitPricesTest extends TestCase
{
    use RefreshDatabase;

    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $this->unit = ProductUnit::create(['code' => 'KG-FP', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        ProductCategory::create(['code' => '777', 'name_th' => 'หมวดทดสอบ']);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_pack_price_on_the_product_becomes_the_base_unit_price(): void
    {
        $this->freshBackup();
        // ไส้ตันใหญ่: หิ้ว*5 = 745, กก. = 149; ราคาหลักได้ 745 ต้นทุน 650 จากแถวหิ้ว
        $wrong = $this->product('777115', 745, 650, [['7771150000009', 1, 149], ['0000007771142', 5, 745], ['777115', 1, null]]);
        $right = $this->product('777001', 35, 21, [['777001', 1, 35], ['7770010000005', 25, 575]]);
        $mixed = $this->product('777060', 850, 530, [['777060', 1, 75], ['7770600000001', 1, 90], ['0000007770604', 10, 850]]);

        $this->artisan('erp:fix-base-unit-prices', ['--force' => true])->assertSuccessful();

        $this->assertEquals(149, $wrong->fresh()->default_price);
        $this->assertEquals(130, $wrong->fresh()->average_cost);
        $this->assertEquals(35, $right->fresh()->default_price);
        $this->assertEquals(850, $mixed->fresh()->default_price); // หลายราคา ไม่เดา
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->product('777115', 745, 650, [['7771150000009', 1, 149], ['0000007771142', 5, 745]]);

        $this->artisan('erp:fix-base-unit-prices', ['--dry-run' => true])
            ->expectsOutputToContain('777115')
            ->assertSuccessful();

        $this->assertEquals(745, $product->fresh()->default_price);
    }

    private function product(string $sku, float $price, float $cost, array $barcodes): Product
    {
        $product = Product::create([
            'sku_code' => $sku, 'name_th' => $sku, 'product_category_id' => ProductCategory::value('id'),
            'base_unit_id' => $this->unit->id, 'default_price' => $price, 'average_cost' => $cost, 'is_vat' => false, 'is_active' => true,
        ]);
        foreach ($barcodes as [$code, $factor, $barcodePrice]) {
            ProductBarcode::create([
                'product_id' => $product->id, 'barcode' => $code, 'barcode_type' => BarcodePolicy::CUSTOM,
                'unit_id' => $this->unit->id, 'unit_factor' => $factor, 'price' => $barcodePrice, 'is_active' => true,
            ]);
        }

        return $product;
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
