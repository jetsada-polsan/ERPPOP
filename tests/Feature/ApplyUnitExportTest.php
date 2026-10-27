<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * เติมหน่วย ตัวคูณ และ VAT จากหน้าราคาระบบเดิม: หน่วยขัดกันต้องข้าม ไม่เดา
 */
class ApplyUnitExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Customer::withTrashed()->forceDelete();
        foreach (['101' => 'หมูหมัก', '109' => 'ปู', 'CO' => 'ค่าใช้จ่าย', 'CC' => 'สินค้ายกเลิก'] as $code => $name) {
            ProductCategory::create(['code' => $code, 'name_th' => $name]);
        }
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $this->freshBackup();
        $this->artisan('erp:import-popstar-export', ['file' => base_path('tests/Fixtures/popstar_export_sample.txt'), '--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_it_sets_pack_factors_base_units_and_vat(): void
    {
        $this->artisan('erp:apply-unit-export', ['file' => base_path('tests/Fixtures/unit_export_sample.tsv'), '--force' => true])
            ->assertSuccessful();

        $product = Product::where('sku_code', '101001')->first();
        $this->assertSame('ก้อน', ProductUnit::find($product->base_unit_id)->name);
        $pack = ProductBarcode::where('barcode', '1010010000005')->first();
        $this->assertEquals(25, $pack->unit_factor);
        $this->assertSame('หิ้ว*25', ProductUnit::find($pack->unit_id)->name);
        $this->assertEquals(1, ProductBarcode::where('barcode', '101001')->value('unit_factor'));
        $this->assertFalse($product->is_vat);

        // หน่วยขัดกัน: ไม่แตะหน่วย แต่ VAT ยังตั้ง
        $conflict = Product::where('sku_code', '109039')->first();
        $this->assertTrue($conflict->is_vat);
        $this->assertSame('PCS', ProductUnit::find($conflict->base_unit_id)->code);
        $this->assertEquals(1, ProductBarcode::where('barcode', '109039')->value('unit_factor'));
    }

    public function test_dry_run_reports_conflicts_and_saves_nothing(): void
    {
        $this->artisan('erp:apply-unit-export', ['file' => base_path('tests/Fixtures/unit_export_sample.tsv'), '--dry-run' => true])
            ->expectsOutputToContain('109039')
            ->assertSuccessful();

        $this->assertEquals(1, ProductBarcode::where('barcode', '1010010000005')->value('unit_factor'));
        $this->assertFalse(Product::where('sku_code', '109039')->value('is_vat'));
    }

    private function freshBackup(): void
    {
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }
}
