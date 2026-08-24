<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Services\LegacyScalePluService;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyScalePluTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_exact_six_digit_801_plu_is_classified_without_changing_product_or_barcode(): void
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'KG-SCALE'], ['name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => '102001', 'legacy_sku' => '102001', 'name_th' => 'หมูทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 189, 'average_cost' => 120, 'is_vat' => false, 'is_active' => true,
        ]);
        $valid = ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '801001', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true,
        ]);
        $invalid = ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '8010010', 'barcode_type' => BarcodePolicy::CUSTOM,
            'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true,
        ]);

        $service = app(LegacyScalePluService::class);
        $plan = $service->plan();

        $this->assertCount(1, $plan['candidates']);
        $this->assertCount(1, $plan['exceptions']);
        $this->assertSame(1, $service->apply($plan['candidates']));
        $this->assertSame(BarcodePolicy::SCALE_PLU, $valid->fresh()->barcode_type);
        $this->assertSame(BarcodePolicy::CUSTOM, $invalid->fresh()->barcode_type);
        $this->assertSame('801001', $valid->fresh()->barcode);
        $this->assertSame('102001', $product->fresh()->sku_code);
    }

    public function test_scale_plu_requires_the_legacy_six_digit_801_shape(): void
    {
        $policy = app(BarcodePolicy::class);

        $this->assertTrue($policy->check(BarcodePolicy::SCALE_PLU, '801001')['ok']);
        $this->assertFalse($policy->check(BarcodePolicy::SCALE_PLU, '8010010')['ok']);
        $this->assertFalse($policy->check(BarcodePolicy::SCALE_PLU, '800123')['ok']);
    }

}
