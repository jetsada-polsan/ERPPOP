<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QtyPromotionPatternsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch1;
    private Branch $branch2;
    private Product $product1;
    private Product $product2;
    private Product $product3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user);

        $this->branch1 = Branch::create(['code' => 'B01', 'name_th' => 'สาขาสำนักงานใหญ่', 'is_active' => true]);
        $this->branch2 = Branch::create(['code' => 'B02', 'name_th' => 'สาขา 101', 'is_active' => true]);

        $unit = ProductUnit::create(['code' => 'PCS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product1 = Product::create(['sku_code' => 'SKU01', 'name_th' => 'ปลาหมึกอบกรอบ', 'base_unit_id' => $unit->id, 'default_price' => 39, 'is_active' => true]);
        $this->product2 = Product::create(['sku_code' => 'SKU02', 'name_th' => 'น้ำเก๊กฮวย', 'base_unit_id' => $unit->id, 'default_price' => 15, 'is_active' => true]);
        $this->product3 = Product::create(['sku_code' => 'SKU03', 'name_th' => 'น้ำมันพืช', 'base_unit_id' => $unit->id, 'default_price' => 45, 'is_active' => true]);
    }

    /**
     * ข้อ 32: สร้างได้ทุกการ์ด 7 แบบผ่าน route จริง (POST /qty-promotions), ค่าที่บันทึกตรงกับที่กรอก
     */
    public function test_creates_all_seven_bplus_pattern_cards_via_real_route(): void
    {
        // 1. bundle_price: ซื้อครบ X ชิ้น ราคาเหลือ Y บาท (เช่น 3 แพ็ค 100)
        $resp1 = $this->post(route('qty-promotions.store'), [
            'name' => '3 แพ็ค 100 บาท',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 100,
            'branch_id' => $this->branch1->id,
            'starts_at' => '2026-11-10 08:00:00',
            'ends_at' => '2026-11-30 20:00:00',
            'is_active' => 1,
        ]);
        $resp1->assertSessionHasNoErrors();
        $promo1 = QtyPromotion::where('promo_type', 'bundle_price')->firstOrFail();
        $this->assertSame('3 แพ็ค 100 บาท', $promo1->name);
        $this->assertSame($this->product1->id, (int) $promo1->product_id);
        $this->assertEquals(3, (float) $promo1->min_qty);
        $this->assertEquals(100, (float) $promo1->bundle_price);
        $this->assertSame($this->branch1->id, (int) $promo1->branch_id);

        // 2. free_item: ซื้อ X แถม Y (1 แถม 1)
        $resp2 = $this->post(route('qty-promotions.store'), [
            'name' => 'ซื้อ 1 แถม 1',
            'promo_type' => 'free_item',
            'product_id' => $this->product1->id,
            'min_qty' => 1,
            'free_product_id' => $this->product2->id,
            'free_qty' => 1,
            'is_active' => 1,
        ]);
        $resp2->assertSessionHasNoErrors();
        $promo2 = QtyPromotion::where('promo_type', 'free_item')->firstOrFail();
        $this->assertSame($this->product1->id, (int) $promo2->product_id);
        $this->assertSame($this->product2->id, (int) $promo2->free_product_id);
        $this->assertEquals(1, (float) $promo2->min_qty);
        $this->assertEquals(1, (float) $promo2->free_qty);

        // 3. discount: ซื้อครบ X ชิ้น ลด % / บาท
        $resp3 = $this->post(route('qty-promotions.store'), [
            'name' => 'ซื้อ 2 ชิ้น ลด 10 บาท',
            'promo_type' => 'discount',
            'product_id' => $this->product1->id,
            'min_qty' => 2,
            'discount_type' => 'baht',
            'discount_value' => 10,
            'is_active' => 1,
        ]);
        $resp3->assertSessionHasNoErrors();
        $promo3 = QtyPromotion::where('promo_type', 'discount')->firstOrFail();
        $this->assertEquals(2, (float) $promo3->min_qty);
        $this->assertSame('baht', $promo3->discount_type);
        $this->assertEquals(10, (float) $promo3->discount_value);

        // 4. mix_bundle: เลือกซื้อคละครบ X ชิ้น (ราคาเหลือ/ลด)
        $resp4 = $this->post(route('qty-promotions.store'), [
            'name' => 'คละรส 3 ชิ้น 100 บาท',
            'promo_type' => 'mix_bundle',
            'product_ids' => [$this->product1->id, $this->product2->id],
            'min_qty' => 3,
            'bundle_price' => 100,
            'is_active' => 1,
        ]);
        $resp4->assertSessionHasNoErrors();
        $promo4 = QtyPromotion::where('promo_type', 'mix_bundle')->firstOrFail();
        $this->assertEquals(3, (float) $promo4->min_qty);
        $this->assertEquals(100, (float) $promo4->bundle_price);
        $this->assertEqualsCanonicalizing([$this->product1->id, $this->product2->id], $promo4->groupProducts->pluck('id')->all());

        // 5. bill_discount: ซื้อครบยอด X บาท ลด
        $resp5 = $this->post(route('qty-promotions.store'), [
            'name' => 'ครบ 1,000 ลด 50 บาท',
            'promo_type' => 'bill_discount',
            'min_amount' => 1000,
            'discount_type' => 'baht',
            'discount_value' => 50,
            'max_discount' => 50,
            'is_active' => 1,
        ]);
        $resp5->assertSessionHasNoErrors();
        $promo5 = QtyPromotion::where('promo_type', 'bill_discount')->firstOrFail();
        $this->assertEquals(1000, (float) $promo5->min_amount);
        $this->assertEquals(50, (float) $promo5->discount_value);
        $this->assertEquals(50, (float) $promo5->max_discount);

        // 6. bill_free_item: ซื้อครบยอด X บาท ได้ของแถม
        $resp6 = $this->post(route('qty-promotions.store'), [
            'name' => 'ครบ 500 แถมน้ำเก๊กฮวย',
            'promo_type' => 'bill_free_item',
            'min_amount' => 500,
            'free_product_id' => $this->product2->id,
            'free_qty' => 1,
            'is_active' => 1,
        ]);
        $resp6->assertSessionHasNoErrors();
        $promo6 = QtyPromotion::where('promo_type', 'bill_free_item')->firstOrFail();
        $this->assertEquals(500, (float) $promo6->min_amount);
        $this->assertSame($this->product2->id, (int) $promo6->free_product_id);
        $this->assertEquals(1, (float) $promo6->free_qty);

        // 7. bill_addon: ซื้อครบยอด X บาท แลกซื้อ
        $resp7 = $this->post(route('qty-promotions.store'), [
            'name' => 'ครบ 300 แลกซื้อน้ำมันพืช 19 บาท',
            'promo_type' => 'bill_addon',
            'min_amount' => 300,
            'product_id' => $this->product3->id,
            'bundle_price' => 19,
            'free_qty' => 1,
            'is_active' => 1,
        ]);
        $resp7->assertSessionHasNoErrors();
        $promo7 = QtyPromotion::where('promo_type', 'bill_addon')->firstOrFail();
        $this->assertEquals(300, (float) $promo7->min_amount);
        $this->assertSame($this->product3->id, (int) $promo7->product_id);
        $this->assertEquals(19, (float) $promo7->bundle_price);
        $this->assertEquals(1, (float) $promo7->free_qty);
    }

    /**
     * ข้อ 33: กรอกไม่ครบ/ค่าผิด (X=0, ราคาติดลบ, จบก่อนเริ่ม) ต้องได้ error ไทย ไม่บันทึก
     */
    public function test_validation_errors_in_thai_for_missing_and_invalid_inputs(): void
    {
        $initialCount = QtyPromotion::count();

        // 1. X = 0 (min_qty <= 0)
        $resp1 = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรผิด X=0',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 0,
            'bundle_price' => 100,
        ]);
        $resp1->assertSessionHasErrors(['min_qty' => 'จำนวนสินค้าขั้นต่ำต้องมากกว่า 0']);

        // 2. ราคาติดลบ (bundle_price < 0)
        $resp2 = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรผิด ราคาติดลบ',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => -20,
        ]);
        $resp2->assertSessionHasErrors(['bundle_price' => 'ราคาชุด/ราคาแลกซื้อต้องไม่ติดลบ']);

        // 3. จบก่อนเริ่ม (ends_at < starts_at)
        $resp3 = $this->post(route('qty-promotions.store'), [
            'name' => 'โปรผิด จบก่อนเริ่ม',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 100,
            'starts_at' => '2026-11-10 12:00:00',
            'ends_at' => '2026-11-10 11:00:00',
        ]);
        $resp3->assertSessionHasErrors(['ends_at' => 'เวลาสิ้นสุดต้องไม่ก่อนเวลาเริ่มต้น']);

        // 4. ไม่กรอกชื่อแคมเปญ
        $resp4 = $this->post(route('qty-promotions.store'), [
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 100,
        ]);
        $resp4->assertSessionHasErrors(['name' => 'กรุณากรอกชื่อแคมเปญ']);

        // ตรวจสอบว่าไม่มีข้อมูลใดถูกบันทึกลงฐานข้อมูล
        $this->assertSame($initialCount, QtyPromotion::count());
    }

    /**
     * ข้อ 34: "ใช้อีกครั้ง" สร้างแคมเปญใหม่ ของเดิมไม่เปลี่ยน
     */
    public function test_use_again_workflow_creates_new_campaign_without_mutating_original(): void
    {
        // 1. สร้างแคมเปญต้นฉบับ
        $original = QtyPromotion::create([
            'code' => 'QP-ORIGINAL',
            'name' => 'โปรโมชันต้นฉบับ 3 แพ็ค 100',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 100,
            'branch_id' => $this->branch1->id,
            'starts_at' => '2026-11-01 00:00:00',
            'ends_at' => '2026-11-05 23:59:59',
            'note' => 'หมายเหตุเดิม',
            'is_active' => true,
        ]);

        // 2. จำลองการกดปุ่ม "ใช้อีกครั้ง" ซึ่งส่งข้อมูลชุดเดิมแต่ปรับสาขา/เวลา และได้รหัสใหม่
        $resp = $this->post(route('qty-promotions.store'), [
            'name' => $original->name . ' (คัดลอก)',
            'promo_type' => $original->promo_type,
            'product_id' => $original->product_id,
            'min_qty' => $original->min_qty,
            'bundle_price' => $original->bundle_price,
            'branch_id' => $this->branch2->id, // เปลี่ยนสาขา
            'starts_at' => '2026-11-10 00:00:00', // เปลี่ยนเวลา
            'ends_at' => '2026-11-20 23:59:59',
            'note' => $original->note,
            'is_active' => 1,
        ]);
        $resp->assertSessionHasNoErrors();

        // 3. ตรวจสอบว่ามีแคมเปญใหม่เกิดขึ้น
        $newPromo = QtyPromotion::where('name', 'โปรโมชันต้นฉบับ 3 แพ็ค 100 (คัดลอก)')->firstOrFail();
        $this->assertNotSame($original->id, $newPromo->id);
        $this->assertNotSame($original->code, $newPromo->code);
        $this->assertSame($this->branch2->id, (int) $newPromo->branch_id);

        // 4. ตรวจสอบว่าแคมเปญต้นฉบับยังคงเดิม ไม่ถูกแตะต้องหรือเปลี่ยนแปลง
        $freshOriginal = $original->fresh();
        $this->assertSame('QP-ORIGINAL', $freshOriginal->code);
        $this->assertSame('โปรโมชันต้นฉบับ 3 แพ็ค 100', $freshOriginal->name);
        $this->assertSame($this->branch1->id, (int) $freshOriginal->branch_id);
        $this->assertSame('2026-11-01 00:00:00', $freshOriginal->starts_at->toDateTimeString());
        $this->assertSame('2026-11-05 23:59:59', $freshOriginal->ends_at->toDateTimeString());
    }

    /**
     * ข้อ 35: scope ที่ POS ดึงไป (QtyPromotion scopeRunningToday และ POS endpoint)
     * คืนเฉพาะแคมเปญที่: ใช้งาน + อยู่ในช่วงเวลา + ตรงสาขา
     * ทดสอบก่อนเริ่ม 1 นาที, ระหว่าง, หลังจบ 1 นาที, สาขาอื่น
     */
    public function test_pos_scope_and_route_returns_only_active_in_window_and_matching_branch(): void
    {
        // กำหนดเวลาเริ่ม-จบชัดเจน: วันนี้ 10:00:00 ถึง 20:00:00
        $startTime = '2026-11-15 10:00:00';
        $endTime = '2026-11-15 20:00:00';

        $promoBranch1 = QtyPromotion::create([
            'code' => 'QP-B01',
            'name' => 'โปรเฉพาะสาขา 1',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 100,
            'branch_id' => $this->branch1->id,
            'starts_at' => $startTime,
            'ends_at' => $endTime,
            'is_active' => true,
        ]);

        $promoAllBranches = QtyPromotion::create([
            'code' => 'QP-ALL',
            'name' => 'โปรทุกสาขา',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product2->id,
            'min_qty' => 2,
            'bundle_price' => 25,
            'branch_id' => null, // ทุกสาขา
            'starts_at' => $startTime,
            'ends_at' => $endTime,
            'is_active' => true,
        ]);

        $promoInactive = QtyPromotion::create([
            'code' => 'QP-OFF',
            'name' => 'โปรปิดใช้งาน',
            'promo_type' => 'bundle_price',
            'product_id' => $this->product1->id,
            'min_qty' => 3,
            'bundle_price' => 90,
            'branch_id' => $this->branch1->id,
            'starts_at' => $startTime,
            'ends_at' => $endTime,
            'is_active' => false,
        ]);

        try {
            // จุดที่ 1: ก่อนเริ่ม 1 นาที (09:59:00) -> ต้องยังไม่คืนโปร
            Carbon::setTestNow('2026-11-15 09:59:00');
            $this->assertFalse(QtyPromotion::runningToday()->where('id', $promoBranch1->id)->exists());
            $this->assertFalse(QtyPromotion::runningToday()->where('id', $promoAllBranches->id)->exists());

            $respBefore = $this->getJson(route('pos.promotions', ['branch_id' => $this->branch1->id]));
            $respBefore->assertOk();
            $codesBefore = collect($respBefore->json())->pluck('code')->all();
            $this->assertNotContains('QP-B01', $codesBefore);
            $this->assertNotContains('QP-ALL', $codesBefore);

            // จุดที่ 2: ระหว่างช่วงเวลา (14:30:00)
            Carbon::setTestNow('2026-11-15 14:30:00');
            $this->assertTrue(QtyPromotion::runningToday()->where('id', $promoBranch1->id)->exists());
            $this->assertTrue(QtyPromotion::runningToday()->where('id', $promoAllBranches->id)->exists());
            $this->assertFalse(QtyPromotion::runningToday()->where('id', $promoInactive->id)->exists()); // ปิดใช้งานต้องไม่คืน

            // สาขา 1: ต้องได้โปรสาขา 1 และโปรทุกสาขา
            $respBranch1 = $this->getJson(route('pos.promotions', ['branch_id' => $this->branch1->id]));
            $respBranch1->assertOk();
            $codesB1 = collect($respBranch1->json())->pluck('code')->all();
            $this->assertContains('QP-B01', $codesB1);
            $this->assertContains('QP-ALL', $codesB1);
            $this->assertNotContains('QP-OFF', $codesB1);

            // สาขา 2: ต้องได้โปรทุกสาขา แต่ต้องไม่ได้โปรของสาขา 1
            $respBranch2 = $this->getJson(route('pos.promotions', ['branch_id' => $this->branch2->id]));
            $respBranch2->assertOk();
            $codesB2 = collect($respBranch2->json())->pluck('code')->all();
            $this->assertNotContains('QP-B01', $codesB2, 'โปรของสาขาอื่นต้องไม่ติดไปสาขา 2');
            $this->assertContains('QP-ALL', $codesB2);

            // จุดที่ 3: หลังจบ 1 นาที (20:01:00) -> ต้องหมดเวลา ไม่คืนโปร
            Carbon::setTestNow('2026-11-15 20:01:00');
            $this->assertFalse(QtyPromotion::runningToday()->where('id', $promoBranch1->id)->exists());
            $this->assertFalse(QtyPromotion::runningToday()->where('id', $promoAllBranches->id)->exists());

            $respAfter = $this->getJson(route('pos.promotions', ['branch_id' => $this->branch1->id]));
            $respAfter->assertOk();
            $codesAfter = collect($respAfter->json())->pluck('code')->all();
            $this->assertNotContains('QP-B01', $codesAfter);
            $this->assertNotContains('QP-ALL', $codesAfter);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * ทดสอบสถานะ 4 รูปแบบ: ยังไม่เริ่ม / กำลังใช้ / หมดเวลา / ปิด
     */
    public function test_status_labels_and_badge_classes(): void
    {
        try {
            Carbon::setTestNow('2026-11-10 12:00:00');

            // 1. ปิด
            $pClosed = new QtyPromotion(['is_active' => false]);
            $this->assertSame('ปิด', $pClosed->statusLabel());
            $this->assertStringContainsString('text-muted', $pClosed->statusBadgeClass());

            // 2. ยังไม่เริ่ม
            $pUpcoming = new QtyPromotion([
                'is_active' => true,
                'starts_at' => Carbon::parse('2026-11-10 13:00:00'),
                'ends_at' => Carbon::parse('2026-11-10 18:00:00'),
            ]);
            $this->assertSame('ยังไม่เริ่ม', $pUpcoming->statusLabel());
            $this->assertSame('text-bg-warning text-dark', $pUpcoming->statusBadgeClass());

            // 3. กำลังใช้
            $pRunning = new QtyPromotion([
                'is_active' => true,
                'starts_at' => Carbon::parse('2026-11-10 11:00:00'),
                'ends_at' => Carbon::parse('2026-11-10 18:00:00'),
            ]);
            $this->assertSame('กำลังใช้', $pRunning->statusLabel());
            $this->assertSame('text-bg-success', $pRunning->statusBadgeClass());

            // 4. หมดเวลา
            $pEnded = new QtyPromotion([
                'is_active' => true,
                'starts_at' => Carbon::parse('2026-11-10 09:00:00'),
                'ends_at' => Carbon::parse('2026-11-10 11:00:00'),
            ]);
            $this->assertSame('หมดเวลา', $pEnded->statusLabel());
            $this->assertSame('text-bg-secondary', $pEnded->statusBadgeClass());
        } finally {
            Carbon::setTestNow();
        }
    }
}
