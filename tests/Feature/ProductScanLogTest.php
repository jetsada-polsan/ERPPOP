<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductScanLog;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\User;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าเว็บ POS/คลังมือถือส่งผลสแกนมาบันทึกใน product_scan_logs
 */
class ProductScanLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_pos_records_a_matched_camera_scan(): void
    {
        $product = $this->product('FM-HK-CR35');

        $this->actingAs($this->userWith('pos.use'))
            ->postJson(route('pos.scan-logs.store'), [
                'code' => 'FM-HK-CR35', 'context' => 'pos', 'source' => 'camera',
                'result' => 'matched', 'product_id' => $product->id,
            ])->assertNoContent();

        $log = ProductScanLog::sole();
        $this->assertSame('pos', $log->context);
        $this->assertSame('camera', $log->source);
        $this->assertSame('matched', $log->result);
        $this->assertSame($product->id, $log->product_id);
        $this->assertNotNull($log->user_id);
    }

    public function test_not_found_scan_never_stores_a_product(): void
    {
        $product = $this->product('ANY-1');

        $this->actingAs($this->userWith('pos.use'))
            ->postJson(route('pos.scan-logs.store'), [
                'code' => 'NOT-FOUND-0001', 'context' => 'pos', 'source' => 'keyboard',
                'result' => 'not_found', 'product_id' => $product->id,
            ])->assertNoContent();

        $this->assertNull(ProductScanLog::sole()->product_id);
    }

    public function test_pos_route_cannot_be_used_to_write_warehouse_context(): void
    {
        $this->actingAs($this->userWith('pos.use'))
            ->postJson(route('pos.scan-logs.store'), [
                'code' => 'X', 'context' => 'wh_count', 'source' => 'manual', 'result' => 'not_found',
            ])->assertNoContent();

        $this->assertSame('pos', ProductScanLog::sole()->context);
    }

    public function test_warehouse_records_its_own_context(): void
    {
        $this->actingAs($this->userWith('stock.manage'))
            ->postJson(route('wh.scan-logs.store'), [
                'code' => 'ABC', 'context' => 'wh_count', 'source' => 'image', 'result' => 'not_found',
            ])->assertNoContent();

        $log = ProductScanLog::sole();
        $this->assertSame('wh_count', $log->context);
        $this->assertSame('image', $log->source);
    }

    /** web route ไม่ render JSON (ดู bootstrap/app.php) จึงตอบ redirect + session errors */
    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->userWith('pos.use'))
            ->postJson(route('pos.scan-logs.store'), [
                'code' => 'X', 'context' => 'pos', 'source' => 'telepathy', 'result' => 'maybe',
            ])->assertSessionHasErrors(['source', 'result']);

        $this->assertSame(0, ProductScanLog::count());
    }

    public function test_guests_cannot_write_scan_logs(): void
    {
        $this->postJson(route('pos.scan-logs.store'), [
            'code' => 'X', 'context' => 'pos', 'result' => 'not_found',
        ])->assertUnauthorized();
    }

    public function test_users_without_permission_are_refused(): void
    {
        $this->actingAs($this->userWith('reports.view'))
            ->postJson(route('wh.scan-logs.store'), [
                'code' => 'X', 'context' => 'wh_stock', 'result' => 'not_found',
            ])->assertForbidden();
    }

    public function test_generic_scanner_symbologies_accept_any_code(): void
    {
        $policy = app(BarcodePolicy::class);
        foreach ([BarcodePolicy::CODE128, BarcodePolicy::CODE39, BarcodePolicy::QR_CODE] as $type) {
            $this->assertTrue($policy->check($type, 'FM-HK-CR35')['ok'], $type);
        }
    }

    private function userWith(string $permission): User
    {
        static $n = 0;
        $user = User::factory()->create(['username' => 'scan-user-'.++$n, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'SCAN_'.$n, 'name' => 'Scan '.$n]);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => $permission], ['name' => $permission])->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function product(string $sku): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-SCAN'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        return Product::create([
            'sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
        ]);
    }
}
