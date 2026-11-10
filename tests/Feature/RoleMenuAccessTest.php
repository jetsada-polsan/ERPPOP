<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\ErpMenu;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 17: สิทธิ์ ERP ใช้งานได้ 100% (ตรวจทุกบทบาท ทุกเมนู)
 *
 * ตรวจสอบความถูกต้องของสิทธิ์การเข้าถึงเมนูจริงของระบบ:
 * 1. ทุกบทบาท x ทุกเมนูที่ ErpMenu::forUser แสดง -> GET ต้องได้ 200 หรือ 302 (ห้ามได้ 403 หรือ 5xx)
 *    และต้องผ่าน Middleware จริงของระบบ (ไม่ใช้ withoutMiddleware)
 * 2. เมนูที่ไม่แสดงใน ErpMenu (เมื่อผู้ใช้ไม่มีสิทธิ์ตาม RoutePermissions) -> GET ต้องได้ 403 เสมอ
 */
class RoleMenuAccessTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->branch = Branch::firstOrCreate(
            ['code' => 'B001'],
            ['name_th' => 'สาขาหลัก', 'is_active' => true]
        );
        $wh = Warehouse::firstOrCreate(
            ['code' => 'WH001'],
            ['branch_id' => $this->branch->id, 'name' => 'คลังหลัก', 'is_active' => true]
        );
        $loc = WarehouseLocation::firstOrCreate(
            ['code' => 'LOC001'],
            ['warehouse_id' => $wh->id, 'name' => 'จุดเก็บสินค้า']
        );
        $this->branch->update(['default_warehouse_location_id' => $loc->id]);
    }

    public function test_every_role_can_open_every_menu_item_visible_to_it(): void
    {
        $roles = Role::query()->with('permissions')->orderBy('code')->get();
        $this->assertNotEmpty($roles, 'ต้องมีบทบาทในระบบ');

        $allItems = $this->collectAllMenuItems();
        $totalChecked = 0;
        $failures = [];

        foreach ($roles as $role) {
            $user = $this->createUserForRole($role);
            $visibleMap = $this->getVisibleMenuMap($user);

            foreach ($allItems as $item) {
                $key = $this->itemKey($item);
                if (! isset($visibleMap[$key])) {
                    continue;
                }

                $totalChecked++;
                $url = route($item['route'], $item['params']);
                $response = $this->actingAs($user)->get($url);
                $status = $response->getStatusCode();

                if ($status === 403) {
                    $failures[] = "[$role->code] VISIBLE BUT GOT 403: {$item['section']} / {$item['label']} ({$item['route']})";
                } elseif ($status >= 500) {
                    $failures[] = "[$role->code] VISIBLE BUT GOT $status: {$item['section']} / {$item['label']} ({$item['route']})";
                } elseif (! in_array($status, [200, 302], true)) {
                    $failures[] = "[$role->code] VISIBLE UNEXPECTED STATUS $status: {$item['section']} / {$item['label']} ({$item['route']})";
                }
            }
        }

        $this->assertGreaterThan(300, $totalChecked, 'ต้องตรวจสอบเมนูที่มองเห็นได้มากกว่า 300 รายการ');
        $this->assertSame([], $failures, "พบเมนูที่แสดงในเมนูแต่ผู้ใช้เปิดแล้วติดข้อผิดพลาด:\n".implode("\n", $failures));
    }

    public function test_every_hidden_menu_item_returns_403_for_unauthorized_role(): void
    {
        $roles = Role::query()->with('permissions')->orderBy('code')->get();
        $allItems = $this->collectAllMenuItems();
        $totalChecked = 0;
        $failures = [];

        foreach ($roles as $role) {
            $user = $this->createUserForRole($role);
            $visibleMap = $this->getVisibleMenuMap($user);

            foreach ($allItems as $item) {
                $key = $this->itemKey($item);
                if (isset($visibleMap[$key])) {
                    continue;
                }

                $reqPerm = $item['perm'];
                // Only test routes that require an explicit permission the user lacks
                if ($reqPerm === null || $user->hasPermission($reqPerm)) {
                    continue;
                }

                $totalChecked++;
                $url = route($item['route'], $item['params']);
                $response = $this->actingAs($user)->get($url);
                $status = $response->getStatusCode();

                if ($status !== 403) {
                    $failures[] = "[$role->code] HIDDEN BUT GOT $status (expected 403): {$item['section']} / {$item['label']} ({$item['route']}) req: $reqPerm";
                }
            }
        }

        $this->assertGreaterThan(500, $totalChecked, 'ต้องตรวจสอบเมนูที่ถูกซ่อนมากกว่า 500 รายการ');
        $this->assertSame([], $failures, "พบเมนูที่ซ่อนอยู่แต่ผู้ใช้ที่ไม่มีสิทธิ์ยังสามารถเปิดได้ (ไม่เป็น 403):\n".implode("\n", $failures));
    }

    public function test_dashboard_is_accessible_to_all_authenticated_roles(): void
    {
        $roles = Role::query()->orderBy('code')->get();

        foreach ($roles as $role) {
            $user = $this->createUserForRole($role);
            $response = $this->actingAs($user)->get(route('dashboard'));

            $this->assertTrue(
                in_array($response->getStatusCode(), [200, 302], true),
                "Role [{$role->code}] must be able to open dashboard, got {$response->getStatusCode()}"
            );
        }
    }

    public function test_unauthenticated_requests_cannot_access_protected_menus(): void
    {
        $response = $this->get(route('quotations.index'));
        $this->assertTrue(
            $response->isRedirect(route('login')) || $response->getStatusCode() === 401,
            'Guest should be redirected to login'
        );
    }

    /**
     * @return array<int, array{section: string, label: string, route: string, params: array<string, mixed>, perm: ?string}>
     */
    private function collectAllMenuItems(): array
    {
        $items = [];
        foreach (ErpMenu::all() as $section) {
            foreach ($section['items'] as $item) {
                $items[] = [
                    'section' => $section['label'],
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'params' => $item['params'] ?? [],
                    'perm' => RoutePermissions::resolve($item['route']),
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<string, bool>
     */
    private function getVisibleMenuMap(User $user): array
    {
        $visible = [];
        foreach (ErpMenu::forUser($user) as $section) {
            foreach ($section['items'] as $item) {
                $visible[$this->itemKey($item)] = true;
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemKey(array $item): string
    {
        return $item['route'].'|'.json_encode($item['params'] ?? []);
    }

    private function createUserForRole(Role $role): User
    {
        $user = User::factory()->create([
            'username' => 'uat_role_'.strtolower($role->code).'_'.uniqid(),
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
