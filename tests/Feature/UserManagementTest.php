<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use App\Support\ErpMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_explicit_branch_roles_limit_a_pos_user_to_the_assigned_branches(): void
    {
        $branchOne = Branch::create(['code' => 'B001', 'name_th' => 'สาขา 1', 'is_active' => true]);
        $branchTwo = Branch::create(['code' => 'B002', 'name_th' => 'สาขา 2', 'is_active' => true]);
        $branchThree = Branch::create(['code' => 'B003', 'name_th' => 'สาขา 3', 'is_active' => true]);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS']);
        $role = Role::create(['code' => 'MULTI_BRANCH_CASHIER', 'name' => 'แคชเชียร์หลายสาขา']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create(['username' => 'cashier_b001_b002', 'branch_id' => $branchOne->id]);
        $user->roles()->attach($role->id);

        foreach ([$branchOne, $branchTwo] as $branch) {
            DB::table('user_branch_roles')->insert([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'role_id' => $role->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertTrue($user->canAccessBranch($branchOne->id, 'pos.sell'));
        $this->assertTrue($user->canAccessBranch($branchTwo->id, 'pos.sell'));
        $this->assertFalse($user->canAccessBranch($branchThree->id, 'pos.sell'));
    }

    public function test_legacy_salesman_page_is_not_a_separate_people_menu_anymore(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin-legacy-salesmen',
            'must_change_password' => false,
        ]);

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->get(route('salesmen.index'))
            ->assertRedirect(route('users.index'));

        $menuItems = collect(ErpMenu::all())->flatMap(fn (array $section) => $section['items'] ?? []);

        $this->assertFalse($menuItems->contains(fn (array $item) => ($item['route'] ?? null) === 'salesmen.index'));
        $this->assertFalse($menuItems->contains(fn (array $item) => in_array($item['label'] ?? '', ['พนักงานขาย', 'รหัสขายเดิม', 'แฟ้มพนักงาน'], true)));
    }

    public function test_pos_seller_gets_a_cashier_profile_without_creating_a_duplicate_person_first(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin-create-pos-user',
            'must_change_password' => false,
        ]);
        $branch = Branch::create(['code' => 'AUTO-POS', 'name_th' => 'สาขา POS อัตโนมัติ', 'is_active' => true]);
        $role = Role::create(['code' => 'AUTO_POS_SELLER', 'name' => 'แคชเชียร์อัตโนมัติ']);
        $permission = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS']);
        $role->permissions()->attach($permission->id);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('users.store'), [
                'username' => 'cashier-auto',
                'name' => 'แคชเชียร์สร้างครั้งเดียว',
                'branch_id' => $branch->id,
                'role_ids' => [$role->id],
                'password' => 'TempPass123',
                'password_confirmation' => 'TempPass123',
            ]);

        $response->assertRedirect(route('users.index'));

        $user = User::where('username', 'cashier-auto')->firstOrFail();
        $profile = Salesman::where('user_id', $user->id)->firstOrFail();

        $this->assertNull($user->salesman_id);
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('CASHIER-AUTO', $profile->code);
        $this->assertSame('แคชเชียร์สร้างครั้งเดียว', $profile->name);
        $this->assertSame($branch->id, $profile->branch_id);
        $this->assertTrue($profile->is_active);
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
        $this->assertNotNull($cashier->fresh()->pos_credential_version);

        // User is the POS identity now: user_pos_credentials is the record that authentication
        // actually reads. The salesmen columns above are kept only for the installed-client transition.
        $credential = \App\Models\UserPosCredential::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check($temporary, $credential->pin_hash));
        $this->assertTrue($credential->force_pin_change);
        $this->assertNull($credential->pin_changed_at);
        $this->assertNotNull($credential->credential_version);

        $this->assertNull($device->fresh()->active_cashier_id);
        $this->assertNull($device->fresh()->cashier_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'cashier_pin_reset',
            'table_name' => 'users',
            'record_id' => $user->id,
        ]);
    }

    public function test_pos_pin_reset_reuses_legacy_mapping_and_aligns_its_adapter_to_the_user_branch(): void
    {
        $admin = User::factory()->create(['username' => 'admin-pos-legacy', 'must_change_password' => false]);
        $adminRole = Role::create(['code' => 'LEGACY_PIN_ADMIN', 'name' => 'ผู้ดูแล PIN legacy']);
        $manageUsers = Permission::firstOrCreate(['code' => 'users.manage'], ['name' => 'จัดการผู้ใช้']);
        $posSell = Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย POS']);
        $adminRole->permissions()->sync([$manageUsers->id]);
        $admin->roles()->attach($adminRole->id);
        $cashierRole = Role::create(['code' => 'LEGACY_PIN_CASHIER', 'name' => 'แคชเชียร์ legacy']);
        $cashierRole->permissions()->attach($posSell->id);

        $userBranch = Branch::create(['code' => 'LEGACY-HQ', 'name_th' => 'สำนักงานใหญ่ legacy', 'is_active' => true]);
        $cashierBranch = Branch::create(['code' => 'LEGACY-BR', 'name_th' => 'สาขา legacy', 'is_active' => true]);
        $legacyUser = User::factory()->create([
            'username' => 'legacy-pos-user',
            'branch_id' => $userBranch->id,
            'salesman_id' => null,
            'is_active' => true,
        ]);
        $legacyUser->roles()->attach($cashierRole->id);
        $legacyCashier = Salesman::create([
            'branch_id' => $userBranch->id,
            'user_id' => $legacyUser->id,
            'code' => 'LEGACY-CASHIER',
            'name' => 'แคชเชียร์ legacy',
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('users.reset-pos-pin', $legacyUser));

        $response->assertRedirect(route('users.index'));
        $this->assertTrue(Hash::check(session('reset_pos_pin_result.password'), $legacyCashier->fresh()->pos_pin_hash));

        $mismatchUser = User::factory()->create([
            'username' => 'mismatch-pos-user',
            'branch_id' => $userBranch->id,
            'salesman_id' => $legacyCashier->id,
            'is_active' => true,
        ]);
        $mismatchUser->roles()->attach($cashierRole->id);
        $legacyCashier->update(['branch_id' => $cashierBranch->id]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($admin)
            ->post(route('users.reset-pos-pin', $mismatchUser));

        $response->assertRedirect(route('users.index'));
        $response->assertSessionHas('reset_pos_pin_result');
        $this->assertSame($userBranch->id, Salesman::where('user_id', $mismatchUser->id)->sole()->branch_id);
    }
}
