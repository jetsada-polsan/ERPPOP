<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_page_is_not_cached_and_contains_a_csrf_token(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('name="_token"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_one_user_can_sign_in_to_the_erp_with_username(): void
    {
        $user = User::factory()->create([
            'username' => 'shared-erp-user',
            'password' => 'SecurePass123',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $this->get(route('login'));
        $response = $this->post(route('login.attempt'), [
            '_token' => session('_token'),
            'username' => $user->username,
            'password' => 'SecurePass123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_stale_login_token_recovers_to_a_fresh_login_page(): void
    {
        $this->get(route('login'));

        $response = $this->post(route('login.attempt'), [
            '_token' => 'stale-token',
            'username' => 'shared-erp-user',
            'password' => 'SecurePass123',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username']);
    }

    public function test_active_branch_role_is_a_real_user_permission_for_pos(): void
    {
        $branch = Branch::create([
            'code' => 'AUTH-B001',
            'name_th' => 'สาขาทดสอบสิทธิ์',
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'ใช้ POS']);
        $role = Role::create(['code' => 'AUTH_BRANCH_POS', 'name' => 'สิทธิ์ POS ประจำสาขา']);
        $role->permissions()->attach($permission->id);
        $user = User::factory()->create([
            'username' => 'branch-pos-user',
            'branch_id' => $branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->branchRoles()->attach($role->id, [
            'branch_id' => $branch->id,
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
        ]);

        $this->assertContains('pos.use', $user->permissionCodes());
        $this->actingAs($user)->get(route('pos.index'))->assertOk();
    }
}
