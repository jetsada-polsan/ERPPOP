<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create([
            'username' => 'cleanup-tester-'.uniqid(),
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::firstOrCreate(['code' => 'ADMIN'], ['name' => 'Admin']);
        $perms = Permission::all();
        if ($perms->isEmpty()) {
            foreach ([
                'system.admin', 'reports.view', 'pos.use', 'pos.sell', 'sales.manage',
                'purchasing.manage', 'inventory.manage', 'finance.manage', 'settings.manage'
            ] as $p) {
                $perms->push(Permission::create(['code' => $p, 'name' => $p]));
            }
        }
        $role->permissions()->sync($perms->pluck('id'));
        $user->roles()->sync([$role->id]);

        return $user->fresh();
    }

    public function test_removed_legacy_bplus_routes_return_404(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)->get('/bplus/pos-preparation')->assertNotFound();
        $this->actingAs($user)->post('/bplus/pos-preparation', [])->assertNotFound();
        $this->actingAs($user)->get('/bplus/pos-workbench')->assertNotFound();
    }

    public function test_erp_menu_does_not_contain_removed_bplus_routes(): void
    {
        $user = $this->adminUser();
        $menu = ErpMenu::forUser($user);

        $allRoutes = collect($menu)->flatMap(fn ($sec) => collect($sec['items'])->pluck('route'))->all();

        $this->assertNotContains('bplus.pos-preparation', $allRoutes);
        $this->assertNotContains('bplus.pos-workbench', $allRoutes);
    }

    public function test_features_page_loads_and_contains_no_bplus_jargon(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->get(route('features.index'));
        $response->assertOk();

        $content = $response->getContent();
        $textOnly = strip_tags($content);
        $this->assertStringNotContainsStringIgnoringCase('bplus', $textOnly);
        $this->assertStringNotContainsStringIgnoringCase('PS1', $content);
        $this->assertStringNotContainsStringIgnoringCase('PS2', $content);
        $this->assertStringNotContainsStringIgnoringCase('PS3', $content);
        $this->assertStringNotContainsStringIgnoringCase('PS4', $content);
        $this->assertStringNotContainsStringIgnoringCase('PS5', $content);
        $this->assertStringNotContainsStringIgnoringCase('ลำดับงานอิง', $content);
    }

    public function test_core_modules_manual_page_loads_and_contains_no_bplus_jargon(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->get(route('core-modules.index'));
        $response->assertOk();

        $content = $response->getContent();
        $textOnly = strip_tags($content);
        $this->assertStringNotContainsStringIgnoringCase('bplus', $textOnly);
        $this->assertStringNotContainsStringIgnoringCase('BplusBack', $content);
    }

    public function test_kept_bplus_logic_routes_still_accessible_and_working(): void
    {
        $user = $this->adminUser();
        Branch::firstOrCreate(['code' => 'B01'], ['name_th' => 'สาขาหลัก', 'is_active' => true]);

        $this->actingAs($user)->get(route('bplus.purchase-planning'))->assertOk();
        $this->actingAs($user)->get(route('bplus.approvals'))->assertOk();
        $this->actingAs($user)->get(route('bplus.finance'))->assertOk();
        $this->actingAs($user)->get(route('bplus.tax'))->assertOk();
        $this->actingAs($user)->get(route('bplus.qr-payments'))->assertOk();
        $this->actingAs($user)->get(route('bplus.show-price'))->assertOk();
    }
}
