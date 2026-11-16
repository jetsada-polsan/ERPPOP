<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EtlProductSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function snapshotFile(array $overrides = []): string
    {
        $snapshot = array_merge([
            'categories' => [['ICCAT_CODE' => 'C01', 'ICCAT_NAME' => 'เครื่องดื่ม']],
            'departments' => [['ICDEPT_CODE' => 'D01', 'ICDEPT_THAIDESC' => 'แผนกน้ำ', 'ICDEPT_ENGDESC' => null]],
            'brands' => [['BRN_CODE' => 'B01', 'BRN_NAME' => 'ยี่ห้อหนึ่ง']],
            'units' => [['UTQ_KEY' => 7001, 'UTQ_NAME' => 'ขวด', 'UTQ_QTY' => 1], ['UTQ_KEY' => 7002, 'UTQ_NAME' => 'ลัง', 'UTQ_QTY' => 12]],
            'products' => [
                ['SKU_CODE' => 'CO8110 ', 'SKU_NAME' => 'ค่าขนส่ง', 'SKU_S_UTQ' => 7001, 'SKU_PRICE' => 0, 'SKU_ENABLE' => 'Y',
                    'ICCAT_CODE' => 'C01', 'ICDEPT_CODE' => 'D01', 'BRN_CODE' => 'B01'],
                ['SKU_CODE' => 'W001', 'SKU_NAME' => 'น้ำดื่ม 600 มล.', 'SKU_S_UTQ' => 7001, 'SKU_PRICE' => 7.5, 'SKU_ENABLE' => 'N',
                    'ICCAT_CODE' => 'C01', 'ICDEPT_CODE' => 'D01', 'BRN_CODE' => 'B01'],
            ],
            'barcodes' => [['GOODS_CODE' => '8850000000017', 'SKU_CODE' => 'W001', 'GOODS_UTQ' => 7002, 'GOODS_PRICE' => 85, 'GOODS_ENABLE' => 'Y']],
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'bplus-products');
        file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE));

        return $path;
    }

    public function test_products_follow_the_bplus_snapshot(): void
    {
        $unit = DB::table('product_units')->insertGetId(['code' => '7001', 'name' => 'ขวดเก่า', 'qty_per_base_unit' => 1]);
        DB::table('products')->insert(['sku_code' => 'W001', 'name_th' => 'ชื่อเก่าใน ERP', 'base_unit_id' => $unit, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['sku_code' => 'ERP-ONLY', 'name_th' => 'สินค้าที่ ERP สร้างเอง', 'base_unit_id' => $unit, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('etl:master-data', ['--from-file' => $this->snapshotFile()])->assertSuccessful();

        $transport = DB::table('products')->where('sku_code', 'CO8110')->first();
        $this->assertNotNull($transport, 'สินค้าที่ ERP ยังไม่มีต้องถูกสร้าง');
        $this->assertSame('ค่าขนส่ง', $transport->name_th);
        $water = DB::table('products')->where('sku_code', 'W001')->first();
        $this->assertSame('น้ำดื่ม 600 มล.', $water->name_th, 'ชื่อต้องตาม BPlus');
        $this->assertFalse((bool) $water->is_active, 'สถานะปิดใช้งานต้องตาม BPlus');
        $this->assertSame('7.50', number_format((float) $water->default_price, 2));
        $this->assertSame('ขวด', DB::table('product_units')->where('code', '7001')->value('name'));
        $this->assertSame($water->id, DB::table('product_barcodes')->where('barcode', '8850000000017')->value('product_id'));
        $this->assertTrue(DB::table('products')->where('sku_code', 'ERP-ONLY')->exists(), 'สินค้าที่ไม่มีใน BPlus ต้องไม่ถูกลบ');
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->artisan('etl:master-data', ['--from-file' => $this->snapshotFile(), '--dry-run' => true])
            ->expectsOutputToContain('สินค้าใหม่ 2 รายการ')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_incomplete_snapshot_is_rejected(): void
    {
        $path = $this->snapshotFile();
        $data = json_decode(file_get_contents($path), true);
        unset($data['barcodes']);
        file_put_contents($path, json_encode($data));

        $this->artisan('etl:master-data', ['--from-file' => $path])
            ->expectsOutputToContain('ไม่มีส่วน barcodes')
            ->assertFailed();
        $this->assertSame(0, DB::table('products')->count());
    }
}
