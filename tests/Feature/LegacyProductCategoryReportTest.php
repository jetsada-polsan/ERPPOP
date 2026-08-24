<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Services\LegacyProductCategoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyProductCategoryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_bplus_rows_and_updates_only_the_product_category(): void
    {
        $old = ProductCategory::create(['code' => '101', 'name_th' => 'เก่า']);
        $target = ProductCategory::create(['code' => '102', 'name_th' => 'ใหม่']);
        $unit = ProductUnit::create(['code' => 'EA-LEGACY-REPORT', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'P000001', 'name_th' => 'สินค้าทดสอบ', 'product_category_id' => $old->id, 'base_unit_id' => $unit->id, 'default_price' => 1, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true]);
        Product::whereKey($product->id)->update(['legacy_sku' => '801021']);

        $file = $this->reportFile([
            ['102', 'ชิ้นส่วนหมู', '801021', 'สินค้าในรายงาน'],
            ['102', 'ชิ้นส่วนหมู', 'NO-SUCH-SKU', 'ไม่พบใน ERP'],
        ]);
        $service = app(LegacyProductCategoryReportService::class);
        $plan = $service->plan($file);

        $this->assertSame(1, collect($plan['rows'])->where('status', 'CATEGORY_CHANGE')->count());
        $this->assertSame(1, collect($plan['rows'])->where('status', 'LEGACY_NOT_FOUND')->count());
        $this->assertSame('P000001', $product->fresh()->sku_code);
        $this->assertSame(1, $service->apply($plan['rows']));
        $this->assertSame($target->id, $product->fresh()->product_category_id);
    }

    public function test_it_stops_on_a_category_missing_from_erp(): void
    {
        $file = $this->reportFile([['777', 'ไม่มีใน ERP', '801021', 'สินค้า']]);
        $problems = app(LegacyProductCategoryReportService::class)->plan($file)['problems'];

        $this->assertTrue(collect($problems)->contains('level', 'หยุด'));
    }

    /** @param array<int, array{string,string,string,string}> $items */
    private function reportFile(array $items): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ic021003-');
        $handle = fopen($path, 'wb');
        foreach ($items as [$category, $categoryName, $legacySku, $name]) {
            // 73 columns: marker starts at offset 38, identical to normal IC021003 rows.
            $row = array_fill(0, 73, '');
            $row[38] = 'ประเภทสินค้า'; $row[39] = $category; $row[40] = $categoryName;
            $row[41] = $legacySku; $row[42] = $name;
            fputcsv($handle, array_map(fn (string $value) => iconv('UTF-8', 'CP874//TRANSLIT', $value), $row));
        }
        fclose($handle);
        return $path;
    }
}
