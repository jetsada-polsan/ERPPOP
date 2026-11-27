<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LayoutQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database']);
        Cache::setDefaultDriver('database');
    }

    public function test_dashboard_and_settings_stay_within_query_budget_under_database_cache(): void
    {
        $admin = User::factory()->create([
            'username' => 'budget-admin',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $gmRole = Role::where('code', 'GM')->firstOrFail();
        $admin->roles()->attach($gmRole->id);

        // Warm up database cache and compiled views once per page.
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk();
        $this->actingAs($admin->fresh())->get('/settings')->assertOk();

        // Measured totals after B1+B2: /dashboard = 17, /settings = 10 (allowance +3).
        $budgets = [
            '/dashboard' => 20,
            '/settings' => 13,
        ];

        foreach ($budgets as $uri => $maxTotalQueries) {
            $freshAdmin = $admin->fresh();
            $queries = $this->captureQueries(function () use ($freshAdmin, $uri): void {
                $this->actingAs($freshAdmin)->get($uri)->assertOk();
            });

            $appSettingsCacheReads = 0;
            $rolePermissionExists = 0;

            foreach ($queries as $query) {
                $sql = strtolower($query['sql']);
                $bindingsJson = (string) json_encode($query['bindings']);

                if (str_contains($sql, 'cache') && str_contains($bindingsJson, 'app_settings.all')) {
                    $appSettingsCacheReads++;
                }

                if (str_contains($sql, 'exists(') && (str_contains($sql, 'roles') || str_contains($sql, 'permissions'))) {
                    $rolePermissionExists++;
                }
            }

            $this->assertSame(
                1,
                $appSettingsCacheReads,
                "{$uri} อ่าน cache key app_settings.all {$appSettingsCacheReads} ครั้ง (ต้องอ่านจาก database cache 1 ครั้งต่อ request)"
            );
            $this->assertLessThanOrEqual(
                1,
                $rolePermissionExists,
                "{$uri} ยิง query exists ของ roles/permissions {$rolePermissionExists} ครั้ง (ต้องไม่เกิน 1 ครั้งต่อ request)"
            );
            $this->assertLessThanOrEqual(
                $maxTotalQueries,
                count($queries),
                "{$uri} ยิง query รวม ".count($queries)." ครั้ง (เกินเพดาน {$maxTotalQueries})"
            );
        }
    }

    public function test_app_setting_memo_clears_on_set_and_clear_cache(): void
    {
        AppSetting::set('erp_theme', 'ocean');
        $this->assertSame('ocean', AppSetting::get('erp_theme'));

        // Updating via set() must clear both database cache and request memo immediately.
        AppSetting::set('erp_theme', 'emerald');
        $this->assertSame('emerald', AppSetting::get('erp_theme'));

        // Direct DB updates followed by clearCache() must also refresh the memo.
        DB::table('app_settings')->where('key', 'erp_theme')->update(['value' => 'navy']);
        AppSetting::clearCache();
        $this->assertSame('navy', AppSetting::get('erp_theme'));
    }

    public function test_has_global_permission_excludes_branch_roles_and_avoids_repeated_exists_queries(): void
    {
        $branch = Branch::create(['code' => 'B99', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $user = User::factory()->create([
            'username' => 'branch-only-user',
            'branch_id' => $branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::create(['code' => 'BRANCH_ONLY_ADMIN', 'name' => 'Branch Only Admin']);
        $usersManage = Permission::firstOrCreate(['code' => 'users.manage'], ['name' => 'users.manage']);
        $settingsManage = Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'settings.manage']);
        $role->permissions()->sync([$usersManage->id, $settingsManage->id]);
        $user->branchRoles()->attach($role->id, ['branch_id' => $branch->id, 'is_active' => true]);

        $freshUser = $user->fresh();
        $this->assertTrue($freshUser->hasPermission('users.manage'));
        $this->assertFalse($freshUser->hasGlobalPermission('users.manage'));
        $this->assertFalse($freshUser->hasGlobalPermission('settings.manage'));
        $this->assertFalse($freshUser->hasPermission('system.admin'));

        $globalUser = User::factory()->create([
            'username' => 'global-admin-memo',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $globalUser->roles()->attach($role->id);
        $freshGlobal = $globalUser->fresh();

        $queries = $this->captureQueries(function () use ($freshGlobal): void {
            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue($freshGlobal->hasGlobalPermission('users.manage'));
                $this->assertTrue($freshGlobal->hasGlobalPermission('settings.manage'));
                $this->assertFalse($freshGlobal->hasGlobalPermission('nonexistent.permission'));
            }
        });

        $existsQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains(strtolower($q['sql']), 'exists(')
        );
        $this->assertSame([], $existsQueries);
        $this->assertLessThanOrEqual(2, count($queries), 'ควรโหลด global roles/permissions ครั้งเดียวต่อ user instance');
    }

    /**
     * @return list<array{sql: string, bindings: array<int|string, mixed>}>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        $active = true;

        DB::listen(function ($query) use (&$queries, &$active): void {
            if (! $active) {
                return;
            }
            $queries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
            ];
        });

        try {
            $callback();
        } finally {
            $active = false;
        }

        return $queries;
    }
}
