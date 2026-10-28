<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Models\Branch;
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
        $user = User::factory()->create(['username' => 'branch901', 'is_active' => false]);

        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B901', '--user' => 'branch901', '--cashier' => 'b901', '--manager' => 'm901'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue((bool) $user->is_active);
        $this->assertSame($branch->id, $user->branch_id);

        $cashier = Salesman::where('code', 'B901')->first();
        $manager = Salesman::where('code', 'M901')->first();
        $this->assertNull($cashier->user_id);
        $this->assertSame($user->id, $manager->user_id);
        $this->assertTrue((bool) $cashier->must_change_pin);
        $this->assertNull($cashier->pos_pin_hash); // เจ้าของตั้ง PIN เอง

        $role = new \ReflectionMethod(PosApiController::class, 'cashierRole');
        $this->assertSame('manager', $role->invoke(app(PosApiController::class), $manager->fresh('user.roles')));
        $this->assertSame('cashier', $role->invoke(app(PosApiController::class), $cashier->fresh()));
    }

    public function test_dry_run_saves_nothing(): void
    {
        Branch::create(['code' => 'B902', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        User::factory()->create(['username' => 'branch902', 'is_active' => false]);

        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B902', '--user' => 'branch902', '--cashier' => 'b902', '--manager' => 'm902', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse(Salesman::where('code', 'B902')->exists());
        $this->assertFalse((bool) User::where('username', 'branch902')->value('is_active'));
    }
}
