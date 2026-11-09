<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManualPageTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithPermissions(array $permissionCodes): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::create([
            'code' => 'ROLE_' . strtoupper(uniqid()),
            'name' => 'บทบาททดสอบ',
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permissionIds[] = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code]
            )->id;
        }

        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_authorized_user_can_open_manual_and_see_booking_convert_topic(): void
    {
        $user = $this->createUserWithPermissions([
            'reports.view',
            'sales.manage',
            'pos.use',
            'stock.manage',
            'purchasing.manage',
            'masterdata.manage',
            'finance.manage',
            'settings.manage',
            'users.manage',
        ]);

        $response = $this->actingAs($user)->get(route('core-modules.index'));

        $response->assertOk();
        $response->assertSee('แปลงใบจองเป็นใบขายเชื่อ');
        $response->assertSee('DS');
        $response->assertSee('DSN');
        $response->assertSee('เปิดหน้านี้');
    }

    public function test_every_route_in_manual_config_exists(): void
    {
        $topics = config('manual');

        $this->assertIsArray($topics);
        $this->assertNotEmpty($topics);

        foreach ($topics as $topic) {
            $this->assertArrayHasKey('key', $topic);
            $this->assertArrayHasKey('section', $topic);
            $this->assertArrayHasKey('title', $topic);
            $this->assertArrayHasKey('who', $topic);
            $this->assertArrayHasKey('route', $topic);
            $this->assertArrayHasKey('steps', $topic);
            $this->assertArrayHasKey('tips', $topic);

            $this->assertGreaterThanOrEqual(3, count($topic['steps']), "Topic [{$topic['key']}] must have at least 3 steps.");
            $this->assertLessThanOrEqual(6, count($topic['steps']), "Topic [{$topic['key']}] must have at most 6 steps.");
            $this->assertLessThanOrEqual(3, count($topic['tips']), "Topic [{$topic['key']}] must have at most 3 tips.");

            $this->assertTrue(
                Route::has($topic['route']),
                "Route [{$topic['route']}] in config/manual.php for topic [{$topic['key']}] does not exist."
            );
        }
    }

    public function test_user_without_sales_permission_does_not_see_sales_topics(): void
    {
        $user = $this->createUserWithPermissions([
            'reports.view',
            'stock.manage',
        ]);

        $response = $this->actingAs($user)->get('/core-modules');

        $response->assertOk();

        $salesTopics = array_filter(
            config('manual', []),
            fn (array $topic) => ($topic['section'] ?? '') === 'sales'
        );

        $this->assertNotEmpty($salesTopics);

        foreach ($salesTopics as $topic) {
            $response->assertDontSee($topic['title']);
        }
    }

    public function test_manual_page_does_not_contain_legacy_or_four_m_jargon(): void
    {
        $user = $this->createUserWithPermissions([
            'reports.view',
            'sales.manage',
            'pos.use',
            'stock.manage',
            'purchasing.manage',
            'masterdata.manage',
            'finance.manage',
            'settings.manage',
            'users.manage',
        ]);

        $response = $this->actingAs($user)->get('/core-modules');
        $response->assertOk();

        $content = $response->getContent();

        $response->assertDontSee('BPlus');
        $response->assertDontSee('PS1');
        $response->assertDontSee('MAN · ');
        $response->assertDontSee('MONEY');
        $this->assertStringNotContainsStringIgnoringCase('bplus', strip_tags($content));
    }
}
