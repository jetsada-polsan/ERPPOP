<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['code' => 'WORKFLOW_TEST', 'name' => 'Workflow test']);
        $role->permissions()->sync(collect(['sales.manage', 'stock.manage'])->map(
            fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id
        ));
        $user->roles()->attach($role);
        $this->actingAs($user->fresh())->withoutMiddleware(ErpAuthorize::class);
        $this->withoutVite();
    }

    public function test_partial_customer_product_and_barcode_searches_are_case_insensitive(): void
    {
        $this->signIn();
        Customer::create(['code' => 'WF-AbC-01', 'name_th' => 'ลูกค้าทดลอง', 'is_active' => true]);
        Customer::create(['code' => 'WF-AbC-02', 'name_th' => 'ลูกค้าปิด', 'is_active' => false]);
        $this->getJson('/search/customers?q=abc')->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'WF-AbC-01');
        $this->getJson('/search/customers?q='.urlencode('ทดลอง'))->assertOk()->assertJsonCount(1);
        $unit = ProductUnit::create(['code' => 'WF-KG', 'name' => 'กก.', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'WF-AbC', 'name_th' => 'สินค้าทดลอง', 'base_unit_id' => $unit->id, 'is_active' => true]);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '99123456789', 'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true]);
        foreach (['abc', 'ทดลอง', '123456'] as $query) {
            $this->getJson('/search/products?q='.urlencode($query))->assertOk()->assertJsonPath('0.id', $product->id);
        }
    }

    public function test_supplier_and_branch_searches_match_partial_codes(): void
    {
        $this->signIn();
        Supplier::create(['code' => 'WF-AbC', 'name_th' => 'ผู้ขายทดลอง', 'is_active' => true]);
        Branch::create(['code' => 'WF-AbC', 'name_th' => 'สาขาทดลอง', 'is_active' => true]);
        $this->getJson('/search/suppliers?q=abc')->assertOk()->assertJsonPath('0.code', 'WF-AbC');
        $this->getJson('/search/branches?q=abc')->assertOk()->assertJsonPath('0.code', 'WF-AbC');
    }

    public function test_purchase_request_marks_draft_saved_only_after_a_successful_store(): void
    {
        $this->signIn();
        $branch = Branch::create(['code' => 'WF-PO', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WF-WH', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'WF-MAIN', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'WF-EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'WF-PO-P', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id, 'is_active' => true]);
        $data = ['branch_id' => $branch->id, 'items' => [['product_id' => $product->id, 'qty' => 0, 'unit_price' => '25.00']]];
        $this->from('/purchase-orders')->post('/purchase-orders', $data)
            ->assertSessionHasErrors('items.0.qty')->assertSessionMissing('document_draft_saved');
        $this->assertDatabaseCount('purchase_orders', 0);
        $data['items'][0]['qty'] = '2.5';
        $response = $this->post('/purchase-orders', $data)->assertSessionHas('document_draft_saved', url('/purchase-orders'));
        $this->assertDatabaseHas('purchase_orders', ['status' => 'requested', 'total_amount' => '62.50']);
        $saved = $this->get($response->headers->get('Location'))->assertOk()->assertSee('erp:draft:pending:', false);
        if ($directory = getenv('WF_BROWSER_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/purchase-order-saved.html', $saved->getContent());
        }
    }

    public function test_entry_pages_render_for_browser_regression_tests(): void
    {
        $this->signIn();
        Branch::create(['code' => 'WF-HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        foreach (['bookings', 'purchase-orders', 'pos', 'executive'] as $page) {
            $response = $this->get('/'.$page)->assertOk();
            if (in_array($page, ['bookings', 'purchase-orders'])) {
                $response->assertSee('document-workflow.js')->assertSee('draftEnabled: true', false);
            }
            if ($directory = getenv('WF_BROWSER_FIXTURES')) {
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/'.$page.'.html', $response->getContent());
            }
        }
    }
}
