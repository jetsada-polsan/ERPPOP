<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
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
        $user->refresh();
        $this->assertTrue(Hash::check('12345678', $user->password));
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
}
