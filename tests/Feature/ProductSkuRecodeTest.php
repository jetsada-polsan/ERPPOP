<?php

namespace Tests\Feature;

use App\Models\MasterCutoverRun;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Services\ProductSkuAllocator;
use App\Services\ProductSkuRecodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSkuRecodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_runs_each_numeric_category_from_one_using_legacy_sku_order(): void
    {
        $meat = $this->category('101');
        $drink = $this->category('301');
        $first = $this->product('P000001', '801098', $meat);
        $second = $this->product('P000002', '801003', $meat);
        $third = $this->product('P000003', '301992', $drink);

        $plan = collect($this->service()->plan())->keyBy('id');

        $this->assertSame('101001', $plan[$second->id]['new']);
        $this->assertSame('101002', $plan[$first->id]['new']);
        $this->assertSame('301001', $plan[$third->id]['new']);
    }

    public function test_apply_preserves_legacy_sku_and_does_not_touch_barcodes(): void
    {
        $product = $this->product('P000010', '801098', $this->category('101'));
        $barcode = ProductBarcode::create([
            'product_id' => $product->id, 'barcode' => '8850001234567', 'unit_id' => $product->base_unit_id,
            'unit_factor' => 1, 'barcode_type' => 'CUSTOM', 'is_active' => true,
        ]);

        $result = $this->service()->apply();

        $this->assertSame(1, $result['products']);
        $this->assertSame('101001', $product->fresh()->sku_code);
        $this->assertSame('801098', $product->fresh()->legacy_sku);
        $this->assertSame($product->id, $barcode->fresh()->product_id);
        $this->assertSame(1, MasterCutoverRun::where('scope', ProductSkuRecodeService::SCOPE)->count());
    }

    public function test_unclassified_or_cancelled_products_block_recode(): void
    {
        $this->product('P000010', '801098', null);
        $this->product('P000011', '801099', $this->category('CC'));

        $problems = collect($this->service()->preflight());

        $this->assertTrue($problems->where('level', 'หยุด')->isNotEmpty());
    }

    public function test_next_sku_is_allocated_per_category_and_skips_deleted_numbers(): void
    {
        $category = $this->category('101');
        $first = $this->product('101001', '801001', $category);
        $this->product('101002', '801002', $category)->delete();

        $next = \DB::transaction(fn () => app(ProductSkuAllocator::class)->nextForCategory($category->id));

        $this->assertSame('101003', $next);
        $this->assertNotNull($first);
    }

    private function category(string $code): ProductCategory
    {
        return ProductCategory::firstOrCreate(['code' => $code], ['name_th' => 'ประเภท '.$code]);
    }

    private function product(string $sku, string $legacySku, ?ProductCategory $category): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA-SKU'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);

        $product = Product::create([
            'sku_code' => $sku,
            'name_th' => 'สินค้าทดสอบ '.$sku,
            'product_category_id' => $category?->id,
            'base_unit_id' => $unit->id,
            'default_price' => 10,
            'average_cost' => 0,
            'is_vat' => false,
            'is_active' => true,
        ]);
        // legacy_sku is audit-only and intentionally not mass assignable from UI.
        Product::whereKey($product->id)->update(['legacy_sku' => $legacySku]);

        return $product->fresh();
    }

    private function service(): ProductSkuRecodeService
    {
        return app(ProductSkuRecodeService::class);
    }
}
