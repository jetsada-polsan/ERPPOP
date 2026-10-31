<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\OcrDocument;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const NON_BYPASS = [
        'pos.sell', 'pos.void', 'pos.discount.override', 'pos.sell_below_cost', 'purchasing.approve',
        'stock.adjust.approve', 'inventory.quality.manage', 'inventory.cost.close',
        'stock.damage.approve', 'finance.note.approve', 'management.view', 'budget.manage',
        'payroll.manage', 'ecommerce.sync', 'monitoring.manage',
    ];

    /**
     * Routes intentionally left outside RoutePermissions. SearchController applies
     * its own permission group to sensitive typeaheads; the others are public,
     * account-specific, or shared navigation/notifications.
     *
     * @var array<int, string>
     */
    private const UNMAPPED_ROUTE_EXCEPTIONS = [
        'apps.launcher', 'dashboard', 'login', 'login.attempt', 'logout', 'member.liff', 'member.portal',
        'mfa.challenge', 'mfa.disable', 'mfa.enable', 'mfa.setup', 'mfa.verify', 'notifications.index',
        'password.change', 'password.update', 'portal', 'python-pos.download', 'search.branches',
        'search.customers', 'search.products', 'search.salesmen', 'search.stock-balance', 'search.suppliers',
    ];

    /** @var array<int, string> */
    private const PUBLIC_ROUTES = [
        'login', 'login.attempt', 'logout', 'pos.download', 'python-pos.download',
        'pos.release.latest', 'pos.release.download', 'mfa.challenge', 'mfa.verify', 'portal', 'member.portal',
    ];

    public function test_seeded_roles_match_the_intended_permission_matrix_exactly(): void
    {
        $expected = [
            'ACC' => 'finance.manage reports.export reports.view',
            'ACC_MGR' => 'budget.manage documents.void finance.credit.approve finance.manage finance.note.approve management.view masterdata.manage reports.all_branches reports.export reports.view',
            'BRANCH_MGR' => 'documents.void inventory.cost.close inventory.quality.manage pos.cash.manage pos.discount.override pos.sell_below_cost pos.use pos.void purchasing.approve purchasing.manage reports.export reports.view sales.assign sales.manage sales.return.unreferenced stock.adjust.approve stock.damage.approve stock.manage stock.request transport.manage',
            'CASHIER' => 'pos.sell pos.use stock.request',
            'DELIVERY' => '',
            'EXECUTIVE' => 'reports.all_branches reports.export reports.view',
            'GM' => 'budget.approve budget.manage documents.void ecommerce.sync finance.credit.approve finance.manage finance.note.approve inventory.cost.close inventory.quality.manage management.view masterdata.manage monitoring.manage payroll.approve payroll.manage pos.cash.manage pos.discount.override pos.sell pos.sell_below_cost pos.use pos.void purchasing.approve purchasing.manage reports.all_branches reports.export reports.view sales.assign sales.manage sales.return.unreferenced settings.manage stock.adjust.approve stock.damage.approve stock.manage transport.manage users.manage',
            'HR' => 'reports.view',
            'HR_MGR' => 'management.view payroll.manage reports.view users.manage',
            'IT_MGR' => 'documents.void ecommerce.sync management.view masterdata.manage monitoring.manage pos.cash.manage pos.sell pos.use pos.void purchasing.manage reports.all_branches reports.export reports.view settings.manage transport.manage users.manage',
            'MARKETING' => 'ecommerce.sync management.view masterdata.manage reports.view',
            'PURCHASING' => 'management.view masterdata.manage purchasing.manage reports.export reports.view',
            'SALES' => 'reports.view sales.manage stock.request',
            'WAREHOUSE' => 'reports.view stock.manage',
        ];

        $actual = Role::query()->with('permissions')->orderBy('code')->get()->mapWithKeys(
            fn (Role $role) => [$role->code => $role->permissions->pluck('code')->sort()->implode(' ')],
        )->all();

        ksort($expected);
        $this->assertSame($expected, $actual);

        $permissionCodes = Permission::query()->orderBy('code')->pluck('code')->all();
        $this->assertCount(35, $permissionCodes);
    }

    public function test_every_role_permission_pair_has_the_expected_effective_result(): void
    {
        foreach (Role::query()->with('permissions')->orderBy('code')->get() as $role) {
            $user = User::factory()->create([
                'username' => 'perm-matrix-'.$role->code,
                'branch_id' => null,
                'is_active' => true,
                'must_change_password' => false,
            ]);
            $user->roles()->attach($role->id);
            $assigned = $role->permissions->pluck('code')->all();
            $isFullSystemAdmin = in_array('users.manage', $assigned, true)
                && in_array('settings.manage', $assigned, true);

            foreach (Permission::query()->orderBy('code')->pluck('code') as $code) {
                $expected = in_array($code, $assigned, true)
                    || ($code === 'pos.use' && in_array('pos.sell', $assigned, true))
                    || $isFullSystemAdmin;

                $this->assertSame(
                    $expected,
                    $user->hasPermission($code),
                    "Unexpected effective permission [{$code}] for role [{$role->code}].",
                );
            }

            $this->assertSame($isFullSystemAdmin, $user->hasPermission('system.admin'));
        }
    }

    public function test_every_named_web_route_is_mapped_or_explicitly_authenticated_only(): void
    {
        $unmapped = [];
        $mappedPermissions = [];
        $webRouteCount = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('web', $route->gatherMiddleware(), true) || ! $route->getName()) {
                continue;
            }

            $webRouteCount++;
            $permission = RoutePermissions::resolve($route->getName());
            if ($permission === null) {
                $unmapped[] = $route->getName();

                continue;
            }

            $mappedPermissions[$permission] = true;
            $this->assertTrue(
                $permission === 'system.admin' || Permission::where('code', $permission)->exists(),
                "Route [{$route->getName()}] references missing permission [{$permission}].",
            );
        }

        sort($unmapped);
        $expectedUnmapped = self::UNMAPPED_ROUTE_EXCEPTIONS;
        sort($expectedUnmapped);
        $this->assertSame($expectedUnmapped, $unmapped);
        $this->assertGreaterThanOrEqual(400, $webRouteCount);
        $this->assertCount(28, array_keys($mappedPermissions));

        $criticalRoutes = [
            'pos.index' => 'pos.use',
            'pos.shift.open' => 'pos.sell',
            'pos.checkout' => 'pos.sell',
            'pos.receipts.void' => 'pos.void',
            'stock-adjustments.index' => 'stock.manage',
            'stock-adjustments.approve' => 'stock.adjust.approve',
            'stock-issues.approve' => 'stock.damage.approve',
            'purchase-orders.approve' => 'purchasing.approve',
            'sale-returns.approve' => 'finance.note.approve',
            'documents.void' => 'documents.void',
            'management-controls.index' => 'management.view',
            'management-controls.budgets.approve' => 'budget.approve',
            'management-controls.payroll.approve' => 'payroll.approve',
            'operations.index' => 'system.admin',
            'database-structure.index' => 'system.admin',
            'pos.control' => 'reports.view',
            'bplus.pos-preparation' => 'pos.use',
            'bplus.pos-workbench' => 'pos.use',
        ];
        foreach ($criticalRoutes as $routeName => $permission) {
            $this->assertSame($permission, RoutePermissions::resolve($routeName), "Incorrect permission for [{$routeName}].");
        }
    }

    public function test_route_middleware_allows_and_denies_each_permission_used_by_routes(): void
    {
        $allPermissionsRole = Role::create(['code' => 'TEST_ALL_ROUTE_PERMISSIONS', 'name' => 'Route permission test']);
        $allPermissionsRole->permissions()->sync(Permission::query()->pluck('id')->all());
        $allowedUser = User::factory()->create([
            'username' => 'route-permission-allowed', 'is_active' => true, 'must_change_password' => false,
        ]);
        $allowedUser->roles()->attach($allPermissionsRole->id);

        $deniedUser = User::factory()->create([
            'username' => 'route-permission-denied', 'is_active' => true, 'must_change_password' => false,
        ]);
        $deniedRole = Role::create(['code' => 'TEST_NO_ROUTE_PERMISSIONS', 'name' => 'Route permission deny test']);
        $deniedUser->roles()->attach($deniedRole->id);

        $routesByPermission = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('web', $route->gatherMiddleware(), true) || ! $route->getName()) {
                continue;
            }

            if (in_array($route->getName(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $permission = RoutePermissions::resolve($route->getName());
            if ($permission !== null) {
                $routesByPermission[$permission] ??= $route;
            }
        }

        foreach ($routesByPermission as $permission => $route) {
            $request = Request::create('/'.ltrim($route->uri(), '/'), 'GET');
            $request->setRouteResolver(fn () => $route);
            Auth::guard()->setUser($allowedUser);
            $response = app(ErpAuthorize::class)->handle($request, fn () => response('', 204));
            $this->assertSame(204, $response->getStatusCode(), "Allowed actor was blocked for [{$route->getName()}].");

            Auth::guard()->setUser($deniedUser);
            try {
                app(ErpAuthorize::class)->handle($request, fn () => response('', 204));
                $this->fail("Unprivileged actor reached [{$route->getName()}] ({$permission}).");
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode(), "Wrong denial for [{$route->getName()}].");
            }
        }

        Auth::logout();
    }

    public function test_sensitive_search_routes_apply_permission_groups(): void
    {
        $cases = [
            'search.customers' => [
                'allowed' => ['SALES', 'PURCHASING', 'CASHIER'],
                'denied' => ['WAREHOUSE', 'HR'],
                'query' => [],
            ],
            'search.products' => [
                'allowed' => ['SALES', 'PURCHASING', 'WAREHOUSE', 'CASHIER'],
                'denied' => ['HR', 'DELIVERY'],
                'query' => [],
            ],
            'search.stock-balance' => [
                'allowed' => ['WAREHOUSE', 'CASHIER', 'SALES'],
                'denied' => ['HR'],
                'query' => ['product_id' => 1, 'warehouse_location_id' => 1],
            ],
        ];

        foreach ($cases as $routeName => $case) {
            foreach ($case['allowed'] as $roleCode) {
                $user = $this->userWithRole($roleCode, strtolower($routeName.'-'.$roleCode));
                $this->actingAs($user)->getJson(route($routeName, $case['query']))->assertOk();
            }

            foreach ($case['denied'] as $roleCode) {
                $user = $this->userWithRole($roleCode, strtolower($routeName.'-'.$roleCode));
                $response = $this->actingAs($user)->getJson(route($routeName, $case['query']));
                $this->assertSame(403, $response->getStatusCode(), "Role [{$roleCode}] unexpectedly accessed [{$routeName}]: {$response->getContent()}");
            }
        }

        // These lookups return only identifiers/names and intentionally remain
        // available to any authenticated employee for shared selectors.
        $basicUser = $this->userWithRole('HR', 'basic-selectors');
        foreach (['search.suppliers', 'search.salesmen', 'search.branches'] as $routeName) {
            $this->actingAs($basicUser)->getJson(route($routeName))->assertOk();
        }
    }

    public function test_ocr_api_requires_purchasing_permission_on_every_endpoint(): void
    {
        $branch = Branch::create(['code' => 'PM-OCR', 'name_th' => 'สาขา OCR', 'is_active' => true]);
        $document = OcrDocument::create([
            'uuid' => (string) Str::uuid(),
            'document_type' => 'tax_invoice',
            'original_file_path' => 'ocr/test.txt',
            'original_file_name' => 'test.txt',
            'file_mime_type' => 'text/plain',
            'branch_id' => $branch->id,
        ]);
        $hrUser = $this->userWithRole('HR', 'ocr-api-no-access');
        $purchasingUser = $this->userWithRole('PURCHASING', 'ocr-api-access');

        $requests = [
            ['getJson', route('api.ocr.documents.index')],
            ['postJson', route('api.ocr.documents.store')],
            ['getJson', route('api.ocr.documents.show', $document)],
            ['get', route('api.ocr.documents.file', $document)],
            ['postJson', route('api.ocr.documents.process', $document)],
            ['postJson', route('api.ocr.documents.review', $document)],
            ['postJson', route('api.ocr.documents.approve', $document)],
            ['postJson', route('api.ocr.documents.reject', $document)],
            ['postJson', route('api.ocr.documents.post', $document)],
        ];

        foreach ($requests as [$method, $url]) {
            $response = $this->actingAs($hrUser)->{$method}($url, []);
            $this->assertSame(403, $response->getStatusCode(), "Unauthorized user reached OCR API [{$url}].");
        }

        $this->actingAs($purchasingUser)->getJson(route('api.ocr.documents.index'))->assertOk();

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'api.ocr.')) {
                $this->assertContains('auth', $route->gatherMiddleware(), "OCR API route [{$route->getName()}] is missing auth middleware.");
            }
        }
    }

    public function test_api_routes_have_the_expected_authentication_boundary(): void
    {
        $posCount = 0;
        $memberCount = 0;
        $ocrCount = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($name, 'api.pos.')) {
                $posCount++;
                $this->assertContains('pos.device', $middleware, "POS API route [{$name}] is missing device-token auth.");
            } elseif (str_starts_with($name, 'api.member.me.')) {
                $memberCount++;
                $this->assertContains('member.portal', $middleware, "Member API route [{$name}] is missing member auth.");
            } elseif (str_starts_with($name, 'api.ocr.')) {
                $ocrCount++;
                $this->assertContains('auth', $middleware, "OCR API route [{$name}] is missing session auth.");
            }
        }

        $this->assertSame(25, $posCount);
        $this->assertSame(6, $memberCount);
        $this->assertSame(9, $ocrCount);
        $this->assertSame('api.line.webhook', Route::getRoutes()->getByName('api.line.webhook')->getName());

        $webhookResponse = $this->postJson(route('api.line.webhook'), []);
        $webhookResponse->assertUnauthorized();
    }

    public function test_search_stock_and_cost_data_respect_branch_scope(): void
    {
        $ownBranch = Branch::create(['code' => 'PM-A', 'name_th' => 'สาขาตัวเอง', 'is_active' => true]);
        $otherBranch = Branch::create(['code' => 'PM-B', 'name_th' => 'อีกสาขา', 'is_active' => true]);
        $ownWarehouse = Warehouse::create(['branch_id' => $ownBranch->id, 'code' => 'PM-WA', 'name' => 'คลังตัวเอง']);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'code' => 'PM-WB', 'name' => 'คลังอื่น']);
        $centralWarehouse = Warehouse::create(['branch_id' => null, 'code' => 'PM-WC', 'name' => 'คลังส่วนกลาง']);
        $ownLocation = WarehouseLocation::create(['warehouse_id' => $ownWarehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $otherLocation = WarehouseLocation::create(['warehouse_id' => $otherWarehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $centralLocation = WarehouseLocation::create(['warehouse_id' => $centralWarehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $ownBranch->update(['default_warehouse_location_id' => $ownLocation->id]);
        $unit = ProductUnit::create(['code' => 'PM-EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'PM-SKU', 'name_th' => 'สินค้าทดสอบสิทธิ์', 'base_unit_id' => $unit->id,
            'default_price' => 50, 'average_cost' => 123.45, 'is_vat' => false, 'is_active' => true,
        ]);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $ownLocation->id,
            'on_hand_qty' => 12, 'reserved_qty' => 2,
        ]);
        StockBalance::create([
            'product_id' => $product->id, 'warehouse_location_id' => $otherLocation->id,
            'on_hand_qty' => 99, 'reserved_qty' => 0,
        ]);
        $ownLot = StockLot::create([
            'product_id' => $product->id, 'warehouse_location_id' => $ownLocation->id,
            'lot_number' => 'OWN-LOT', 'received_date' => '2026-10-01',
            'initial_qty' => 10, 'remaining_qty' => 8, 'unit_cost' => 5,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'warehouse_location_id' => $ownLocation->id,
            'stock_lot_id' => $ownLot->id, 'movement_type' => 'out', 'qty' => 2,
            'movement_date' => '2026-10-01',
        ]);
        $otherLot = StockLot::create([
            'product_id' => $product->id, 'warehouse_location_id' => $otherLocation->id,
            'lot_number' => 'OTHER-LOT', 'received_date' => '2026-10-02',
            'initial_qty' => 10, 'remaining_qty' => 9, 'unit_cost' => 5,
        ]);
        StockMovement::create([
            'product_id' => $product->id, 'warehouse_location_id' => $otherLocation->id,
            'stock_lot_id' => $otherLot->id, 'movement_type' => 'out', 'qty' => 1,
            'movement_date' => '2026-10-02',
        ]);

        $branchManager = $this->userWithRole('BRANCH_MGR', 'branch-manager-scope', $ownBranch->id);
        $this->actingAs($branchManager)
            ->getJson(route('search.stock-balance', [
                'product_id' => $product->id,
                'warehouse_location_id' => $ownLocation->id,
            ]))
            ->assertOk()
            ->assertExactJson(['on_hand_qty' => 12]);

        $this->actingAs($branchManager)
            ->getJson(route('search.stock-balance', [
                'product_id' => $product->id,
                'warehouse_location_id' => $otherLocation->id,
            ]))
            ->assertForbidden();

        $this->actingAs($branchManager)
            ->getJson(route('search.stock-balance', [
                'product_id' => $product->id,
                'warehouse_location_id' => $centralLocation->id,
            ]))
            ->assertForbidden();

        $this->actingAs($branchManager)
            ->getJson(route('search.products', ['branch_id' => $ownBranch->id, 'q' => 'PM-SKU', 'include_lots' => 1]))
            ->assertOk()
            ->assertJsonPath('0.on_hand_qty', 12)
            ->assertJsonMissingPath('0.average_cost')
            ->assertJsonPath('0.lots.0.lot_number', 'OWN-LOT')
            ->assertJsonMissing(['lot_number' => 'OTHER-LOT']);

        $this->actingAs($branchManager)
            ->getJson(route('search.products', ['branch_id' => $otherBranch->id, 'q' => 'PM-SKU']))
            ->assertForbidden();
    }

    public function test_expired_or_inactive_branch_assignment_does_not_reopen_global_role_scope(): void
    {
        $firstBranch = Branch::create(['code' => 'PM-X', 'name_th' => 'สาขา X', 'is_active' => true]);
        $secondBranch = Branch::create(['code' => 'PM-Y', 'name_th' => 'สาขา Y', 'is_active' => true]);
        $salesRole = Role::where('code', 'SALES')->firstOrFail();
        $assignments = [
            ['active' => false, 'from' => null, 'to' => null],
            ['active' => true, 'from' => now()->addDay(), 'to' => null],
            ['active' => true, 'from' => null, 'to' => now()->subDay()],
        ];

        foreach ($assignments as $index => $assignment) {
            $user = $this->userWithRole('SALES', 'inactive-branch-role-'.$index);
            $user->branchRoles()->attach($salesRole->id, [
                'branch_id' => $firstBranch->id,
                'is_active' => $assignment['active'],
                'effective_from' => $assignment['from'],
                'effective_to' => $assignment['to'],
            ]);

            $this->assertFalse($user->canAccessBranch($firstBranch->id, 'sales.manage'));
            $this->assertFalse($user->canAccessBranch($secondBranch->id, 'sales.manage'));
        }

        $activeUser = $this->userWithRole('SALES', 'active-branch-role');
        $activeUser->branchRoles()->attach($salesRole->id, [
            'branch_id' => $firstBranch->id,
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
        ]);
        $this->assertTrue($activeUser->canAccessBranch($firstBranch->id, 'sales.manage'));
        $this->assertFalse($activeUser->canAccessBranch($secondBranch->id, 'sales.manage'));
    }

    private function userWithRole(string $roleCode, string $username, ?int $branchId = null): User
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        $user = User::factory()->create([
            'username' => $username,
            'branch_id' => $branchId,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
