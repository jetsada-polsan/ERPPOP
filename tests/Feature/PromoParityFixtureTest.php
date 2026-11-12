<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoParityFixtureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private PosPricingGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user);

        $this->branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาหลัก', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'PCS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $priceTable = PriceTable::create(['code' => 'STD', 'name' => 'มาตรฐาน', 'is_default' => true, 'is_active' => true]);

        $productsData = [
            101 => ['sku' => 'SKU101', 'name' => 'ปลาหมึกสด', 'price' => 50],
            102 => ['sku' => 'SKU102', 'name' => 'น้ำเก๊กฮวย', 'price' => 30],
            103 => ['sku' => 'SKU103', 'name' => 'น้ำมันพืช', 'price' => 20],
            104 => ['sku' => 'SKU104', 'name' => 'ขนมปัง', 'price' => 15],
        ];

        foreach ($productsData as $id => $p) {
            Product::forceCreate([
                'id' => $id,
                'sku_code' => $p['sku'],
                'name_th' => $p['name'],
                'base_unit_id' => $unit->id,
                'default_price' => $p['price'],
                'average_cost' => 10,
                'is_vat' => true,
                'is_active' => true,
            ]);
            ProductPrice::create([
                'product_id' => $id,
                'price_table_id' => $priceTable->id,
                'price' => $p['price'],
                'is_active' => true,
            ]);
        }

        $this->guard = app(PosPricingGuard::class);
    }

    public function test_all_121_parity_fixture_scenarios_pass_against_pos_pricing_guard(): void
    {
        $fixturePath = base_path('tests/Fixtures/promo-parity.json');
        $this->assertFileExists($fixturePath);

        $fixture = json_decode(file_get_contents($fixturePath), true);
        $scenarios = $fixture['scenarios'];
        $this->assertCount(121, $scenarios);

        foreach ($scenarios as $scenario) {
            DB::table('qty_promotion_products')->delete();
            DB::table('qty_promotions')->delete();

            $pData = $scenario['promo'];
            $promo = QtyPromotion::create([
                'code' => 'P-' . substr(md5($scenario['id']), 0, 10),
                'name' => $scenario['name'],
                'promo_type' => $pData['promo_type'],
                'product_id' => $pData['product_id'],
                'min_qty' => $pData['min_qty'],
                'free_product_id' => $pData['free_product_id'],
                'free_qty' => $pData['free_qty'],
                'discount_type' => $pData['discount_type'],
                'discount_value' => $pData['discount_value'],
                'bundle_price' => $pData['bundle_price'],
                'max_sets_per_bill' => $pData['max_sets_per_bill'],
                'is_active' => true,
            ]);

            if (!empty($pData['product_ids'])) {
                $promo->groupProducts()->sync($pData['product_ids']);
            }

            $lines = collect($scenario['lines']);
            $actualDiscount = $this->guard->qtyPromotionDiscount($lines, $this->branch->id, true, true);
            $formattedDiscount = number_format($actualDiscount, 2, '.', '');

            $this->assertSame(
                $scenario['expected_discount'],
                $formattedDiscount,
                "Parity mismatch in scenario '{$scenario['id']}' ({$scenario['name']}): expected {$scenario['expected_discount']}, got {$formattedDiscount}"
            );
        }
    }
}
