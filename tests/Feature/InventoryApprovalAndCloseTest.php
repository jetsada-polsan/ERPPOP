<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InventoryCostClose;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Http\Controllers\StockCountController;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\InventoryCostCloseService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
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

    public function test_full_catalog_stock_count_includes_zero_balance_and_inactive_products_by_scope(): void
    {
        [$branch, $location, $activeProduct] = $this->masters();
        $inactiveProduct = Product::create([
            'sku_code' => 'SKU-OFF', 'name_th' => 'สินค้าปิดใช้งาน', 'base_unit_id' => $activeProduct->base_unit_id,
            'average_cost' => 0, 'is_active' => false, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $this->assertSame(2, Product::query()->count());

        $count = app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_mode' => 'full_zero_missing', 'product_scope' => 'all',
        ]);

        $this->assertSame(2, $count->items()->count());
        $this->assertSame(0.0, (float) $count->items()->where('product_id', $activeProduct->id)->value('system_qty'));
        $this->assertSame(0.0, (float) $count->items()->where('product_id', $inactiveProduct->id)->value('system_qty'));
        $this->assertSame('all', $count->product_scope);
    }

    public function test_full_stock_count_cannot_submit_with_uncounted_items(): void
    {
        [$branch, $location] = $this->masters();
        $count = app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_mode' => 'full_zero_missing', 'product_scope' => 'active',
        ]);
        $count->update(['status' => 'review']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ต้องกรอกหรือ import จำนวนจริงให้ครบทุกสินค้า');
        app(StockCountService::class)->post($count);
    }

    public function test_stock_count_import_rejects_a_csv_missing_catalog_rows_without_partial_updates(): void
    {
        [$branch, $location, $product] = $this->masters();
        Product::create([
            'sku_code' => 'SKU-2', 'name_th' => 'สินค้าอีกตัว', 'base_unit_id' => $product->base_unit_id,
            'average_cost' => 0, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $count = app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_mode' => 'full_zero_missing', 'product_scope' => 'active',
        ]);
        $item = $count->items()->where('product_id', $product->id)->firstOrFail();
        $csv = implode("\n", [
            "ใบตรวจนับ,{$count->doc_number},สาขา,HQ,ตำแหน่งเก็บ,MAIN",
            'รหัสสินค้า,ชื่อสินค้า,หน่วย,จำนวนตามระบบ,จำนวนนับจริง,หมายเหตุ,รหัสอ้างอิง',
            "{$product->sku_code},สินค้าทดสอบ,ชิ้น,0,5,,{$item->id}",
        ]);
        $request = Request::create('/stock-counts/'.$count->id.'/import', 'POST', [], [], [
            'file' => UploadedFile::fake()->createWithContent('stock-count.csv', $csv),
        ]);

        try {
            app(StockCountController::class)->import($request, $count);
            $this->fail('ไฟล์ที่ขาดรายการต้องถูกปฏิเสธ');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ไฟล์มีรายการไม่ครบ', $exception->errors()['file'][0] ?? '');
        }

        $this->assertNull($count->items()->where('product_id', $product->id)->value('counted_qty'));
    }

    public function test_stock_count_import_accepts_a_complete_csv_and_saves_all_quantities(): void
    {
        [$branch, $location, $product] = $this->masters();
        $second = Product::create([
            'sku_code' => 'SKU-2', 'name_th' => 'สินค้าอีกตัว', 'base_unit_id' => $product->base_unit_id,
            'average_cost' => 0, 'is_active' => true, 'is_vat' => false, 'negative_stock_policy' => 'block',
        ]);
        $count = app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_mode' => 'full_zero_missing', 'product_scope' => 'active',
        ]);
        $items = $count->items()->with('product')->get();
        $rows = $items->map(fn ($item) => implode(',', [
            $item->product->sku_code, $item->product->name_th, 'ชิ้น', $item->system_qty,
            $item->product_id === $product->id ? '5' : '0', '', $item->id,
        ]))->all();
        $csv = implode("\n", [
            "ใบตรวจนับ,{$count->doc_number},สาขา,HQ,ตำแหน่งเก็บ,MAIN",
            'รหัสสินค้า,ชื่อสินค้า,หน่วย,จำนวนตามระบบ,จำนวนนับจริง,หมายเหตุ,รหัสอ้างอิง',
            ...$rows,
        ]);
        $request = Request::create('/stock-counts/'.$count->id.'/import', 'POST', [], [], [
            'file' => UploadedFile::fake()->createWithContent('stock-count.csv', $csv),
        ]);

        app(StockCountController::class)->import($request, $count);

        $this->assertSame(5.0, (float) $count->items()->where('product_id', $product->id)->value('counted_qty'));
        $this->assertSame(0.0, (float) $count->items()->where('product_id', $second->id)->value('counted_qty'));
    }

    public function test_cost_close_is_blocked_while_a_count_sheet_in_the_period_is_open(): void
    {
        $this->travelTo('2026-08-31 18:00:00');
        [$branch, $location] = $this->masters();
        app(StockCountService::class)->create([
            'branch_id' => $branch->id, 'warehouse_location_id' => $location->id,
            'count_mode' => 'full_zero_missing', 'product_scope' => 'active',
        ]);
        $this->assertDatabaseHas('stock_counts', ['status' => 'counting', 'count_date' => '2026-08-31 00:00:00']);
        $this->travelTo('2026-09-01 08:00:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ยังปิดต้นทุนไม่ได้ มีใบตรวจนับค้างในงวดนี้');
        app(InventoryCostCloseService::class)->close('2026-08');
    }

    public function test_cost_close_requires_a_complete_full_catalog_count_for_stock_locations(): void
    {
        [$branch, $location, $product] = $this->masters();
        StockBalance::create(['product_id' => $product->id, 'warehouse_location_id' => $location->id, 'on_hand_qty' => 0, 'reserved_qty' => 0]);
        $this->travelTo('2026-09-01 08:00:00');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ต้องมีใบตรวจนับเต็มที่กรอกครบและยืนยันแล้วสำหรับคลัง: MAIN');
        app(InventoryCostCloseService::class)->close('2026-08');
    }

    public function test_closed_inventory_period_blocks_backdated_stock_document_creation(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        [$branch] = $this->masters();
        $this->closePeriodWithFullCounts('2026-08');
        $type = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด', 'affects_stock' => true]);

        try {
            Document::create([
                'document_type_id' => $type->id, 'branch_id' => $branch->id,
                'doc_number' => 'CS-CLOSED-PERIOD', 'doc_date' => '2026-08-20', 'status' => 'draft',
            ]);
            $this->fail('เอกสารสต็อกย้อนหลังในงวดปิดต้องถูกปฏิเสธ');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('งวดที่ปิดต้นทุนสินค้าแล้ว', $exception->errors()['doc_date'][0] ?? '');
        }

        $this->assertDatabaseMissing('documents', ['doc_number' => 'CS-CLOSED-PERIOD']);
    }

    public function test_closed_inventory_period_blocks_edits_to_existing_stock_documents(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        [$branch] = $this->masters();
        $type = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด', 'affects_stock' => true]);
        $document = Document::create([
            'document_type_id' => $type->id, 'branch_id' => $branch->id,
            'doc_number' => 'CS-EDIT-CLOSED', 'doc_date' => '2026-08-20', 'status' => 'active',
        ]);
        app(InventoryCostCloseService::class)->close('2026-08');

        try {
            $document->update(['remark' => 'แก้ย้อนหลัง']);
            $this->fail('เอกสารสต็อกในงวดปิดต้องแก้ไม่ได้');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('งวดที่ปิดต้นทุนสินค้าแล้ว', $exception->errors()['doc_date'][0] ?? '');
        }
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
        $this->closePeriodWithFullCounts('2026-08');
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

        $this->closePeriodWithFullCounts('2026-06');
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

        $this->closePeriodWithFullCounts('2026-06');

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
        $this->closePeriodWithFullCounts('2026-08');
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

    private function closePeriodWithFullCounts(string $period): void
    {
        [$year, $month] = explode('-', $period);
        $countDate = \Carbon\Carbon::createFromDate((int) $year, (int) $month, 1)->endOfMonth()->toDateString();
        $locationIds = DB::table('stock_balances')->distinct()->pluck('warehouse_location_id')
            ->merge(DB::table('stock_movements')->whereBetween('movement_date', [$period.'-01', $countDate])->distinct()->pluck('warehouse_location_id'))
            ->unique();
        foreach ($locationIds as $locationId) {
            $warehouse = WarehouseLocation::findOrFail($locationId)->warehouse;
            $count = StockCount::create([
                'doc_number' => 'TEST-'.$period.'-'.$locationId.'-'.\Illuminate\Support\Str::random(8),
                'branch_id' => $warehouse->branch_id,
                'warehouse_location_id' => $locationId,
                'status' => 'posted',
                'count_mode' => 'full_zero_missing',
                'count_date' => $countDate,
                'product_scope' => 'all',
            ]);
            $products = Product::query()->get(['id']);
            foreach ($products as $catalogProduct) {
                $systemQty = DB::table('stock_balances')->where('warehouse_location_id', $locationId)
                    ->where('product_id', $catalogProduct->id)->value('on_hand_qty') ?? 0;
                $count->items()->create([
                    'product_id' => $catalogProduct->id,
                    'system_qty' => $systemQty,
                    'counted_qty' => $systemQty,
                ]);
            }
        }

        $this->assertSame($locationIds->count(), DB::table('stock_counts')
            ->join('stock_count_items', 'stock_count_items.stock_count_id', '=', 'stock_counts.id')
            ->whereDate('stock_counts.count_date', '>=', $period.'-01')
            ->whereDate('stock_counts.count_date', '<=', $countDate)
            ->where('stock_counts.status', 'posted')
            ->where('stock_counts.count_mode', 'full_zero_missing')
            ->where('stock_counts.product_scope', 'all')
            ->whereNotNull('stock_count_items.counted_qty')
            ->groupBy('stock_counts.id')
            ->havingRaw('COUNT(DISTINCT stock_count_items.product_id) = ?', [Product::query()->count()])
            ->get()->count());

        app(InventoryCostCloseService::class)->close($period);
    }
}
