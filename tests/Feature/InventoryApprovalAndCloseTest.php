<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\InventoryCostClose;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\InventoryCostCloseService;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryApprovalAndCloseTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertDatabaseHas('stock_movements', ['document_id' => $document->id, 'movement_type' => 'adjust']);
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
