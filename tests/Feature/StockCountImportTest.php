<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockCountImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private WarehouseLocation $location;
    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ErpAuthorize::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->branch = Branch::firstOrCreate(['code' => 'HQ'], ['name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'WH01'], ['branch_id' => $this->branch->id, 'name' => 'คลังหลัก']);
        $this->location = WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'MAIN'], ['name' => 'พื้นที่หลัก']);
        $this->unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
    }

    private function createCountSheet(int $count = 2): array
    {
        $stockCount = StockCount::create([
            'doc_number' => 'SC-TEST-'.uniqid(),
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'status' => 'counting',
            'count_mode' => 'partial',
        ]);

        $products = [];
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $product = Product::create([
                'sku_code' => 'SKU-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'name_th' => "สินค้าทดสอบ {$i}",
                'base_unit_id' => $this->unit->id,
                'average_cost' => 10.00,
                'is_active' => true,
                'is_vat' => false,
                'negative_stock_policy' => 'block',
            ]);
            $products[] = $product;

            $item = StockCountItem::create([
                'stock_count_id' => $stockCount->id,
                'product_id' => $product->id,
                'system_qty' => 100.0000,
                'counted_qty' => null,
                'note' => 'หมายเหตุเดิม '.$i,
            ]);
            $items[] = $item;
        }

        return [$stockCount, $products, $items];
    }

    public function test_import_updates_counted_qty_matching_original_behavior(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(3);

        $initialRowCount = StockCountItem::count();

        $csv = "รหัสสินค้า,ชื่อสินค้า,ยอดนับ\n";
        $csv .= "{$products[0]->sku_code},{$products[0]->name_th},45.50\n";
        $csv .= "{$products[1]->sku_code},{$products[1]->name_th},80\n";

        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->post(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('stock-counts.show', $stockCount));
        $response->assertSessionHas('success');

        // Check updated values
        $this->assertEquals(45.50, (float) $items[0]->fresh()->counted_qty);
        $this->assertEquals(80.00, (float) $items[1]->fresh()->counted_qty);
        $this->assertNull($items[2]->fresh()->counted_qty);

        // Ensure system_qty and note were untouched
        $this->assertEquals(100.00, (float) $items[0]->fresh()->system_qty);
        $this->assertSame('หมายเหตุเดิม 1', $items[0]->fresh()->note);
        $this->assertSame($stockCount->id, $items[0]->fresh()->stock_count_id);
        $this->assertSame($products[0]->id, $items[0]->fresh()->product_id);

        // Ensure no new rows were inserted
        $this->assertSame($initialRowCount, StockCountItem::count());
    }

    public function test_import_handles_duplicate_sku_where_last_row_wins(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(2);

        $csv = "SKU,Name,Counted\n";
        $csv .= "{$products[0]->sku_code},Item 1,10\n";
        $csv .= "{$products[1]->sku_code},Item 2,50\n";
        $csv .= "{$products[0]->sku_code},Item 1 Duplicate,99.75\n"; // Last row wins!

        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 2,
        ]);

        $this->assertEquals(99.75, (float) $items[0]->fresh()->counted_qty);
        $this->assertEquals(50.00, (float) $items[1]->fresh()->counted_qty);
    }

    public function test_file_without_a_counted_header_never_imports_the_system_qty_column(): void
    {
        [$stockCount, $products, $items] = $this->createCountSheet(1);

        // ไม่มีหัวคอลัมน์ และมีแค่ 4 คอลัมน์ (คอลัมน์สุดท้ายคือจำนวนตามระบบ) ต้องไม่ถูกนำไปเป็นยอดนับ
        $csv = "{$products[0]->sku_code},สินค้าทดสอบ 1,ชิ้น,100\n";
        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $this->postJson(route('stock-counts.import', $stockCount), ['file' => $file])
            ->assertOk()->assertJson(['updated' => 0]);
        $this->assertNull($items[0]->fresh()->counted_qty);
    }

    public function test_import_handles_bom_commas_and_whitespace(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(2);

        $bom = "\xEF\xBB\xBF";
        $csv = $bom;
        $csv .= " รหัสสินค้า , ชื่อสินค้า , ยอดนับ \n";
        $csv .= "  {$products[0]->sku_code}  , {$products[0]->name_th} ,  \"1,250.50\"  \n";
        $csv .= "  {$products[1]->sku_code}  , {$products[1]->name_th} ,  \"2,000\"  \n";

        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 2,
        ]);

        $this->assertEquals(1250.50, (float) $items[0]->fresh()->counted_qty);
        $this->assertEquals(2000.00, (float) $items[1]->fresh()->counted_qty);
    }

    public function test_import_skips_headers_empty_rows_and_non_numeric(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(2);

        $csv = "SKU,Name,Counted\n";
        $csv .= ",,\n"; // completely empty
        $csv .= "รหัสสินค้า,ชื่อ,ยอด\n"; // thai header repeat
        $csv .= "{$products[0]->sku_code},Item 1,abc\n"; // non-numeric
        $csv .= "{$products[0]->sku_code},Item 1,\n"; // empty counted
        $csv .= "{$products[1]->sku_code},Item 2,77\n"; // valid

        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 1,
        ]);

        $this->assertNull($items[0]->fresh()->counted_qty);
        $this->assertEquals(77.00, (float) $items[1]->fresh()->counted_qty);
    }

    public function test_import_skips_skus_not_in_count_sheet_and_reports_them(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(1);

        $csv = "SKU,Name,Counted\n";
        $csv .= "{$products[0]->sku_code},Item 1,35\n";
        $csv .= "UNKNOWN-SKU-999,Unknown Item,10\n";
        $csv .= "UNKNOWN-SKU-888,Unknown Item 2,20\n";

        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 1,
            'skipped_count' => 2,
            'skipped' => [
                'UNKNOWN-SKU-999',
                'UNKNOWN-SKU-888',
            ],
        ]);
        $response->assertJsonFragment([
            'message' => 'Import สำเร็จ อัปเดตยอดนับ 1 รายการ (ไม่พบรหัสในใบนี้ 2 รายการ เช่น UNKNOWN-SKU-999, UNKNOWN-SKU-888)',
        ]);

        $this->assertEquals(35.00, (float) $items[0]->fresh()->counted_qty);
    }

    public function test_import_aborts_with_422_when_stock_count_is_already_posted(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products] = $this->createCountSheet(1);
        $stockCount->update(['status' => 'posted']);

        $csv = "SKU,Name,Counted\n{$products[0]->sku_code},Item,10\n";
        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_import_query_count_does_not_grow_with_rows_1000_rows_uses_at_most_10_queries(): void
    {
        $stockCount = StockCount::create([
            'doc_number' => 'SC-1000-'.uniqid(),
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'status' => 'counting',
            'count_mode' => 'partial',
        ]);

        // Bulk insert 1,000 products and stock_count_items
        $productRecords = [];
        $skuCodes = [];
        for ($i = 1; $i <= 1000; $i++) {
            $sku = 'SKU-BULK-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
            $skuCodes[] = $sku;
            $productRecords[] = [
                'sku_code' => $sku,
                'name_th' => "สินค้าจำนวนมาก {$i}",
                'base_unit_id' => $this->unit->id,
                'average_cost' => 10.00,
                'is_active' => true,
                'is_vat' => false,
                'negative_stock_policy' => 'block',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($productRecords, 500) as $chunk) {
            Product::insert($chunk);
        }

        $insertedProducts = Product::whereIn('sku_code', $skuCodes)->get(['id', 'sku_code']);
        $itemRecords = [];
        $csvLines = ["SKU,Name,Counted\n"];
        foreach ($insertedProducts as $p) {
            $itemRecords[] = [
                'stock_count_id' => $stockCount->id,
                'product_id' => $p->id,
                'system_qty' => 50.0000,
                'counted_qty' => null,
                'note' => null,
            ];
            $csvLines[] = "{$p->sku_code},Name,99\n";
        }

        foreach (array_chunk($itemRecords, 500) as $chunk) {
            StockCountItem::insert($chunk);
        }

        $file = UploadedFile::fake()->createWithContent('1000_counts.csv', implode('', $csvLines));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 1000,
        ]);

        // 1,000 rows must use at most 10 queries (chunked in 500s inside transaction)
        $this->assertLessThanOrEqual(10, count($queries), 'Query count must be <= 10 for 1,000 rows. Actual query count: '.count($queries));

        // Verify count of updated items
        $this->assertSame(1000, StockCountItem::where('stock_count_id', $stockCount->id)->where('counted_qty', 99)->count());
    }

    public function test_import_returns_json_when_requested(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products] = $this->createCountSheet(1);

        $csv = "SKU,Name,Counted\n{$products[0]->sku_code},Item,15\n";
        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->post(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonStructure([
            'success',
            'message',
            'updated',
            'skipped',
            'skipped_count',
        ]);
        $response->assertJson([
            'success' => true,
            'updated' => 1,
            'skipped_count' => 0,
        ]);
    }

    public function test_import_redirects_when_not_requesting_json(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products] = $this->createCountSheet(1);

        $csv = "SKU,Name,Counted\n{$products[0]->sku_code},Item,22\n";
        $file = UploadedFile::fake()->createWithContent('counts.csv', $csv);

        $response = $this->post(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('stock-counts.show', $stockCount));
        $response->assertSessionHas('success', 'Import สำเร็จ อัปเดตยอดนับ 1 รายการ');
    }

    public function test_import_roundtrip_with_erp_exported_csv_format(): void
    {
        /** @var StockCount $stockCount */
        [$stockCount, $products, $items] = $this->createCountSheet(2);

        // Standard 6-column export from export():
        // Row 1: ใบตรวจนับ, ...
        // Row 2: รหัสสินค้า,ชื่อสินค้า,หน่วย,จำนวนตามระบบ,จำนวนนับจริง,หมายเหตุ
        // Row 3+: SKU, Name, Unit, system_qty, counted_qty, note
        $bom = "\xEF\xBB\xBF";
        $csv = $bom;
        $csv .= "ใบตรวจนับ,{$stockCount->doc_number},สาขา,สำนักงานใหญ่,ตำแหน่งเก็บ,พื้นที่หลัก\n";
        $csv .= "รหัสสินค้า,ชื่อสินค้า,หน่วย,จำนวนตามระบบ,จำนวนนับจริง,หมายเหตุ\n";
        $csv .= "{$products[0]->sku_code},{$products[0]->name_th},ชิ้น,100,85.25,\n";
        $csv .= "{$products[1]->sku_code},{$products[1]->name_th},ชิ้น,100,120,\n";

        $file = UploadedFile::fake()->createWithContent('exported_count.csv', $csv);

        $response = $this->postJson(route('stock-counts.import', $stockCount), [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'updated' => 2,
            'skipped_count' => 0,
        ]);

        $this->assertEquals(85.25, (float) $items[0]->fresh()->counted_qty);
        $this->assertEquals(120.00, (float) $items[1]->fresh()->counted_qty);
    }
}
