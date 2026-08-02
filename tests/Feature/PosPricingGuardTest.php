<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\PosPriceSchedule;
use App\Models\Promotion;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PosPricingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_rejects_a_client_price_that_does_not_match_master_price(): void
    {
        [$user, $branch, $product] = $this->masters();

        $this->assertSame(100.0, app(PosPricingGuard::class)->validate($this->payload($branch, $product, 100), $user));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ราคาหรือส่วนลดเปลี่ยน');
        app(PosPricingGuard::class)->validate($this->payload($branch, $product, 1), $user);
    }

    public function test_server_recalculates_an_active_product_promotion(): void
    {
        [$user, $branch, $product] = $this->masters();
        Promotion::create([
            'code' => 'PROMO10', 'name' => 'ลด 10%', 'product_id' => $product->id,
            'discount_percent' => 10, 'is_active' => true,
        ]);

        $this->assertSame(90.0, app(PosPricingGuard::class)->validate($this->payload($branch, $product, 90), $user));
    }

    public function test_server_calculates_bundle_price_for_complete_sets_and_regular_price_for_remainder(): void
    {
        [$user, $branch, $product] = $this->masters();
        $product->update(['default_price' => 50]);
        ProductPrice::where('product_id', $product->id)->update(['price' => 50]);
        $promotion = QtyPromotion::create([
            'code' => 'BUNDLE3', 'name' => '3 ชิ้น 100 บาท', 'promo_type' => 'bundle_price',
            'product_id' => $product->id, 'min_qty' => 3, 'bundle_price' => 100,
            'is_active' => true,
        ]);

        $this->assertSame('ซื้อ 3 ชิ้น 100 บาท', $promotion->label());

        // 7 ชิ้น = 2 ชุด x 100 + 1 ชิ้น x 50 = 250 บาท
        $payload = $this->payload($branch, $product, 250 / 7);
        $payload['items'][0]['qty'] = 7;

        $this->assertSame(250.0, app(PosPricingGuard::class)->validate($payload, $user));
    }

    public function test_server_uses_only_a_published_price_schedule_inside_its_time_window(): void
    {
        [$user, $branch, $product] = $this->masters();
        $startsAt = now()->addHour();
        PosPriceSchedule::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'price' => 80,
            'effective_from' => $startsAt,
            'effective_to' => $startsAt->copy()->addHour(),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->assertSame(100.0, app(PosPricingGuard::class)->validate($this->payload($branch, $product, 100), $user));

        $this->travelTo($startsAt->copy()->addMinute());
        $this->assertSame(80.0, app(PosPricingGuard::class)->validate($this->payload($branch, $product, 80), $user));

        $this->travelBack();
    }

    public function test_manual_discount_requires_a_real_override_permission(): void
    {
        [$user, $branch, $product] = $this->masters();
        $payload = $this->payload($branch, $product, 90);
        $payload['manual_discount_amount'] = 10;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ผู้จัดการที่มีสิทธิ์อนุมัติ');
        app(PosPricingGuard::class)->validate($payload, $user);
    }

    public function test_verified_pack_barcode_is_normalized_to_base_stock_units(): void
    {
        [$user, $branch, $product] = $this->masters();
        $barcode = ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => '8850000000001',
            'unit_id' => $product->base_unit_id,
            'unit_factor' => 12,
            'price' => 1200,
            'is_active' => true,
        ]);
        $payload = $this->payload($branch, $product, 1200);
        $payload['items'][0]['barcode'] = $barcode->barcode;

        $this->assertSame(1200.0, app(PosPricingGuard::class)->validate($payload, $user));
        $normalized = app(PosPricingGuard::class)->normalizeItems($payload['items']);
        $this->assertSame(12.0, (float) $normalized[0]['qty']);
        $this->assertSame(100.0, (float) $normalized[0]['unit_price']);
        $this->assertArrayNotHasKey('barcode', $normalized[0]);
    }

    public function test_server_enforces_maximum_sale_price_per_base_unit(): void
    {
        [$user, $branch, $product] = $this->masters();
        $product->update(['maximum_sale_price' => 99]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('เกินราคาขายสูงสุด');
        app(PosPricingGuard::class)->validate($this->payload($branch, $product, 100), $user);
    }

    public function test_server_enforces_minimum_margin_after_vat(): void
    {
        [$user, $branch, $product] = $this->masters();
        $product->update([
            'average_cost' => 80,
            'minimum_margin_percent' => 20,
            'margin_control_policy' => 'block',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('กำไร');
        app(PosPricingGuard::class)->validate($this->payload($branch, $product, 100), $user);
    }

    /** @return array{User,Branch,Product} */
    private function masters(): array
    {
        $user = User::factory()->create(['username' => 'pricing_tester_'.uniqid()]);
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'P-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 120, 'average_cost' => 70, 'is_vat' => true, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $table = PriceTable::create(['code' => 'RETAIL', 'name' => 'ราคาปลีก', 'is_default' => true, 'is_active' => true]);
        ProductPrice::create(['product_id' => $product->id, 'price_table_id' => $table->id, 'price' => 100, 'is_active' => true]);

        return [$user, $branch, $product];
    }

    /** @return array<string,mixed> */
    private function payload(Branch $branch, Product $product, float $price): array
    {
        return [
            'branch_id' => $branch->id,
            'vat_mode' => 'included',
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $price]],
        ];
    }
}
