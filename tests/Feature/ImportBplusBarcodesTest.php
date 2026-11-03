<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** บาร์โค้ดที่ขายจริงใน BPlus ต้องเข้า ERP ตามเลขบนป้าย ไม่แตะของที่มีอยู่แล้ว */
class ImportBplusBarcodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_groups_rows_and_apply_adds_only_missing_barcodes(): void
    {
        $piece = ProductUnit::create(['code' => 'PC', 'name' => 'แพ็ค', 'qty_per_base_unit' => 1]);
        $sack = ProductUnit::create(['code' => 'SK20', 'name' => 'กระสอบX20', 'qty_per_base_unit' => 20]);
        $squid = $this->product('106080', 'P-OLD-1', $piece);
        $other = $this->product('104250', null, $piece);
        $existing = ProductBarcode::create(['product_id' => $squid->id, 'barcode' => '0000001060799', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $sack->id, 'unit_factor' => 20, 'is_active' => true]);
        ProductBarcode::create(['product_id' => $other->id, 'barcode' => '0000001052503', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $piece->id, 'unit_factor' => 1, 'is_active' => true]);

        $file = tempnam(sys_get_temp_dir(), 'bplus');
        file_put_contents($file, "\xEF\xBB\xBFsku_code,barcode,unit_name,unit_factor,lines,last_sold\n"
            ."106080,0000001060799,กระสอบX20,20,50,2026-10-01\n"   // มีแล้ว ตรงกัน
            ."106080,1060800000005,กระสอบX20,20,30,2026-10-01\n"   // ขาด ต้องเพิ่ม
            ."106080,1060800000005,แพ็ค,1,2,2026-01-01\n"          // ซ้ำ บิลน้อยกว่า ไม่เลือก
            ."106080,0000001052503,แพ็ค,1,9,2026-10-01\n"          // ERP ผูกกับสินค้าอื่น
            ."106080,8850000000003,ลัง,12,3,2026-10-01\n"          // หาหน่วยไม่เจอ
            ."999999,0000009999993,แพ็ค,1,1,2026-10-01\n"          // ไม่มีสินค้าใน ERP
            ."102007,-,แพ็ค,1,1,2026-10-01\n");                    // ค่าขยะ

        $this->artisan('erp:import-bplus-barcodes', ['file' => $file])
            ->expectsTable(['กลุ่ม', 'จำนวน'], [
                ['ตรงกันแล้ว', 1], ['ขาดใน ERP (จะเพิ่ม)', 1], ['ผูกคนละสินค้า (ต้องตรวจ)', 1],
                ['ไม่พบสินค้าใน ERP', 1], ['หาหน่วยไม่เจอ (ต้องตรวจ)', 1], ['ไม่ใช่บาร์โค้ด (ข้าม)', 1],
            ])->assertSuccessful();
        $this->assertSame(2, ProductBarcode::count(), 'preview ต้องไม่บันทึก');

        $this->artisan('erp:import-bplus-barcodes', ['file' => $file, '--apply' => true])->assertSuccessful();

        $added = ProductBarcode::where('barcode', '1060800000005')->sole();
        $this->assertSame([$squid->id, $sack->id, 20.0, 'bplus'], [$added->product_id, $added->unit_id, (float) $added->unit_factor, $added->source]);
        $this->assertSame($other->id, ProductBarcode::where('barcode', '0000001052503')->value('product_id'), 'ห้ามย้ายบาร์โค้ดของสินค้าอื่น');
        $this->assertSame(BarcodePolicy::CUSTOM, $existing->fresh()->barcode_type, 'ห้ามแก้ของเดิม');
        $this->assertSame(3, ProductBarcode::count());
        unlink($file);
    }

    public function test_new_sku_skips_a_number_already_used_as_an_old_barcode(): void
    {
        $unit = ProductUnit::create(['code' => 'PC', 'name' => 'แพ็ค', 'qty_per_base_unit' => 1]);
        $category = \App\Models\ProductCategory::create(['code' => '107', 'name_th' => 'ทดสอบ']);
        $old = $this->product('104999', null, $unit);
        ProductBarcode::create(['product_id' => $old->id, 'barcode' => '107001', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true]);

        $this->assertSame('107002', app(\App\Services\ProductSkuAllocator::class)->nextForCategory($category->id));
    }

    private function product(string $sku, ?string $legacy, ProductUnit $unit): Product
    {
        return Product::create(['sku_code' => $sku, 'legacy_sku' => $legacy, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true]);
    }
}
