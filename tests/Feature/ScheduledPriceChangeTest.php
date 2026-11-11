<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosPriceSchedule;
use App\Models\PriceChange;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\ScheduledPriceChange;
use App\Models\User;
use App\Services\Pricing\ScheduledPriceChangeService;
use App\Services\Sales\PosPriceScheduleService;
use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduledPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createMasterManager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $perm = Permission::firstOrCreate(['code' => 'masterdata.manage'], ['name' => 'จัดการข้อมูลหลัก']);
        $role = Role::firstOrCreate(['code' => 'ROLE_MD_MGR'], ['name' => 'MD Manager']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function createRegularUser(): User
    {
        return User::factory()->create(['is_active' => true, 'must_change_password' => false]);
    }

    private function createProduct(string $sku, string $name, float $price): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $cat = ProductCategory::firstOrCreate(['code' => 'CAT-SEAFOOD'], ['name_th' => 'อาหารทะเล']);

        return Product::create([
            'sku_code' => $sku,
            'name_th' => $name,
            'product_category_id' => $cat->id,
            'base_unit_id' => $unit->id,
            'default_price' => $price,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
    }

    /**
     * สถานการณ์จริงของเจ้าของ: ซัพพลายเออร์แจ้งปรับราคา "คอหมึก" 3 ขนาด
     * มีผล 12/11/2569 (12 พ.ย. 2026): 100/300 = 54 บาท, 300/500 = 59 บาท, 500 ขึ้นไป = 62 บาท
     * ตั้งไว้ล่วงหน้าวันนี้ แล้วให้ราคาเปลี่ยนเองตอน 00:00 ทั้งหลังบ้านและเครื่อง POS
     */
    public function test_owner_scenario_squid_supplier_three_sizes_batch_scheduling(): void
    {
        $manager = $this->createMasterManager();

        $squidSmall = $this->createProduct('SQUID-100-300', 'คอหมึก 100/300', 50.00);
        $squidMedium = $this->createProduct('SQUID-300-500', 'คอหมึก 300/500', 55.00);
        $squidLarge = $this->createProduct('SQUID-500-UP', 'คอหมึก 500 ขึ้นไป', 60.00);

        $effectiveAt = '2026-11-12 00:00:00';

        // บันทึกนัดเปลี่ยนราคา 3 สินค้าในชุดเดียวกัน
        $response = $this->actingAs($manager)->post(route('products.scheduled-prices.store'), [
            'effective_at' => '2026-11-12T00:00',
            'note' => 'ซัพพลายเออร์แจ้งปรับราคาคอหมึก',
            'items' => [
                ['product_id' => $squidSmall->id, 'new_price' => 54],
                ['product_id' => $squidMedium->id, 'new_price' => 59],
                ['product_id' => $squidLarge->id, 'new_price' => 62],
            ],
        ]);

        $response->assertRedirect(route('products.scheduled-prices.index'));
        $response->assertSessionHasNoErrors();

        // ตรวจสอบว่าถูกสร้างครบ 3 แถว และ batch_ref เดียวกัน
        $pendingRecords = ScheduledPriceChange::where('status', 'pending')->get();
        $this->assertCount(3, $pendingRecords);
        $batchRef = $pendingRecords->first()->batch_ref;
        $this->assertNotNull($batchRef);
        $this->assertTrue($pendingRecords->every(fn ($r) => $r->batch_ref === $batchRef));

        // ตรวจสอบแถว PosPriceSchedule ที่ถูกสร้างล่วงหน้าสำหรับเครื่อง POS (สถานะ published)
        $posSchedules = PosPriceSchedule::whereIn('id', $pendingRecords->pluck('pos_price_schedule_id'))->get();
        $this->assertCount(3, $posSchedules);
        $this->assertTrue($posSchedules->every(fn ($s) => $s->status === 'published'));

        // ณ เวลาปัจจุบัน (2026-11-11 10:00:00) รัน erp:apply-scheduled-prices ต้องยังไม่เปลี่ยนราคา
        $this->artisan('erp:apply-scheduled-prices')
            ->expectsOutput('No scheduled price changes due.')
            ->assertSuccessful();

        $this->assertSame(50.0, (float) $squidSmall->fresh()->default_price);
        $this->assertSame(55.0, (float) $squidMedium->fresh()->default_price);
        $this->assertSame(60.0, (float) $squidLarge->fresh()->default_price);

        // หมุนเวลาไปถึง 00:00 ของวันที่ 12/11/2026
        Carbon::setTestNow('2026-11-12 00:00:00');

        // รันคำสั่ง erp:apply-scheduled-prices
        $this->artisan('erp:apply-scheduled-prices')
            ->expectsOutput('Scheduled prices applied: 3 succeeded, 0 failed (total 3).')
            ->assertSuccessful();

        // ตรวจสอบราคาใหม่หลังบ้าน
        $this->assertSame(54.0, (float) $squidSmall->fresh()->default_price);
        $this->assertSame(59.0, (float) $squidMedium->fresh()->default_price);
        $this->assertSame(62.0, (float) $squidLarge->fresh()->default_price);

        // ตรวจสอบสถานะว่า applied และบันทึก old_price ถูกต้อง
        $appliedSmall = ScheduledPriceChange::where('product_id', $squidSmall->id)->sole();
        $this->assertSame('applied', $appliedSmall->status);
        $this->assertSame(50.0, (float) $appliedSmall->old_price);
        $this->assertSame(54.0, (float) $appliedSmall->new_price);
        $this->assertNotNull($appliedSmall->applied_at);

        // ตรวจสอบว่าบันทึก PriceChange ครบทั้ง 3 รายการ
        $this->assertSame(1, PriceChange::where('product_id', $squidSmall->id)->where('new_price', 54)->count());
        $this->assertSame(1, PriceChange::where('product_id', $squidMedium->id)->where('new_price', 59)->count());
        $this->assertSame(1, PriceChange::where('product_id', $squidLarge->id)->where('new_price', 62)->count());

        // ตรวจสอบ AuditLog
        $this->assertTrue(AuditLog::where('table_name', 'products')->where('record_id', $squidSmall->id)->where('action', 'price_updated')->exists());

        // ตรวจสอบว่า PosPriceSchedule ถูกปิดแล้ว (status = cancelled)
        $closedSchedules = PosPriceSchedule::whereIn('id', $pendingRecords->pluck('pos_price_schedule_id'))->get();
        $this->assertTrue($closedSchedules->every(fn ($s) => $s->status === 'cancelled'));
    }

    /**
     * ราคาตรงถึงทศนิยมสุดท้าย (เช่น 35.5, 38.25 และเลข 4 ตำแหน่ง)
     */
    public function test_exact_decimal_precision(): void
    {
        $manager = $this->createMasterManager();
        $p1 = $this->createProduct('P-DEC-1', 'สินค้าทศนิยม 1', 30.00);
        $p2 = $this->createProduct('P-DEC-2', 'สินค้าทศนิยม 2', 32.50);

        $service = app(ScheduledPriceChangeService::class);
        $service->schedule([
            ['product_id' => $p1->id, 'new_price' => 35.5],
            ['product_id' => $p2->id, 'new_price' => 38.25],
        ], now()->addHour(), 'เทสต์ทศนิยมตรงเป๊ะ', $manager->id);

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('erp:apply-scheduled-prices')->assertSuccessful();

        $this->assertSame('35.5000', number_format((float) $p1->fresh()->default_price, 4, '.', ''));
        $this->assertSame('38.2500', number_format((float) $p2->fresh()->default_price, 4, '.', ''));
        $this->assertSame(0, DecimalMath::compare($p1->fresh()->default_price, '35.5'));
        $this->assertSame(0, DecimalMath::compare($p2->fresh()->default_price, '38.25'));
    }

    /**
     * ยกเลิกก่อนถึงเวลา: แถว PosPriceSchedule ต้องถูกยกเลิกด้วย และเมื่อถึงเวลาต้องไม่ถูกปรับราคา
     */
    public function test_cancellation_before_effective_time_cancels_pos_schedule(): void
    {
        $manager = $this->createMasterManager();
        $product = $this->createProduct('P-CANCEL-1', 'สินค้ายกเลิก', 100.00);

        $service = app(ScheduledPriceChangeService::class);
        $scheduled = $service->schedule([
            ['product_id' => $product->id, 'new_price' => 120.00],
        ], now()->addDay(), 'จะยกเลิก', $manager->id);

        $change = $scheduled[0];
        $posSchedule = PosPriceSchedule::findOrFail($change->pos_price_schedule_id);
        $this->assertSame('published', $posSchedule->status);

        // ทำการยกเลิกผ่าน endpoint DELETE
        $response = $this->actingAs($manager)->delete(route('products.scheduled-prices.cancel', $change));
        $response->assertRedirect(route('products.scheduled-prices.index'));
        $response->assertSessionHas('success');

        $this->assertSame('cancelled', $change->fresh()->status);
        $this->assertSame('cancelled', $posSchedule->fresh()->status);

        // หมุนเวลาผ่านไปและรัน apply
        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('erp:apply-scheduled-prices')->assertSuccessful();

        // ราคาต้องเป็น 100 เหมือนเดิม
        $this->assertSame(100.0, (float) $product->fresh()->default_price);
        $this->assertSame('cancelled', $change->fresh()->status);
    }

    /**
     * Idempotency: รัน apply ซ้ำกี่ครั้งก็ได้ผลเดิม ไม่ปรับราคาซ้ำ ไม่สร้างประวัติซ้ำ
     */
    public function test_idempotency_running_apply_multiple_times(): void
    {
        $manager = $this->createMasterManager();
        $product = $this->createProduct('P-IDEM-1', 'สินค้า Idempotent', 200.00);

        $service = app(ScheduledPriceChangeService::class);
        $service->schedule([
            ['product_id' => $product->id, 'new_price' => 250.00],
        ], now()->addHour(), 'เทสต์ Idempotency', $manager->id);

        Carbon::setTestNow(now()->addHours(2));

        // รันครั้งที่ 1
        $this->artisan('erp:apply-scheduled-prices')
            ->expectsOutput('Scheduled prices applied: 1 succeeded, 0 failed (total 1).')
            ->assertSuccessful();

        $this->assertSame(250.0, (float) $product->fresh()->default_price);
        $this->assertSame(1, PriceChange::where('product_id', $product->id)->count());

        // รันครั้งที่ 2 และ 3
        $this->artisan('erp:apply-scheduled-prices')
            ->expectsOutput('No scheduled price changes due.')
            ->assertSuccessful();

        $this->artisan('erp:apply-scheduled-prices')
            ->expectsOutput('No scheduled price changes due.')
            ->assertSuccessful();

        $this->assertSame(250.0, (float) $product->fresh()->default_price);
        $this->assertSame(1, PriceChange::where('product_id', $product->id)->count());
    }

    /**
     * หัวใจสำคัญและจุดพิสูจน์ Mutation Test:
     * แถว PosPriceSchedule ต้องถูกปิด (status = cancelled) หลัง apply
     * หากไม่ปิด PosPriceScheduleService::active() จะเลือกแถวนั้นมาทับราคาหลัก
     * และถ้าผู้จัดการแก้ราคาหลักวันหลัง เครื่อง POS จะยังคงเห็นราคาเก่าของแถวนั้น!
     */
    public function test_closing_pos_schedule_after_apply_prevents_shadowing_subsequent_price_updates(): void
    {
        $manager = $this->createMasterManager();
        $branch = Branch::create(['code' => 'B-POS-TEST', 'name_th' => 'สาขาทดสอบ POS', 'is_active' => true]);
        $product = $this->createProduct('P-POS-SHADOW', 'สินค้าเทสต์ทับราคา POS', 100.00);

        // 1. นัดเปลี่ยนราคาเป็น 120
        $service = app(ScheduledPriceChangeService::class);
        $service->schedule([
            ['product_id' => $product->id, 'new_price' => 120.00],
        ], now()->addHour(), 'ปรับเป็น 120', $manager->id);

        // 2. ถึงเวลา นัดเปลี่ยนราคาทำงาน
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('erp:apply-scheduled-prices')->assertSuccessful();
        $this->assertSame(120.0, (float) $product->fresh()->default_price);

        // 3. วันถัดมา ผู้จัดการมาแก้ราคาหลักในหลังบ้านเป็น 150 บาท
        Carbon::setTestNow(now()->addDay());
        $product->update(['default_price' => 150.00]);
        $this->assertSame(150.0, (float) $product->fresh()->default_price);

        // 4. ตรวจสอบราคาที่ POS มองเห็นผ่าน PosPriceScheduleService::active() (ตามตรรกะของ PosController และ PosPricingGuard)
        $posService = app(PosPriceScheduleService::class);
        $activeSchedules = $posService->active([$product->id], $branch->id, now())
            ->keyBy(fn ($schedule) => $schedule->product_id.':'.($schedule->unit_id ?? 'base'));

        // แถว override ของ POS ต้องว่างเปล่า (เพราะถูกปิดไปแล้ว) ทำให้ POS หันไปใช้ราคาหลัก 150
        $override = $activeSchedules->get($product->id.':base');
        $this->assertNull(
            $override,
            'แถว PosPriceSchedule หลัง apply ต้องถูกปิด (cancelled) ไม่งั้นจะบังราคาหลักที่ผู้จัดการแก้ในวันถัดไป'
        );

        $effectivePosPrice = (float) ($override?->price ?? $product->fresh()->default_price);
        $this->assertSame(
            150.0,
            $effectivePosPrice,
            'POS ต้องเห็นราคา 150 บาทที่ผู้จัดการแก้ใหม่ ไม่ใช่ราคา 120 ของแถวในอดีต'
        );
    }

    /**
     * ตรวจสอบสิทธิ์: masterdata.manage เท่านั้นที่เข้าถึงได้, ผู้ใช้ทั่วไปติด 403
     */
    public function test_permission_checks_masterdata_manage(): void
    {
        $regularUser = $this->createRegularUser();
        $manager = $this->createMasterManager();
        $product = $this->createProduct('P-PERM-1', 'สินค้าสิทธิ์', 100.00);

        // ผู้ใช้ทั่วไปเปิดหน้า -> 403
        $this->actingAs($regularUser)->get(route('products.scheduled-prices.index'))->assertForbidden();

        // ผู้ใช้ทั่วไปกดบันทึก -> 403
        $this->actingAs($regularUser)->post(route('products.scheduled-prices.store'), [
            'effective_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'items' => [['product_id' => $product->id, 'new_price' => 110]],
        ])->assertForbidden();

        // ผู้ใช้ที่มีสิทธิ์ masterdata.manage เปิดหน้าและบันทึกได้
        $this->actingAs($manager)->get(route('products.scheduled-prices.index'))->assertOk();

        $this->actingAs($manager)->post(route('products.scheduled-prices.store'), [
            'effective_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'items' => [['product_id' => $product->id, 'new_price' => 110]],
        ])->assertRedirect(route('products.scheduled-prices.index'));
    }

    /**
     * การตรวจสอบข้อมูล (Validation): ราคาต้อง > 0 และวันที่มีผลต้องอยู่ในอนาคต
     */
    public function test_validation_rejects_non_positive_price_and_past_date(): void
    {
        $manager = $this->createMasterManager();
        $product = $this->createProduct('P-VAL-1', 'สินค้าตรวจสอบ', 100.00);

        // ราคาเป็น 0
        $this->actingAs($manager)->post(route('products.scheduled-prices.store'), [
            'effective_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'items' => [['product_id' => $product->id, 'new_price' => 0]],
        ])->assertSessionHasErrors(['items.0.new_price']);

        // ราคาติดลบ
        $this->actingAs($manager)->post(route('products.scheduled-prices.store'), [
            'effective_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'items' => [['product_id' => $product->id, 'new_price' => -15]],
        ])->assertSessionHasErrors(['items.0.new_price']);

        // วันที่มีผลอยู่ในอดีต
        $this->actingAs($manager)->post(route('products.scheduled-prices.store'), [
            'effective_at' => now()->subMinute()->format('Y-m-d\TH:i'),
            'items' => [['product_id' => $product->id, 'new_price' => 120]],
        ])->assertSessionHasErrors(['effective_at']);
    }

    /**
     * เอกสารเดิม (ใบขาย/ใบจอง) ที่สร้างก่อนวันที่มีผลคงราคาเดิม เอกสารใหม่หลังเวลาใช้ราคาใหม่
     */
    public function test_existing_documents_keep_old_price_new_documents_use_new_price(): void
    {
        $manager = $this->createMasterManager();
        $product = $this->createProduct('P-DOC-1', 'สินค้าใบขาย', 100.00);

        $branch = Branch::create(['code' => 'B-DOC', 'name_th' => 'สาขาเอกสาร', 'is_active' => true]);
        $warehouse = \App\Models\Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-DOC', 'name' => 'คลังหลัก']);
        $location = \App\Models\WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-DOC', 'name' => 'จุดเก็บ']);
        $docType = \App\Models\DocumentType::firstOrCreate(['code' => 'SALE'], ['name_th' => 'ขาย', 'name_en' => 'Sale']);

        $doc = \App\Models\Document::create([
            'document_type_id' => $docType->id,
            'branch_id' => $branch->id,
            'doc_number' => 'DOC-TEST-001',
            'doc_date' => now()->toDateString(),
            'status' => 'active',
            'created_by' => $manager->id,
            'total_amount' => 500.00,
        ]);
        $stockDoc = \App\Models\StockDocument::create([
            'document_id' => $doc->id,
            'direction' => 'out',
            'status' => 'active',
            'total_qty' => 5,
            'total_items' => 1,
        ]);

        // 1. สร้างรายการเอกสารขายเดิมก่อนเปลี่ยนราคา ราคาต่อหน่วย = 100.00
        $oldDocumentItemId = DB::table('stock_document_items')->insertGetId([
            'stock_document_id' => $stockDoc->id,
            'seq' => 1,
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'qty' => 5,
            'unit_price' => 100.00,
        ]);

        // 2. นัดเปลี่ยนราคาเป็น 130.00
        $service = app(ScheduledPriceChangeService::class);
        $service->schedule([
            ['product_id' => $product->id, 'new_price' => 130.00],
        ], now()->addHour(), 'ปรับราคา', $manager->id);

        // 3. ถึงเวลาและ apply
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('erp:apply-scheduled-prices')->assertSuccessful();

        // 4. เอกสารเดิมต้องคงราคา 100.00 เท่าเดิม ไม่ถูกกระทบ
        $oldItem = DB::table('stock_document_items')->where('id', $oldDocumentItemId)->first();
        $this->assertSame(100.0, (float) $oldItem->unit_price);

        // 5. ตัวค้นหาสินค้าสำหรับเปิดเอกสารใหม่ (SearchController::products) คืนราคาใหม่ 130.00
        $searchResponse = $this->actingAs($manager)->getJson(route('search.products', ['q' => $product->sku_code]));
        $searchResponse->assertOk();
        $searchData = $searchResponse->json();
        $this->assertNotEmpty($searchData);
        $this->assertSame(130.0, (float) $searchData[0]['default_price']);
    }

    /**
     * ฟังก์ชัน Quick Paste แปลงข้อความ "รหัสสินค้า<tab/comma>ราคา" ได้ถูกต้อง
     */
    public function test_quick_paste_multi_line_tab_or_comma_parsing(): void
    {
        $manager = $this->createMasterManager();
        $p1 = $this->createProduct('PASTE-01', 'สินค้าแปะ 1', 10.00);
        $p2 = $this->createProduct('PASTE-02', 'สินค้าแปะ 2', 20.00);

        $text = "PASTE-01\t54.50\nPASTE-02,59.00\nUNKNOWN-CODE\t99";

        $response = $this->actingAs($manager)->postJson(route('products.scheduled-prices.parse-paste'), [
            'text' => $text,
        ]);

        $response->assertOk();
        $rows = $response->json('rows');

        $this->assertCount(2, $rows);
        $this->assertSame($p1->id, $rows[0]['product_id']);
        $this->assertSame(54.5, (float) $rows[0]['new_price']);
        $this->assertSame(10.0, (float) $rows[0]['current_price']);

        $this->assertSame($p2->id, $rows[1]['product_id']);
        $this->assertSame(59.0, (float) $rows[1]['new_price']);
        $this->assertSame(20.0, (float) $rows[1]['current_price']);
    }

    /**
     * หากมีรายการที่ล้มเหลว จะถูกตั้งเป็น failed พร้อม error และไม่ขวางรายการอื่นใน batch เดียวกัน
     */
    public function test_failed_item_does_not_block_other_items_in_batch(): void
    {
        $manager = $this->createMasterManager();
        $pGood = $this->createProduct('P-GOOD', 'สินค้าปกติ', 50.00);
        $pFaulty = $this->createProduct('P-FAULTY', 'สินค้าที่มีปัญหา', 70.00);

        $service = app(ScheduledPriceChangeService::class);
        $scheduled = $service->schedule([
            ['product_id' => $pGood->id, 'new_price' => 60.00],
            ['product_id' => $pFaulty->id, 'new_price' => 80.00],
        ], now()->addHour(), 'ทดสอบ error isolation', $manager->id);

        // จำลองให้สินค้าตัวที่ 2 ถูก soft-delete ก่อนถึงเวลา ทำให้ตอน lockForUpdate หาไม่เจอ
        $pFaulty->delete();

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('erp:apply-scheduled-prices')->assertFailed(); // returns FAILURE because failed > 0

        // สินค้าตัวแรกต้องสำเร็จ
        $this->assertSame(60.0, (float) $pGood->fresh()->default_price);
        $this->assertSame('applied', ScheduledPriceChange::where('id', $scheduled[0]->id)->value('status'));

        // สินค้าตัวที่สองต้องเป็น failed พร้อม error
        $failedRow = ScheduledPriceChange::where('id', $scheduled[1]->id)->sole();
        $this->assertSame('failed', $failedRow->status);
        $this->assertNotNull($failedRow->error);
    }
}
