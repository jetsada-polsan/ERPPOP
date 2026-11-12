<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Branch;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * production 12 พ.ย.: POS Python ขายสินค้าราคาลด/นาทีทองที่ราคาเต็ม เพราะได้แค่ pos_price ณ เวลา sync
 * ERP ต้องส่งกติกาแคมเปญ (ลดบาท/ลด %/ช่วงนาทีทอง 8 วัน) ให้เครื่องคิดเองแบบเดียวกับ PosPricingGuard
 */
class PosCampaignRulesPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pos_products_carry_promotion_and_flash_sale_rules_for_offline_pricing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 20:30:00', config('app.timezone')));
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));
        $branch = Branch::create(['code' => 'BCP', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $other = Branch::create(['code' => 'BCO', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $unit = ProductUnit::firstOrCreate(['code' => 'PK-CP'], ['name' => 'แพ็ค', 'qty_per_base_unit' => 1]);
        $cut = Product::create(['sku_code' => '202151', 'name_th' => 'ไส้กรอกกุ้ง', 'base_unit_id' => $unit->id,
            'default_price' => 39, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true]);
        $flash = Product::create(['sku_code' => '202152', 'name_th' => 'ไส้กรอกเวียนนา', 'base_unit_id' => $unit->id,
            'default_price' => 39, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true]);

        Promotion::create(['code' => 'P-CUT', 'name' => 'ลด 10%', 'promotion_type' => 'discount', 'product_id' => $cut->id,
            'discount_percent' => 10, 'starts_at' => '2026-11-11', 'ends_at' => '2026-11-13', 'is_active' => true]);
        $sale = FlashSale::create(['code' => 'F-1', 'name' => 'นาทีทอง', 'branch_id' => $branch->id, 'starts_date' => '2026-11-12',
            'ends_date' => '2026-11-13', 'start_time' => '20:30', 'end_time' => '23:59', 'is_active' => true]);
        FlashSaleItem::create(['flash_sale_id' => $sale->id, 'product_id' => $flash->id, 'flash_price' => 30]);
        $otherSale = FlashSale::create(['code' => 'F-2', 'name' => 'สาขาอื่น', 'branch_id' => $other->id, 'starts_date' => '2026-11-12',
            'is_active' => true]);
        FlashSaleItem::create(['flash_sale_id' => $otherSale->id, 'product_id' => $flash->id, 'flash_price' => 1]);

        $this->actingAs($user);
        $request = Request::create('/pos/products', 'GET', ['branch_id' => $branch->id, 'all' => 1]);
        $this->app->instance('request', $request);
        $products = collect(app(PosController::class)->products($request)->getData(true));
        $payload = fn (string $sku) => $products->firstWhere('sku_code', $sku)['campaigns'];

        $promotions = $payload('202151')['promotions'];
        $this->assertCount(1, $promotions);
        $this->assertSame(0, bccomp($promotions[0]['discount_percent'], '10', 2));
        $this->assertSame(['2026-11-11', '2026-11-13'], [$promotions[0]['starts_on'], $promotions[0]['ends_on']]);
        $this->assertSame([], $payload('202151')['flash']);

        $windows = $payload('202152')['flash'];
        $this->assertCount(2, $windows, 'สองวัน (12-13 พ.ย.) ของสาขานี้เท่านั้น ไม่รวมนาทีทองสาขาอื่น');
        $this->assertSame(0, bccomp($windows[0]['price'], '30', 2));
        $this->assertSame('2026-11-12T20:30:00', substr($windows[0]['from'], 0, 19));
        $this->assertSame('2026-11-13T23:59:00', substr($windows[1]['to'], 0, 19));
    }
}
