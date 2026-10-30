<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UatReceiveAllActiveProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_only_previews_the_shortfall_for_each_sellable_unit(): void
    {
        [$branch, , $product, $actor] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'on_hand_qty' => 2,
            'reserved_qty' => 0,
        ]);

        $this->artisan('erp:uat-receive-all-active-products', [
            '--reference' => 'UAT-PREVIEW-1',
        ])
            ->expectsOutputToContain('ตรวจสอบอย่างเดียว (dry-run)')
            ->expectsOutputToContain('รับ (หน่วยฐาน)')
            ->expectsOutputToContain('5.0000')
            ->expectsOutputToContain('฿50.00')
            ->assertExitCode(0);

        $this->assertSame(0, Document::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(2.0, (float) StockBalance::first()->on_hand_qty);
    }

    public function test_execute_requires_admin_confirmation_and_respects_the_amount_cap(): void
    {
        [$branch, , $product, $actor] = $this->masters();
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'on_hand_qty' => 2,
            'reserved_qty' => 0,
        ]);

        $this->artisan('erp:uat-receive-all-active-products', [
            '--reference' => 'UAT-CAP-1', '--execute' => true,
            '--actor' => $actor->username, '--confirm' => 'RECEIVE-ALL-ACTIVE-PRODUCTS', '--max-total' => '49.99',
        ])->expectsOutputToContain('เกินเพดาน')->assertExitCode(1);
        $this->assertSame(0, Document::count());
        $this->assertSame(2.0, (float) StockBalance::first()->on_hand_qty);

        $this->artisan('erp:uat-receive-all-active-products', [
            '--reference' => 'UAT-CAP-1', '--execute' => true,
            '--actor' => $actor->username, '--confirm' => 'RECEIVE-ALL-ACTIVE-PRODUCTS', '--max-total' => '50.00',
        ])->expectsOutputToContain('รับสินค้าแล้ว:')
            ->assertExitCode(0);

        $purchase = Document::where('reference', 'UAT-CAP-1')->firstOrFail();
        $this->assertSame('PURCHASE', $purchase->documentType->code);
        $this->assertSame($actor->id, (int) $purchase->created_by);
        $this->assertSame(50.0, (float) $purchase->total_amount);
        $this->assertSame(7.0, (float) StockBalance::first()->fresh()->on_hand_qty);
        $this->assertSame(1, SupplierLedger::where('document_id', $purchase->id)->count());
        $this->assertGreaterThan(0, DB::table('gl_journals')->where('document_id', $purchase->id)->count());

        $this->artisan('erp:uat-receive-all-active-products', [
            '--reference' => 'UAT-CAP-1', '--execute' => true,
            '--actor' => $actor->username, '--confirm' => 'RECEIVE-ALL-ACTIVE-PRODUCTS', '--max-total' => '50.00',
        ])->expectsOutputToContain('ป้องกันการรับซ้ำ')->assertExitCode(1);
        $this->assertSame(1, Document::where('reference', 'UAT-CAP-1')->count());
    }

    public function test_execute_stops_when_expiry_metadata_is_required_but_missing(): void
    {
        [$branch, , $product, $actor] = $this->masters();
        $product->update(['tracks_expiry' => true, 'shelf_life_days' => null]);

        $this->artisan('erp:uat-receive-all-active-products', [
            '--reference' => 'UAT-LOT-1', '--execute' => true,
            '--actor' => $actor->username, '--confirm' => 'RECEIVE-ALL-ACTIVE-PRODUCTS', '--max-total' => '100.00',
        ])->expectsOutputToContain('lot metadata')->assertExitCode(1);

        $this->assertSame(0, Document::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0.0, (float) StockBalance::where('product_id', $product->id)->sum('on_hand_qty'));
    }

    public function test_execute_uses_lot_metadata_from_a_csv_for_expiry_controlled_products(): void
    {
        [, , $product, $actor] = $this->masters();
        $product->update(['tracks_expiry' => true]);
        $csvPath = tempnam(sys_get_temp_dir(), 'uat-product-lots-');
        file_put_contents($csvPath, "sku_code,expiry_date,manufacture_date,lot_number\n"
            .'SKU-UAT-1,'.today()->addDays(30)->toDateString().','.today()->toDateString().",UAT-LOT-1\n");

        try {
            $this->artisan('erp:uat-receive-all-active-products', [
                '--reference' => 'UAT-LOT-CSV-1', '--lots' => $csvPath, '--execute' => true,
                '--actor' => $actor->username, '--confirm' => 'RECEIVE-ALL-ACTIVE-PRODUCTS', '--max-total' => '100.00',
            ])->expectsOutputToContain('รับสินค้าแล้ว:')
                ->assertExitCode(0);

            $lot = StockLot::where('product_id', $product->id)->firstOrFail();
            $this->assertSame('UAT-LOT-1', $lot->lot_number);
            $this->assertSame(today()->addDays(30)->toDateString(), $lot->expiry_date->toDateString());
            $this->assertSame(today()->toDateString(), $lot->manufacture_date->toDateString());
        } finally {
            @unlink($csvPath);
        }
    }

    public function test_lot_metadata_rejects_expiry_dates_in_the_past(): void
    {
        [, , $product] = $this->masters();
        $product->update(['tracks_expiry' => true]);
        $csvPath = tempnam(sys_get_temp_dir(), 'uat-expired-product-lots-');
        file_put_contents($csvPath, "sku_code,expiry_date,manufacture_date,lot_number\n"
            .'SKU-UAT-1,'.today()->subDay()->toDateString().',,UAT-LOT-OLD');

        try {
            $this->artisan('erp:uat-receive-all-active-products', [
                '--reference' => 'UAT-LOT-PAST-1', '--lots' => $csvPath,
            ])->expectsOutputToContain('ต้องไม่ผ่านไปแล้ว')->assertExitCode(1);

            $this->assertSame(0, Document::count());
            $this->assertSame(0, DB::table('stock_movements')->count());
        } finally {
            @unlink($csvPath);
        }
    }

    /** @return array{Branch, Supplier, Product, User} */
    private function masters(): array
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-UAT', 'name' => 'คลัง UAT']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN-UAT', 'name' => 'พื้นที่หลัก UAT']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'UAT-POS-TEST', 'name_th' => 'ผู้ขายทดสอบ UAT', 'is_active' => true]);
        $baseUnit = ProductUnit::create(['code' => 'EA-UAT', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $caseUnit = ProductUnit::create(['code' => 'CASE-UAT', 'name' => 'ลัง', 'qty_per_base_unit' => 6]);
        $product = Product::create([
            'sku_code' => 'SKU-UAT-1', 'name_th' => 'สินค้า UAT', 'base_unit_id' => $baseUnit->id,
            'default_price' => 30, 'average_cost' => 10, 'last_purchase_cost' => 10,
            'is_vat' => false, 'tracks_expiry' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => 'BAR-UAT-1', 'unit_id' => $baseUnit->id,
            'unit_factor' => 1, 'price' => 30, 'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => 'BAR-UAT-CASE', 'unit_id' => $caseUnit->id,
            'unit_factor' => 6, 'price' => 180, 'is_active' => true,
        ]);

        $actor = User::factory()->create(['username' => 'uat-admin']);
        $role = Role::create(['code' => 'UAT_SYSTEM_ADMIN', 'name' => 'UAT system admin']);
        $role->permissions()->attach([
            Permission::firstOrCreate(['code' => 'users.manage'], ['name' => 'Manage users'])->id,
            Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'Manage settings'])->id,
        ]);
        $actor->roles()->attach($role->id);

        return [$branch->fresh(), $supplier, $product, $actor];
    }
}
