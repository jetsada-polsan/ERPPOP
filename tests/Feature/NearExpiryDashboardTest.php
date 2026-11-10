<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\ErpMenu;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NearExpiryDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_permission_and_erp_menu_registered(): void
    {
        $this->assertSame('stock.manage', RoutePermissions::resolve('stock-expiry.index'));

        $menuItems = collect(ErpMenu::all())
            ->flatMap(fn ($s) => $s['items'])
            ->pluck('route')
            ->all();

        $this->assertContains('stock-expiry.index', $menuItems);
    }

    public function test_stock_expiry_dashboard_loads_for_authorized_user(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::firstOrCreate(['code' => 'STOCK_MGR'], ['name' => 'Stock Manager']);
        $permission = Permission::firstOrCreate(['code' => 'stock.manage'], ['name' => 'Manage stock']);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->sync([$role->id]);

        $response = $this->actingAs($user)->get(route('stock-expiry.index'));
        $response->assertOk();
        $response->assertSee('สินค้าใกล้หมดอายุ');
        $response->assertSee('หมดอายุแล้ว');
        $response->assertSee('หมดอายุใน 3 วัน');
        $response->assertSee('หมดอายุใน 4-7 วัน');
        $response->assertSee('หมดอายุใน 8-15 วัน');
    }

    public function test_stock_expiry_kpis_and_window_filtering(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->withoutVite();

        [$branch, $warehouse, $location, $product] = $this->seedStockEnvironment();

        // Lot 1: Expired 5 days ago (2026-11-05)
        $lotExpired = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-EXP-01',
            'received_date' => '2026-10-01',
            'expiry_date' => '2026-11-05',
            'initial_qty' => 10,
            'remaining_qty' => 10,
            'unit_cost' => 15.00,
            'quality_status' => 'pass',
        ]);

        // Lot 2: Expiring in 2 days (2026-11-12)
        $lot3Days = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-3D-02',
            'received_date' => '2026-10-10',
            'expiry_date' => '2026-11-12',
            'initial_qty' => 5,
            'remaining_qty' => 5,
            'unit_cost' => 20.00,
            'quality_status' => 'pass',
        ]);

        // Lot 3: Expiring in 6 days (2026-11-16)
        $lot7Days = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-7D-03',
            'received_date' => '2026-10-15',
            'expiry_date' => '2026-11-16',
            'initial_qty' => 4,
            'remaining_qty' => 4,
            'unit_cost' => 25.00,
            'quality_status' => 'pass',
        ]);

        // Lot 4: Expiring in 12 days (2026-11-22)
        $lot15Days = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-15D-04',
            'received_date' => '2026-10-20',
            'expiry_date' => '2026-11-22',
            'initial_qty' => 8,
            'remaining_qty' => 8,
            'unit_cost' => 10.00,
            'quality_status' => 'pass',
        ]);

        // Lot 5: Expiring in 45 days (2026-12-25) - safe future
        $lotFuture = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-SAFE-05',
            'received_date' => '2026-10-25',
            'expiry_date' => '2026-12-25',
            'initial_qty' => 100,
            'remaining_qty' => 100,
            'unit_cost' => 5.00,
            'quality_status' => 'pass',
        ]);

        // Lot 6: Zero remaining - should not be included
        StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-ZERO-06',
            'received_date' => '2026-10-25',
            'expiry_date' => '2026-11-11',
            'initial_qty' => 10,
            'remaining_qty' => 0,
            'unit_cost' => 5.00,
            'quality_status' => 'pass',
        ]);

        $this->signIn();

        // 1. Check default window ('all' <= 15 days) and summary stats
        $response = $this->get(route('stock-expiry.index'))->assertOk();
        $summary = $response->viewData('summary');

        $this->assertSame(1, $summary['expired']['count']);
        $this->assertSame(10.0, (float) $summary['expired']['qty']);
        $this->assertSame(150.0, (float) $summary['expired']['cost']);

        $this->assertSame(1, $summary['3days']['count']);
        $this->assertSame(5.0, (float) $summary['3days']['qty']);
        $this->assertSame(100.0, (float) $summary['3days']['cost']);

        $this->assertSame(1, $summary['7days']['count']);
        $this->assertSame(4.0, (float) $summary['7days']['qty']);
        $this->assertSame(100.0, (float) $summary['7days']['cost']);

        $this->assertSame(1, $summary['15days']['count']);
        $this->assertSame(8.0, (float) $summary['15days']['qty']);
        $this->assertSame(80.0, (float) $summary['15days']['cost']);

        $this->assertSame(430.0, (float) $summary['total_at_risk_cost']);

        // Default 'all' lists all 4 lots within 15 days (excluding safe future and zero qty)
        $lots = $response->viewData('lots');
        $lotNumbers = collect($lots->items())->pluck('lot_number')->all();
        $this->assertContains('LOT-EXP-01', $lotNumbers);
        $this->assertContains('LOT-3D-02', $lotNumbers);
        $this->assertContains('LOT-7D-03', $lotNumbers);
        $this->assertContains('LOT-15D-04', $lotNumbers);
        $this->assertNotContains('LOT-SAFE-05', $lotNumbers);
        $this->assertNotContains('LOT-ZERO-06', $lotNumbers);

        // 2. Window 'expired'
        $respExpired = $this->get(route('stock-expiry.index', ['window' => 'expired']))->assertOk();
        $lotsExpired = collect($respExpired->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-EXP-01'], $lotsExpired);

        // 3. Window '3days'
        $resp3d = $this->get(route('stock-expiry.index', ['window' => '3days']))->assertOk();
        $lots3d = collect($resp3d->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-3D-02'], $lots3d);

        // 4. Window '7days'
        $resp7d = $this->get(route('stock-expiry.index', ['window' => '7days']))->assertOk();
        $lots7d = collect($resp7d->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-7D-03'], $lots7d);

        // 5. Window '15days'
        $resp15d = $this->get(route('stock-expiry.index', ['window' => '15days']))->assertOk();
        $lots15d = collect($resp15d->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-15D-04'], $lots15d);

        // 6. Window 'everything' includes the future lot
        $respEverything = $this->get(route('stock-expiry.index', ['window' => 'everything']))->assertOk();
        $lotsEvery = collect($respEverything->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertContains('LOT-SAFE-05', $lotsEvery);
        $this->assertNotContains('LOT-ZERO-06', $lotsEvery);
    }

    public function test_stock_expiry_filter_by_branch_warehouse_and_search(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->withoutVite();

        [$branch1, $wh1, $loc1, $product1] = $this->seedStockEnvironment('B1', 'SKU-001');

        $branch2 = Branch::create(['code' => 'B2', 'name_th' => 'สาขา 2', 'is_active' => true]);
        $wh2 = Warehouse::create(['branch_id' => $branch2->id, 'code' => 'WH2', 'name' => 'คลัง 2', 'is_active' => true]);
        $loc2 = WarehouseLocation::create(['warehouse_id' => $wh2->id, 'code' => 'LOC2', 'name' => 'จุด 2']);
        $product2 = Product::create([
            'sku_code' => 'SKU-002',
            'name_th' => 'ปีกไก่ทอด',
            'base_unit_id' => $product1->base_unit_id,
            'default_price' => 50,
            'average_cost' => 30,
            'is_vat' => false,
            'is_active' => true,
        ]);

        // Lot in branch 1
        StockLot::create([
            'product_id' => $product1->id,
            'warehouse_location_id' => $loc1->id,
            'lot_number' => 'LOT-B1',
            'received_date' => '2026-11-01',
            'expiry_date' => '2026-11-12',
            'initial_qty' => 10,
            'remaining_qty' => 10,
            'unit_cost' => 10,
            'quality_status' => 'pass',
        ]);

        // Lot in branch 2
        StockLot::create([
            'product_id' => $product2->id,
            'warehouse_location_id' => $loc2->id,
            'lot_number' => 'LOT-B2',
            'received_date' => '2026-11-01',
            'expiry_date' => '2026-11-12',
            'initial_qty' => 20,
            'remaining_qty' => 20,
            'unit_cost' => 12,
            'quality_status' => 'pass',
        ]);

        $this->signIn();

        // Filter by branch 1
        $resB1 = $this->get(route('stock-expiry.index', ['branch_id' => $branch1->id]))->assertOk();
        $lotsB1 = collect($resB1->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-B1'], $lotsB1);

        // Filter by warehouse 2
        $resWh2 = $this->get(route('stock-expiry.index', ['warehouse_id' => $wh2->id]))->assertOk();
        $lotsWh2 = collect($resWh2->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-B2'], $lotsWh2);

        // Search by SKU
        $resSearch = $this->get(route('stock-expiry.index', ['q' => 'SKU-002']))->assertOk();
        $lotsSearch = collect($resSearch->viewData('lots')->items())->pluck('lot_number')->all();
        $this->assertSame(['LOT-B2'], $lotsSearch);
    }

    public function test_lot_expiry_badge_renders_on_product_show_and_lot_trace(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->withoutVite();

        [$branch, $wh, $loc, $product] = $this->seedStockEnvironment();

        // Expiring in 2 days
        $lot = StockLot::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $loc->id,
            'lot_number' => 'LOT-URGENT',
            'received_date' => '2026-11-01',
            'expiry_date' => '2026-11-12',
            'initial_qty' => 5,
            'remaining_qty' => 5,
            'unit_cost' => 10,
            'quality_status' => 'pass',
        ]);

        $this->signIn();

        // Product show page
        $resProduct = $this->get(route('products.show', $product))->assertOk();
        $resProduct->assertSee('เหลือ 2 วัน');

        // Lot trace page
        $resTrace = $this->get(route('products.lots.trace', [$product, $lot]))->assertOk();
        $resTrace->assertSee('เหลือ 2 วัน');
    }

    private function seedStockEnvironment(string $branchCode = 'B01', string $sku = 'TEST-SKU'): array
    {
        $branch = Branch::firstOrCreate(['code' => $branchCode], ['name_th' => 'สาขาทดสอบ '.$branchCode, 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH-'.$branchCode], ['branch_id' => $branch->id, 'name' => 'คลัง '.$branchCode, 'is_active' => true]);
        $location = WarehouseLocation::firstOrCreate(['code' => 'LOC-'.$branchCode], ['warehouse_id' => $warehouse->id, 'name' => 'จุดเก็บ '.$branchCode]);
        $unit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กก.', 'qty_per_base_unit' => 1]);
        $product = Product::firstOrCreate(
            ['sku_code' => $sku],
            [
                'name_th' => 'สินค้าทดสอบ '.$sku,
                'base_unit_id' => $unit->id,
                'default_price' => 100,
                'average_cost' => 60,
                'is_vat' => false,
                'is_active' => true,
                'expiry_warning_days' => 7,
            ]
        );

        return [$branch, $warehouse, $location, $product];
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
