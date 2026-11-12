<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\QtyPromotion;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** เจ้าของ 12 พ.ย.: ลดราคา นาทีทอง บัตรส่วนลด แต้ม โปรแคมเปญ = โปรโมชั่นทั้งหมด รวมเป็นเมนูเดียว */
class PromotionCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));

        return $user;
    }

    public function test_center_lists_every_running_promotion_type_and_hides_ended_ones(): void
    {
        Carbon::setTestNow('2026-11-12 20:30:00');
        $branch = Branch::create(['code' => 'BPC', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $unit = ProductUnit::firstOrCreate(['code' => 'PK-PC'], ['name' => 'แพ็ค', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'PC-1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id,
            'default_price' => 39, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true]);

        Promotion::create(['code' => 'CUT-ON', 'name' => 'ลดราคารายตัว', 'promotion_type' => 'discount', 'product_id' => $product->id,
            'discount_percent' => 10, 'starts_at' => '2026-11-01', 'ends_at' => '2026-11-30', 'is_active' => true]);
        Promotion::create(['code' => 'CUT-OLD', 'name' => 'หมดแล้ว', 'promotion_type' => 'discount', 'product_id' => $product->id,
            'discount_percent' => 10, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-02', 'is_active' => true]);
        FlashSale::create(['code' => 'FL-ON', 'name' => 'นาทีทองเย็นนี้', 'branch_id' => $branch->id, 'starts_date' => '2026-11-12',
            'start_time' => '17:00', 'end_time' => '19:00', 'is_active' => true]);
        QtyPromotion::create(['code' => 'QP-ON', 'name' => 'ซื้อ 3 ชิ้น 90', 'promo_type' => 'bundle_price', 'product_id' => $product->id,
            'min_qty' => 3, 'bundle_price' => 90, 'is_active' => true]);
        QtyPromotion::create(['code' => 'QP-BILL', 'name' => 'ครบ 500 ลด 5%', 'promo_type' => 'bill_discount', 'min_amount' => 500,
            'discount_type' => 'percent', 'discount_value' => 5, 'is_active' => true]);
        QtyPromotion::create(['code' => 'QP-OLD', 'name' => 'โปรหมดเวลา', 'promo_type' => 'bundle_price', 'product_id' => $product->id,
            'min_qty' => 3, 'bundle_price' => 90, 'ends_at' => '2026-11-01 00:00:00', 'is_active' => true]);

        $this->actingAs($this->manager())->get(route('promotion-center.index'))
            ->assertOk()
            ->assertSee('ลดราคารายตัว')->assertSee('นาทีทองเย็นนี้')->assertSee('รอช่วงเวลา')
            ->assertSee('ซื้อ 3 ชิ้น 90')->assertSee('ครบ 500 ลด 5%')->assertSee('โปรทั้งบิล')
            ->assertDontSee('หมดแล้ว')->assertDontSee('โปรหมดเวลา');
    }

    public function test_menu_has_one_promotion_entry_and_old_pages_still_open(): void
    {
        $labels = collect(ErpMenu::all())->flatMap(fn ($section) => collect($section['items'])->pluck('label'));
        $this->assertTrue($labels->contains('ศูนย์โปรโมชั่น'));
        foreach (['ส่วนลดราคาสินค้า (แบบเดิม)', 'ราคานาทีทอง', 'บัตรส่วนลด', 'แต้มสมาชิก', 'โปรโมชัน / แคมเปญ'] as $old) {
            $this->assertFalse($labels->contains($old), "{$old} ต้องเข้าผ่านศูนย์โปรโมชั่น");
        }

        $user = $this->manager();
        foreach (['promotions.index', 'flash-sales.index', 'qty-promotions.index', 'discount-cards.index', 'member-points.index'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_user_without_masterdata_permission_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

        $this->actingAs($user)->get(route('promotion-center.index'))->assertForbidden();
    }
}
