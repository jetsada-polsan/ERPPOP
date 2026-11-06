<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Inventory\StockIssueService;
use App\Services\Inventory\StockTransferReceiptService;
use App\Services\Inventory\StockTransferService;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** เดินเส้นคลังครบ: โอนข้ามสาขา ตรวจรับ เบิกใช้ ปรับสต๊อก แล้วเช็กยอดและ GL ดุล */
class InventoryFlowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function location(string $code): array
    {
        $branch = Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$code, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$code, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        return [$branch, $location];
    }

    private function onHand(Product $product, WarehouseLocation $location): float
    {
        return (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->value('on_hand_qty');
    }

    public function test_transfer_receive_issue_and_adjust_keep_stock_and_ledger_right(): void
    {
        foreach (['PURCHASE' => 'in', 'STOCK_TRANSFER' => 'none', 'STOCK_ADJUSTMENT' => 'none', 'STOCK_REQUISITION' => 'out'] as $code => $effect) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $this->actingAs(tap(User::factory()->create(['is_active' => true]), fn ($u) => $u->roles()->attach(Role::where('code', 'GM')->value('id'))));
        [$hq, $hqLocation] = $this->location('FHQ');
        [$shop, $shopLocation] = $this->location('FSH');
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'FLOW1', 'name_th' => 'x', 'base_unit_id' => $unit->id, 'default_price' => 100, 'is_active' => true, 'negative_stock_policy' => 'block']);
        $supplier = Supplier::create(['code' => 'SUPF', 'name_th' => 's', 'is_active' => true]);
        app(PurchaseService::class)->create(['branch_id' => $hq->id, 'supplier_id' => $supplier->id, 'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => 20, 'unit_price' => 50]]]);
        $this->assertSame(20.0, $this->onHand($product, $hqLocation), 'รับซื้อเข้า HQ');

        $transfer = app(StockTransferService::class)->createRequest(['branch_id' => $hq->id, 'from_warehouse_location_id' => $hqLocation->id,
            'to_warehouse_location_id' => $shopLocation->id, 'remark' => null, 'items' => [['product_id' => $product->id, 'qty' => 8]]]);
        app(StockTransferService::class)->approve($transfer);
        $this->assertSame(12.0, $this->onHand($product, $hqLocation), 'อนุมัติโอนแล้วตัดต้นทาง');

        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame(0.0, $this->onHand($product, $shopLocation));
        $before = DB::table('stock_movements')->count();
        try {
            app(StockTransferService::class)->approve($transfer); // stale pending instance
            $this->fail('duplicate approval accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('รออนุมัติ', $e->getMessage());
        }
        $this->assertSame($before, DB::table('stock_movements')->count());
        $transitId = $transfer->fresh()->stockDocument->transit_location_id;
        $this->assertNotNull($transitId);
        try {
            DB::transaction(fn () => app(FifoStockService::class)->issue(
                $product->id, $transitId, 1, null, allowRestricted: true));
            $this->fail('transit stock was sold');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $receipts = app(StockTransferReceiptService::class);
        $receipt = $receipts->openOrGet($transfer->fresh());
        $receipts->saveScans($receipt, $receipt->items->map(fn ($item) => ['id' => $item->id, 'scanned_qty' => 8])->all());
        $receipts->complete($receipt->fresh());
        $this->assertSame(8.0, $this->onHand($product, $shopLocation), 'ปลายทางรับครบ');

        $this->assertSame('active', $transfer->fresh()->status);
        $this->assertSame(StockLot::where('warehouse_location_id', $hqLocation->id)->first()->unit_cost, StockLot::where('warehouse_location_id', $shopLocation->id)->first()->unit_cost);
        try {
            $receipts->complete($receipt); // stale checking instance
            $this->fail('duplicate receipt accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ปิดแล้ว', $e->getMessage());
        }

        $issue = app(StockIssueService::class)->create(['type' => 'requisition', 'branch_id' => $shop->id, 'warehouse_location_id' => $shopLocation->id,
            'purpose' => 'office', 'reference' => null, 'remark' => null, 'items' => [['product_id' => $product->id, 'qty' => 2]]]);
        $this->assertNotNull($issue);

        $adjustment = app(StockAdjustmentService::class)->create(['branch_id' => $hq->id, 'warehouse_location_id' => $hqLocation->id,
            'remark' => null, 'items' => [['product_id' => $product->id, 'counted_qty' => 10]]]);
        app(StockAdjustmentService::class)->approve($adjustment);
        $this->assertSame(10.0, $this->onHand($product, $hqLocation), 'ปรับยอดตามนับจริง');

        $unbalanced = DB::table('gl_journals')->select('document_id')->groupBy('document_id')
            ->havingRaw('round(sum(debit) - sum(credit), 2) <> 0')->count();
        $this->assertSame(0, $unbalanced, 'ทุกเอกสารลง GL ดุล');
        $this->assertTrue(DB::table('gl_journals')->where('document_id', $adjustment->id)->exists(), 'ปรับสต๊อกต้องลง GL');
    }
}
