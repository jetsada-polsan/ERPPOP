<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * นำเข้าไฟล์ POPSTAR DATA EXPORT ตามกติกาเจ้าของ: ลูกค้าซ้ำ (ชื่อ+เบอร์) เก็บรหัสแรก,
 * ต้นทุน 0 ลงประเภท CC ปิดใช้งาน, รหัสชั่ง 801 ที่มีตัวเกินท้ายไม่นำเข้า
 */
class ImportPopstarExportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = base_path('tests/Fixtures/popstar_export_sample.txt');
        foreach (['101' => 'หมูหมัก', '109' => 'ปู', 'CO' => 'ค่าใช้จ่าย', 'CC' => 'สินค้ายกเลิก'] as $code => $name) {
            ProductCategory::create(['code' => $code, 'name_th' => $name]);
        }
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_it_imports_customers_and_products_by_the_owner_rules(): void
    {
        $this->freshBackup();

        $this->artisan('erp:import-popstar-export', ['file' => $this->file, '--force' => true])->assertSuccessful();

        // 116 ชื่อ+เบอร์ตรงกับ 115 = ซ้ำ; คุณทดสอบสองคนเบอร์ต่าง = คนละคน
        $this->assertSame(['115', '1467', '186'], Customer::orderBy('code')->pluck('code')->all());
        $this->assertSame('090-000-0001', CustomerContact::where('customer_id', Customer::where('code', '115')->value('id'))->value('phone'));

        $main = Product::where('sku_code', '101001')->first();
        $this->assertSame('101', $this->categoryCode($main));
        $this->assertEquals(35, $main->default_price);
        $this->assertEquals(575, ProductBarcode::where('barcode', '1010010000005')->value('price'));

        $this->assertSame(BarcodePolicy::SCALE_PLU, ProductBarcode::where('barcode', '801031')->value('barcode_type'));
        $this->assertFalse(ProductBarcode::where('barcode', '8010310')->exists());
        $this->assertTrue(Product::where('sku_code', '101003')->exists());

        $zeroCost = Product::where('sku_code', '109039')->first();
        $this->assertFalse($zeroCost->is_active);
        $this->assertSame('CC', $this->categoryCode($zeroCost));
        $this->assertSame('CO', $this->categoryCode(Product::where('sku_code', 'CO8109')->first()));
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->artisan('erp:import-popstar-export', ['file' => $this->file, '--dry-run' => true])
            ->expectsOutputToContain('8010310')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Product::count());
    }

    public function test_it_refuses_when_old_products_are_still_there(): void
    {
        $this->freshBackup();
        $this->artisan('erp:import-popstar-export', ['file' => $this->file, '--force' => true])->assertSuccessful();

        $this->artisan('erp:import-popstar-export', ['file' => $this->file, '--force' => true])
            ->expectsOutputToContain('erp:purge-products')
            ->assertFailed();
    }

    private function categoryCode(Product $product): string
    {
        return ProductCategory::find($product->product_category_id)->code;
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
