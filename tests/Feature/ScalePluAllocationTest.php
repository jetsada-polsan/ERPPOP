<?php

namespace Tests\Feature;

use App\Http\Controllers\ScalePriceController;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ScalePluAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_scale_plu_starts_at_801001_and_does_not_change_legacy_800_codes(): void
    {
        $unit = ProductUnit::create(['code' => 'KG-PLU', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $legacy = $this->product('P-OLD-PLU', $unit->id);
        $new = $this->product('P-NEW-PLU', $unit->id);
        ProductBarcode::create([
            'product_id' => $legacy->id, 'barcode' => '800123', 'unit_id' => $unit->id,
            'unit_factor' => 1, 'is_active' => true,
        ]);

        app(ScalePriceController::class)->attachPlu(Request::create('/scale-prices/attach', 'POST', [
            'product_id' => $new->id,
        ]));

        $this->assertDatabaseHas('product_barcodes', ['product_id' => $new->id, 'barcode' => '801001']);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $legacy->id, 'barcode' => '800123']);
    }

    public function test_new_scale_plu_skips_an_existing_801_code(): void
    {
        $unit = ProductUnit::create(['code' => 'KG-SKIP', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $existing = $this->product('P-801001', $unit->id);
        $new = $this->product('P-801002', $unit->id);
        ProductBarcode::create([
            'product_id' => $existing->id, 'barcode' => '801001', 'unit_id' => $unit->id,
            'unit_factor' => 1, 'is_active' => true,
        ]);

        app(ScalePriceController::class)->attachPlu(Request::create('/scale-prices/attach', 'POST', [
            'product_id' => $new->id,
        ]));

        $this->assertDatabaseHas('product_barcodes', ['product_id' => $new->id, 'barcode' => '801002']);
    }

    private function product(string $sku, int $unitId): Product
    {
        return Product::create([
            'sku_code' => $sku,
            'name_th' => 'สินค้าชั่ง '.$sku,
            'base_unit_id' => $unitId,
            'default_price' => 1,
            'is_active' => true,
        ]);
    }
}
