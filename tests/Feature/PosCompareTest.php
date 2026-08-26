<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCompareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('pos.compare'))->assertRedirect(route('login'));
    }

    public function test_authorized_users_get_both_pos_frames(): void
    {
        $user = User::factory()->create([
            'username' => 'pos-compare-user',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'POS_COMPARE', 'name' => 'POS compare']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'Use POS'])->id);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get(route('pos.compare'))
            ->assertOk()
            ->assertSee('/pos?compare=1', false)
            ->assertSee('pos-desktop-preview', false)
            ->assertSee('โหมด Vue เป็นข้อมูลตัวอย่าง', false);
    }

    public function test_browser_preview_asset_is_published(): void
    {
        $this->assertFileExists(public_path('pos-desktop-preview/index.html'));
    }
}
