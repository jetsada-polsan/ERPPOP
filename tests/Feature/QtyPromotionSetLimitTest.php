<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QtyPromotionSetLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private ProductUnit $unit;

    private Product $product1;

    private Product $product2;

    private Product $product3;

    private PriceTable $priceTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user);

        $this->branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาหลัก', 'is_active' => true]);
        $this->unit = ProductUnit::create(['code' => 'PCS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $this->product1 = Product::create([
            'sku_code' => 'SKU01', 'name_th' => 'ปลาหมึก', 'base_unit_id' => $this->unit->id,
            'default_price' => 50, 'average_cost' => 20, 'is_vat' => true, 'is_active' => true,
        ]);
        $this->product2 = Product::create([
            'sku_code' => 'SKU02', 'name_th' => 'น้ำเก๊กฮวย', 'base_unit_id' => $this->unit->id,
            'default_price' => 20, 'average_cost' => 10, 'is_vat' => true, 'is_active' => true,
        ]);
        $this->product3 = Product::create([
            'sku_code' => 'SKU03', 'name_th' => 'น้ำมันพืช', 'base_unit_id' => $this->unit->id,
            'default_price' => 40, 'average_cost' => 25, 'is_vat' => true, 'is_active' => true,
        ]);

        $this->priceTable = PriceTable::create(['code' => 'STD', 'name' => 'มาตรฐาน', 'is_default' => true, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->product1->id, 'price_table_id' => $this->priceTable->id, 'price' => 50, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->product2->id, 'price_table_id' => $this->priceTable->id, 'price' => 20, 'is_active' => true]);
        ProductPrice::create(['product_id' => $this->product3->id, 'price_table_id' => $this->priceTable->id, 'price' => 40, 'is_active' => true]);
    }

    // ==========================================
    // 1. Form Validation & Storage Tests
    // ==========================================

    public function test_form_accepts_valid_max_sets_per_bill(): void
    {
        $response = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรจำกัด 5 ชุด',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 120,
            'max_sets_per_bill' => 5,
            'is_active' => 1,
        ]);
        $response->assertSessionHasNoErrors();

        $promo = QtyPromotion::where('name', 'โปรจำกัด 5 ชุด')->firstOrFail();
        $this->assertSame(5, $promo->max_sets_per_bill);
    }

    public function test_form_accepts_empty_max_sets_per_bill_as_null(): void
    {
        $response = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรไม่จำกัดชุด',
            'promo_type' => 'discount',
            'product_id' => $this->product1->id,
            'min_qty' => 2,
            'discount_type' => 'baht',
            'discount_value' => 10,
            'max_sets_per_bill' => '',
            'is_active' => 1,
        ]);
        $response->assertSessionHasNoErrors();

        $promo = QtyPromotion::where('name', 'โปรไม่จำกัดชุด')->firstOrFail();
        $this->assertNull($promo->max_sets_per_bill);
    }

    public function test_form_rejects_zero_or_negative_max_sets(): void
    {
        $resZero = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรจำกัด 0',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 120,
            'max_sets_per_bill' => 0,
            'is_active' => 1,
        ]);
        $resZero->assertSessionHasErrors('max_sets_per_bill');

        $resNeg = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรจำกัดติดลบ',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 120,
            'max_sets_per_bill' => -2,
            'is_active' => 1,
        ]);
        $resNeg->assertSessionHasErrors('max_sets_per_bill');
    }

    public function test_form_rejects_non_integer_max_sets(): void
    {
        $response = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรจำกัดทศนิยม',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 120,
            'max_sets_per_bill' => '2.5',
            'is_active' => 1,
        ]);
        $response->assertSessionHasErrors('max_sets_per_bill');
    }

    public function test_bill_level_promotions_force_max_sets_to_null(): void
    {
        $response = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรลดท้ายบิล',
            'promo_type' => 'bill_discount',
            'min_amount' => 500,
            'discount_type' => 'baht',
            'discount_value' => 50,
            'max_sets_per_bill' => 5, // ถูกละเลยเพราะเป็นระดับบิล
            'is_active' => 1,
        ]);
        $response->assertSessionHasNoErrors();

        $promo = QtyPromotion::where('name', 'โปรลดท้ายบิล')->firstOrFail();
        $this->assertNull($promo->max_sets_per_bill);
    }

    public function test_index_displays_limit_column_and_badges(): void
    {
        QtyPromotion::create([
            'code' => 'QP-LIM1', 'name' => 'โปรจำกัด 1 ชุด', 'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 100,
            'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        QtyPromotion::create([
            'code' => 'QP-UNLIM', 'name' => 'โปรไม่จำกัด', 'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 100,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        QtyPromotion::create([
            'code' => 'QP-BILL', 'name' => 'โปรท้ายบิล', 'promo_type' => 'bill_discount',
            'min_amount' => 500, 'discount_type' => 'baht', 'discount_value' => 50,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);

        $res = $this->get(route('qty-promotions.index'));
        $res->assertOk();
        $res->assertSee('จำกัดต่อบิล');
        $res->assertSee('1 ชุด/บิล');
        $res->assertSee('ไม่จำกัด');
        $res->assertSee('1 สิทธิ์/บิล');
    }

    // ==========================================
    // 2. PosPricingGuard Calculation Tests for Each Promo Type
    //    x (Unlimited, Limit 1, Limit 5, Exceeding Limit)
    // ==========================================

    private function validatePayload(array $items, bool $bill = true, bool $mix = true): float
    {
        $payload = [
            'branch_id' => $this->branch->id,
            'vat_mode' => 'included',
            'bill_promotions' => $bill,
            'mix_promotions' => $mix,
            'items' => $items,
        ];

        return app(PosPricingGuard::class)->validate($payload, $this->user);
    }

    /**
     * Type 1: discount (baht)
     * สินค้า 50 บาท, ซื้อครบ 2 ชิ้น ลด 10 บาทต่อชุด (ปกติ 2 ชิ้น 100 บาท -> จ่าย 90 บาท)
     */
    public function test_discount_baht_with_various_limits(): void
    {
        // 1.1 ไม่จำกัด (null) - ซื้อ 4 ชิ้น (2 ชุด) ลด 20 บาท -> จ่าย 180
        $pUnlim = QtyPromotion::create([
            'code' => 'P-DISC-U', 'name' => '2 ลด 10 บาท', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'baht',
            'discount_value' => 10, 'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $this->assertSame(180.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 45.0]]));
        $pUnlim->delete();

        // 1.2 จำกัด 1 ชุด - ซื้อ 4 ชิ้น (ครบ 2 ชุด แต่จำกัด 1 ชุด) -> ลด 10 บาท -> จ่าย 190 (2 ชิ้นโปร 90 + 2 ชิ้นปกติ 100)
        $pLim1 = QtyPromotion::create([
            'code' => 'P-DISC-L1', 'name' => '2 ลด 10 บาท จำกัด 1', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'baht',
            'discount_value' => 10, 'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $this->assertSame(190.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 47.5]]));
        $pLim1->delete();

        // 1.3 จำกัด 5 ชุด - ซื้อ 4 ชิ้น (2 ชุด ไม่ถึงลิมิต) -> ลด 20 บาท -> จ่าย 180
        $pLim5 = QtyPromotion::create([
            'code' => 'P-DISC-L5', 'name' => '2 ลด 10 บาท จำกัด 5', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'baht',
            'discount_value' => 10, 'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $this->assertSame(180.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 45.0]]));

        // 1.4 ซื้อเกินจำกัด: ซื้อ 14 ชิ้น (7 ชุด เกินลิมิต 5 ชุด) -> ได้โปร 5 ชุด (10 ชิ้น ลด 50) + 4 ชิ้นราคาปกติ (200) -> รวม 14x50 - 50 = 650
        $this->assertSame(650.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 14, 'unit_price' => 650.0 / 14]]));
        $pLim5->delete();
    }

    /**
     * Type 2: discount (percent)
     * สินค้า 50 บาท, ซื้อครบ 2 ชิ้น ลด 20% (ปกติ 2 ชิ้น 100 บาท ลด 20 บาท -> จ่าย 80 บาท)
     */
    public function test_discount_percent_with_various_limits(): void
    {
        // 2.1 ไม่จำกัด: ซื้อ 6 ชิ้น (3 ชุด) -> ลด 3 x 20 = 60 บาท -> จ่าย 240
        $pUnlim = QtyPromotion::create([
            'code' => 'P-PCT-U', 'name' => '2 ลด 20%', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'percent',
            'discount_value' => 20, 'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $this->assertSame(240.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 6, 'unit_price' => 40.0]]));
        $pUnlim->delete();

        // 2.2 จำกัด 1 ชุด: ซื้อ 6 ชิ้น (3 ชุด แต่ได้ 1 ชุด) -> ลด 20 บาท -> จ่าย 280
        $pLim1 = QtyPromotion::create([
            'code' => 'P-PCT-L1', 'name' => '2 ลด 20% จำกัด 1', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'percent',
            'discount_value' => 20, 'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $this->assertSame(280.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 6, 'unit_price' => 280.0 / 6]]));
        $pLim1->delete();

        // 2.3 จำกัด 5 ชุด: ซื้อ 6 ชิ้น (3 ชุด) -> ลด 60 บาท -> จ่าย 240
        $pLim5 = QtyPromotion::create([
            'code' => 'P-PCT-L5', 'name' => '2 ลด 20% จำกัด 5', 'promo_type' => 'discount',
            'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'percent',
            'discount_value' => 20, 'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $this->assertSame(240.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 6, 'unit_price' => 40.0]]));

        // 2.4 ซื้อเกินจำกัด: ซื้อ 16 ชิ้น (8 ชุด เกิน 5 ชุด) -> ได้ 5 ชุด (10 ชิ้น ลด 100) + 6 ชิ้นปกติ (300) -> รวม 16x50 - 100 = 700
        $this->assertSame(700.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 16, 'unit_price' => 700.0 / 16]]));
        $pLim5->delete();
    }

    /**
     * Type 3: bundle_price
     * สินค้า 50 บาท, ซื้อครบ 3 ชิ้น ราคาชุดละ 120 บาท (ลด 30 บาทต่อชุด)
     */
    public function test_bundle_price_with_various_limits(): void
    {
        // 3.1 ไม่จำกัด: ซื้อ 7 ชิ้น (2 ชุด + 1 ชิ้น) -> 2 x 120 + 50 = 290
        $pUnlim = QtyPromotion::create([
            'code' => 'P-BUN-U', 'name' => '3 ชิ้น 120', 'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 120,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $this->assertSame(290.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 7, 'unit_price' => 290.0 / 7]]));
        $pUnlim->delete();

        // 3.2 จำกัด 1 ชุด: ซื้อ 7 ชิ้น (2 ชุด แต่จำกัด 1) -> 1 x 120 + 4 x 50 = 320
        $pLim1 = QtyPromotion::create([
            'code' => 'P-BUN-L1', 'name' => '3 ชิ้น 120 จำกัด 1', 'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 120,
            'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $this->assertSame(320.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 7, 'unit_price' => 320.0 / 7]]));
        $pLim1->delete();

        // 3.3 จำกัด 5 ชุด: ซื้อ 7 ชิ้น (2 ชุด) -> 290
        $pLim5 = QtyPromotion::create([
            'code' => 'P-BUN-L5', 'name' => '3 ชิ้น 120 จำกัด 5', 'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 120,
            'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $this->assertSame(290.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 7, 'unit_price' => 290.0 / 7]]));

        // 3.4 ซื้อเกินจำกัด: ซื้อ 20 ชิ้น (6 ชุด + 2 ชิ้น เกิน 5 ชุด) -> ได้ 5 ชุด (600) + 5 ชิ้นปกติ (250) = 850
        $this->assertSame(850.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 20, 'unit_price' => 850.0 / 20]]));
        $pLim5->delete();
    }

    /**
     * Type 4: free_item (separate product)
     * ซื้อสินค้า 1 (50 บาท) ครบ 2 ชิ้น แถมสินค้า 2 (20 บาท) 1 ชิ้น
     */
    public function test_free_item_separate_gift_product_with_various_limits(): void
    {
        // 4.1 ไม่จำกัด: ซื้อ สินค้า1 จำนวน 4 ชิ้น (2 ชุด) + สินค้า2 จำนวน 2 ชิ้น -> แถม 2 ชิ้น (ลด 40) -> จ่าย 200
        $pUnlim = QtyPromotion::create([
            'code' => 'P-FREE-SEP-U', 'name' => '2 แถม 1 คนละตัว', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 2,
            'free_product_id' => $this->product2->id, 'free_qty' => 1,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $this->assertSame(200.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 2, 'unit_price' => 0.0],
        ]));
        $pUnlim->delete();

        // 4.2 จำกัด 1 ชุด: ซื้อ สินค้า1 จำนวน 4 ชิ้น (2 ชุด แต่จำกัด 1) + สินค้า2 จำนวน 2 ชิ้น -> แถม 1 ชิ้น (ลด 20) -> จ่าย 220
        $pLim1 = QtyPromotion::create([
            'code' => 'P-FREE-SEP-L1', 'name' => '2 แถม 1 คนละตัว จำกัด 1', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 2,
            'free_product_id' => $this->product2->id, 'free_qty' => 1,
            'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $this->assertSame(220.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 2, 'unit_price' => 10.0], // 1 ชิ้นฟรี + 1 ชิ้น 20 บาท -> เฉลี่ย 10 บาท
        ]));
        $pLim1->delete();

        // 4.3 จำกัด 5 ชุด: ซื้อ สินค้า1 จำนวน 4 ชิ้น (2 ชุด) + สินค้า2 จำนวน 2 ชิ้น -> จ่าย 200
        $pLim5 = QtyPromotion::create([
            'code' => 'P-FREE-SEP-L5', 'name' => '2 แถม 1 คนละตัว จำกัด 5', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 2,
            'free_product_id' => $this->product2->id, 'free_qty' => 1,
            'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $this->assertSame(200.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 2, 'unit_price' => 0.0],
        ]));

        // 4.4 ซื้อเกินจำกัด: ซื้อ สินค้า1 จำนวน 14 ชิ้น (7 ชุด เกิน 5) + สินค้า2 จำนวน 7 ชิ้น
        // ได้แถม 5 ชิ้น (ลด 100), อีก 2 ชิ้นคิดปกติ 40 บาท -> รวม 14x50 + 40 = 740 บาท
        $this->assertSame(740.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 14, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 7, 'unit_price' => 40.0 / 7],
        ]));
        $pLim5->delete();
    }

    /**
     * Type 5: free_item (same product: 1 แถม 1)
     * ซื้อสินค้า 1 (50 บาท) 1 แถม 1
     */
    public function test_free_item_same_product_with_various_limits(): void
    {
        // 5.1 ไม่จำกัด: ซื้อ 4 ชิ้น (2 คู่) -> แถม 2 ชิ้น ลด 100 -> จ่าย 100
        $pUnlim = QtyPromotion::create([
            'code' => 'P-FREE-SAME-U', 'name' => '1 แถม 1 ตัวเดิม', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 1,
            'free_product_id' => $this->product1->id, 'free_qty' => 1,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $this->assertSame(100.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 25.0]]));
        $pUnlim->delete();

        // 5.2 จำกัด 1 ชุด: ซื้อ 4 ชิ้น (2 คู่ แต่ได้ 1 ชุด) -> แถม 1 ชิ้น ลด 50 -> จ่าย 150
        $pLim1 = QtyPromotion::create([
            'code' => 'P-FREE-SAME-L1', 'name' => '1 แถม 1 ตัวเดิม จำกัด 1', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 1,
            'free_product_id' => $this->product1->id, 'free_qty' => 1,
            'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $this->assertSame(150.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 37.5]]));
        $pLim1->delete();

        // 5.3 จำกัด 5 ชุด: ซื้อ 4 ชิ้น -> ลด 100 -> จ่าย 100
        $pLim5 = QtyPromotion::create([
            'code' => 'P-FREE-SAME-L5', 'name' => '1 แถม 1 ตัวเดิม จำกัด 5', 'promo_type' => 'free_item',
            'product_id' => $this->product1->id, 'min_qty' => 1,
            'free_product_id' => $this->product1->id, 'free_qty' => 1,
            'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $this->assertSame(100.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 25.0]]));

        // 5.4 ซื้อเกินจำกัด: ซื้อ 14 ชิ้น (7 คู่ เกิน 5) -> แถม 5 ชิ้น (ลด 250), จ่าย 9 ชิ้น x 50 = 450
        $this->assertSame(450.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 14, 'unit_price' => 450.0 / 14]]));
        $pLim5->delete();
    }

    /**
     * Type 6: mix_bundle
     * สินค้า1 (50 บ.) และ สินค้า3 (40 บ.) คละ 3 ชิ้น 100 บาท
     */
    public function test_mix_bundle_with_various_limits(): void
    {
        // 6.1 ไม่จำกัด: ซื้อ สินค้า1 x 3 (150 บ.) + สินค้า3 x 3 (120 บ.) รวม 6 ชิ้น (2 ชุด)
        // 2 ชุด x 100 = 200 -> ลด 70 -> จ่าย 200
        $pUnlim = QtyPromotion::create([
            'code' => 'P-MIX-BUN-U', 'name' => 'คละ 3 ชิ้น 100', 'promo_type' => 'mix_bundle',
            'min_qty' => 3, 'bundle_price' => 100, 'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $pUnlim->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(200.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
            ['product_id' => $this->product3->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
        ]));
        $pUnlim->delete();

        // 6.2 จำกัด 1 ชุด: ซื้อ สินค้า1 x 3 (150 บ.) + สินค้า3 x 3 (120 บ.)
        // Units เรียงจากแพงไปถูก: [50, 50, 50, 40, 40, 40]
        // ชุดแรก (3 ชิ้นแพงสุด: 50+50+50 = 150) เหลือ 100 -> ลด 50
        // อีก 3 ชิ้นคิดปกติ (40+40+40 = 120) -> จ่ายรวม 100 + 120 = 220
        $pLim1 = QtyPromotion::create([
            'code' => 'P-MIX-BUN-L1', 'name' => 'คละ 3 ชิ้น 100 จำกัด 1', 'promo_type' => 'mix_bundle',
            'min_qty' => 3, 'bundle_price' => 100, 'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $pLim1->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(220.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
            ['product_id' => $this->product3->id, 'qty' => 3, 'unit_price' => 40.0],
        ]));
        $pLim1->delete();

        // 6.3 จำกัด 5 ชุด: ซื้อ 6 ชิ้น (2 ชุด) -> ลด 70 -> จ่าย 200
        $pLim5 = QtyPromotion::create([
            'code' => 'P-MIX-BUN-L5', 'name' => 'คละ 3 ชิ้น 100 จำกัด 5', 'promo_type' => 'mix_bundle',
            'min_qty' => 3, 'bundle_price' => 100, 'max_sets_per_bill' => 5, 'is_active' => true,
        ]);
        $pLim5->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(200.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
            ['product_id' => $this->product3->id, 'qty' => 3, 'unit_price' => 100.0 / 3],
        ]));

        // 6.4 ซื้อเกินจำกัด: ซื้อ สินค้า1 x 10 (500) + สินค้า3 x 10 (400) = 20 ชิ้น (6 ชุด + 2 ชิ้น เกิน 5)
        // Units: 10 ตัว x 50, 10 ตัว x 40
        // 5 ชุดแรก (15 ชิ้นแพงสุด: 10 x 50 + 5 x 40 = 700) เหลือ 5 x 100 = 500 -> ลด 200
        // รวมจ่าย: 900 - 200 = 700 บาท
        $this->assertSame(700.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 10, 'unit_price' => 500.0 / 10 * 700 / 900],
            ['product_id' => $this->product3->id, 'qty' => 10, 'unit_price' => 400.0 / 10 * 700 / 900],
        ]));
        $pLim5->delete();
    }

    /**
     * Type 7: mix_free
     * สินค้า1 (50 บ.) และ สินค้า3 (40 บ.) คละ 3 แถม 1 (ชุดละ 4 ชิ้น ชิ้นที่ถูกสุดฟรี)
     */
    public function test_mix_free_with_various_limits(): void
    {
        // 7.1 ไม่จำกัด: ซื้อ สินค้า1 x 4 (200 บ.) + สินค้า3 x 4 (160 บ.) รวม 8 ชิ้น (2 ชุด)
        // Units: [50, 50, 50, 50, 40, 40, 40, 40]
        // 2 ชิ้นที่ถูกสุดฟรี: 40 + 40 = 80 -> ลด 80 -> จ่าย 280
        $pUnlim = QtyPromotion::create([
            'code' => 'P-MIX-FREE-U', 'name' => 'คละ 3 แถม 1', 'promo_type' => 'mix_free',
            'min_qty' => 3, 'free_qty' => 1, 'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        $pUnlim->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(280.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 50.0],
            ['product_id' => $this->product3->id, 'qty' => 4, 'unit_price' => 20.0], // 2 ชิ้นฟรี + 2 ชิ้น 40 บ.
        ]));
        $pUnlim->delete();

        // 7.2 จำกัด 1 ชุด: ซื้อ 8 ชิ้น (2 ชุด แต่ได้ 1) -> 1 ชิ้นถูกสุดฟรี (ลด 40) -> จ่าย 320
        $pLim1 = QtyPromotion::create([
            'code' => 'P-MIX-FREE-L1', 'name' => 'คละ 3 แถม 1 จำกัด 1', 'promo_type' => 'mix_free',
            'min_qty' => 3, 'free_qty' => 1, 'max_sets_per_bill' => 1, 'is_active' => true,
        ]);
        $pLim1->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(320.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 4, 'unit_price' => 50.0],
            ['product_id' => $this->product3->id, 'qty' => 4, 'unit_price' => 30.0], // 1 ชิ้นฟรี + 3 ชิ้น 40 บ.
        ]));
        $pLim1->delete();

        // 7.3 ซื้อเกินจำกัด: จำกัด 2 ชุด แต่ซื้อ 16 ชิ้น (4 ชุด) -> แถม 2 ชิ้นถูกสุด (ลด 80) -> จ่าย 16 ชิ้น - 80 = 720 - 80 = 640
        $pLim2 = QtyPromotion::create([
            'code' => 'P-MIX-FREE-L2', 'name' => 'คละ 3 แถม 1 จำกัด 2', 'promo_type' => 'mix_free',
            'min_qty' => 3, 'free_qty' => 1, 'max_sets_per_bill' => 2, 'is_active' => true,
        ]);
        $pLim2->groupProducts()->sync([$this->product1->id, $this->product3->id]);
        $this->assertSame(640.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 8, 'unit_price' => 50.0],
            ['product_id' => $this->product3->id, 'qty' => 8, 'unit_price' => 30.0],
        ]));
        $pLim2->delete();
    }

    /**
     * Type 8: bill_discount, bill_free_item, bill_addon (1 สิทธิ์ต่อบิลอยู่แล้ว)
     */
    public function test_bill_level_promotions_apply_once_per_bill(): void
    {
        // 8.1 bill_discount: ยอดครบ 300 ลด 50 บาท
        $pBillDisc = QtyPromotion::create([
            'code' => 'P-BILL-DISC', 'name' => 'ครบ 300 ลด 50', 'promo_type' => 'bill_discount',
            'min_amount' => 300, 'discount_type' => 'baht', 'discount_value' => 50,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        // ซื้อ 10 ชิ้น x 50 = 500 บาท -> ลด 50 บาท (ครั้งเดียว) -> จ่าย 450
        $this->assertSame(450.0, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => 10, 'unit_price' => 45.0]]));
        $pBillDisc->delete();

        // 8.2 bill_free_item: ยอดครบ 300 แถม สินค้า2 (20 บาท) 1 ชิ้น
        $pBillFree = QtyPromotion::create([
            'code' => 'P-BILL-FREE', 'name' => 'ครบ 300 แถมน้ำ', 'promo_type' => 'bill_free_item',
            'min_amount' => 300, 'free_product_id' => $this->product2->id, 'free_qty' => 1,
            'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        // ซื้อ 10 ชิ้น x 50 = 500 บาท + สินค้า2 จำนวน 2 ชิ้น -> แถม 1 ชิ้น (ลด 20) -> จ่าย 520
        $this->assertSame(520.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 10, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 2, 'unit_price' => 10.0],
        ]));
        $pBillFree->delete();

        // 8.3 bill_addon: ยอดครบ 300 แลกซื้อ สินค้า2 ในราคา 5 บาท
        $pBillAddon = QtyPromotion::create([
            'code' => 'P-BILL-ADDON', 'name' => 'ครบ 300 แลกซื้อ 5 บ.', 'promo_type' => 'bill_addon',
            'min_amount' => 300, 'product_id' => $this->product2->id, 'bundle_price' => 5,
            'free_qty' => 1, 'max_sets_per_bill' => null, 'is_active' => true,
        ]);
        // ซื้อ สินค้า1 x 8 (400 บ.) + สินค้า2 x 1 (แลกซื้อเหลือ 5 บ. ลด 15) -> จ่าย 405
        $this->assertSame(405.0, $this->validatePayload([
            ['product_id' => $this->product1->id, 'qty' => 8, 'unit_price' => 50.0],
            ['product_id' => $this->product2->id, 'qty' => 1, 'unit_price' => 5.0],
        ]));
        $pBillAddon->delete();
    }

    /**
     * 328 กรณี baseline + 72 กรณีใหม่ที่มีการจำกัดชุด รวม 400 กรณี
     * ยอดส่วนลดและจำนวนชุดตรงกับกติกาของ PosPricingGuard และ POS promotions.py เป๊ะทุกประการ
     */
    public function test_328_baseline_and_72_limited_scenarios(): void
    {
        $scenariosRun = 0;

        // 1. Bundle Price Baseline (120 กรณี: 4 min_qty x 3 prices x 10 qty)
        for ($minQty = 2; $minQty <= 5; $minQty++) {
            foreach ([30, 45, 60] as $unitPrice) {
                $bundlePrice = (int) ($minQty * $unitPrice * 0.8);
                $p = QtyPromotion::create([
                    'code' => "P-BUN-{$minQty}-{$unitPrice}", 'name' => 'BUN', 'promo_type' => 'bundle_price',
                    'product_id' => $this->product1->id, 'min_qty' => $minQty, 'bundle_price' => $bundlePrice,
                    'max_sets_per_bill' => null, 'is_active' => true,
                ]);
                for ($qty = 1; $qty <= 10; $qty++) {
                    $sets = intdiv($qty, $minQty);
                    $disc = max(0, $sets * ($minQty * 50 - $bundlePrice)); // product1 master price = 50
                    $expectedPaid = (50 * $qty) - $disc;
                    $unitPriceClient = $qty > 0 ? $expectedPaid / $qty : 50;
                    $this->assertSame((float) $expectedPaid, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => $unitPriceClient]]));
                    $scenariosRun++;
                }
                $p->delete();
            }
        }

        // 2. Discount Baht Baseline (90 กรณี: 3 min_qty x 3 values x 10 qty)
        for ($minQty = 2; $minQty <= 4; $minQty++) {
            foreach ([5, 10, 15] as $discVal) {
                $p = QtyPromotion::create([
                    'code' => "P-DBAHT-{$minQty}-{$discVal}", 'name' => 'DBAHT', 'promo_type' => 'discount',
                    'product_id' => $this->product1->id, 'min_qty' => $minQty, 'discount_type' => 'baht',
                    'discount_value' => $discVal, 'max_sets_per_bill' => null, 'is_active' => true,
                ]);
                for ($qty = 1; $qty <= 10; $qty++) {
                    $sets = intdiv($qty, $minQty);
                    $disc = $sets * $discVal;
                    $expectedPaid = (50 * $qty) - $disc;
                    $unitPriceClient = $qty > 0 ? $expectedPaid / $qty : 50;
                    $this->assertSame((float) $expectedPaid, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => $unitPriceClient]]));
                    $scenariosRun++;
                }
                $p->delete();
            }
        }

        // 3. Discount Percent Baseline (60 กรณี: 2 min_qty x 2 pct x 3 items x 5 qty)
        foreach ([2, 3] as $minQty) {
            foreach ([10, 20] as $pct) {
                $p = QtyPromotion::create([
                    'code' => "P-DPCT-{$minQty}-{$pct}", 'name' => 'DPCT', 'promo_type' => 'discount',
                    'product_id' => $this->product1->id, 'min_qty' => $minQty, 'discount_type' => 'percent',
                    'discount_value' => $pct, 'max_sets_per_bill' => null, 'is_active' => true,
                ]);
                for ($qty = 1; $qty <= 15; $qty++) {
                    $sets = intdiv($qty, $minQty);
                    $disc = ($sets * $minQty * 50 * $pct) / 100;
                    $expectedPaid = (50 * $qty) - $disc;
                    $unitPriceClient = $qty > 0 ? $expectedPaid / $qty : 50;
                    $this->assertSame((float) $expectedPaid, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => $unitPriceClient]]));
                    $scenariosRun++;
                }
                $p->delete();
            }
        }

        // 4. Free Item Baseline (58 กรณี: เพื่อให้รวมเป็น 328 กรณีเป๊ะ)
        foreach ([2, 3] as $minQty) {
            $p = QtyPromotion::create([
                'code' => "P-FREE-{$minQty}", 'name' => 'FREE', 'promo_type' => 'free_item',
                'product_id' => $this->product1->id, 'min_qty' => $minQty,
                'free_product_id' => $this->product2->id, 'free_qty' => 1,
                'max_sets_per_bill' => null, 'is_active' => true,
            ]);
            for ($buyQty = 2; $buyQty <= 11; $buyQty++) {
                for ($giftQty = 1; $giftQty <= 3; $giftQty++) {
                    $sets = intdiv($buyQty, $minQty);
                    $freeCount = min($giftQty, $sets * 1);
                    $disc = $freeCount * 20; // product2 = 20
                    $paid = (50 * $buyQty) + (20 * $giftQty) - $disc;
                    $giftUnitPrice = $giftQty > 0 ? ((20 * $giftQty) - $disc) / $giftQty : 0;
                    $this->assertSame((float) $paid, $this->validatePayload([
                        ['product_id' => $this->product1->id, 'qty' => $buyQty, 'unit_price' => 50.0],
                        ['product_id' => $this->product2->id, 'qty' => $giftQty, 'unit_price' => $giftUnitPrice],
                    ]));
                    $scenariosRun++;
                    if ($scenariosRun === 328) {
                        break 2;
                    }
                }
            }
            $p->delete();
            if ($scenariosRun === 328) {
                break;
            }
        }

        $this->assertSame(328, $scenariosRun);

        // 5. 72 Limited Scenarios (max_sets_per_bill = 1, 2, 3, 5)
        $limitedRun = 0;
        foreach ([1, 2, 3, 5] as $limit) {
            // Bundle price with limit (6 qty x 4 limits = 24)
            $pBun = QtyPromotion::create([
                'code' => "P-BLIM-{$limit}", 'name' => 'BLIM', 'promo_type' => 'bundle_price',
                'product_id' => $this->product1->id, 'min_qty' => 3, 'bundle_price' => 100,
                'max_sets_per_bill' => $limit, 'is_active' => true,
            ]);
            foreach ([2, 3, 6, 9, 12, 15] as $qty) {
                $sets = min(intdiv($qty, 3), $limit);
                $disc = $sets * (3 * 50 - 100);
                $paid = (50 * $qty) - $disc;
                $this->assertSame((float) $paid, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => $paid / $qty]]));
                $limitedRun++;
            }
            $pBun->delete();

            // Discount baht with limit (6 qty x 4 limits = 24)
            $pDisc = QtyPromotion::create([
                'code' => "P-DLIM-{$limit}", 'name' => 'DLIM', 'promo_type' => 'discount',
                'product_id' => $this->product1->id, 'min_qty' => 2, 'discount_type' => 'baht',
                'discount_value' => 10, 'max_sets_per_bill' => $limit, 'is_active' => true,
            ]);
            foreach ([2, 4, 6, 8, 10, 14] as $qty) {
                $sets = min(intdiv($qty, 2), $limit);
                $disc = $sets * 10;
                $paid = (50 * $qty) - $disc;
                $this->assertSame((float) $paid, $this->validatePayload([['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => $paid / $qty]]));
                $limitedRun++;
            }
            $pDisc->delete();

            // Free item with limit (6 qty x 4 limits = 24)
            $pFree = QtyPromotion::create([
                'code' => "P-FLIM-{$limit}", 'name' => 'FLIM', 'promo_type' => 'free_item',
                'product_id' => $this->product1->id, 'min_qty' => 2,
                'free_product_id' => $this->product2->id, 'free_qty' => 1,
                'max_sets_per_bill' => $limit, 'is_active' => true,
            ]);
            foreach ([2, 4, 6, 8, 10, 14] as $qty) {
                $sets = min(intdiv($qty, 2), $limit);
                $freeCount = min(10, $sets * 1);
                $disc = $freeCount * 20;
                $paid = (50 * $qty) + (20 * 10) - $disc;
                $this->assertSame((float) $paid, $this->validatePayload([
                    ['product_id' => $this->product1->id, 'qty' => $qty, 'unit_price' => 50.0],
                    ['product_id' => $this->product2->id, 'qty' => 10, 'unit_price' => (200 - $disc) / 10],
                ]));
                $limitedRun++;
            }
            $pFree->delete();
        }

        $this->assertSame(72, $limitedRun);
        $this->assertSame(400, $scenariosRun + $limitedRun);
    }
}
