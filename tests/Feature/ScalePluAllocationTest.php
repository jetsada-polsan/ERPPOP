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

    public function test_scale_price_screen_never_generates_or_changes_an_existing_scale_plu(): void
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

        $this->assertSame(1, ProductBarcode::count(), 'หน้าราคาเครื่องชั่งต้องไม่สร้าง PLU เอง');
        $this->assertDatabaseMissing('product_barcodes', ['product_id' => $new->id]);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $legacy->id, 'barcode' => '800123']);
    }

    public function test_an_existing_801_plu_remains_usable_without_any_renumbering(): void
    {
        $unit = ProductUnit::create(['code' => 'KG-SKIP', 'name' => 'กิโลกรัม', 'qty_per_base_unit' => 1]);
        $existing = $this->product('P-801001', $unit->id);
        ProductBarcode::create([
            'product_id' => $existing->id, 'barcode' => '801001', 'unit_id' => $unit->id,
            'unit_factor' => 1, 'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_barcodes', ['product_id' => $existing->id, 'barcode' => '801001']);
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
