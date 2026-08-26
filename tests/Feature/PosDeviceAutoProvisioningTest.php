<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosDeviceAutoProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_terminal_and_token_by_selecting_only_a_branch(): void
    {
        $branch = $this->branch('B001');
        $cashier = User::factory()->create([
            'username' => 'cashier-b001',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $this->grantPosSell($cashier);

        $response = $this->withoutMiddleware()->post(route('settings.pos-token.issue'), [
            'pos_branch_id' => $branch->id,
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('pos_token');

        $device = PosDevice::firstOrFail();
        $this->assertSame($branch->id, $device->branch_id);
        $this->assertSame($cashier->id, $device->user_id);
        $this->assertSame('POS-B001-01', $device->terminal_code);
        $this->assertSame('PopCentral POS POS-B001-01', $device->name);
    }

    public function test_it_does_not_create_a_device_when_branch_has_no_cashier_with_pos_sell(): void
    {
        $branch = $this->branch('B002');
        User::factory()->create([
            'username' => 'staff-b002',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->withoutMiddleware()
            ->post(route('settings.pos-token.issue'), ['pos_branch_id' => $branch->id])
            ->assertStatus(422);

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
