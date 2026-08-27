<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reset_a_user_to_the_temporary_password(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin-reset',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'username' => 'emp0099',
            'password' => 'OldPassword123',
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('users.reset-password', $user));

        $response->assertRedirect(route('users.index'));
        // รหัสสุ่มถูกส่งกลับให้แอดมินคัดลอกครั้งเดียว (ไม่ใช่ค่าตายตัวเดิม)
        $response->assertSessionHas('reset_password_result');
        $temporary = session('reset_password_result')['password'];
        $this->assertNotSame('12345678', $temporary, 'ต้องไม่ใช่รหัสตายตัวเดิม');
        $this->assertNotSame('OldPassword123', $temporary);
        $this->assertGreaterThanOrEqual(8, strlen($temporary));

        $user->refresh();
        $this->assertTrue(Hash::check($temporary, $user->password), 'รหัสที่โชว์ต้องตรงกับที่เก็บ');
        $this->assertFalse(Hash::check('12345678', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user_password_reset',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);
    }

    public function test_user_list_can_search_and_filter_accounts_waiting_for_password_change(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin-search',
            'must_change_password' => false,
        ]);
        User::factory()->create([
            'username' => 'emp-visible',
            'name' => 'พนักงานที่ค้นหา',
            'must_change_password' => true,
        ]);
        User::factory()->create([
            'username' => 'emp-hidden',
            'must_change_password' => false,
        ]);

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->get(route('users.index', ['q' => 'emp-visible', 'status' => 'must_change']))
            ->assertOk()
            ->assertSee('emp-visible')
            ->assertDontSee('emp-hidden');
    }

    public function test_admin_can_issue_a_one_time_pos_pin_and_revoke_old_device_verification(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin-pos-pin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $adminRole = Role::create(['code' => 'PIN_RESET_ADMIN', 'name' => 'ผู้ดูแล PIN ทดสอบ']);
        $adminPermission = Permission::firstOrCreate(['code' => 'users.manage'], ['name' => 'จัดการผู้ใช้']);
        $adminRole->permissions()->attach($adminPermission->id);
        $admin->roles()->attach($adminRole->id);
        $branch = Branch::create(['code' => 'PIN-RESET', 'name_th' => 'สาขาทดสอบ PIN', 'is_active' => true]);
        $cashier = Salesman::create([
            'branch_id' => $branch->id,
            'code' => 'C-PIN-RESET',
            'name' => 'แคชเชียร์ทดสอบ',
            'is_active' => true,
        ]);
        $cashier->setPin('482165', false);
        $role = Role::create(['code' => 'PIN_RESET_ROLE', 'name' => 'ขาย POS ทดสอบ']);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create([
            'username' => 'cashier-pos-reset',
            'branch_id' => $branch->id,
            'salesman_id' => $cashier->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach($role->id);
        $cashier->update(['user_id' => $user->id]);
        $device = PosDevice::create([
            'name' => 'POS PIN Reset',
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'token_hash' => hash('sha256', 'pin-reset-token'),
            'active_cashier_id' => $cashier->id,
            'active_cashier_user_id' => $user->id,
            'cashier_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('users.reset-pos-pin', $user));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('reset_pos_pin_result');
        $temporary = session('reset_pos_pin_result')['password'];
        $this->assertMatchesRegularExpression('/^\\d{6}$/', $temporary);
        $this->assertNotSame('482165', $temporary);
        $this->assertTrue(Hash::check($temporary, $cashier->fresh()->pos_pin_hash));
        $this->assertTrue($cashier->fresh()->must_change_pin);
        $this->assertNull($cashier->fresh()->pin_changed_at);
        $this->assertNull($device->fresh()->active_cashier_id);
        $this->assertNull($device->fresh()->cashier_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'cashier_pin_reset',
            'table_name' => 'salesmen',
            'record_id' => $cashier->id,
        ]);
    }
}
