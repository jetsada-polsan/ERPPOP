<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_products_are_reachable_by_page_with_an_accurate_total_without_creating_stock(): void
    {
        $branch = $this->branch('B001');
        for ($i = 1; $i <= 30; $i++) {
            $this->product(sprintf('%06d', $i));
        }
        $this->product('102204');
        $this->actingAs($this->viewer($branch));

        $first = $this->report($branch)->assertOk()->assertSee('data-report-pagination', false);
        $this->assertSame(31, $first->viewData('result')['total']);
        $this->assertCount(25, $first->viewData('result')['rows']);
        $this->assertStringContainsString('page=2', $first->viewData('result')['pagination']->nextPageUrl());

        $second = $this->report($branch, ['page' => 2])->assertOk();
        $this->assertCount(6, $second->viewData('result')['rows']);
        $this->assertContains('102204', $second->viewData('result')['rows']->pluck('sku_code')->all());
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_search_finds_zero_stock_by_sku_name_or_any_barcode_without_duplicate_rows(): void
    {
        $branch = $this->branch('B001');
        for ($i = 1; $i <= 30; $i++) {
            $this->product(sprintf('%06d', $i));
        }
        $product = $this->product('102204', 'หมูเด้ง TKF แพ็ค 500กรัม');
        foreach (['0001022040005', '0001022040012'] as $barcode) {
            ProductBarcode::create(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'barcode' => $barcode]);
        }
        $this->actingAs($this->viewer($branch));

        foreach (['102204', 'หมูเด้ง', 'tkf', '000102204', '0001022040012'] as $q) {
            $response = $this->report($branch, ['q' => $q])->assertOk()
                ->assertSee('data-live-report', false)->assertSee('report-search.js');
            $result = $response->viewData('result');
            $this->assertSame(1, $result['total'], $q);
            $row = $result['rows']->sole();
            $this->assertSame('102204', $row->sku_code);
            $this->assertEquals(0, $row->on_hand_qty);
            $this->assertStringContainsString('0001022040012', $row->barcodes);
        }
    }

    public function test_report_keeps_real_negative_fractional_and_reserved_quantities_for_the_selected_location(): void
    {
        $branch = $this->branch('B001');
        $other = $this->branch('HQ');
        $product = $this->product('102204');
        foreach ([[$branch, -1.12500001, 0.25], [$other, 99, 1]] as [$owner, $qty, $reserved]) {
            DB::table('stock_balances')->insert([
                'product_id' => $product->id, 'warehouse_location_id' => $owner->default_warehouse_location_id,
                'on_hand_qty' => $qty, 'reserved_qty' => $reserved,
            ]);
        }
        $response = $this->actingAs($this->viewer($branch))->report($branch)->assertOk()->assertSee('-1.12500001')->assertSee('0.25');
        $row = $response->viewData('result')['rows']->sole();
        $this->assertEquals(-1.12500001, $row->on_hand_qty);
        $this->assertEquals(0.25, $row->reserved_qty);
        $this->assertDatabaseCount('stock_balances', 2);
    }

    public function test_search_pagination_export_and_print_preserve_branch_permissions(): void
    {
        $own = $this->branch('B001');
        $other = $this->branch('HQ');
        $this->product('102204');
        $this->actingAs($this->viewer($own));
        $parameters = ['q' => '102204', 'branch_id' => $other->id];
        foreach ([[], ['page' => 2], ['print' => 1]] as $extra) {
            $result = $this->report($own, $parameters + $extra)->assertOk()->viewData('result');
            foreach ($result['rows'] as $row) {
                $this->assertStringContainsString('สาขา B001', $row->location_name);
                $this->assertStringNotContainsString('สาขา HQ', $row->location_name);
            }
            $this->assertSame(1, $result['total']);
        }
        $csv = $this->report($own, $parameters + ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringContainsString('สาขา B001', $csv);
        $this->assertStringNotContainsString('สาขา HQ', $csv);

        $this->actingAs($this->viewer(null));
        $this->assertSame(0, $this->report($own, ['branch_id' => 'all'])->assertOk()->viewData('result')['total']);

        $this->actingAs($this->viewer($own, true));
        $all = $this->report($own, ['branch_id' => 'all', 'q' => '102204'])->assertOk()->viewData('result');
        $this->assertSame(WarehouseLocation::count(), $all['total']);
        $this->assertTrue($all['rows']->contains(fn ($row) => str_contains($row->location_name, 'สาขา B001')));
        $this->assertTrue($all['rows']->contains(fn ($row) => str_contains($row->location_name, 'สาขา HQ')));
    }

    public function test_secondary_locations_in_an_owned_warehouse_are_included(): void
    {
        $branch = $this->branch('B001');
        $location = WarehouseLocation::findOrFail($branch->default_warehouse_location_id);
        $location->warehouse->update(['branch_id' => $branch->id]);
        WarehouseLocation::create(['warehouse_id' => $location->warehouse_id, 'code' => 'FREEZER', 'name' => 'ตู้แช่ B001']);
        $this->product('102204');

        $result = $this->actingAs($this->viewer($branch))->report($branch)->assertOk()->viewData('result');
        $this->assertSame(2, $result['total']);
        $this->assertTrue($result['rows']->contains(fn ($row) => str_contains($row->location_name, 'ตู้แช่ B001')));
    }

    public function test_csv_and_print_include_every_matching_row_even_when_opened_from_page_two(): void
    {
        $branch = $this->branch('B001');
        for ($i = 1; $i <= 31; $i++) {
            $this->product(sprintf('%06d', $i));
        }
        $this->actingAs($this->viewer($branch));
        $parameters = ['per_page' => 10, 'page' => 2];
        $csv = $this->report($branch, $parameters + ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertCount(32, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('000001', $csv);
        $this->assertStringContainsString('000031', $csv);

        $result = $this->report($branch, $parameters + ['print' => 1])->assertOk()->viewData('result');
        $this->assertCount(31, $result['rows']);
        $this->assertNull($result['pagination']);
    }

    public function test_xlsx_preserves_leading_zero_barcodes_as_text_and_quantities_as_numbers(): void
    {
        $branch = $this->branch('B001');
        $product = $this->product('102204');
        ProductBarcode::create(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'barcode' => '0001022040005']);
        DB::table('stock_balances')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $branch->default_warehouse_location_id,
            'on_hand_qty' => 20.125, 'reserved_qty' => 0,
        ]);
        $response = $this->actingAs($this->viewer($branch))->report($branch, ['export' => 'xlsx'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'stock-report-');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('0001022040005', $sheet);
        $this->assertStringContainsString('<c r="F2" s="0"><v>20.125</v></c>', $sheet);
        $zip->close();
        unlink($path);
    }

    public function test_search_treats_percent_and_underscore_as_literal_product_text(): void
    {
        $branch = $this->branch('B001');
        $this->product('102204', 'ส่วนผสม 100% TKF_1');
        $this->product('102205', 'ส่วนผสม 100 TKFA1');
        $this->actingAs($this->viewer($branch));
        foreach (['%', 'TKF_1'] as $q) {
            $result = $this->report($branch, ['q' => $q])->assertOk()->viewData('result');
            $this->assertSame(1, $result['total']);
            $this->assertSame('102204', $result['rows']->sole()->sku_code);
        }
    }

    private function report(Branch $branch, array $parameters = [])
    {
        $response = $this->get('/reports?'.http_build_query($parameters + [
            'category' => 'inventory', 'report' => 'stock_balance', 'branch_id' => $branch->id,
        ]));
        if (! isset($parameters['export'])) {
            $response->assertViewHas('selectedReport', 'stock_balance');
        }

        return $response;
    }

    private function branch(string $code): Branch
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        // Legacy warehouses need not have branch_id; the branch default is authoritative.
        $warehouse = Warehouse::create(['code' => 'WH-'.$code, 'name' => 'คลัง '.$code]);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่ '.$code]);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        return $branch;
    }

    private function product(string $sku, string $name = 'สินค้าทดสอบ'): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'PACK-RPT'], ['name' => 'แพ็ค', 'qty_per_base_unit' => 1]);

        return Product::create(['sku_code' => $sku, 'name_th' => $name, 'base_unit_id' => $unit->id, 'is_active' => true]);
    }

    private function viewer(?Branch $branch, bool $allBranches = false): User
    {
        $user = User::factory()->create(['username' => 'stock-report-'.uniqid(), 'branch_id' => $branch?->id, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'STOCK_RPT_'.uniqid(), 'name' => 'Stock report viewer']);
        foreach (['reports.view', 'reports.export', 'stock.manage', ...($allBranches ? ['reports.all_branches'] : [])] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role);
        ReportDefinition::where('code', 'inventory.stock_balance')->update(['enabled' => true, 'status' => 'available']);

        return $user->fresh();
    }
}
