<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\PosOfflineSale;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
