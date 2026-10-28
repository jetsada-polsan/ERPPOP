<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1 สาขา 1 user: แคชเชียร์กลางไม่ผูก user, หัวหน้าผูก user ของสาขาเพื่อให้ POS ให้สิทธิ์หัวหน้า
 */
class SetupBranchPosStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_up_the_branch_login_a_shared_cashier_and_a_manager(): void
    {
        $branch = Branch::create(['code' => 'B901', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $user = User::factory()->create(['username' => 'branch901', 'is_active' => false]);

        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B901', '--user' => 'branch901', '--cashier' => 'b901', '--manager' => 'm901'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue((bool) $user->is_active);
        $this->assertSame($branch->id, $user->branch_id);

        $cashier = Salesman::where('code', 'B901')->first();
        $manager = Salesman::where('code', 'M901')->first();
        // โปรแกรม POS ถือคนขาย = user เครื่องแสดงเฉพาะ user ที่ผูก จึงต้องมี user ของแคชเชียร์เอง
        $this->assertSame('b901', $cashier->user?->username);
        $this->assertSame($branch->id, $cashier->user->branch_id);
        $this->assertSame($user->id, $manager->user_id);
        $this->assertTrue((bool) $cashier->must_change_pin);
        $this->assertNull($cashier->pos_pin_hash); // เจ้าของตั้ง PIN เอง
        $this->assertNull($cashier->user->posCredential);

        $role = new \ReflectionMethod(PosApiController::class, 'cashierRole');
        $this->assertSame('manager', $role->invoke(app(PosApiController::class), $manager->fresh('user.roles')));
        $this->assertSame('cashier', $role->invoke(app(PosApiController::class), $cashier->fresh()));
    }

    public function test_each_till_gets_its_own_cashier_code_and_rerunning_adds_tills_without_resetting_pins(): void
    {
        Branch::create(['code' => 'B903', 'name_th' => 'สาขาสามเครื่อง', 'is_active' => true]);
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        User::factory()->create(['username' => 'branch903']);
        $run = fn (array $cashiers) => $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B903', '--user' => 'branch903', '--cashier' => $cashiers, '--manager' => 'm903'])->assertSuccessful();

        $run(['b903-1']);
        Salesman::where('code', 'B903-1')->first()->setPin('482917', false);
        $run(['b903-1', 'b903-2', 'b903-3']);
        $run(['b903', 'b903-2']);
        $this->assertSame('แคชเชียร์ สาขาสามเครื่อง เครื่อง 1', Salesman::where('code', 'B903')->value('name'));

        $this->assertSame(['B903-1', 'B903-2', 'B903-3'], Salesman::where('code', 'like', 'B903-%')->orderBy('code')->pluck('code')->all());
        $this->assertSame('แคชเชียร์ สาขาสามเครื่อง เครื่อง 2', Salesman::where('code', 'B903-2')->value('name'));
        // เครื่องแรกตั้ง PIN แล้ว เพิ่มเครื่องไม่ทำให้ต้องเปลี่ยน PIN ใหม่ และ PIN ยังอยู่
        $first = Salesman::where('code', 'B903-1')->first();
        $this->assertFalse((bool) $first->must_change_pin);
        $this->assertNotNull($first->pos_pin_hash);
        $this->assertTrue((bool) Salesman::where('code', 'B903-3')->value('must_change_pin'));
    }

    public function test_dry_run_saves_nothing(): void
    {
        Branch::create(['code' => 'B902', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        User::factory()->create(['username' => 'branch902', 'is_active' => false]);

        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B902', '--user' => 'branch902', '--cashier' => 'b902', '--manager' => 'm902', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse(Salesman::where('code', 'B902')->exists());
        $this->assertFalse((bool) User::where('username', 'branch902')->value('is_active'));
    }
}
