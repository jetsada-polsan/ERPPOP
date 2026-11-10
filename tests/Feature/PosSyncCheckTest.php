<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosDevice;
use App\Models\PosOfflineSale;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_counts_are_reported_and_compared_with_erp(): void
    {
        [$device, $token] = $this->device();
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'CNT1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'default_price' => 40, 'is_active' => true]);
        foreach (['CNT-A', 'CNT-B'] as $code) {
            QtyPromotion::create(['code' => $code, 'name' => $code, 'promo_type' => 'discount',
                'product_id' => $product->id, 'min_qty' => 3, 'discount_type' => 'amount', 'discount_value' => 10, 'is_active' => true]);
        }

        $response = $this->withToken($token)->postJson('/api/pos/sync-check', [
            'counts' => ['catalog' => 1, 'promotions' => 1, 'cashiers' => 0],
        ])->assertOk();

        $this->assertSame(2, $response->json('counts.promotions'));
        $device->refresh();
        $this->assertSame(1, $device->applied_versions['device_counts']['promotions']);
        $this->assertSame(2, $device->applied_versions['server_counts']['promotions']);
    }

    public function test_only_this_devices_resolved_bills_are_returned(): void
    {
        [$device, $token] = $this->device();
        [$other] = $this->device('SYNC2');
        $resolved = (string) Str::uuid();
        $stillOpen = (string) Str::uuid();
        $otherDevice = (string) Str::uuid();
        $make = fn (string $uuid, string $status, PosDevice $owner) => PosOfflineSale::create([
            'offline_uuid' => $uuid, 'source' => PosOfflineSale::SOURCE_DESKTOP, 'pos_device_id' => $owner->id,
            'sold_at' => now(), 'status' => $status, 'payload' => ['items' => []],
        ]);
        $make($resolved, PosOfflineSale::RESOLVED, $device);
        $make($stillOpen, PosOfflineSale::NEEDS_REVIEW, $device);
        $make($otherDevice, PosOfflineSale::RESOLVED, $other);

        $response = $this->withToken($token)->postJson('/api/pos/sync-check', [
            'review_uuids' => [strtoupper($resolved), $stillOpen, $otherDevice, 'not-a-uuid'],
        ])->assertOk();

        $this->assertSame([$resolved], $response->json('resolved_sales'));
    }

    public function test_sync_check_requires_a_device_token(): void
    {
        $this->postJson('/api/pos/sync-check', [])->assertStatus(401);
    }

    /** @return array{PosDevice,string} */
    private function device(string $code = 'SYNC'): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $user = User::factory()->create(['username' => 'sync_'.uniqid(), 'branch_id' => $branch->id]);
        $role = Role::firstOrCreate(['code' => 'POS_SELLER'], ['name' => 'POS Seller']);
        $role->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'pos.sell'])->id);
        $user->roles()->attach($role->id);

        return PosDevice::issue(['name' => 'POS '.$code, 'user_id' => $user->id, 'branch_id' => $branch->id]);
    }
}
