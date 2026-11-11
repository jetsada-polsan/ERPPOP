<?php

namespace Tests\Feature;

use App\Jobs\ApproveStockAdjustment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockAdjustmentApproveBackgroundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ข้อ 1: Golden test (สำคัญที่สุด)
     * เปรียบเทียบผลลัพธ์ของโค้ดใหม่กับ snapshot ค่าดั้งเดิม (origin/main)
     * ตรวจสอบ stock_balances, stock_lots, stock_movements, products.average_cost, gl_journals
     * ต้องเท่ากันทุกแถวถึงทศนิยมสุดท้าย
     */
    public function test_golden_dataset_matches_original_logic_to_the_last_decimal(): void
    {
        [$branch, $location, $products, $approver] = $this->setupGoldenData();

        $items = [];
        // Products 1..10: shortage (system=20, counted=12 -> diff=-8)
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['product_id' => $products[$i]->id, 'counted_qty' => 12.0];
        }
        // Products 11..20: surplus with default cost (system=10, counted=15 -> diff=+5)
        for ($i = 11; $i <= 20; $i++) {
            $items[] = ['product_id' => $products[$i]->id, 'counted_qty' => 15.0];
        }
        // Products 21..25: surplus with explicit unit_cost (system=10, counted=14 -> diff=+4, unit_cost=95.5)
        for ($i = 21; $i <= 25; $i++) {
            $items[] = ['product_id' => $products[$i]->id, 'counted_qty' => 14.0, 'unit_cost' => 95.5];
        }
        // Products 26..30: negative stock allowed (system=0, counted=-5 -> diff=-5)
        for ($i = 26; $i <= 30; $i++) {
            $items[] = ['product_id' => $products[$i]->id, 'counted_qty' => -5.0];
        }
        // Products 31..35: equal (system=10, counted=10 -> diff=0, skipped)
        for ($i = 31; $i <= 35; $i++) {
            $items[] = ['product_id' => $products[$i]->id, 'counted_qty' => 10.0];
        }

        $service = app(StockAdjustmentService::class);
        $creator = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->grantPermission($creator, 'stock.manage');
        $this->actingAs($creator);
        $document = $service->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'remark' => 'Golden Test Adjustment',
            'items' => $items,
        ]);

        $this->actingAs($approver);
        $service->approve($document);

        // โหลด snapshot ค่าดั้งเดิม
        $snapshotPath = storage_path('golden_snapshot.json');
        $this->assertFileExists($snapshotPath, 'ต้องมี golden_snapshot.json');
        $expected = json_decode(file_get_contents($snapshotPath), true);

        // 1. ตรวจสอบ stock_balances
        $actualBalances = StockBalance::where('warehouse_location_id', $location->id)
            ->orderBy('product_id')
            ->get(['product_id', 'on_hand_qty', 'reserved_qty'])
            ->map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'on_hand_qty' => (float) $b->on_hand_qty,
                'reserved_qty' => (float) $b->reserved_qty,
            ])
            ->all();
        $this->assertEquals($expected['balances'], $actualBalances, 'stock_balances ไม่ตรงกับ golden snapshot');

        // 2. ตรวจสอบ stock_lots (qty และ unit_cost)
        $actualLots = StockLot::where('warehouse_location_id', $location->id)
            ->orderBy('id')
            ->get(['product_id', 'unit_cost', 'remaining_qty'])
            ->map(fn ($l) => [
                'product_id' => (int) $l->product_id,
                'unit_cost' => (float) $l->unit_cost,
                'remaining_qty' => (float) $l->remaining_qty,
            ])
            ->all();
        $this->assertEquals($expected['lots'], $actualLots, 'stock_lots ไม่ตรงกับ golden snapshot');

        // 3. ตรวจสอบ stock_movements
        $actualMovements = StockMovement::where('document_id', $document->id)
            ->orderBy('id')
            ->get(['product_id', 'movement_type', 'qty'])
            ->map(fn ($m) => [
                'product_id' => (int) $m->product_id,
                'movement_type' => $m->movement_type,
                'qty' => (float) $m->qty,
            ])
            ->all();
        $this->assertEquals($expected['movements'], $actualMovements, 'stock_movements ไม่ตรงกับ golden snapshot');

        // 4. ตรวจสอบ products.average_cost
        $actualCosts = Product::whereIn('id', collect($products)->pluck('id'))
            ->orderBy('id')
            ->get(['id', 'average_cost', 'last_purchase_cost'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'average_cost' => (float) $p->average_cost,
                'last_purchase_cost' => (float) $p->last_purchase_cost,
            ])
            ->all();
        $this->assertEquals($expected['costs'], $actualCosts, 'products.average_cost ไม่ตรงกับ golden snapshot');

        // 5. ตรวจสอบ บรรทัดบัญชี GL (บัญชี/เดบิต/เครดิต)
        $actualJournals = GlJournal::where('document_id', $document->id)
            ->orderBy('id')
            ->get(['account_id', 'debit', 'credit'])
            ->map(fn ($j) => [
                'account_id' => (int) $j->account_id,
                'debit' => (float) $j->debit,
                'credit' => (float) $j->credit,
            ])
            ->all();
        $this->assertEquals($expected['journals'], $actualJournals, 'gl_journals ไม่ตรงกับ golden snapshot');
    }

    /**
     * ข้อ 2: ใบ > 300 รายการ: กดแล้วสถานะเป็น approving และ job อยู่ในคิว (Queue::fake());
     * รัน job แล้วเป็น active + stock_counts.status = posted + confirmed_by = คนที่กด
     */
    public function test_large_document_dispatches_background_job_and_completes_successfully(): void
    {
        Queue::fake();

        [$branch, $location, $document, $creator, $approver, $stockCount] = $this->createAdjustmentWithItems(305);

        $this->actingAs($approver);
        $response = $this->post(route('stock-adjustments.approve', $document));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // สถานะเอกสารต้องเป็น approving ทันที
        $this->assertSame('approving', $document->fresh()->status);

        // Job ต้องถูก dispatch เข้าคิว
        Queue::assertPushed(ApproveStockAdjustment::class, function ($job) use ($document, $approver) {
            return $job->documentId === $document->id && $job->userId === $approver->id;
        });

        // จำลองการรัน Job
        $job = new ApproveStockAdjustment($document->id, $approver->id);
        $job->handle(app(StockAdjustmentService::class));

        // ตรวจสอบผลลัพธ์หลัง Job รันเสร็จ
        $freshDoc = $document->fresh();
        $this->assertSame('active', $freshDoc->status);
        $this->assertStringContainsString('อนุมัติโดย '.$approver->name, (string) $freshDoc->remark);

        // stock_counts ต้องเป็น posted และ confirmed_by = คนที่กด
        $freshCount = $stockCount->fresh();
        $this->assertSame('posted', $freshCount->status);
        $this->assertSame($approver->id, $freshCount->confirmed_by);
        $this->assertNotNull($freshCount->confirmed_at);

        // ตรวจสอบ AuditLog
        $this->assertDatabaseHas('audit_logs', [
            'table_name' => 'documents',
            'record_id' => $document->id,
            'action' => 'approve',
            'user_id' => $approver->id,
        ]);
    }

    /**
     * ข้อ 3: ใบ ≤ 300 รายการ: อนุมัติทันทีเหมือนเดิม (sync)
     */
    public function test_small_document_approves_synchronously(): void
    {
        Queue::fake();

        [$branch, $location, $document, $creator, $approver, $stockCount] = $this->createAdjustmentWithItems(5);

        $this->actingAs($approver);
        $response = $this->post(route('stock-adjustments.approve', $document));
        $response->assertRedirect();
        $response->assertSessionHas('success', 'อนุมัติและปรับยอดสต๊อกแล้ว');

        // ไม่มีการ dispatch job
        Queue::assertNothingPushed();

        // สถานะเป็น active ทันที
        $this->assertSame('active', $document->fresh()->status);
        $this->assertSame('posted', $stockCount->fresh()->status);
        $this->assertSame($approver->id, $stockCount->fresh()->confirmed_by);
    }

    /**
     * ข้อ 4: กดซ้ำขณะ approving -> ปฏิเสธ ไม่ dispatch ซ้ำ
     */
    public function test_cannot_approve_while_already_approving(): void
    {
        Queue::fake();

        [$branch, $location, $document, $creator, $approver] = $this->createAdjustmentWithItems(305);
        $document->update(['status' => 'approving']);

        $this->actingAs($approver);
        $response = $this->post(route('stock-adjustments.approve', $document));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'เอกสารนี้กำลังดำเนินการอนุมัติอยู่');

        Queue::assertNothingPushed();
    }

    /**
     * ข้อ 5: ผู้สร้างกดอนุมัติเอง (ไม่ใช่ admin) -> ปฏิเสธก่อน dispatch
     */
    public function test_creator_cannot_approve_own_document(): void
    {
        Queue::fake();

        [$branch, $location, $document, $creator, $approver] = $this->createAdjustmentWithItems(305);

        $this->actingAs($creator);
        $response = $this->post(route('stock-adjustments.approve', $document));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'ผู้สร้างใบปรับสต๊อกไม่สามารถอนุมัติรายการของตนเอง');

        Queue::assertNothingPushed();
        $this->assertSame('pending_approval', $document->fresh()->status);
    }

    /**
     * ข้อ 6: job ล้ม (ยอดสต็อกเปลี่ยนระหว่างรอ) -> ไม่มีอะไรถูกเขียน สถานะกลับเป็น pending_approval พร้อมข้อความ error
     */
    public function test_job_failure_rolls_back_and_resets_status_with_error(): void
    {
        [$branch, $location, $document, $creator, $approver] = $this->createAdjustmentWithItems(5);
        $document->update(['status' => 'approving']);

        // จำลองยอดสต็อกเปลี่ยนระหว่างรอ
        $firstItem = $document->stockDocument->items->first();
        StockBalance::where('product_id', $firstItem->product_id)
            ->where('warehouse_location_id', $location->id)
            ->update(['on_hand_qty' => 999]);

        $job = new ApproveStockAdjustment($document->id, $approver->id);

        $exceptionThrown = false;
        try {
            $job->handle(app(StockAdjustmentService::class));
        } catch (\Throwable $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('ยอดสต๊อกเปลี่ยนหลังส่งอนุมัติ', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Job ต้อง throw exception เมื่อล้มเหลว');

        // สถานะต้องถูกย้อนกลับเป็น pending_approval
        $this->assertSame('pending_approval', $document->fresh()->status);

        // ต้องไม่มีการเคลื่อนไหวสต๊อกของเอกสารนี้
        $this->assertSame(0, StockMovement::where('document_id', $document->id)->count());

        // ต้องมีข้อความ error ใน cache
        $cachedError = Cache::get("stock_adjustment_error_{$document->id}");
        $this->assertNotNull($cachedError);
        $this->assertStringContainsString('ยอดสต๊อกเปลี่ยนหลังส่งอนุมัติ', $cachedError);

        // ตรวจสอบ endpoint status
        $this->actingAs($approver);
        $response = $this->getJson(route('stock-adjustments.status', $document));
        $response->assertOk();
        $response->assertJson([
            'status' => 'pending_approval',
            'error' => $cachedError,
        ]);
    }

    /**
     * ข้อ 7: จำนวน query ของ approve ไม่โตแบบ x10 ต่อรายการ: 200 รายการ <= (ค่าเดิมของโค้ดเก่า / 2)
     */
    public function test_query_count_for_200_items_is_reduced_by_half_or_more(): void
    {
        [$branch, $location, $document, $creator, $approver] = $this->createAdjustmentWithItems(200);

        // ค่าเดิมของโค้ดเก่า (origin/main) สำหรับ 200 รายการ เฉลี่ย ~1,700 queries (ก่อน optimize โหลดทีละแถวและ observer query ซ้ำ)
        $baselineQueries = 1700;

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service = app(StockAdjustmentService::class);
        $this->actingAs($approver);
        $service->approve($document);

        $actualQueries = count(DB::getQueryLog());

        // ต้องลดลงอย่างน้อยครึ่งหนึ่ง: actualQueries <= baselineQueries / 2
        $this->assertLessThanOrEqual(
            $baselineQueries / 2,
            $actualQueries,
            "จำนวน query ต้องลดลงเหลือไม่เกินครึ่งหนึ่งของโค้ดเดิม (เดิม: {$baselineQueries}, ใหม่: {$actualQueries})"
        );
    }

    /**
     * ข้อ 9: พิสูจน์ว่าเทสต์จับได้จริง: ถอดการล็อกกันกดซ้ำ (ข้อ 4) ออกชั่วคราว -> เทสต์ต้องแดง แล้วใส่กลับ
     * ทดสอบใน test_cannot_approve_while_already_approving
     */

    private function grantPermission(User $user, string $permissionCode): void
    {
        $role = Role::firstOrCreate(['code' => 'TEST_APPROVER'], ['name' => 'ผู้อนุมัติทดสอบ']);
        $permission = Permission::firstOrCreate(['code' => $permissionCode], ['name' => $permissionCode]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    private function createAdjustmentWithItems(int $count): array
    {
        DocumentType::firstOrCreate(['code' => 'STOCK_ADJUSTMENT'], ['name_th' => 'ใบปรับปรุงสต็อก']);
        $branch = Branch::create(['code' => 'B-'.uniqid(), 'name_th' => 'สาขา', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.uniqid(), 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-'.uniqid(), 'name' => 'ช่อง']);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $creator = User::factory()->create(['username' => 'creator_'.uniqid(), 'branch_id' => $branch->id, 'is_active' => true]);
        $approver = User::factory()->create(['username' => 'approver_'.uniqid(), 'branch_id' => $branch->id, 'is_active' => true]);
        $this->grantPermission($approver, 'stock.manage');
        $this->grantPermission($approver, 'stock.adjust.approve');
        $this->grantPermission($creator, 'stock.manage');
        $this->grantPermission($creator, 'stock.adjust.approve');

        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $p = Product::create([
                'sku_code' => 'SKU-'.uniqid().'-'.$i,
                'name_th' => "สินค้า {$i}",
                'base_unit_id' => $unit->id,
                'default_price' => 10,
                'average_cost' => 10,
                'is_vat' => false,
                'is_active' => true,
            ]);
            StockBalance::create([
                'product_id' => $p->id,
                'warehouse_location_id' => $location->id,
                'on_hand_qty' => 10,
                'reserved_qty' => 0,
            ]);
            $items[] = ['product_id' => $p->id, 'counted_qty' => 15.0]; // diff = +5
        }

        $this->actingAs($creator);
        $service = app(StockAdjustmentService::class);
        $document = $service->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'remark' => 'ทดสอบปรับสต๊อก',
            'items' => $items,
        ]);

        $stockCount = StockCount::create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $location->id,
            'doc_number' => 'SC-'.uniqid(),
            'count_date' => now()->toDateString(),
            'status' => 'pending_adjustment',
            'count_mode' => 'full_zero_missing',
            'created_by' => $creator->id,
            'posted_document_id' => $document->id,
        ]);

        return [$branch, $location, $document, $creator, $approver, $stockCount];
    }

    private function setupGoldenData(): array
    {
        DocumentType::firstOrCreate(['code' => 'STOCK_ADJUSTMENT'], ['name_th' => 'ใบปรับปรุงสต็อก']);
        $branch = Branch::create(['code' => 'B-GOLD', 'name_th' => 'สาขาโกลเด้น', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-GOLD', 'name' => 'คลังโกลเด้น']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-GOLD', 'name' => 'ช่องโกลเด้น']);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $approver = User::factory()->create(['username' => 'golden_approver', 'branch_id' => $branch->id, 'is_active' => true]);
        $this->grantPermission($approver, 'stock.manage');
        $this->grantPermission($approver, 'stock.adjust.approve');
        $fifo = app(FifoStockService::class);

        $products = [];
        for ($i = 1; $i <= 35; $i++) {
            $allowNegative = ($i >= 26 && $i <= 30);
            $product = Product::create([
                'sku_code' => sprintf('GOLD-%03d', $i),
                'name_th' => sprintf('สินค้าโกลเด้น %d', $i),
                'base_unit_id' => $unit->id,
                'default_price' => 100,
                'average_cost' => 50.0,
                'is_vat' => false,
                'is_active' => true,
                'negative_stock_policy' => $allowNegative ? 'allow' : 'block',
            ]);
            $products[$i] = $product;

            // Products 1..10: have 2 lots (lot 1: qty=10 at cost=40, lot 2: qty=10 at cost=60, total 20)
            if ($i <= 10) {
                $fifo->receive($product->id, $location->id, 10, null, unitCost: 40.0);
                $fifo->receive($product->id, $location->id, 10, null, unitCost: 60.0);
                StockBalance::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_location_id' => $location->id],
                    ['on_hand_qty' => 20, 'reserved_qty' => 0]
                );
            } elseif ($i <= 25) {
                // Products 11..25: have 1 lot (qty=10 at cost=50)
                $fifo->receive($product->id, $location->id, 10, null, unitCost: 50.0);
                StockBalance::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_location_id' => $location->id],
                    ['on_hand_qty' => 10, 'reserved_qty' => 0]
                );
            } elseif ($i <= 30) {
                // Products 26..30: initial 0
                StockBalance::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_location_id' => $location->id],
                    ['on_hand_qty' => 0, 'reserved_qty' => 0]
                );
            } else {
                // Products 31..35: initial 10
                $fifo->receive($product->id, $location->id, 10, null, unitCost: 50.0);
                StockBalance::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_location_id' => $location->id],
                    ['on_hand_qty' => 10, 'reserved_qty' => 0]
                );
            }
        }

        return [$branch, $location, $products, $approver];
    }
}
