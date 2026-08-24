<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Services\ManufacturerEan13Service;
use App\Support\BarcodePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturerEan13Test extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_verified_885_ean13_is_retyped_as_a_manufacturer_code(): void
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-MFG'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => '104001', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 10, 'average_cost' => 5, 'is_vat' => false, 'is_active' => true,
        ]);
        $valid = ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '8850000000003', 'barcode_type' => BarcodePolicy::INTERNAL_13,
            'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true,
        ]);
        $invalid = ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '8850000000000', 'barcode_type' => BarcodePolicy::INTERNAL_13,
            'unit_id' => $unit->id, 'unit_factor' => 1, 'is_active' => true,
        ]);

        $service = app(ManufacturerEan13Service::class);
        $plan = $service->plan();

        $this->assertCount(1, $plan['standard']);
        $this->assertCount(1, $plan['exceptions']);
        $this->assertSame(1, $service->apply($plan['standard']));
        $this->assertSame(BarcodePolicy::EAN13_STANDARD, $valid->fresh()->barcode_type);
        $this->assertSame(BarcodePolicy::INTERNAL_13, $invalid->fresh()->barcode_type);
        $this->assertSame('8850000000003', $valid->fresh()->barcode);
    }
}
