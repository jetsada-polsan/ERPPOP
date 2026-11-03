<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** หน้าสร้างแคมเปญรับครบ 8 แบบ ซื้อคละเก็บกลุ่มสินค้า แลกซื้อเก็บราคาและจำนวน */
class QtyPromotionFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_mix_and_add_on_campaigns_are_saved_and_listed(): void
    {
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $make = fn ($sku) => Product::create(['sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $unit->id, 'default_price' => 40, 'is_active' => true]);
        [$a, $b, $c] = [$make('M1'), $make('M2'), $make('M3')];
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());

        $this->post(route('qty-promotions.store'), [
            'name' => 'คละรส 3 ถุง 100', 'promo_type' => 'mix_bundle', 'product_ids' => [$a->id, $b->id, $c->id],
            'min_qty' => 3, 'bundle_price' => 100, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('qty-promotions.store'), [
            'name' => 'ครบ 300 แลกซื้อ', 'promo_type' => 'bill_addon', 'product_id' => $c->id,
            'min_amount' => 300, 'bundle_price' => 19, 'free_qty' => 2, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->post(route('qty-promotions.store'), [
            'name' => 'คละตัวเดียวไม่ได้', 'promo_type' => 'mix_free', 'product_ids' => [$a->id], 'min_qty' => 3, 'free_qty' => 1,
        ])->assertSessionHasErrors('product_ids');

        $mix = QtyPromotion::where('promo_type', 'mix_bundle')->sole();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $mix->groupProducts->pluck('id')->all());
        $this->assertNull($mix->product_id);
        $addon = QtyPromotion::where('promo_type', 'bill_addon')->sole();
        $this->assertSame($c->id, (int) $addon->product_id);
        $this->assertEquals(2, (float) $addon->free_qty);
        $this->assertSame('ยอดครบ 300 บาท แลกซื้อ 19 บาท', $addon->label());

        $this->get(route('qty-promotions.index'))->assertOk()->assertSee('ซื้อคละ 3 ชิ้น 100 บาท')->assertSee('คละ 3 ตัว');
    }
}
