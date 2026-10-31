<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosAdminReassignTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(string $code, Branch $branch): Salesman
    {
        $role = Role::firstOrCreate(['code' => 'T_SELLER'], ['name' => 'seller']);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'x'])->id]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach($role->id);

        return Salesman::create(['code' => $code, 'name' => $code, 'branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
    }

    private function admin(): User
    {
        $role = Role::create(['code' => 'T_ADMIN', 'name' => 'admin']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'x'])->id);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function device(Branch $branch, User $user): PosDevice
    {
        return PosDevice::create(['name' => 'POS-1', 'user_id' => $user->id, 'branch_id' => $branch->id, 'terminal_code' => 'POS-1', 'token_hash' => 'h'.$user->id]);
    }

    public function test_device_can_switch_to_another_cashier_of_its_branch_only(): void
    {
        $a = Branch::create(['code' => 'A', 'name_th' => 'A', 'is_active' => true]);
        $b = Branch::create(['code' => 'B', 'name_th' => 'B', 'is_active' => true]);
        $first = $this->cashier('C1', $a);
        $second = $this->cashier('C2', $a);
        $outsider = $this->cashier('C3', $b);
        $device = $this->device($a, $first->user);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('pos-admin.devices.assign', $device), ['user_id' => $outsider->user_id]);
        $this->assertSame($first->user_id, $device->fresh()->user_id);

        $this->actingAs($admin)->post(route('pos-admin.devices.assign', $device), ['user_id' => $second->user_id]);
        $this->assertSame($second->user_id, $device->fresh()->user_id);
        $this->assertSame($device->token_hash, $device->fresh()->token_hash);
    }

    public function test_cashier_moves_branch_and_loses_the_old_one(): void
    {
        $a = Branch::create(['code' => 'A', 'name_th' => 'A', 'is_active' => true]);
        $b = Branch::create(['code' => 'B', 'name_th' => 'B', 'is_active' => true]);
        $cashier = $this->cashier('C1', $a);
        $role = $cashier->user->roles()->first();
        DB::table('user_branch_roles')->insert(['user_id' => $cashier->user_id, 'branch_id' => $a->id, 'role_id' => $role->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $device = $this->device($a, $cashier->user);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('pos-admin.cashiers.move', $cashier), ['branch_id' => $b->id]);
        $this->assertSame($a->id, $cashier->fresh()->branch_id, 'ยังเป็นคนประจำเครื่องสาขาเดิม ต้องย้ายไม่ได้');

        $device->delete();
        $this->actingAs($admin)->post(route('pos-admin.cashiers.move', $cashier), ['branch_id' => $b->id]);

        $user = $cashier->fresh()->user;
        $this->assertSame($b->id, $cashier->fresh()->branch_id);
        $this->assertSame($b->id, $user->branch_id);
        $this->assertTrue($user->canUsePosDevice($b->id) || $user->canAccessBranch($b->id, 'pos.sell'));
        $this->assertFalse($user->canAccessBranch($a->id, 'pos.sell'));
    }

    public function test_open_shift_blocks_device_switch(): void
    {
        $a = Branch::create(['code' => 'A', 'name_th' => 'A', 'is_active' => true]);
        $first = $this->cashier('C1', $a);
        $second = $this->cashier('C2', $a);
        $device = $this->device($a, $first->user);
        $terminal = PosTerminal::create(['branch_id' => $a->id, 'code' => 'POS-1', 'name' => 'x']);
        DB::table('pos_shifts')->insert(['shift_no' => 'S1', 'branch_id' => $a->id, 'pos_terminal_id' => $terminal->id, 'cashier_id' => $first->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin())->post(route('pos-admin.devices.assign', $device), ['user_id' => $second->user_id]);

        $this->assertSame($first->user_id, $device->fresh()->user_id);
    }
}
