<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ทุกเครื่องในสาขาต้องเห็นคนขายทุกคนที่มีสิทธิ์ในสาขานั้น ไม่เห็นคนสาขาอื่น
 * (เดิมเห็นแค่ user ที่ผูก หัวหน้าเลยอนุมัติลดราคาไม่ได้ และแคชเชียร์ที่ไม่ผูก user มองไม่เห็นเลย)
 */
class PosTillCashierListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_till_lists_every_cashier_of_its_branch_only(): void
    {
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $branch = Branch::create(['code' => 'B901', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $other = Branch::create(['code' => 'B902', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        User::factory()->create(['username' => 'b901mgr', 'branch_id' => $branch->id]);
        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B901', '--user' => 'b901mgr', '--cashier' => ['b901', 'b901-2'], '--manager' => 'm901'])->assertSuccessful();
        $otherManager = User::factory()->create(['username' => 'b902mgr', 'branch_id' => $other->id]);
        $otherManager->roles()->attach(Role::where('code', 'BRANCH_MGR')->value('id'));
        Salesman::create(['branch_id' => $other->id, 'user_id' => $otherManager->id, 'code' => 'M902', 'name' => 'หัวหน้าอื่น', 'is_active' => true]);

        $till = User::where('username', 'b901')->sole();
        $device = PosDevice::create(['name' => 'เครื่อง 1', 'user_id' => $till->id, 'branch_id' => $branch->id, 'terminal_code' => 'POS-B901-01', 'token_hash' => hash('sha256', 't')]);
        $request = Request::create('/api/pos/cashiers', 'GET');
        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => $till);

        $rows = collect(app(PosApiController::class)->cashiers($request)->getData(true)['cashiers']);

        $this->assertEqualsCanonicalizing(['b901', 'b901-2', 'b901mgr'], $rows->pluck('code')->all());
        $this->assertSame('manager', $rows->firstWhere('code', 'b901mgr')['role']);
        $this->assertSame('cashier', $rows->firstWhere('code', 'b901')['role']);
    }

    public function test_only_a_manager_pin_approves_and_the_active_cashier_stays(): void
    {
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $branch = Branch::create(['code' => 'B903', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        User::factory()->create(['username' => 'b903mgr', 'branch_id' => $branch->id]);
        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B903', '--user' => 'b903mgr', '--cashier' => ['b903'], '--manager' => 'm903'])->assertSuccessful();
        $manager = Salesman::where('code', 'M903')->sole();
        $cashier = Salesman::where('code', 'B903')->sole();
        $manager->setPin('482165', false);
        $cashier->setPin('731904', false);
        $device = PosDevice::create(['name' => 'เครื่อง 1', 'user_id' => $cashier->user_id, 'branch_id' => $branch->id, 'terminal_code' => 'POS-B903-01', 'token_hash' => hash('sha256', 'a'), 'active_cashier_id' => $cashier->id]);

        $call = function (Salesman $who, string $pin) use ($device) {
            $request = Request::create('/api/pos/approver/verify', 'POST', ['cashier_id' => $who->id, 'pin' => $pin]);
            $request->attributes->set('pos_device', $device);

            return app(PosApiController::class)->verifyApprover($request)->getStatusCode();
        };

        $this->assertSame(422, $call($manager, '000000'));
        $this->assertSame(422, $call($cashier, '731904'));
        $this->assertSame(200, $call($manager, '482165'));
        $this->assertSame($cashier->id, (int) $device->fresh()->active_cashier_id);
    }
}
