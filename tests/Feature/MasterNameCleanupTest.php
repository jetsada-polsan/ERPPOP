<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ข้อ 17: แก้ชื่อผู้จำหน่ายภาษาไทยเพี้ยนจากชื่อในระบบเดิม ลบหน่วยเพี้ยนที่ไม่มีใครใช้
 * และรายงานสินค้าชื่อซ้ำเป็น CSV โดยไม่รวมอัตโนมัติ
 */
class MasterNameCleanupTest extends TestCase
{
    use RefreshDatabase;

    // "บริษัท ทดสอบ" ที่ผ่านการอ่าน UTF-8 เป็น 874 มาแล้ว (ไบต์ 0x80–0x9F หาย)
    private const GARBLED = 'เธเธฃเธดเธฉเธฑเธ— เธ—เธ”เธชเธญเธ';

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists(storage_path('app/backups'));
        File::cleanDirectory(storage_path('app/backups'));
        $path = storage_path('app/backups/erp-db-'.now()->format('Ymd-His').'.sql.gz');
        File::put($path, gzencode('-- backup ทดสอบ'));
        File::put($path.'.sha256', hash_file('sha256', $path).'  '.basename($path));
    }

    protected function tearDown(): void
    {
        File::cleanDirectory(storage_path('app/backups'));
        parent::tearDown();
    }

    public function test_garbled_supplier_names_are_replaced_from_the_legacy_list_only_where_still_garbled(): void
    {
        Supplier::create(['code' => 'A00006', 'name_th' => self::GARBLED, 'is_active' => true]);
        Supplier::create(['code' => 'B00002', 'name_th' => 'บริษัท เนสท์เล่ เนเธอร์แลนด์ จำกัด', 'is_active' => true]);
        Supplier::create(['code' => 'B00008', 'name_th' => self::GARBLED, 'is_active' => true]);
        $file = storage_path('app/supplier-names-test.tsv');
        File::put($file, "A00006\tบริษัท ทดสอบ จำกัด\nB00002\tชื่อจากระบบเดิม\n");

        $this->artisan('erp:repair-master-names', ['--suppliers' => $file, '--force' => true])
            ->expectsOutputToContain('B00008')
            ->assertSuccessful();

        $this->assertSame('บริษัท ทดสอบ จำกัด', Supplier::where('code', 'A00006')->value('name_th'));
        // ชื่อปกติที่มี "เน"/"เธอ" ไม่ถูกทับ
        $this->assertSame('บริษัท เนสท์เล่ เนเธอร์แลนด์ จำกัด', Supplier::where('code', 'B00002')->value('name_th'));
        $this->assertSame(self::GARBLED, Supplier::where('code', 'B00008')->value('name_th'));
        File::delete($file);
    }

    public function test_only_unused_garbled_units_are_dropped(): void
    {
        $unused = ProductUnit::create(['code' => 'G1', 'name' => 'เธเธฃเธฐเธเธญ', 'qty_per_base_unit' => 1]);
        $used = ProductUnit::create(['code' => 'G2', 'name' => 'เธเธฅเนเธญเธ', 'qty_per_base_unit' => 1]);
        $clean = ProductUnit::create(['code' => 'G3', 'name' => 'กล่อง', 'qty_per_base_unit' => 1]);
        Product::create(['sku_code' => 'U-1', 'name_th' => 'สินค้า', 'base_unit_id' => $used->id, 'default_price' => 1, 'average_cost' => 0, 'is_active' => true]);

        $this->artisan('erp:repair-master-names', ['--drop-unused-units' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertTrue(ProductUnit::whereKey($unused->id)->exists());

        $this->artisan('erp:repair-master-names', ['--drop-unused-units' => true, '--force' => true])->assertSuccessful();
        $this->assertFalse(ProductUnit::whereKey($unused->id)->exists());
        $this->assertTrue(ProductUnit::whereKey($used->id)->exists());
        $this->assertTrue(ProductUnit::whereKey($clean->id)->exists());
    }

    public function test_duplicate_names_are_reported_not_merged(): void
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $category = ProductCategory::create(['code' => '104', 'name_th' => 'ไก่แช่แข็ง']);
        foreach ([['104064', 'ปีกไก่  กลาง'], ['104174', 'ปีกไก่ กลาง'], ['104200', 'น่องไก่']] as [$sku, $name]) {
            Product::create(['sku_code' => $sku, 'name_th' => $name, 'product_category_id' => $category->id, 'base_unit_id' => $unit->id, 'default_price' => 1, 'average_cost' => 0, 'is_active' => true]);
        }
        $csv = storage_path('app/reports/dup-test.csv');

        $this->artisan('erp:report-duplicate-product-names', ['--output' => $csv])
            ->expectsOutputToContain('ชื่อซ้ำ 1 กลุ่ม (2 รหัส)')
            ->assertSuccessful();

        $content = File::get($csv);
        $this->assertStringContainsString('104064', $content);
        $this->assertStringContainsString('104174', $content);
        $this->assertStringNotContainsString('104200', $content);
        $this->assertSame(3, Product::count());
        File::delete($csv);
    }
}
