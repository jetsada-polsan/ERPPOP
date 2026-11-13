<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\UnitQty;
use Tests\TestCase;

class UnitQtyFormatTest extends TestCase
{
    private ProductUnit $bagUnit;
    private ProductUnit $crateUnit;
    private ProductUnit $boxUnit;
    private ProductUnit $corruptedUnit;
    private ProductUnit $kiloUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bagUnit = new ProductUnit(['name' => 'ถุง', 'code' => 'BAG', 'qty_per_base_unit' => 1]);
        $this->bagUnit->id = 1;

        $this->crateUnit = new ProductUnit(['name' => 'ลังx18', 'code' => 'CRATE', 'qty_per_base_unit' => 18]);
        $this->crateUnit->id = 2;

        $this->boxUnit = new ProductUnit(['name' => 'กล่อง*6', 'code' => 'BOX', 'qty_per_base_unit' => 6]);
        $this->boxUnit->id = 3;

        $this->corruptedUnit = new ProductUnit(['name' => 'ǧ', 'code' => 'BAD', 'qty_per_base_unit' => 10]);
        $this->corruptedUnit->id = 4;

        $this->kiloUnit = new ProductUnit(['name' => 'กก.', 'code' => 'KG', 'qty_per_base_unit' => 1]);
        $this->kiloUnit->id = 5;
    }

    private function makeProductWithBarcodes(array $barcodeConfigs, ProductUnit $baseUnit): Product
    {
        $product = new Product([
            'sku_code' => 'SKU-001',
            'name_th' => 'สินค้าทดสอบ',
            'base_unit_id' => $baseUnit->id,
        ]);
        $product->id = 100;
        $product->setRelation('baseUnit', $baseUnit);

        $barcodes = collect();
        foreach ($barcodeConfigs as $idx => $cfg) {
            $b = new ProductBarcode([
                'product_id' => $product->id,
                'barcode' => $cfg['barcode'] ?? "BAR-{$idx}",
                'unit_id' => $cfg['unit']->id,
                'unit_factor' => $cfg['factor'],
                'is_active' => $cfg['is_active'] ?? true,
            ]);
            $b->id = 200 + $idx;
            $b->setRelation('unit', $cfg['unit']);
            $barcodes->push($b);
        }
        $product->setRelation('barcodes', $barcodes);

        return $product;
    }

    /**
     * 1. รูปแบบพอดีลัง: 36 ถุง (2 ลัง)
     */
    public function test_format_exact_larger_unit(): void
    {
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->crateUnit, 'factor' => 18],
        ], $this->bagUnit);

        $result = UnitQty::format($product, 36);
        $this->assertSame('36 ถุง (2 ลัง)', $result);
    }

    /**
     * 2. รูปแบบมีเศษ: 40 ถุง (2 ลัง 4 ถุง)
     */
    public function test_format_with_remainder(): void
    {
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->crateUnit, 'factor' => 18],
        ], $this->bagUnit);

        $result = UnitQty::format($product, 40);
        $this->assertSame('40 ถุง (2 ลัง 4 ถุง)', $result);
    }

    /**
     * 3. รูปแบบไม่ถึง 1 ลัง: 5 ถุง
     */
    public function test_format_less_than_one_larger_unit(): void
    {
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->crateUnit, 'factor' => 18],
        ], $this->bagUnit);

        $result = UnitQty::format($product, 5);
        $this->assertSame('5 ถุง', $result);
    }

    /**
     * 4. ไม่มีหน่วยใหญ่: 10 ถุง
     */
    public function test_format_without_larger_unit(): void
    {
        $product = $this->makeProductWithBarcodes([], $this->bagUnit);

        $result = UnitQty::format($product, 10);
        $this->assertSame('10 ถุง', $result);
    }

    /**
     * 5. ทศนิยม (สินค้าชั่ง): แสดงแค่หน่วยหลัก
     */
    public function test_format_decimal_quantity_shows_only_base_unit(): void
    {
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->crateUnit, 'factor' => 18],
        ], $this->kiloUnit);

        $result = UnitQty::format($product, 2.5);
        $this->assertSame('2.5 กก.', $result);
    }

    /**
     * 6. ชื่อหน่วยเสีย: ไม่นำมาแสดงเป็นหน่วยใหญ่
     */
    public function test_format_corrupted_unit_name_is_ignored(): void
    {
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->corruptedUnit, 'factor' => 10], // factor 10 แต่ชื่อ 'ǧ' เสีย
        ], $this->bagUnit);

        $result = UnitQty::format($product, 30);
        $this->assertSame('30 ถุง', $result);
    }

    /**
     * 7. Factor ไม่ใช่จำนวนเต็ม: ไม่นำมาเป็นหน่วยใหญ่
     */
    public function test_format_fractional_factor_is_ignored(): void
    {
        $pack = new ProductUnit(['name' => 'แพ็ค', 'code' => 'PACK', 'qty_per_base_unit' => 1.5]);
        $pack->id = 9;

        $product = $this->makeProductWithBarcodes([
            ['unit' => $pack, 'factor' => 1.5],
        ], $this->bagUnit);

        $result = UnitQty::format($product, 30);
        $this->assertSame('30 ถุง', $result);
    }

    /**
     * 8. ถ้าบรรทัดมี unit_id ให้ใช้หน่วยนั้นก่อน
     */
    public function test_format_prefers_specified_unit_id(): void
    {
        // มีทั้งกล่อง (factor 6) และลัง (factor 18)
        $product = $this->makeProductWithBarcodes([
            ['unit' => $this->boxUnit, 'factor' => 6],
            ['unit' => $this->crateUnit, 'factor' => 18],
        ], $this->bagUnit);

        // ถ้าไม่ระบุ unit_id จะเลือกตัว factor มากสุด (ลัง 18): 18 ถุง = 1 ลัง
        $this->assertSame('18 ถุง (1 ลัง)', UnitQty::format($product, 18));

        // ฝ่ายขาย 13 พ.ย.: "เลือกตัวไหน ตัวนั้นต้องแสดง" — หน่วยที่เลือกขึ้นก่อน หน่วยหลักอยู่ในวงเล็บ
        $this->assertSame('3 กล่อง (18 ถุง)', UnitQty::format($product, 18, $this->boxUnit->id));
        $this->assertSame('3 กล่อง 2 ถุง (20 ถุง)', UnitQty::format($product, 20, $this->boxUnit->id));
        // เลือกหน่วยหลัก (ถุง) ก็แสดงถุงอย่างเดียว ไม่แปลงเป็นลังให้เอง
        $this->assertSame('18 ถุง', UnitQty::format($product, 18, $this->bagUnit->id));
    }
}
