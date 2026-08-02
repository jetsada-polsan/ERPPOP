<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PurchaseOrderController;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosHeldBill;
use App\Models\PosPayment;
use App\Models\PosReceipt;
use App\Models\PosShift;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\SupplierPriceSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PosRetailControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_control_center_shows_shift_and_unmatched_qr_payment(): void
    {
        $branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'POS-B01', 'name' => 'POS สาขาทดสอบ']);
        $shift = PosShift::create([
            'branch_id' => $branch->id, 'pos_terminal_id' => $terminal->id, 'shift_no' => 'SHIFT-CONTROL-01',
            'opened_at' => '2026-08-02 08:00:00', 'opening_cash' => 500, 'expected_cash' => 750,
            'receipt_count' => 1, 'status' => 'open',
        ]);
        $receipt = PosReceipt::create([
            'pos_terminal_id' => $terminal->id, 'pos_shift_id' => $shift->id, 'receipt_no' => 'POS-CONTROL-001',
            'receipt_date' => '2026-08-02 09:00:00', 'net_sales' => 250, 'status' => 'completed',
        ]);
        PosPayment::create(['pos_receipt_id' => $receipt->id, 'method' => 'qr', 'payment_reference' => 'QR-CONTROL-01', 'amount' => 250]);

        $user = User::factory()->create(['username' => 'pos-control-user', 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'POS_CONTROL', 'name' => 'POS Control']);
        $permission = Permission::firstOrCreate(['code' => 'reports.view'], ['name' => 'reports.view']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/pos/control?date=2026-08-02')
            ->assertOk()
            ->assertSee('ศูนย์ควบคุม POS')
            ->assertSee('SHIFT-CONTROL-01')
            ->assertSee('POS-CONTROL-001')
            ->assertSee('QR-CONTROL-01');
    }

    public function test_held_bill_is_shared_by_branch_and_can_only_be_resumed_once(): void
    {
        [$user, $branch, $cashier, $shift, $product] = $this->posMasters('HOLD');
        $this->actingAs($user);
        $controller = app(PosController::class);

        $hold = $controller->holdBill(Request::create('/pos/held-bills', 'POST', [
            'branch_id' => $branch->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'label' => 'โต๊ะ A1',
            'total_amount' => 250,
            'payload' => [
                'id' => 999999,
                'hold_no' => 'CLIENT-CANNOT-OVERRIDE',
                'label' => 'ป้ายปลอมจากเครื่องขาย',
                'cart' => [[
                    'id' => $product->id,
                    'sku_code' => $product->sku_code,
                    'name_th' => $product->name_th,
                    'qty' => 2,
                    'unit_price' => 125,
                ]],
            ],
        ]));
        $this->assertSame(200, $hold->getStatusCode());
        $heldBill = PosHeldBill::firstOrFail();

        $listed = $controller->heldBills(Request::create('/pos/held-bills', 'GET', [
            'branch_id' => $branch->id,
            'cashier_id' => $cashier->id,
        ]))->getData(true);
        $this->assertSame($heldBill->id, $listed['held_bills'][0]['id']);
        $this->assertSame($heldBill->hold_no, $listed['held_bills'][0]['hold_no']);
        $this->assertSame('โต๊ะ A1', $listed['held_bills'][0]['label']);
        $this->assertCount(1, $listed['held_bills'][0]['cart']);

        $first = $controller->resumeHeldBill(Request::create('/resume', 'POST'), $heldBill);
        $second = $controller->resumeHeldBill(Request::create('/resume', 'POST'), $heldBill->fresh());

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($heldBill->id, $first->getData(true)['held_bill']['id']);
        $this->assertSame($heldBill->hold_no, $first->getData(true)['held_bill']['hold_no']);
        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame('resumed', $heldBill->fresh()->status);
    }

    public function test_cash_drop_reduces_expected_cash_and_is_frozen_in_z_report_data(): void
    {
        [$user, , , $shift] = $this->posMasters('DROP');
        $this->actingAs($user);
        $controller = app(PosController::class);

        $response = $controller->recordCashMovement(Request::create('/pos/shift/cash-movement', 'POST', [
            'shift_id' => $shift->id,
            'movement_type' => 'drop',
            'amount' => 300,
            'reference_no' => 'BAG-001',
            'reason' => 'นำส่งรอบบ่าย',
        ]))->getData(true);

        $this->assertEquals(700.0, $response['shift']['expected_cash']);
        $this->assertEquals(300.0, $response['shift']['cash_drops']);
        $this->assertDatabaseHas('pos_cash_movements', [
            'pos_shift_id' => $shift->id,
            'movement_type' => 'drop',
            'reference_no' => 'BAG-001',
        ]);

        $closed = $controller->closeShift(Request::create('/pos/shift/close', 'POST', [
            'shift_id' => $shift->id,
            'counted_cash' => 700,
            'closing_note' => 'ยอดตรง',
        ]))->getData(true);
        $this->assertEquals(0.0, $closed['shift']['cash_difference']);
        $this->assertStringContainsString('/z-report', $closed['report_url']);
    }

    public function test_supplier_schedule_selects_the_highest_effective_quantity_tier(): void
    {
        [$user, $branch, , , $product] = $this->posMasters('SUP');
        $this->actingAs($user);
        $supplier = Supplier::create(['code' => 'SUP-1', 'name_th' => 'ผู้ขายหนึ่ง', 'is_active' => true]);
        $order = PurchaseOrder::create([
            'doc_number' => 'PO-SUP-1',
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'doc_date' => now()->toDateString(),
            'status' => 'approved',
            'total_amount' => 0,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 12,
            'unit_price' => 0,
        ]);
        foreach ([[1, 90], [10, 80], [20, 70]] as [$minimum, $price]) {
            SupplierPriceSchedule::create([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'minimum_qty' => $minimum,
                'unit_price' => $price,
                'vat_mode' => 'included',
                'effective_from' => now()->subDay()->toDateString(),
                'is_active' => true,
            ]);
        }

        $data = app(PurchaseOrderController::class)
            ->supplierPrices(Request::create('/supplier-prices', 'GET', ['supplier_id' => $supplier->id]), $order)
            ->getData(true);

        $this->assertEquals(80.0, $data['prices'][$item->id]['unit_price']);
    }

    public function test_desktop_ping_returns_terminal_hardware_profile(): void
    {
        [$user, $branch] = $this->posMasters('HW');
        $terminal = PosTerminal::where('branch_id', $branch->id)->firstOrFail();
        $terminal->update(['hardware_profile' => [
            'printer_driver' => 'escpos_network',
            'paper_width' => '80mm',
            'printer_address' => '192.168.1.20:9100',
            'scanner_mode' => 'keyboard',
            'scale_mode' => 'serial',
            'customer_display' => 'network',
            'cash_drawer_enabled' => true,
            'auto_print' => true,
            'print_copies' => 1,
        ]]);
        $device = PosDevice::create([
            'name' => 'POS Hardware',
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'terminal_code' => null,
            'token_hash' => hash('sha256', 'hardware-token'),
        ]);
        $request = Request::create('/api/pos/ping', 'GET');
        $request->attributes->set('pos_device', $device);
        $request->setUserResolver(fn () => $user);

        $data = app(PosApiController::class)->ping($request)->getData(true);

        $this->assertSame('escpos_network', $data['hardware_profile']['printer_driver']);
        $this->assertSame('192.168.1.20:9100', $data['hardware_profile']['printer_address']);
        $this->assertTrue($data['hardware_profile']['cash_drawer_enabled']);
    }

    public function test_product_catalog_marks_prices_below_the_configured_margin(): void
    {
        [$user, $branch, , , $product] = $this->posMasters('MARGIN');
        $product->update([
            'average_cost' => 100,
            'minimum_margin_percent' => 20,
            'margin_control_policy' => 'warn',
        ]);
        $this->actingAs($user);

        $products = app(PosController::class)->products(Request::create('/pos/products', 'GET', [
            'branch_id' => $branch->id,
        ]))->getData(true);

        $this->assertTrue($products[0]['margin_warning']);
        $this->assertLessThan(20, $products[0]['margin_percent']);
        $this->assertArrayNotHasKey('average_cost', $products[0]);
    }

    /** @return array{User,Branch,Salesman,PosShift,Product} */
    private function posMasters(string $code): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $cashier = Salesman::create([
            'branch_id' => $branch->id,
            'code' => 'C-'.$code,
            'name' => 'แคชเชียร์ '.$code,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'username' => 'retail_'.strtolower($code).'_'.uniqid(),
            'branch_id' => $branch->id,
            'salesman_id' => $cashier->id,
        ]);
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T-'.$code, 'name' => 'POS '.$code]);
        $shift = PosShift::create([
            'branch_id' => $branch->id,
            'pos_terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_no' => 'SHIFT-'.$code,
            'opened_at' => now(),
            'opening_cash' => 1000,
            'expected_cash' => 1000,
            'status' => 'open',
        ]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'P-'.$code,
            'name_th' => 'สินค้า '.$code,
            'base_unit_id' => $unit->id,
            'default_price' => 125,
            'average_cost' => 70,
            'is_vat' => true,
            'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);

        return [$user, $branch, $cashier, $shift, $product];
    }
}
