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
 * เครื่องที่ผูกกับแคชเชียร์เครื่อง 1 ต้องเห็นแคชเชียร์ของตัวเอง + หัวหน้าสาขา
 * (เดิมเห็นแค่ user ที่ผูก หัวหน้าเลยอนุมัติลดราคาไม่ได้ และแคชเชียร์ที่ไม่ผูก user มองไม่เห็นเลย)
 */
class PosTillCashierListTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_till_lists_its_own_cashier_and_the_branch_manager_only(): void
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

        $this->assertEqualsCanonicalizing(['b901', 'b901mgr'], $rows->pluck('code')->all());
        $this->assertSame('manager', $rows->firstWhere('code', 'b901mgr')['role']);
        $this->assertSame('cashier', $rows->firstWhere('code', 'b901')['role']);
    }
}
