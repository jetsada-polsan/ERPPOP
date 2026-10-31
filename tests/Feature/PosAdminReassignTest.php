<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_cashier_moves_branch_and_loses_the_old_one(): void
    {
        $a = Branch::create(['code' => 'A', 'name_th' => 'A', 'is_active' => true]);
        $b = Branch::create(['code' => 'B', 'name_th' => 'B', 'is_active' => true]);
        $cashier = $this->cashier('C1', $a);
        $role = $cashier->user->roles()->first();
        DB::table('user_branch_roles')->insert(['user_id' => $cashier->user_id, 'branch_id' => $a->id, 'role_id' => $role->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $device = $this->device($a, $cashier->user);
        $admin = $this->admin();

        $spare = $this->cashier('C2', $a);
        $this->actingAs($admin)->post(route('pos-admin.cashiers.move', $cashier), ['branch_id' => $b->id]);

        $this->assertSame($spare->user_id, $device->fresh()->user_id);
        $user = $cashier->fresh()->user;
        $this->assertSame($b->id, $cashier->fresh()->branch_id);
        $this->assertSame($b->id, $user->branch_id);
        $this->assertTrue($user->canUsePosDevice($b->id) || $user->canAccessBranch($b->id, 'pos.sell'));
        $this->assertFalse($user->canAccessBranch($a->id, 'pos.sell'));
    }

    public function test_admin_sets_a_ready_to_use_four_digit_pin(): void
    {
        $branch = Branch::create(['code' => 'A', 'name_th' => 'A', 'is_active' => true]);
        $cashier = $this->cashier('C1', $branch);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.pos-cashiers.set-pin', $cashier), ['pin' => '12a4'])->assertSessionHasErrors('pin');
        $this->actingAs($admin)->post(route('settings.pos-cashiers.set-pin', $cashier), ['pin' => '4827'])->assertRedirect();

        $other = $this->cashier('C2', $branch);
        $this->actingAs($admin)->post(route('settings.pos-cashiers.set-pin', $other), ['pin' => '4827']);
        $this->assertNull($other->fresh()->pos_pin_hash);

        $this->assertTrue(Hash::check('4827', $cashier->fresh()->user->posCredential->pin_hash));
        $this->assertFalse((bool) $cashier->fresh()->must_change_pin);
        $this->assertFalse((bool) $cashier->fresh()->user->posCredential->force_pin_change);
    }
}
