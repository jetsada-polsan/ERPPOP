<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PosBuild;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosBuildCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('services.github_pos_build', [
            'token' => 'github-test-token',
            'repository' => 'jetsada-polsan/ERPPOP',
            'workflow' => 'pos-python-windows-uat.yml',
            'ref' => 'main',
        ]);
    }

    public function test_admin_can_open_the_build_center(): void
    {
        $this->actingAs($this->admin())->get('/settings/pos-builds')
            ->assertOk()
            ->assertSee('POS Build Center')
            ->assertSee('Build โปรแกรม');
    }

    public function test_cashier_cannot_open_or_trigger_the_build_center(): void
    {
        $cashier = User::factory()->create([
            'username' => 'pos-build-cashier',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'POS_BUILD_CASHIER', 'name' => 'Cashier']);
        $permission = Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'ใช้งาน POS']);
        $role->permissions()->attach($permission->id);
        $cashier->roles()->attach($role->id);

        $this->actingAs($cashier)->get('/settings/pos-builds')->assertForbidden();
        $this->actingAs($cashier)->post('/settings/pos-builds', [
            'version' => '0.5.1',
            'source_ref' => 'main',
        ])->assertForbidden();

        $this->assertDatabaseCount('pos_builds', 0);
    }

    public function test_admin_can_dispatch_a_windows_build_without_exposing_the_token(): void
    {
        Http::fake([
            'api.github.com/repos/jetsada-polsan/ERPPOP/actions/workflows/pos-python-windows-uat.yml/dispatches' => Http::response('', 204),
        ]);

        $this->actingAs($this->admin())->post('/settings/pos-builds', [
            'version' => '0.5.1',
            'source_ref' => 'main',
        ])->assertRedirect('/settings/pos-builds');

        $build = PosBuild::sole();
        $this->assertSame('queued', $build->status);
        $this->assertSame('0.5.1', $build->version);
        $this->assertNotNull($build->dispatched_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pos_build_dispatched', 'record_id' => $build->id]);

        Http::assertSent(function (Request $request) use ($build) {
            return $request->url() === 'https://api.github.com/repos/jetsada-polsan/ERPPOP/actions/workflows/pos-python-windows-uat.yml/dispatches'
                && $request['inputs']['build_id'] === $build->build_uuid
                && $request['inputs']['publish'] === true
                && $request->hasHeader('Authorization', 'Bearer github-test-token');
        });
    }

    public function test_build_status_can_be_refreshed_from_github(): void
    {
        $build = PosBuild::create([
            'build_uuid' => (string) Str::uuid(),
            'version' => '0.5.2',
            'channel' => 'uat',
            'source_ref' => 'main',
            'status' => 'queued',
            'requested_by' => $this->admin()->id,
        ]);

        Http::fake([
            'api.github.com/repos/jetsada-polsan/ERPPOP/actions/workflows/pos-python-windows-uat.yml/runs*' => Http::response([
                'workflow_runs' => [[
                    'id' => 123456,
                    'display_title' => 'POS Python 0.5.2 · '.$build->build_uuid,
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'html_url' => 'https://github.com/jetsada-polsan/ERPPOP/actions/runs/123456',
                    'head_sha' => str_repeat('a', 40),
                    'run_started_at' => now()->subMinutes(5)->toIso8601String(),
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]),
        ]);

        $this->actingAs($this->admin())->post("/settings/pos-builds/{$build->id}/refresh")
            ->assertRedirect();

        $build->refresh();
        $this->assertSame('success', $build->status);
        $this->assertSame(123456, $build->github_run_id);
        $this->assertNotNull($build->completed_at);
    }

    public function test_only_one_active_build_can_be_dispatched_at_a_time(): void
    {
        PosBuild::create([
            'build_uuid' => (string) Str::uuid(),
            'version' => '0.5.0',
            'channel' => 'uat',
            'source_ref' => 'main',
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->admin())->post('/settings/pos-builds', [
            'version' => '0.5.1',
            'source_ref' => 'main',
        ])->assertSessionHasErrors('build');

        $this->assertDatabaseCount('pos_builds', 1);
        Http::assertNothingSent();
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'username' => 'pos-build-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::firstOrCreate(['code' => 'POS_BUILD_ADMIN'], ['name' => 'POS Build Admin']);
        $permission = Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'จัดการตั้งค่า']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}
