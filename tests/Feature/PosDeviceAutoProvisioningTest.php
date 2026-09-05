<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosDeviceAutoProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_terminal_and_token_for_the_selected_user(): void
    {
        $branch = $this->branch('B001');
        $cashier = User::factory()->create([
            'username' => 'cashier-b001',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $this->grantPosSell($cashier);
        Salesman::create([
            'branch_id' => $branch->id,
            'user_id' => $cashier->id,
            'code' => 'CASHIER-B001',
            'name' => 'แคชเชียร์ B001',
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware()->post(route('settings.pos-token.issue'), [
            'pos_branch_id' => $branch->id,
            'pos_user_id' => $cashier->id,
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('pos_token');

        $device = PosDevice::firstOrFail();
        $this->assertSame($branch->id, $device->branch_id);
        $this->assertSame($cashier->id, $device->user_id);
        $this->assertSame('POS-B001-01', $device->terminal_code);
        $this->assertSame('PopCentral POS POS-B001-01', $device->name);
    }

    public function test_admin_choice_wins_when_multiple_users_can_sell(): void
    {
        $branch = $this->branch('B003');
        $first = User::factory()->create([
            'username' => 'cashier-first',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $second = User::factory()->create([
            'username' => 'cashier-second',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $this->grantPosSell($first);
        $this->grantPosSell($second);
        foreach ([$first, $second] as $index => $user) {
            Salesman::create([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'code' => 'CASHIER-B003-'.($index + 1),
                'name' => 'แคชเชียร์ B003 '.($index + 1),
                'is_active' => true,
            ]);
        }

        $this->withoutMiddleware()->post(route('settings.pos-token.issue'), [
            'pos_branch_id' => $branch->id,
            'pos_user_id' => $second->id,
        ])->assertRedirect(route('settings.index'));

        $this->assertSame($second->id, PosDevice::firstOrFail()->user_id);
    }

    public function test_admin_cannot_bind_an_active_user_without_pos_sell(): void
    {
        $branch = $this->branch('B004');
        $staff = User::factory()->create([
            'username' => 'staff-b004',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        Salesman::create([
            'branch_id' => $branch->id,
            'user_id' => $staff->id,
            'code' => 'STAFF-B004',
            'name' => 'พนักงานไม่มีสิทธิ์ POS',
            'is_active' => true,
        ]);

        $this->withoutMiddleware()->post(route('settings.pos-token.issue'), [
            'pos_branch_id' => $branch->id,
            'pos_user_id' => $staff->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('pos_devices', 0);
    }

    public function test_it_does_not_create_a_device_when_branch_has_no_cashier_with_pos_sell(): void
    {
        $branch = $this->branch('B002');
        $staff = User::factory()->create([
            'username' => 'staff-b002',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->withoutMiddleware()
            ->post(route('settings.pos-token.issue'), [
                'pos_branch_id' => $branch->id,
                'pos_user_id' => $staff->id,
            ])->assertRedirect();

        $this->assertDatabaseCount('pos_devices', 0);
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
    }

    private function grantPosSell(User $user): void
    {
        $role = Role::firstOrCreate(['code' => 'POS_SELLER'], ['name' => 'POS Seller']);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->roles()->attach($role->id);
    }
}
