<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** เครื่อง POS ถามเลขเวอร์ชันข้อมูลแทนการดึงทั้งชุดทุกครั้ง และรายงานสถานะให้หลังบ้านเห็น */
class PosSyncCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_are_stable_until_a_promotion_changes(): void
    {
        [$device, $token] = $this->device();

        $first = $this->withToken($token)->postJson('/api/pos/sync-check', [])->assertOk();
        $this->assertEqualsCanonicalizing(['catalog', 'promotions', 'cashiers'], $first->json('stale'));
        $versions = $first->json('versions');

        $again = $this->withToken($token)->postJson('/api/pos/sync-check', ['applied' => $versions])->assertOk();
        $this->assertSame([], $again->json('stale'));

        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'SYNC1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'default_price' => 40, 'is_active' => true]);
        $afterProduct = $this->withToken($token)->postJson('/api/pos/sync-check', ['applied' => $versions])->assertOk();
        $this->assertSame(['catalog'], $afterProduct->json('stale'));
        $versions = $afterProduct->json('versions');

        QtyPromotion::create(['code' => 'SYNC-P', 'name' => '3 ลด 30', 'promo_type' => 'discount',
            'product_id' => $product->id, 'min_qty' => 3, 'discount_type' => 'amount', 'discount_value' => 30, 'is_active' => true]);
        $afterPromo = $this->withToken($token)->postJson('/api/pos/sync-check', ['applied' => $versions])->assertOk();
        $this->assertSame(['promotions'], $afterPromo->json('stale'));
    }

    public function test_device_report_is_saved_for_the_admin_page(): void
    {
        [$device, $token] = $this->device();

        $this->withToken($token)->postJson('/api/pos/sync-check', [
            'pending_bills' => 3, 'app_version' => '0.9.9', 'pulled' => true,
        ])->assertOk();

        $device->refresh();
        $this->assertSame(3, $device->pending_bills);
        $this->assertSame('0.9.9', $device->app_version);
        $this->assertNotNull($device->sync_reported_at);
        $this->assertNotNull($device->master_pulled_at);
        $this->assertContains('promotions', $device->applied_versions['stale']);
    }

    public function test_sync_check_requires_a_device_token(): void
    {
        $this->postJson('/api/pos/sync-check', [])->assertStatus(401);
    }

    /** @return array{PosDevice,string} */
    private function device(): array
    {
        $branch = Branch::create(['code' => 'SYNC', 'name_th' => 'สาขา SYNC', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'sync_'.uniqid(), 'branch_id' => $branch->id]);
        $role = Role::firstOrCreate(['code' => 'POS_SELLER'], ['name' => 'POS Seller']);
        $role->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell'])->id);
        $user->roles()->attach($role->id);

        return PosDevice::issue(['name' => 'POS SYNC', 'user_id' => $user->id, 'branch_id' => $branch->id]);
    }
}
