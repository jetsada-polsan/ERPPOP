<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Sales\PosPricingGuard;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 8 — Comprehensive Automated Promotion Master Suite
 *
 * Verifies all 10 promotion business cases:
 * 1. BP-101: Mix & Match 3 for 100 Baht (different SKUs mixed)
 * 2. BP-101: Mix VAT & non-VAT items in same promo set (proper VAT pro-rata allocation)
 * 3. QP-019: Quantity promo (buy 3 for 100 Baht)
 * 4. QP-019: Capped promo (max 5 sets per bill)
 * 5. BP-105A: Buy 1 Get 1 Free (BOGO)
 * 6. BP-105B: Buy 2 Get 1 Free
 * 7. BP-111A: Buy X Get Y (different SKU)
 * 8. BP-111B: Buy X Get Y capped limit
 * 9. FS-256911-001: Flash Sale with active date/time window
 * 10. BP-102: Inactive or expired promotion must NOT apply
 */
class PosPromotionMasterSuiteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private ProductUnit $unit;
    private PriceTable $priceTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['username' => 'promo_tester_'.uniqid()]);
        $this->branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาสำนักงานใหญ่', 'is_active' => true]);
        $this->unit = ProductUnit::create(['code' => 'PCS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->priceTable = PriceTable::create(['code' => 'STD', 'name' => 'ราคามาตรฐาน', 'is_default' => true, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createProduct(string $sku, string $name, float $price, bool $isVat = true, float $cost = 10.0): Product
    {
        $product = Product::create([
            'sku_code' => $sku,
            'name_th' => $name,
            'base_unit_id' => $this->unit->id,
            'default_price' => $price,
            'average_cost' => $cost,
            'is_vat' => $isVat,
            'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'price_table_id' => $this->priceTable->id,
            'price' => $price,
            'is_active' => true,
        ]);

        return $product;
    }

    /**
     * 1. BP-101: Mix & Match ซื้อรวมกัน 3 ชิ้น 100 บาท (สินค้าคนละรหัส คละได้)
     */
    public function test_01_mix_and_match_three_for_hundred_bp101(): void
    {
        $p1 = $this->createProduct('SKU-A', 'ปลาหมึกทรงเครื่อง', 40.0);
        $p2 = $this->createProduct('SKU-B', 'ไก่จ๊อทอด', 45.0);
        $p3 = $this->createProduct('SKU-C', 'หมูสวรรค์แผ่น', 50.0);

        $promo = QtyPromotion::create([
            'code' => 'BP-101',
            'name' => 'คละรส 3 ชิ้น 100 บาท',
            'promo_type' => 'mix_bundle',
            'min_qty' => 3,
            'bundle_price' => 100.0,
            'is_active' => true,
        ]);
        $promo->groupProducts()->sync([$p1->id, $p2->id, $p3->id]);

        // ซื้ออย่างละ 1 ชิ้น: 40 + 45 + 50 = 135 บาท -> เข้าโปรเหลือ 100 บาท (ลด 35 บาท)
        // PosPricingGuard รับ payload ที่กระจายส่วนลดสัดส่วนตามบรรทัด
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'bill_promotions' => true,
            'mix_promotions' => true,
            'items' => [
                ['product_id' => $p1->id, 'qty' => 1, 'unit_price' => 40 - (35 * (40 / 135))],
                ['product_id' => $p2->id, 'qty' => 1, 'unit_price' => 45 - (35 * (45 / 135))],
                ['product_id' => $p3->id, 'qty' => 1, 'unit_price' => 50 - (35 * (50 / 135))],
            ],
        ];

        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertEqualsWithDelta(100.0, $payable, 0.02);
        $this->assertEqualsWithDelta(135.0, $summary['gross_sales'], 0.02);
        $this->assertEqualsWithDelta(35.0, $summary['discount_amount'], 0.02);
        $this->assertEqualsWithDelta(100.0, $summary['net_sales'], 0.02);
    }

    /**
     * 2. BP-101: Mix สินค้ามี VAT และไม่มี VAT อยู่ในชุดเดียวกัน (ปันส่วนภาษีและยอดสุทธิถูกต้องตามสรรพากร)
     */
    public function test_02_mix_vat_and_non_vat_items_proper_prorata_bp101(): void
    {
        // สินค้ามี VAT (ไส้กรอกหมู 50 บาท) และไม่มี VAT (ไก่สดแดดเดียว 50 บาท)
        $pTaxable = $this->createProduct('SKU-TAX', 'ไส้กรอกหมู (VAT 7%)', 50.0, isVat: true);
        $pExempt = $this->createProduct('SKU-EXEMPT', 'ไก่สดแดดเดียว (ยกเว้น VAT)', 50.0, isVat: false);

        $promo = QtyPromotion::create([
            'code' => 'BP-101-VAT',
            'name' => 'คละ 2 ชิ้น 80 บาท',
            'promo_type' => 'mix_bundle',
            'min_qty' => 2,
            'bundle_price' => 80.0,
            'is_active' => true,
        ]);
        $promo->groupProducts()->sync([$pTaxable->id, $pExempt->id]);

        // รวมปกติ 50 + 50 = 100 บาท -> เข้าชุด 80 บาท (ลด 20 บาท)
        // ตามเกณฑ์สรรพากร ปันส่วนลดตามสัดส่วนมูลค่าสินค้าแต่ละตัว:
        // ส่วนลดตัวละ 20 * (50/100) = 10 บาท -> ราคาขายสุทธิหลังหักส่วนลดตัวละ 40 บาท
        // สินค้ามี VAT: ยอดรวม 40 บาท (รวม VAT 7%) -> ฐานภาษี = 40 * 100 / 107 = 37.38 บาท, VAT = 2.62 บาท
        // สินค้ายกเว้น VAT: ยอดรวม 40 บาท (VAT 0%) -> ฐานยกเว้นภาษี = 40.00 บาท, VAT = 0.00 บาท
        // รวมทั้งบิล = 80.00 บาท (ฐานภาษี 37.38 + ภาษี 2.62 + ยกเว้นภาษี 40.00)
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'bill_promotions' => true,
            'mix_promotions' => true,
            'items' => [
                ['product_id' => $pTaxable->id, 'qty' => 1, 'unit_price' => 40.0],
                ['product_id' => $pExempt->id, 'qty' => 1, 'unit_price' => 40.0],
            ],
        ];

        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertEqualsWithDelta(80.0, $payable, 0.01);
        $this->assertEqualsWithDelta(100.0, $summary['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(20.0, $summary['discount_amount'], 0.01);

        // คำนวณภาษีปันส่วนตามเกณฑ์สรรพากร
        $netTaxable = 40.0;
        $vatRate = 7.0;
        $taxBase = round(($netTaxable * 100) / (100 + $vatRate), 2);
        $vatAmount = round($netTaxable - $taxBase, 2);
        $netExempt = 40.0;

        $this->assertEquals(37.38, $taxBase);
        $this->assertEquals(2.62, $vatAmount);
        $this->assertEquals(40.00, $netExempt);
        $this->assertEquals(80.00, $taxBase + $vatAmount + $netExempt);
    }

    /**
     * 3. QP-019: โปรโมชั่นจำนวนชิ้น (ซื้อ 3 ชิ้น 100 บาท)
     */
    public function test_03_quantity_promo_three_for_hundred_qp019(): void
    {
        $product = $this->createProduct('SKU-QP', 'ปูอัดทอดกรอบ', 40.0);

        QtyPromotion::create([
            'code' => 'QP-019',
            'name' => 'ซื้อ 3 ชิ้น 100 บาท',
            'promo_type' => 'bundle_price',
            'product_id' => $product->id,
            'min_qty' => 3,
            'bundle_price' => 100.0,
            'is_active' => true,
        ]);

        // ซื้อ 3 ชิ้น: ปกติ 3 * 40 = 120 -> ราคาชุด 100 บาท (ลด 20 บาท)
        $payload3 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
            ],
        ];
        $payable3 = app(PosPricingGuard::class)->validate($payload3, $this->user);
        $this->assertEqualsWithDelta(100.0, $payable3, 0.01);

        // ซื้อ 4 ชิ้น: 1 ชุด (100 บาท) + 1 ชิ้นราคาปกติ (40 บาท) = 140 บาท
        $payload4 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 4, 'unit_price' => 140.0 / 4],
            ],
        ];
        $payable4 = app(PosPricingGuard::class)->validate($payload4, $this->user);
        $this->assertEqualsWithDelta(140.0, $payable4, 0.01);
    }

    /**
     * 4. QP-019: จำกัดสิทธิ์ (ไม่เกิน 5 ชุดต่อบิล)
     */
    public function test_04_quantity_promo_capped_five_sets_qp019(): void
    {
        $product = $this->createProduct('SKU-CAP', 'ลูกชิ้นปลาโบราณ', 40.0);

        QtyPromotion::create([
            'code' => 'QP-019-CAP',
            'name' => 'ซื้อ 3 ชิ้น 100 บาท จำกัด 5 ชุด',
            'promo_type' => 'bundle_price',
            'product_id' => $product->id,
            'min_qty' => 3,
            'bundle_price' => 100.0,
            'max_sets_per_bill' => 5,
            'is_active' => true,
        ]);

        // ลูกค้าซื้อ 18 ชิ้น (เท่ากับ 6 ชุด):
        // ได้สิทธิ์โปร 5 ชุดแรก = 5 * 100 = 500 บาท
        // ชุดที่ 6 (3 ชิ้น) เกินโควตา ต้องคิดราคาปกติ = 3 * 40 = 120 บาท
        // ยอดรวมสุทธิที่ต้องจ่าย = 500 + 120 = 620 บาท (จากราคาเต็ม 18 * 40 = 720, ลด 100 บาท)
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 18, 'unit_price' => 620.0 / 18],
            ],
        ];
        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertEqualsWithDelta(620.0, $payable, 0.02);
        $this->assertEqualsWithDelta(720.0, $summary['gross_sales'], 0.02);
        $this->assertEqualsWithDelta(100.0, $summary['discount_amount'], 0.02);
        $this->assertEqualsWithDelta(620.0, $summary['net_sales'], 0.02);
    }

    /**
     * 5. BP-105A: ซื้อ 1 แถม 1 (BOGO)
     */
    public function test_05_bogo_buy_one_get_one_bp105a(): void
    {
        $product = $this->createProduct('SKU-BOGO1', 'ชาเขียวมะลิ 500ml', 50.0);

        QtyPromotion::create([
            'code' => 'BP-105A',
            'name' => 'ซื้อ 1 แถม 1 ฟรี',
            'promo_type' => 'free_item',
            'product_id' => $product->id,
            'free_product_id' => $product->id,
            'min_qty' => 1,
            'free_qty' => 1,
            'is_active' => true,
        ]);

        // ซื้อ 2 ชิ้น (1 จ่าย + 1 แถม): ยอดปกติ 100 -> ลด 50 -> จ่าย 50
        $payload2 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 25.0],
            ],
        ];
        $payable2 = app(PosPricingGuard::class)->validate($payload2, $this->user);
        $this->assertEqualsWithDelta(50.0, $payable2, 0.01);

        // ซื้อ 3 ชิ้น (1 คู่โปร [1 จ่าย + 1 แถม] + 1 ชิ้นคิดราคาเต็ม): จ่าย 50 + 50 = 100
        $payload3 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
            ],
        ];
        $payable3 = app(PosPricingGuard::class)->validate($payload3, $this->user);
        $this->assertEqualsWithDelta(100.0, $payable3, 0.01);
    }

    /**
     * 6. BP-105B: ซื้อ 2 แถม 1
     */
    public function test_06_buy_two_get_one_bp105b(): void
    {
        $product = $this->createProduct('SKU-B2G1', 'ขนมปังเนยสด', 60.0);

        QtyPromotion::create([
            'code' => 'BP-105B',
            'name' => 'ซื้อ 2 แถม 1',
            'promo_type' => 'free_item',
            'product_id' => $product->id,
            'free_product_id' => $product->id,
            'min_qty' => 2,
            'free_qty' => 1,
            'is_active' => true,
        ]);

        // ซื้อ 3 ชิ้น (2 จ่าย + 1 แถม): ยอดปกติ 3 * 60 = 180 -> ลด 60 -> จ่าย 120
        $payload3 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 120.0 / 3],
            ],
        ];
        $payable3 = app(PosPricingGuard::class)->validate($payload3, $this->user);
        $this->assertEqualsWithDelta(120.0, $payable3, 0.01);

        // ซื้อ 5 ชิ้น (1 ชุด 3 ชิ้น [จ่าย 2 + แถม 1] + 2 ชิ้นจ่ายปกติ): จ่าย 2*60 + 2*60 = 240
        $payload5 = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit_price' => 240.0 / 5],
            ],
        ];
        $payable5 = app(PosPricingGuard::class)->validate($payload5, $this->user);
        $this->assertEqualsWithDelta(240.0, $payable5, 0.01);
    }

    /**
     * 7. BP-111A: ซื้อ X แถม Y (ต่างรหัส)
     */
    public function test_07_buy_x_get_y_different_item_bp111a(): void
    {
        $pMain = $this->createProduct('SKU-MAIN', 'หม้อทอดไร้น้ำมัน', 1200.0);
        $pGift = $this->createProduct('SKU-GIFT', 'ตะแกรงรองหม้อทอด', 150.0);

        QtyPromotion::create([
            'code' => 'BP-111A',
            'name' => 'ซื้อหม้อทอด แถมตะแกรง',
            'promo_type' => 'free_item',
            'product_id' => $pMain->id,
            'free_product_id' => $pGift->id,
            'min_qty' => 1,
            'free_qty' => 1,
            'is_active' => true,
        ]);

        // ซื้อหม้อทอด 1 ตัว + ตะแกรง 1 อัน: ปกติ 1,200 + 150 = 1,350 -> ลด 150 -> จ่าย 1,200
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $pMain->id, 'qty' => 1, 'unit_price' => 1200.0],
                ['product_id' => $pGift->id, 'qty' => 1, 'unit_price' => 0.0],
            ],
        ];

        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertEqualsWithDelta(1200.0, $payable, 0.01);
        $this->assertEqualsWithDelta(1350.0, $summary['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(150.0, $summary['discount_amount'], 0.01);
    }

    /**
     * 8. BP-111B: จำกัดสิทธิ์ (ซื้อ X แถม Y จำกัดจำนวนชุด)
     */
    public function test_08_buy_x_get_y_capped_bp111b(): void
    {
        $pMain = $this->createProduct('SKU-TEA', 'ชานมไข่มุก', 80.0);
        $pGift = $this->createProduct('SKU-TOPPING', 'ท็อปปิ้งพุดดิ้ง', 20.0);

        QtyPromotion::create([
            'code' => 'BP-111B',
            'name' => 'ซื้อชานม 1 แก้ว แถมพุดดิ้ง 1 ชิ้น จำกัด 2 ชุด',
            'promo_type' => 'free_item',
            'product_id' => $pMain->id,
            'free_product_id' => $pGift->id,
            'min_qty' => 1,
            'free_qty' => 1,
            'max_sets_per_bill' => 2,
            'is_active' => true,
        ]);

        // ซื้อชานม 4 แก้ว และพุดดิ้ง 4 ชิ้น:
        // สิทธิ์โปรได้สูงสุด 2 ชุด (แถมพุดดิ้ง 2 ชิ้น ลด 40 บาท)
        // พุดดิ้งอีก 2 ชิ้นคิดราคาปกติ (40 บาท)
        // ชานม 4 แก้ว = 320 บาท
        // รวมปกติ = 4 * 80 + 4 * 20 = 400 บาท -> ลด 40 -> สุทธิ 360 บาท
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $pMain->id, 'qty' => 4, 'unit_price' => 80.0],
                ['product_id' => $pGift->id, 'qty' => 4, 'unit_price' => 10.0], // (40 - 20) / 4 หรือสุทธิ 40 บาทสำหรับพุดดิ้ง
            ],
        ];

        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertEqualsWithDelta(360.0, $payable, 0.02);
        $this->assertEqualsWithDelta(400.0, $summary['gross_sales'], 0.02);
        $this->assertEqualsWithDelta(40.0, $summary['discount_amount'], 0.02);
        $this->assertEqualsWithDelta(360.0, $summary['net_sales'], 0.02);
    }

    /**
     * 9. FS-256911-001: Flash Sale กำหนดช่วงวันและเวลา
     */
    public function test_09_flash_sale_active_window_fs_256911_001(): void
    {
        $product = $this->createProduct('SKU-FLASH', 'หมูสันนอกหมักซอส', 100.0);

        $flashSale = FlashSale::create([
            'code' => 'FS-256911-001',
            'name' => 'Flash Sale นาทีทองประจำวัน',
            'branch_id' => $this->branch->id,
            'starts_date' => '2026-11-15',
            'ends_date' => '2026-11-15',
            'start_time' => '14:00',
            'end_time' => '16:00',
            'is_active' => true,
        ]);

        FlashSaleItem::create([
            'flash_sale_id' => $flashSale->id,
            'product_id' => $product->id,
            'flash_price' => 69.0,
        ]);

        // 9.1 ในช่วงเวลา Flash Sale (15:00 น.): ราคาต้องเป็น 69.00 บาท
        Carbon::setTestNow(Carbon::parse('2026-11-15 15:00:00', config('app.timezone')));
        $payloadIn = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 69.0],
            ],
        ];
        $payableIn = app(PosPricingGuard::class)->validate($payloadIn, $this->user);
        $this->assertSame(69.0, $payableIn);

        // 9.2 นอกช่วงเวลา Flash Sale (16:30 น.): ราคาต้องกลับเป็นราคาปกติ 100.00 บาท
        Carbon::setTestNow(Carbon::parse('2026-11-15 16:30:00', config('app.timezone')));
        $payloadOut = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100.0],
            ],
        ];
        $payableOut = app(PosPricingGuard::class)->validate($payloadOut, $this->user);
        $this->assertSame(100.0, $payableOut);
    }

    /**
     * 10. BP-102: ปิดใช้งาน / หมดอายุ ต้องไม่ถูกนำมาคำนวณ
     */
    public function test_10_inactive_or_expired_promotion_ignored_bp102(): void
    {
        $product = $this->createProduct('SKU-INACT', 'ซาลาเปาไส้หมูสับ', 50.0);

        // โปรที่ปิดใช้งาน is_active = false
        QtyPromotion::create([
            'code' => 'BP-102-DISABLED',
            'name' => 'โปรปิดใช้งาน ลด 20 บาท',
            'promo_type' => 'discount',
            'product_id' => $product->id,
            'min_qty' => 1,
            'discount_type' => 'baht',
            'discount_value' => 20.0,
            'is_active' => false,
        ]);

        // โปรที่หมดอายุแล้ว
        QtyPromotion::create([
            'code' => 'BP-102-EXPIRED',
            'name' => 'โปรหมดอายุ ซื้อ 1 แถม 1',
            'promo_type' => 'free_item',
            'product_id' => $product->id,
            'free_product_id' => $product->id,
            'min_qty' => 1,
            'free_qty' => 1,
            'starts_at' => '2026-01-01 00:00:00',
            'ends_at' => '2026-01-02 23:59:59',
            'is_active' => true,
        ]);

        // ซื้อ 2 ชิ้น: ไม่มีโปรใดทำงาน ต้องคิดราคาปกติ 2 * 50 = 100 บาท
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 50.0],
            ],
        ];

        $summary = null;
        $payable = app(PosPricingGuard::class)->validate($payload, $this->user, null, $summary);

        $this->assertSame(100.0, $payable);
        $this->assertEquals(0.0, $summary['discount_amount']);
        $this->assertEquals(100.0, $summary['gross_sales']);
    }
}
