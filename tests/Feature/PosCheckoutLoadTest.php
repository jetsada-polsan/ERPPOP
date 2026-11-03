<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
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
 * ยิงบิลจริงต่อเนื่อง 300 ใบ (ไม่ mock) ดูว่าเลขบิลไม่ชน ส่งซ้ำไม่เกิดบิลซ้ำ สต็อกกับยอดกะกระทบกันได้
 * ใช้เวลานาน รันเฉพาะเมื่อสั่ง: POS_LOAD_TEST=1 php artisan test --filter=PosCheckoutLoadTest
 */
class PosCheckoutLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_hundred_bills_in_a_row_post_once_each_and_reconcile(): void
    {
        if (! getenv('POS_LOAD_TEST')) {
            $this->markTestSkipped('ตั้ง POS_LOAD_TEST=1 เพื่อรัน');
        }
        ob_start();
        require base_path('tools/staging/seed_uat_data.php');
        ob_end_clean();
        $branchId = (int) DB::table('branches')->where('code', 'UAT')->value('id');
        $productId = (int) DB::table('products')->where('sku_code', 'UAT-SKU-001')->value('id');
        $price = (float) DB::table('products')->where('id', $productId)->value('default_price');
        $user = User::factory()->create(['username' => 'load-cashier', 'branch_id' => $branchId, 'is_active' => true, 'must_change_password' => false]);
        $cashier = Salesman::create(['branch_id' => $branchId, 'code' => 'LOAD-C1', 'name' => 'แคชเชียร์โหลด', 'is_active' => true, 'user_id' => $user->id]);
        $terminal = PosTerminal::create(['branch_id' => $branchId, 'code' => 'POS-UAT-09', 'name' => 'Load']);
        $shift = PosShift::create(['branch_id' => $branchId, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id, 'shift_no' => 'LOAD-1', 'opened_at' => now(), 'opening_cash' => 0, 'status' => 'open']);
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs($user);
        $payload = ['branch_id' => $branchId, 'shift_id' => $shift->id, 'cashier_id' => $cashier->id, 'method' => 'cash', 'cash_received' => $price, 'change_amount' => 0,
            'items' => [['product_id' => $productId, 'qty' => 1, 'unit_price' => $price]]];

        $started = microtime(true);
        $uuids = [];
        for ($i = 0; $i < 300; $i++) {
            $uuids[] = $uuid = (string) Str::uuid();
            $this->postJson(route('pos.checkout'), $payload, ['X-Offline-Uuid' => $uuid])->assertOk()->assertJsonPath('success', true);
        }
        $seconds = microtime(true) - $started;
        // เน็ตหลุดแล้วเครื่องส่งซ้ำ 50 ใบแรก ต้องได้บิลเดิม ไม่เกิดบิลใหม่
        foreach (array_slice($uuids, 0, 50) as $uuid) {
            $this->postJson(route('pos.checkout'), $payload, ['X-Offline-Uuid' => $uuid])->assertOk()->assertJsonPath('replayed', true);
        }

        $this->assertSame(300, DB::table('pos_receipts')->count());
        $this->assertSame(300, DB::table('pos_receipts')->distinct()->count('receipt_no'));
        $this->assertSame(300, (int) $shift->fresh()->receipt_count);
        $this->assertSame(0, Artisan::call('uat:reconcile', ['--branch' => $branchId]), Artisan::output());
        fwrite(STDERR, sprintf("\n300 บิล ใช้ %.1f วินาที (%.0f ms/บิล)\n", $seconds, $seconds * 1000 / 300));
    }
}
