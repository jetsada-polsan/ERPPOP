<?php

namespace Tests\Feature;

use App\Http\Controllers\ManagementControlController;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagementProfitReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_profit_report_uses_product_cost_when_legacy_cost_snapshot_is_missing(): void
    {
        $user = User::factory()->create([
            'username' => 'management-profit-'.uniqid(),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'MANAGEMENT_PROFIT_'.uniqid(), 'name' => 'Management profit test']);
        $permission = Permission::firstOrCreate(['code' => 'management.view'], ['name' => 'ดูศูนย์ควบคุมบริหาร']);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->attach($role->id);
        $this->actingAs($user);

        $branch = Branch::create(['code' => 'MGT', 'name_th' => 'สาขาทดสอบรายงาน', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-MGT', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-MGT', 'name' => 'พื้นที่ทดสอบ']);
        $unit = ProductUnit::create(['code' => 'EA-MGT', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'MGT-1', 'name_th' => 'สินค้าทดสอบรายงาน', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 40, 'is_vat' => false, 'is_active' => true,
        ]);
        $type = DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด']);
        $document = Document::create([
            'document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => 'MGT-0001',
            'doc_date' => '2026-07-30', 'status' => 'active', 'total_items' => 1, 'total_amount' => 200,
        ]);
        $stock = StockDocument::create([
            'document_id' => $document->id, 'total_qty' => 5, 'total_items' => 1,
        ]);
        DB::table('stock_document_items')->insert([
            'stock_document_id' => $stock->id, 'seq' => 1, 'product_id' => $product->id,
            'warehouse_location_id' => $location->id, 'qty' => 5, 'unit_price' => 40,
            // Legacy rows may not have a frozen cost snapshot.
            'unit_cost' => null, 'cost_amount' => null,
        ]);

        $request = Request::create('/management-controls', 'GET', ['period' => '2026-07']);
        $request->setUserResolver(fn () => $user);
        $view = app(ManagementControlController::class)->index($request);
        $row = $view->getData()['profit']->firstWhere('id', $branch->id);

        $this->assertSame(200.0, (float) $row->sales);
        $this->assertSame(200.0, (float) $row->cogs);
        $this->assertSame(0.0, (float) $row->net_profit);
    }
}
