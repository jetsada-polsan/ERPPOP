<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function posUser(): User
    {
        $user = User::factory()->create([
            'username' => 'pos-workbench-user',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::create(['code' => 'POS_WORKBENCH', 'name' => 'POS Workbench']);
        $role->permissions()->attach(
            Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'pos.use'])->id
        );
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_pos_workbench_promotes_python_pos_instead_of_tauri_release(): void
    {
        $response = $this->actingAs($this->posUser())->get(route('bplus.pos-workbench'));

        $response->assertOk()
            ->assertSee('PopCentral Python POS')
            ->assertSee('Python + PySide6')
            ->assertSee('Local SQLite')
            ->assertSee(route('python-pos.download'), false)
            ->assertDontSee('Vue + Tauri')
            ->assertDontSee('0.1.7')
            ->assertDontSee(route('pos.download'), false);
    }

    public function test_legacy_pos_download_url_redirects_to_python_pos(): void
    {
        $this->get(route('pos.download'))
            ->assertRedirect(route('python-pos.download'));
    }
}
