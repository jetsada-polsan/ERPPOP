<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\QtyPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QtyPromotionTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotion_runs_between_exact_times(): void
    {
        $unit = ProductUnit::create(['code' => 'QT', 'name' => 'u', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => '770001', 'name_th' => 'x', 'base_unit_id' => $unit->id, 'is_active' => true]);
        Carbon::setTestNow('2026-11-02 11:59:00');
        $promotion = QtyPromotion::create(['code' => 'P3', 'name' => 'x', 'promo_type' => 'bundle_price', 'product_id' => $product->id,
            'min_qty' => 3, 'bundle_price' => 100, 'is_active' => true, 'starts_at' => '2026-11-02 12:00:00', 'ends_at' => '2026-11-03 12:00:00']);

        $this->assertFalse(QtyPromotion::runningToday()->exists());
        $this->assertTrue(QtyPromotion::notEnded()->exists());
        $this->assertSame('2026-11-02', $promotion->fresh()->starts_date->toDateString());

        Carbon::setTestNow('2026-11-02 12:00:00');
        $this->assertTrue(QtyPromotion::runningToday()->exists());

        Carbon::setTestNow('2026-11-03 12:00:01');
        $this->assertFalse(QtyPromotion::notEnded()->exists());
        Carbon::setTestNow();
    }
}
