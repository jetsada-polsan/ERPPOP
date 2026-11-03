<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosReceipt;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * เครื่อง POS ยกเลิกบิลได้เมื่อหัวหน้าใส่ PIN เท่านั้น user ของเครื่อง (แคชเชียร์) ไม่มีสิทธิ์ pos.void เอง
 */
class PosManagerPinVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_manager_pin_lets_the_till_void_a_bill(): void
    {
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $branch = Branch::create(['code' => 'B904', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        User::factory()->create(['username' => 'b904mgr', 'branch_id' => $branch->id]);
        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B904', '--user' => 'b904mgr', '--cashier' => ['b904'], '--manager' => 'm904'])->assertSuccessful();
        $manager = Salesman::where('code', 'M904')->sole();
        $cashier = Salesman::where('code', 'B904')->sole();
        $manager->setPin('482165', false);
        $cashier->setPin('731904', false);
        $till = User::find($cashier->user_id);
        $this->assertFalse($till->hasPermission('pos.void'));

        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS-B904-01', 'name' => 'เครื่อง 1']);
        $device = PosDevice::create(['name' => 'เครื่อง 1', 'user_id' => $till->id, 'branch_id' => $branch->id, 'terminal_code' => 'POS-B904-01', 'token_hash' => hash('sha256', 'v'), 'active_cashier_id' => $cashier->id]);
        $shift = PosShift::create(['branch_id' => $branch->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $cashier->id, 'shift_no' => 'S904', 'opened_at' => now(), 'opening_cash' => 0, 'expected_cash' => 0, 'status' => 'open']);
        $receipt = PosReceipt::create(['pos_terminal_id' => $terminal->id, 'pos_shift_id' => $shift->id, 'receipt_no' => 'R904', 'receipt_date' => now(), 'net_sales' => 50, 'status' => 'completed']);

        $void = function (array $extra) use ($device, $till, $shift) {
            $request = Request::create('/api/pos/receipt/void', 'POST', ['receipt_no' => 'R904', 'shift_id' => $shift->id, 'reason' => 'คิดเงินผิด'] + $extra);
            $request->attributes->set('pos_device', $device);
            $request->setUserResolver(fn () => $till);
            $this->app->instance('request', $request);
            $this->actingAs($till);

            return app(PosApiController::class)->voidReceipt($request)->getStatusCode();
        };

        $this->assertSame(403, $void([]));
        $this->assertSame(422, $void(['approver_id' => $cashier->id, 'approver_pin' => '731904']));
        $this->assertSame(422, $void(['approver_id' => $manager->id, 'approver_pin' => '000000']));
        // client แอบส่ง pos_approver มาเองต้องไม่มีผล
        $this->assertSame(403, $void(['pos_approver' => $manager->id]));
        $this->assertSame('completed', $receipt->fresh()->status);

        $this->assertSame(200, $void(['approver_id' => $manager->id, 'approver_pin' => '482165']));
        $this->assertSame('void', $receipt->fresh()->status);
    }
}
