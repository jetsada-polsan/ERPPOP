<?php

namespace Tests\Feature;

use App\Http\Controllers\StockCountController;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InventoryCostClose;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockCountItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\InventoryCostCloseService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InventoryApprovalAndCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_adjustment_rejects_a_location_from_another_branch(): void
    {
        [$branch, , $product] = $this->masters();
        $otherBranch = Branch::create(['code' => 'B002', 'name_th' => 'สาขา 2', 'is_active' => true]);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'code' => 'WH-B002', 'name' => 'คลัง 2']);
        $otherLocation = WarehouseLocation::create(['warehouse_id' => $otherWarehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ตำแหน่งเก็บต้องอยู่ในสาขาเดียวกับใบปรับสต๊อก');

        app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id,
            'warehouse_location_id' => $otherLocation->id,
            'items' => [['product_id' => $product->id, 'counted_qty' => 1]],
        ]);
    }

    public function test_stock_count_rejects_cross_branch_or_duplicate_open_sheet(): void
    {
        [$branch, $location, $product] = $this->masters();
        $otherBranch = Branch::create(['code' => 'B002', 'name_th' => 'สาขา 2', 'is_active' => true]);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'code' => 'WH-B002', 'name' => 'คลัง 2']);
        $otherLocation = WarehouseLocation::create(['warehouse_id' => $otherWarehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 1, 'reserved_qty' => 0]);
        $counts = app(StockCountService::class);

        try {
            $counts->create(['branch_id' => $branch->id, 'warehouse_location_id' => $otherLocation->id, 'count_mode' => 'partial']);
            $this->fail('ต้องปฏิเสธตำแหน่งเก็บต่างสาขา');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ตำแหน่งเก็บต้องอยู่ในสาขาเดียวกับใบตรวจนับ', $exception->getMessage());
        }

        $counts->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ตำแหน่งเก็บนี้มีใบตรวจนับที่ยังไม่ปิดอยู่');
        $counts->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);
    }

    public function test_stock_count_includes_inactive_and_zero_balance_catalog_products(): void
    {
        [$branch, $location, $product] = $this->masters();
        $inactive = Product::create([
            'sku_code' => 'SKU-2', 'name_th' => 'สินค้าไม่ใช้งาน', 'base_unit_id' => $product->base_unit_id,
            'average_cost' => 0, 'is_active' => false, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $count = app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial',
        ]);

        $this->assertDatabaseCount('stock_count_items', 2);
        $this->assertDatabaseHas('stock_count_items', ['stock_count_id' => $count->id, 'product_id' => $inactive->id, 'system_qty' => 0]);
    }

    public function test_stock_count_export_is_numbered_and_limited_to_selected_branch_location(): void
    {
        [$branch, $location, $product] = $this->masters();
        $pack = ProductUnit::create(['code' => 'PACK25', 'name' => 'หิ้ว', 'qty_per_base_unit' => 25]);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'PACK25-TEST', 'unit_id' => $pack->id, 'unit_factor' => 25, 'is_active' => true]);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 53, 'reserved_qty' => 0]);
        $otherBranch = Branch::create(['code' => 'B002', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $otherWarehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'code' => 'WH-B002', 'name' => 'คลัง 2']);
        $otherLocation = WarehouseLocation::create(['warehouse_id' => $otherWarehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $otherLocation->id, 'on_hand_qty' => 999, 'reserved_qty' => 0]);
        $count = app(StockCountService::class)->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);

        $response = app(StockCountController::class)->export(Request::create('/stock-counts/'.$count->id.'/export', 'GET'), $count, app(StockCountService::class));
        ob_start();
        $response->sendContent();
        $lines = preg_split('/\r\n|\n|\r/', trim(ob_get_clean()));
        $meta = str_getcsv($lines[0]);
        $headers = str_getcsv($lines[1]);
        $row = str_getcsv($lines[2]);

        $this->assertSame($branch->name_th, $meta[3]);
        $this->assertSame(['ลำดับ', 'รหัสสินค้า', 'ชื่อสินค้า', 'หน่วยหลัก', 'ตัวคูณเป็นหน่วยย่อย', 'จำนวนตามระบบ (หน่วยหลัก)', 'หน่วยย่อย', 'จำนวนตามระบบ (หน่วยย่อย)', 'นับจริง (หน่วยหลัก)', 'นับจริง (หน่วยย่อย)', 'หมายเหตุ'], $headers);
        $this->assertSame('1', $row[0]);
        $this->assertSame($product->sku_code, $row[1]);
        $this->assertSame('หิ้ว', $row[3]);
        $this->assertSame('25', $row[4]);
        $this->assertSame('2', $row[5]);
        $this->assertSame('3', $row[7]);
        $this->assertStringNotContainsString('999', implode(',', $lines));
    }

    public function test_stock_count_export_can_filter_by_active_status_and_category(): void
    {
        [$branch, $location, $active] = $this->masters();
        $category = ProductCategory::create(['code' => 'CAT1', 'name_th' => 'หมวดหนึ่ง']);
        $otherCategory = ProductCategory::create(['code' => 'CAT2', 'name_th' => 'หมวดสอง']);
        $active->update(['product_category_id' => $category->id]);
        $inactive = Product::create(['sku_code' => 'SKU-2', 'name_th' => 'ปิดใช้งาน', 'base_unit_id' => $active->base_unit_id, 'average_cost' => 0, 'is_active' => false, 'is_vat' => false, 'negative_stock_policy' => 'block']);
        $other = Product::create(['sku_code' => 'SKU-3', 'name_th' => 'อีกหมวด', 'base_unit_id' => $active->base_unit_id, 'product_category_id' => $otherCategory->id, 'average_cost' => 0, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block']);
        $count = app(StockCountService::class)->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);
        $request = Request::create('/stock-counts/'.$count->id.'/export', 'GET', ['active' => '1', 'category_id' => $category->id]);

        $response = app(StockCountController::class)->export($request, $count, app(StockCountService::class));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString($active->sku_code, $csv);
        $this->assertStringNotContainsString($inactive->sku_code, $csv);
        $this->assertStringNotContainsString($other->sku_code, $csv);
    }

    public function test_stock_count_import_converts_large_and_small_unit_counts_to_base_units(): void
    {
        [$branch, $location, $product] = $this->masters();
        $pack = ProductUnit::create(['code' => 'PACK25', 'name' => 'หิ้ว', 'qty_per_base_unit' => 25]);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'PACK25-IMPORT', 'unit_id' => $pack->id, 'unit_factor' => 25, 'is_active' => true]);
        $count = app(StockCountService::class)->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);
        $csv = "\xEF\xBB\xBFใบตรวจนับ,{$count->doc_number},สาขา,{$branch->name_th},ตำแหน่งเก็บ,{$location->name}\nลำดับ,รหัสสินค้า,ชื่อสินค้า,หน่วยหลัก,ตัวคูณเป็นหน่วยย่อย,จำนวนตามระบบ (หน่วยหลัก),หน่วยย่อย,จำนวนตามระบบ (หน่วยย่อย),นับจริง (หน่วยหลัก),นับจริง (หน่วยย่อย),หมายเหตุ\n1,SKU-1,ทดสอบ,หิ้ว,25,0,ชิ้น,0,1,5,\n";
        $file = UploadedFile::fake()->createWithContent('count.csv', $csv);
        $request = Request::create('/stock-counts/'.$count->id.'/import', 'POST', [], [], ['file' => $file]);

        app(StockCountController::class)->import($request, $count, app(StockCountService::class));

        $this->assertSame(30.0, (float) StockCountItem::where('stock_count_id', $count->id)->where('product_id', $product->id)->value('counted_qty'));
    }

    public function test_stock_count_import_rejects_a_file_from_another_branch(): void
    {
        [$branch, $location, $product] = $this->masters();
        $otherBranch = Branch::create(['code' => 'B002', 'name_th' => 'อีกสาขา', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $otherBranch->id, 'code' => 'WH-B002', 'name' => 'คลัง 2']);
        $otherLocation = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $count = app(StockCountService::class)->create(['branch_id' => $branch->id, 'warehouse_location_id' => $location->id, 'count_mode' => 'partial']);
        $otherCount = app(StockCountService::class)->create(['branch_id' => $otherBranch->id, 'warehouse_location_id' => $otherLocation->id, 'count_mode' => 'partial']);
        $csv = "\xEF\xBB\xBFใบตรวจนับ,{$otherCount->doc_number},สาขา,{$otherBranch->name_th},ตำแหน่งเก็บ,{$otherLocation->name}\nรหัสสินค้า,ชื่อสินค้า,หน่วย,จำนวนตามระบบ,จำนวนนับจริง,หมายเหตุ\n{$product->sku_code},ทดสอบ,ชิ้น,0,99,\n";
        $file = UploadedFile::fake()->createWithContent('wrong-branch.csv', $csv);
        $request = Request::create('/stock-counts/'.$count->id.'/import', 'POST', [], [], ['file' => $file]);

        app(StockCountController::class)->import($request, $count, app(StockCountService::class));

        $this->assertNull(StockCountItem::where('stock_count_id', $count->id)->where('product_id', $product->id)->value('counted_qty'));
    }

    public function test_stock_adjustment_does_not_change_stock_until_another_user_approves(): void
    {
        [$branch, $location, $product] = $this->masters();
        DocumentType::create(['code' => 'STOCK_ADJUSTMENT', 'name_th' => 'ใบปรับสต๊อก']);
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 10, 'reserved_qty' => 0]);
        $creator = User::factory()->create(['username' => 'counter']);
        $approver = User::factory()->create(['username' => 'approver']);

        $this->actingAs($creator);
        $document = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'remark' => 'cycle count', 'items' => [['product_id' => $product->id, 'counted_qty' => 7]],
        ]);

        $this->assertSame('pending_approval', $document->status);
        $this->assertSame(10.0, (float) StockBalance::first()->on_hand_qty);
        $this->assertDatabaseMissing('stock_movements', ['document_id' => $document->id]);

        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);
        $this->assertSame('active', $document->fresh()->status);
        $this->assertSame(7.0, (float) StockBalance::first()->on_hand_qty);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $document->id, 'movement_type' => 'adjust_out']);
    }

    public function test_shrinkage_adjustment_depletes_stock_lots_not_just_stock_balances(): void
    {
        [$branch, $location, $product] = $this->masters();
        $fifo = app(FifoStockService::class);
        $fifo->receive($product->id, $location->id, 10, null, unitCost: 10);
        StockBalance::where('product_id', $product->id)->update(['on_hand_qty' => 10]);
        DocumentType::create(['code' => 'STOCK_ADJUSTMENT', 'name_th' => 'ใบปรับสต๊อก']);
        $creator = User::factory()->create(['username' => 'shrink-counter']);
        $approver = User::factory()->create(['username' => 'shrink-approver']);

        $this->actingAs($creator);
        $document = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'remark' => 'shrinkage', 'items' => [['product_id' => $product->id, 'counted_qty' => 6]],
        ]);
        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        // stock_balances และ stock_lots ต้องตรงกัน ไม่ใช่แค่ stock_balances ที่ถูกแก้
        $this->assertSame(6.0, (float) StockBalance::first()->on_hand_qty);
        $lotSum = (float) DB::table('stock_lots')->where('product_id', $product->id)->sum('remaining_qty');
        $this->assertSame(6.0, $lotSum);
    }

    public function test_cost_close_counts_shrinkage_as_an_issue_and_reduces_lot_value(): void
    {
        $this->travelTo('2026-08-10 10:00:00');
        [$branch, $location, $product] = $this->masters();
        app(FifoStockService::class)->receive($product->id, $location->id, 10, null, receivedDate: '2026-08-01', unitCost: 10);
        DocumentType::create(['code' => 'STOCK_ADJUSTMENT', 'name_th' => 'ใบปรับสต๊อก']);
        $creator = User::factory()->create(['username' => 'close-counter']);
        $approver = User::factory()->create(['username' => 'close-approver']);

        $this->actingAs($creator);
        $document = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'remark' => 'shrink before close', 'items' => [['product_id' => $product->id, 'counted_qty' => 6]],
        ]);
        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        $this->travelTo('2026-09-01 10:00:00');
        app(InventoryCostCloseService::class)->close('2026-08');
        $close = InventoryCostClose::where('period', '2026-08')->where('product_id', $product->id)->firstOrFail();

        $this->assertSame(4.0, (float) $close->issued_qty);
        $this->assertSame(6.0, (float) $close->ending_qty);
        $this->assertSame(60.0, (float) $close->ending_value);
    }

    public function test_found_extra_adjustment_creates_a_costed_lot_not_a_silent_balance_bump(): void
    {
        [$branch, $location, $product] = $this->masters();
        $product->update(['average_cost' => 12]);
        // เริ่มจากสต๊อกที่ stock_lots กับ stock_balances ตรงกันอยู่แล้ว (4 หน่วย ผ่าน fifo->receive)
        app(FifoStockService::class)->receive($product->id, $location->id, 4, null, unitCost: 8);
        DocumentType::create(['code' => 'STOCK_ADJUSTMENT', 'name_th' => 'ใบปรับสต๊อก']);
        $creator = User::factory()->create(['username' => 'found-counter']);
        $approver = User::factory()->create(['username' => 'found-approver']);

        $this->actingAs($creator);
        $document = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'remark' => 'found extra', 'items' => [['product_id' => $product->id, 'counted_qty' => 9]],
        ]);
        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        $this->assertSame(9.0, (float) StockBalance::first()->on_hand_qty);
        // stock_lots ต้องตามทัน: 4 หน่วยเดิม (unit_cost 8) + 5 หน่วยที่เจอเกินใหม่ (unit_cost 12) = 9
        $lotSum = (float) DB::table('stock_lots')->where('product_id', $product->id)->sum('remaining_qty');
        $this->assertSame(9.0, $lotSum);
        // Lot ของส่วนที่เกินต้องมีต้นทุน (average_cost ปัจจุบัน) ไม่ใช่ 0 ลอยๆ
        $foundLotCost = DB::table('stock_lots')->where('product_id', $product->id)
            ->where('remaining_qty', 5)->value('unit_cost');
        $this->assertSame(12.0, (float) $foundLotCost);
    }

    public function test_opening_adjustment_applies_explicit_cost_only_when_approved(): void
    {
        [$branch, $location, $product] = $this->masters();
        $product->update(['average_cost' => 5]);
        DocumentType::create(['code' => 'STOCK_ADJUSTMENT', 'name_th' => 'ใบปรับสต๊อก']);
        $creator = User::factory()->create(['username' => 'opening-maker']);
        $approver = User::factory()->create(['username' => 'opening-checker']);

        $this->actingAs($creator);
        $document = app(StockAdjustmentService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'remark' => 'opening stock',
            'items' => [['product_id' => $product->id, 'counted_qty' => 10, 'unit_cost' => 27.5]],
        ]);

        $this->assertSame(5.0, (float) $product->fresh()->average_cost);
        $this->assertDatabaseMissing('stock_lots', ['source_document_id' => $document->id]);

        $this->actingAs($approver);
        app(StockAdjustmentService::class)->approve($document);

        $this->assertSame(27.5, (float) $product->fresh()->average_cost);
        $this->assertDatabaseHas('stock_lots', [
            'source_document_id' => $document->id, 'remaining_qty' => 10, 'unit_cost' => 27.5,
        ]);
    }

    public function test_cost_close_uses_movements_up_to_period_end_only(): void
    {
        [, $location, $product] = $this->masters();
        $fifo = app(FifoStockService::class);
        $fifo->receive($product->id, $location->id, 5, null, receivedDate: '2026-05-20', unitCost: 10);
        $fifo->receive($product->id, $location->id, 3, null, receivedDate: '2026-06-05', unitCost: 20);
        $fifo->issue($product->id, $location->id, 2, null, movementDate: '2026-06-15');
        $fifo->receive($product->id, $location->id, 9, null, receivedDate: '2026-07-01', unitCost: 99);

        app(InventoryCostCloseService::class)->close('2026-06');
        $close = InventoryCostClose::where('period', '2026-06')->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(5.0, (float) $close->opening_qty);
        $this->assertSame(3.0, (float) $close->received_qty);
        $this->assertSame(2.0, (float) $close->issued_qty);
        $this->assertSame(6.0, (float) $close->ending_qty);
        $this->assertSame(90.0, (float) $close->ending_value);
    }

    /**
     * "2026-06" ต้องหมายถึงมิถุนายนเสมอ ไม่ว่าจะสั่งปิดวันไหนของเดือน
     * ถ้า parse ด้วย 'Y-m' เฉยๆ วันที่ของวันนี้จะติดมาด้วย พอสั่งวันที่ 31
     * เดือนที่มี 30 วันจะล้นไปเดือนถัดไป กลายเป็นปิดผิดงวดโดยไม่มีใครรู้
     */
    public function test_period_string_is_not_shifted_by_the_day_the_close_is_run(): void
    {
        $this->travelTo('2026-07-31 09:00:00');
        [, $location, $product] = $this->masters();
        $fifo = app(FifoStockService::class);
        $fifo->receive($product->id, $location->id, 4, null, receivedDate: '2026-06-10', unitCost: 25);

        app(InventoryCostCloseService::class)->close('2026-06');

        $close = InventoryCostClose::where('period', '2026-06')->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(4.0, (float) $close->received_qty);
        $this->assertSame(4.0, (float) $close->ending_qty);
    }

    public function test_void_restores_the_original_fifo_lot_and_period_cost(): void
    {
        $this->travelTo('2026-08-10 10:00:00');
        [$branch, $location, $product] = $this->masters();
        $type = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด']);
        $document = Document::create([
            'document_type_id' => $type->id,
            'branch_id' => $branch->id,
            'doc_number' => 'CS-VOID-1',
            'doc_date' => '2026-08-10',
        ]);
        $fifo = app(FifoStockService::class);
        $lot = $fifo->receive($product->id, $location->id, 10, null, receivedDate: '2026-08-01', unitCost: 15);
        $fifo->issue($product->id, $location->id, 4, $document->id, movementDate: '2026-08-10');

        $fifo->restoreDocumentIssues($document->id, movementDate: '2026-08-10');

        $this->assertSame(10.0, (float) $lot->fresh()->remaining_qty);
        $this->assertSame(10.0, (float) StockBalance::first()->on_hand_qty);
        $this->assertDatabaseHas('stock_movements', [
            'document_id' => $document->id,
            'stock_lot_id' => $lot->id,
            'movement_type' => 'void_in',
            'qty' => 4,
        ]);

        $this->travelTo('2026-09-01 10:00:00');
        app(InventoryCostCloseService::class)->close('2026-08');
        $close = InventoryCostClose::where('period', '2026-08')->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(14.0, (float) $close->received_qty);
        $this->assertSame(4.0, (float) $close->issued_qty);
        $this->assertSame(10.0, (float) $close->ending_qty);
        $this->assertSame(150.0, (float) $close->ending_value);
    }

    public function test_opening_import_rejects_warehouses_with_movement_history(): void
    {
        [$branch, $location, $product] = $this->masters();
        app(FifoStockService::class)->receive($product->id, $location->id, 1, null, unitCost: 10);
        $path = tempnam(sys_get_temp_dir(), 'opening-stock-');
        File::put($path, "sku,name,cost,สำนักงานใหญ่({$branch->code})\n{$product->sku_code},สินค้า,10,5\n");

        try {
            $exit = Artisan::call('stock:import-opening', ['csv' => $path, '--dry-run' => true]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('พบประวัติการเคลื่อนไหว', Artisan::output());
        } finally {
            File::delete($path);
        }
    }

    public function test_opening_import_dry_run_rejects_invalid_numeric_cells(): void
    {
        [$branch, , $product] = $this->masters();
        $path = tempnam(sys_get_temp_dir(), 'opening-stock-');
        File::put($path, "sku,name,cost,สำนักงานใหญ่({$branch->code})\n{$product->sku_code},สินค้า,10,not-a-number\n");

        try {
            $exit = Artisan::call('stock:import-opening', ['csv' => $path, '--dry-run' => true]);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString('ไม่ใช่ตัวเลข', Artisan::output());
            $this->assertDatabaseCount('documents', 0);
        } finally {
            File::delete($path);
        }
    }

    private function masters(): array
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'average_cost' => 10, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);

        return [$branch->fresh(), $location, $product];
    }
}
