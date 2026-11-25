<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\UnitQty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * หน้าเอกสาร/ใบพิมพ์เรียก UnitQty::format ทุกบรรทัด ส่วนใหญ่สินค้าไม่ได้ eager load หน่วยมา
 * เดิมเสีย 2 query ต่อบรรทัด (baseUnit + barcodes) ตอนนี้ต้องโหลดครั้งเดียวต่อสินค้าต่อ request
 */
class UnitQtyQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatting_many_lines_of_the_same_product_loads_units_once(): void
    {
        $bag = ProductUnit::create(['code' => 'BAGQ', 'name' => 'ถุง', 'qty_per_base_unit' => 1]);
        $crate = ProductUnit::create(['code' => 'CRATEQ', 'name' => 'ลัง', 'qty_per_base_unit' => 18]);
        $product = Product::create([
            'sku_code' => 'QCOUNT', 'name_th' => 'สินค้านับ query',
            'base_unit_id' => $bag->id, 'default_price' => 10, 'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => 'QCOUNT-CRATE',
            'unit_id' => $crate->id, 'unit_factor' => 18, 'is_active' => true,
        ]);

        // เหมือนบรรทัดเอกสาร 30 บรรทัดที่แต่ละบรรทัดถือ Product ของตัวเองที่ยังไม่โหลดหน่วย
        $lines = collect(range(1, 30))->map(fn () => Product::query()->find($product->id));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $texts = $lines->map(fn (Product $p) => UnitQty::format($p, 40));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('40 ถุง (2 ลัง 4 ถุง)', $texts->first());
        $this->assertCount(1, $texts->unique());
        $this->assertLessThanOrEqual(4, $queries, "UnitQty::format ใช้ {$queries} query สำหรับ 30 บรรทัด");
    }
}
