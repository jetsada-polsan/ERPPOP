<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Middleware\ErpAuthorize;
use App\Models\Permission;
use App\Models\PosOfflineSale;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Web POS checkout จริง (ไม่ mock) — กันไม่ให้บิลล้มทั้งระบบแบบ hotfix fb16bdf อีก
 * ครอบคลุม: ขายออนไลน์พร้อม X-Offline-Uuid, ส่งซ้ำได้บิลเดิม, คิวออฟไลน์ลงบิลจริงและกระทบยอดผ่าน
 */
class PosWebCheckoutRealTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    private PosShift $shift;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        ob_start();
        require base_path('tools/staging/seed_uat_data.php');
        ob_end_clean();
        $this->branchId = (int) DB::table('branches')->where('code', 'UAT')->value('id');
        $this->productId = (int) DB::table('products')->where('sku_code', 'UAT-SKU-001')->value('id');
        $user = User::factory()->create(['username' => 'web-cashier', 'branch_id' => $this->branchId, 'is_active' => true, 'must_change_password' => false]);
        $cashier = Salesman::create(['branch_id' => $this->branchId, 'code' => 'WEB-C1', 'name' => 'แคชเชียร์เว็บ', 'is_active' => true, 'user_id' => $user->id]);
        $terminal = PosTerminal::create(['branch_id' => $this->branchId, 'code' => 'WEB-T1', 'name' => 'Web POS']);
        $this->shift = PosShift::create([
            'branch_id' => $this->branchId, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id,
            'shift_no' => 'WEB-SHIFT-1', 'opened_at' => now(), 'opening_cash' => 0, 'status' => 'open',
        ]);
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs($user);
    }

    public function test_online_checkout_with_bill_uuid_posts_once_even_when_resent(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->postJson(route('pos.checkout'), $this->payload(), ['X-Offline-Uuid' => $uuid])
            ->assertOk()->assertJsonPath('success', true);
        $again = $this->postJson(route('pos.checkout'), $this->payload(), ['X-Offline-Uuid' => $uuid])
            ->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('receipt_no'), $again->json('receipt_no'));
        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame(PosOfflineSale::POSTED, PosOfflineSale::where('offline_uuid', $uuid)->value('status'));
        $this->assertReconciles();
    }

    public function test_queued_offline_bill_posts_through_the_real_checkout(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson(route('pos.offline-sales.sync'), ['sales' => [[
            'offline_uuid' => $uuid, 'sold_at' => now()->subMinutes(30)->toIso8601String(), 'total' => 102,
            'payload' => $this->payload(),
        ]]])->assertOk()->assertJsonPath('results.0.status', 'posted');

        $sale = PosOfflineSale::sole();
        $this->assertNotNull($sale->receipt_no);
        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertStringContainsString('ขายออฟไลน์', (string) DB::table('documents')->latest('id')->value('remark'));
        $this->assertReconciles();
    }

    public function test_checkout_records_manual_discount_separately_from_net_sales(): void
    {
        $permission = Permission::firstOrCreate(['code' => 'pos.discount.override'], ['name' => 'pos.discount.override']);
        $role = Role::create(['code' => 'WEB_DISCOUNT_'.uniqid(), 'name' => 'Web discount approver']);
        $role->permissions()->attach($permission->id);
        auth()->user()->roles()->attach($role->id);

        $price = (float) DB::table('products')->where('id', $this->productId)->value('default_price');
        $payload = $this->payload();
        $payload['items'][0]['unit_price'] = $price - 10;
        $payload['cash_received'] = ($price - 10) * 2;
        $payload['manual_discount_amount'] = 20;

        $this->postJson(route('pos.checkout'), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $receipt = DB::table('pos_receipts')->sole();
        $this->assertEquals($price * 2, (float) $receipt->gross_sales);
        $this->assertEquals(20, (float) $receipt->discount_amount);
        $this->assertEquals(($price - 10) * 2, (float) $receipt->net_sales);
        $this->assertEquals(($price - 10) * 2, (float) DB::table('documents')->where('id', $receipt->document_id)->value('total_amount'));
        $this->assertReconciles();
    }

    public function test_float_and_deposit_do_not_duplicate_real_sales_or_cashbook_across_shifts(): void
    {
        $this->shift->update(['opening_cash' => 2000, 'carried_float' => 2000, 'opening_variance' => 0]);
        $this->postJson(route('pos.checkout'), $this->payload())->assertOk()->assertJsonPath('success', true);
        $cashSales = (float) DB::table('pos_payments')->where('method', 'cash')->sum('amount');
        $request = Request::create('/pos/shift/close', 'POST', [
            'shift_id' => $this->shift->id, 'counted_cash' => 2000 + $cashSales, 'float_kept' => 2000,
        ]);
        $this->app->instance('request', $request);
        $closed = app(PosController::class)->closeShift($request)->getData(true)['shift'];
        $this->assertEquals($cashSales, $closed['deposit_amount']);
        $this->assertEquals($cashSales, DB::table('cash_books')->sum('cash_in'));
        $this->assertReconciles();

        $next = PosShift::create(['branch_id' => $this->branchId, 'pos_terminal_id' => $this->shift->pos_terminal_id,
            'cashier_id' => $this->shift->cashier_id, 'shift_no' => 'WEB-SHIFT-2', 'opened_at' => now(),
            'opening_cash' => 2000, 'carried_float' => 2000, 'opening_variance' => 0, 'status' => 'open']);
        $request = Request::create('/pos/shift/close', 'POST', [
            'shift_id' => $next->id, 'counted_cash' => 2000, 'float_kept' => 2000,
        ]);
        $this->app->instance('request', $request);
        app(PosController::class)->closeShift($request);
        $this->assertEquals($cashSales, DB::table('cash_books')->sum('cash_in'));
        $this->assertEquals(1, DB::table('cash_books')->count());
        $this->assertReconciles();
    }

    public function test_receipt_vat_is_computed_by_erp_per_product_and_ignores_the_till(): void
    {
        $vatProduct = (int) DB::table('products')->where('sku_code', 'UAT-SKU-002')->value('id');
        $vatPrice = (float) DB::table('products')->where('id', $vatProduct)->value('default_price');
        $plainPrice = (float) DB::table('products')->where('id', $this->productId)->value('default_price');
        $this->assertTrue((bool) DB::table('products')->where('id', $vatProduct)->value('is_vat'));
        $this->assertFalse((bool) DB::table('products')->where('id', $this->productId)->value('is_vat'));

        $payload = $this->payload();
        $payload['items'][] = ['product_id' => $vatProduct, 'qty' => 3, 'unit_price' => $vatPrice];
        $payload['cash_received'] = $plainPrice * 2 + $vatPrice * 3;
        $payload['vat_amount'] = 999; // the till's figure must never reach reporting
        $payload['vat_mode'] = 'included';

        $this->postJson(route('pos.checkout'), $payload)->assertOk()->assertJsonPath('success', true);

        $receipt = DB::table('pos_receipts')->sole();
        $document = DB::table('documents')->where('id', $receipt->document_id)->first();
        $vatGross = $vatPrice * 3;
        $expected = round($vatGross - round($vatGross * 100 / 107, 4), 2);

        $this->assertGreaterThan(0, $expected);
        $this->assertEqualsWithDelta($expected, (float) $receipt->vat_amount, 0.001, 'VAT ต้องคิดเฉพาะสินค้ามี VAT');
        $this->assertEqualsWithDelta((float) $document->vat_amount, (float) $receipt->vat_amount, 0.001, 'VAT บิลต้องเท่ากับที่ลง GL');
        $lines = DB::table('pos_receipt_items')->where('pos_receipt_id', $receipt->id)->orderBy('seq')->pluck('vat_amount');
        $this->assertEquals(0, (float) $lines[0], 'สินค้าไม่มี VAT ต้อง VAT 0');
        $this->assertEqualsWithDelta($expected, (float) $lines[1], 0.01);
        $this->assertEqualsWithDelta($plainPrice * 2 + $vatGross, (float) $receipt->net_sales, 0.001, 'ยอดเงินห้ามเปลี่ยน');
        $this->assertReconciles();
    }

    public function test_backfill_restores_vat_on_old_receipts_only_when_applied(): void
    {
        $vatProduct = (int) DB::table('products')->where('sku_code', 'UAT-SKU-002')->value('id');
        $vatPrice = (float) DB::table('products')->where('id', $vatProduct)->value('default_price');
        $payload = $this->payload();
        $payload['items'] = [['product_id' => $vatProduct, 'qty' => 1, 'unit_price' => $vatPrice]];
        $payload['cash_received'] = $vatPrice;
        $this->postJson(route('pos.checkout'), $payload)->assertOk();

        $receipt = DB::table('pos_receipts')->sole();
        $correct = (float) $receipt->vat_amount;
        $net = (float) $receipt->net_sales;
        // Simulate a receipt written before the fix.
        DB::table('pos_receipts')->update(['vat_amount' => 0]);
        DB::table('pos_receipt_items')->update(['vat_amount' => 0]);

        $this->artisan('erp:pos-receipt-vat-backfill')->assertSuccessful();
        $this->assertEquals(0, (float) DB::table('pos_receipts')->value('vat_amount'), 'dry run ต้องไม่แก้ข้อมูล');

        $this->artisan('erp:pos-receipt-vat-backfill', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta($correct, (float) DB::table('pos_receipts')->value('vat_amount'), 0.001);
        $this->assertEqualsWithDelta($correct, (float) DB::table('pos_receipt_items')->value('vat_amount'), 0.01);
        $this->assertEqualsWithDelta($net, (float) DB::table('pos_receipts')->value('net_sales'), 0.0001, 'ยอดเงินห้ามเปลี่ยน');

        // Running again changes nothing.
        $this->artisan('erp:pos-receipt-vat-backfill', ['--apply' => true])->expectsOutputToContain('บิล 0 ใบ')->assertSuccessful();
    }

    private function payload(): array
    {
        $price = (float) DB::table('products')->where('id', $this->productId)->value('default_price');

        return [
            'branch_id' => $this->branchId, 'shift_id' => $this->shift->id, 'cashier_id' => $this->shift->cashier_id,
            'method' => 'cash', 'cash_received' => $price * 2, 'change_amount' => 0,
            'items' => [['product_id' => $this->productId, 'qty' => 2, 'unit_price' => $price]],
        ];
    }

    private function assertReconciles(): void
    {
        $code = Artisan::call('uat:reconcile', ['--branch' => $this->branchId]);
        $this->assertSame(0, $code, "uat:reconcile ไม่ผ่าน:\n".Artisan::output());
    }
}
