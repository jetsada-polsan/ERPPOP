<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\PriceTable;
use App\Models\ProductPrice;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\ProductUnit;
use App\Models\StockDocumentItem;
use App\Models\Supplier;
use App\Models\QtyPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\InventoryCostCloseService;
use App\Services\Inventory\ProductCostHistoryService;
use App\Services\Inventory\ProductionReceiptService;
use App\Services\Inventory\StockTransformService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\PosPricingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class InventoryCostFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_separates_recoverable_vat_and_non_vat_cost(): void
    {
        [$branch, $supplier, $vatProduct, $nonVatProduct] = $this->masters();

        $document = app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => true,
            'prices_include_vat' => true,
            'claim_input_vat' => true,
            'items' => [
                ['product_id' => $vatProduct->id, 'qty' => 10, 'unit_price' => 107],
                ['product_id' => $nonVatProduct->id, 'qty' => 2, 'unit_price' => 50],
            ],
        ]);

        $this->assertSame(1100.0, (float) $document->subtotal_amount);
        $this->assertSame(70.0, (float) $document->vat_amount);
        $this->assertSame(1170.0, (float) $document->total_amount);
        $this->assertSame(100.0, (float) $vatProduct->fresh()->average_cost);
        $this->assertSame(100.0, (float) $vatProduct->fresh()->last_purchase_cost);
        $this->assertSame(50.0, (float) $nonVatProduct->fresh()->average_cost);

        $lines = $document->stockDocument()->first()->items()->orderBy('seq')->get();
        $this->assertSame(100.0, (float) $lines[0]->unit_cost);
        $this->assertSame(70.0, (float) $lines[0]->vat_amount);
        $this->assertSame(0.0, (float) $lines[1]->vat_amount);
    }

    public function test_sale_cogs_uses_true_fifo_lot_cost_not_blended_average_when_lots_differ(): void
    {
        // สินค้าไม่คิด VAT เพื่อให้ unit_cost = unit_price ตรงเป๊ะ ไม่ปนภาษี
        [$branch, $supplier, , $product] = $this->masters();
        $purchases = app(PurchaseService::class);
        // lot A: 10 หน่วย @ 10 บาท
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 10]],
        ]);
        // lot B: 10 หน่วย @ 20 บาท -> average_cost ถัวเฉลี่ยเป็น 15
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 20]],
        ]);
        $this->assertSame(15.0, (float) $product->fresh()->average_cost);

        // ขาย 10 หน่วย -> FIFO ต้องตัด lot A ทั้งหมด (ต้นทุนจริง = 10x10 = 100) ไม่ใช่ 10x15=150 ตาม average
        $sale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 50]],
        ]);
        $saleLine = $sale->stockDocument()->first()->items()->first();
        $this->assertSame(10.0, (float) $saleLine->unit_cost);
        $this->assertSame(100.0, (float) $saleLine->cost_amount);

        // มูลค่าสต๊อกคงเหลือ (lot B ทั้งก้อน 10x20=200) ต้องกระทบยอดกับบัญชีได้พอดี:
        // ยอดซื้อรวม(300) - COGS ที่บันทึกจริง(100) = 200 ตรงกับมูลค่า Lot ที่เหลือจริงเป๊ะ
        $lotValue = (float) DB::table('stock_lots')->where('product_id', $product->id)
            ->selectRaw('sum(remaining_qty * unit_cost) as v')->value('v');
        $this->assertSame(200.0, $lotValue);
        $this->assertSame(300.0 - (float) $saleLine->cost_amount, $lotValue);
    }

    public function test_bundle_promotion_keeps_fifo_cost_and_records_the_true_profit(): void
    {
        [$branch, $supplier, , $product] = $this->masters();
        $user = User::factory()->create(['username' => 'bundle_uat_'.uniqid()]);
        $table = PriceTable::create([
            'code' => 'RETAIL', 'name' => 'ราคาปลีก', 'is_default' => true, 'is_active' => true,
        ]);
        ProductPrice::create([
            'product_id' => $product->id, 'price_table_id' => $table->id,
            'price' => 50, 'is_active' => true,
        ]);
        QtyPromotion::create([
            'code' => 'BUNDLE-UAT', 'name' => '3 ชิ้น 100 บาท', 'promo_type' => 'bundle_price',
            'product_id' => $product->id, 'min_qty' => 3, 'bundle_price' => 100,
            'is_active' => true,
        ]);

        // รับเข้าทุน 20 บาทต่อชิ้น จำนวน 10 ชิ้น
        app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 20]],
        ]);

        // 7 ชิ้น: 2 ชุด x 100 + 1 ชิ้น x 50 = รายได้ 250 บาท
        $saleUnitPrice = 250 / 7;
        $payload = [
            'branch_id' => $branch->id, 'vat_mode' => 'included',
            'items' => [['product_id' => $product->id, 'qty' => 7, 'unit_price' => $saleUnitPrice]],
        ];
        $this->assertSame(250.0, app(PosPricingGuard::class)->validate($payload, $user));

        $sale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'items' => $payload['items'],
        ]);
        $saleLine = $sale->stockDocument()->first()->items()->first();

        $this->assertSame(250.0, (float) $sale->total_amount);
        $this->assertSame(20.0, (float) $saleLine->unit_cost);
        $this->assertSame(140.0, (float) $saleLine->cost_amount);
        $this->assertSame(110.0, (float) $sale->total_amount - (float) $saleLine->cost_amount);
    }

    public function test_sale_cost_is_frozen_when_a_later_purchase_changes_average_cost(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $purchases = app(PurchaseService::class);
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 107]],
        ]);

        $sale = app(CashSaleService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => null,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 160.50]],
        ]);
        $saleLine = $sale->stockDocument()->first()->items()->first();
        $this->assertSame(100.0, (float) $saleLine->unit_cost);
        $this->assertSame(100.0, (float) $saleLine->cost_amount);
        $this->assertSame(10.5, (float) $saleLine->vat_amount);

        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 214]],
        ]);

        $this->assertEqualsWithDelta(152.6316, (float) $product->fresh()->average_cost, 0.0001);
        $this->assertSame(100.0, app(CostingService::class)->cogsForDocument($sale->fresh()));
        $this->assertSame(100.0, (float) StockDocumentItem::find($saleLine->id)->cost_amount);
    }

    public function test_non_recoverable_input_vat_is_included_in_inventory_cost(): void
    {
        [$branch, $supplier, $product] = $this->masters();

        $document = app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 107]],
        ]);

        $this->assertSame(535.0, (float) $document->subtotal_amount);
        $this->assertSame(0.0, (float) $document->vat_amount);
        $this->assertSame(535.0, (float) $document->total_amount);
        $this->assertSame(107.0, (float) $product->fresh()->average_cost);
    }

    public function test_product_master_shows_latest_receipt_and_weighted_cost_by_month(): void
    {
        $this->travelTo('2026-05-10 10:00:00');
        [$branch, $supplier, , $product] = $this->masters();
        $purchases = app(PurchaseService::class);
        $purchases->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => false,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 10]],
        ]);

        app(FifoStockService::class)->issue(
            $product->id,
            (int) $branch->default_warehouse_location_id,
            4,
            null,
            movementDate: '2026-05-20',
        );

        $this->travelTo('2026-06-05 10:00:00');
        $purchases->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => false,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 20]],
        ]);

        $this->travelTo('2026-06-20 10:00:00');
        $purchases->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => false,
            'items' => [['product_id' => $product->id, 'qty' => 6, 'unit_price' => 30]],
        ]);

        $this->travelTo('2026-07-01 10:00:00');
        app(InventoryCostCloseService::class)->close('2026-06');
        $history = app(ProductCostHistoryService::class)
            ->history($product->fresh(), 3)
            ->keyBy('period');
        $june = $history->get('2026-06');
        $may = $history->get('2026-05');

        $this->assertSame('closed', $june['status']);
        $this->assertSame(6.0, $june['opening_qty']);
        $this->assertSame(60.0, $june['opening_value']);
        $this->assertSame(10.0, $june['purchase_qty']);
        $this->assertSame(260.0, $june['purchase_value']);
        $this->assertSame(26.0, $june['purchase_average_cost']);
        $this->assertSame(30.0, $june['last_purchase_cost']);
        $this->assertSame(20.0, $june['period_average_cost']);
        $this->assertSame(16.0, $june['ending_qty']);
        $this->assertSame(320.0, $june['ending_value']);
        $this->assertSame(20.0, $june['ending_average_cost']);
        $this->assertSame(10.0, $may['purchase_average_cost']);
        $this->assertSame(10.0, $may['period_average_cost']);

        $product->refresh();
        $this->assertSame(30.0, (float) $product->last_purchase_cost);
        $this->assertSame('2026-06-20', $product->last_purchase_cost_at->toDateString());
        $this->assertSame(20.0, (float) $product->average_cost);
    }

    public function test_weighed_set_allocates_all_actual_input_cost_to_actual_output_weight(): void
    {
        [$branch, $supplier, $meatA, $meatB] = $this->masters();
        $purchases = app(PurchaseService::class);
        $purchases->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false, 'prices_include_vat' => true, 'claim_input_vat' => true,
            'items' => [
                ['product_id' => $meatA->id, 'qty' => 10, 'unit_price' => 107],
                ['product_id' => $meatB->id, 'qty' => 10, 'unit_price' => 50],
            ],
        ]);
        $output = Product::create([
            'sku_code' => 'SET-1', 'name_th' => 'ชุดหมูกระทะ', 'base_unit_id' => $meatA->base_unit_id,
            'default_price' => 200, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        ProductBarcode::create([
            'product_id' => $output->id, 'barcode' => '800999', 'unit_id' => $output->base_unit_id,
            'unit_factor' => 1, 'price' => 200, 'is_active' => true,
        ]);

        $document = app(StockTransformService::class)->create([
            'branch_id' => $branch->id, 'batch_mode' => true, 'input_weight_qty' => 8,
            'loss_reason_code' => 'trim', 'expected_loss_percent' => 20,
            'loss_note' => 'ตัดแต่งตามมาตรฐาน UAT',
            'raw_items' => [
                ['product_id' => $meatA->id, 'qty' => 3],
                ['product_id' => $meatB->id, 'qty' => 5],
            ],
            'output_items' => [['product_id' => $output->id, 'qty' => 5, 'percent' => 100]],
        ]);

        $batch = $document->productionBatch;
        $this->assertSame(550.0, (float) $batch->total_input_cost);
        $this->assertSame(110.0, (float) $batch->output_unit_cost);
        $this->assertSame(3.0, (float) $batch->loss_weight_qty);
        $this->assertSame('trim', $batch->loss_reason_code);
        $this->assertSame(206.25, (float) $batch->loss_cost_amount);
        $this->assertSame(1.4, (float) $batch->abnormal_loss_qty);
        $this->assertSame(96.25, (float) $batch->abnormal_loss_cost_amount);
        $this->assertSame(62.5, (float) $batch->yield_percent);
        $this->assertSame(110.0, (float) $output->fresh()->average_cost);
        $this->assertSame(200.0, (float) $batch->selling_unit_price);
        $this->assertEqualsWithDelta(76.9159, (float) $batch->estimated_profit_per_unit, 0.0001);
        $outputLotId = $output->stockLots()->value('id');
        $this->assertSame(2, DB::table('stock_lot_lineages')->where('output_lot_id', $outputLotId)->count());
        $this->assertSame(8.0, (float) DB::table('stock_lot_lineages')->where('output_lot_id', $outputLotId)->sum('input_qty'));

        app(StockTransformService::class)->addPackages($batch, [0.5, 1.25]);
        $packages = $batch->packages()->get();
        $this->assertCount(2, $packages);
        $this->assertSame(100.0, (float) $packages[0]->total_price);
        $this->assertMatchesRegularExpression('/^800999[0-9]{7}$/', $packages[0]->barcode);
    }

    public function test_transform_rejects_output_weight_above_input_and_rolls_stock_back(): void
    {
        [$branch, $supplier, $rawProduct] = $this->masters();
        app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id,
            'is_credit' => false,
            'items' => [['product_id' => $rawProduct->id, 'qty' => 10, 'unit_price' => 100]],
        ]);
        $output = Product::create([
            'sku_code' => 'OUTPUT-GUARD', 'name_th' => 'ผลผลิตทดสอบ',
            'base_unit_id' => $rawProduct->base_unit_id, 'average_cost' => 0,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $before = (float) $rawProduct->stockBalances()->sum('on_hand_qty');

        try {
            app(StockTransformService::class)->create([
                'branch_id' => $branch->id, 'batch_mode' => true, 'input_weight_qty' => 5,
                'raw_items' => [['product_id' => $rawProduct->id, 'qty' => 5]],
                'output_items' => [['product_id' => $output->id, 'qty' => 6, 'percent' => 100]],
            ]);
            $this->fail('ระบบต้องปฏิเสธผลผลิตที่หนักกว่าวัตถุดิบ');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ต้องไม่มากกว่า', $e->getMessage());
        }

        $this->assertSame($before, (float) $rawProduct->stockBalances()->sum('on_hand_qty'));
        $this->assertSame(0.0, (float) $output->stockBalances()->sum('on_hand_qty'));
    }

    public function test_expiry_control_uses_fefo_and_blocks_expired_lots(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $product->update([
            'tracks_expiry' => true,
            'expiry_warning_days' => 7,
            'expiry_sale_policy' => 'block',
        ]);
        $locationId = (int) $branch->default_warehouse_location_id;
        $fifo = app(FifoStockService::class);
        $expired = $fifo->receive($product->id, $locationId, 2, null, expiryDate: today()->subDay()->toDateString());
        $later = $fifo->receive($product->id, $locationId, 3, null, expiryDate: today()->addDays(20)->toDateString());
        $earlier = $fifo->receive($product->id, $locationId, 3, null, expiryDate: today()->addDays(5)->toDateString());

        $allocation = $fifo->issue($product->id, $locationId, 2, null);
        $this->assertSame($earlier->id, $allocation->first()['lot']->id);
        $this->assertSame(1.0, (float) $earlier->fresh()->remaining_qty);
        $this->assertSame(3.0, (float) $later->fresh()->remaining_qty);
        $this->assertSame(2.0, (float) $expired->fresh()->remaining_qty);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lot หมดอายุถูกระงับ');
        $fifo->issue($product->id, $locationId, 5, null);
    }

    public function test_quality_hold_blocks_normal_issue_but_allows_damage_clearance(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $fifo = app(FifoStockService::class);
        $locationId = (int) $branch->default_warehouse_location_id;
        $lot = $fifo->receive($product->id, $locationId, 4, null);
        $lot->update(['quality_status' => 'quarantine', 'quality_reason' => 'รอตรวจจุลินทรีย์']);

        try {
            $fifo->issue($product->id, $locationId, 1, null);
            $this->fail('A quarantined lot must not be issued normally.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Lot ถูกพักตรวจ กักกัน หรือเรียกคืน', $exception->getMessage());
        }

        $allocation = $fifo->issue(
            $product->id, $locationId, 1, null,
            allowExpired: true, allowRestricted: true
        );
        $this->assertSame($lot->id, $allocation->first()['lot']->id);
        $this->assertSame(3.0, (float) $lot->fresh()->remaining_qty);
    }

    public function test_purchase_derives_expiry_from_manufacture_date_and_shelf_life(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $product->update(['tracks_expiry' => true, 'shelf_life_days' => 30]);

        $document = app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'is_credit' => false,
            'items' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'unit_price' => 100,
                'lot_number' => 'MFG-001',
                'manufacture_date' => '2026-07-01',
            ]],
        ]);

        $line = $document->stockDocument->items->first();
        $lot = $product->stockLots()->first();
        $this->assertSame('2026-07-31', $line->expire_date->toDateString());
        $this->assertSame('2026-07-01', $line->manufacture_date->toDateString());
        $this->assertSame('2026-07-31', $lot->expiry_date->toDateString());
        $this->assertSame('2026-07-01', $lot->manufacture_date->toDateString());
    }

    public function test_production_receipt_issues_recipe_inputs_and_receives_output_at_actual_fifo_cost(): void
    {
        [$branch, , $rawMaterial] = $this->masters();
        DocumentType::firstOrCreate(
            ['code' => 'PRODUCTION_RECEIPT'],
            ['name_th' => 'ใบรับจากการผลิต', 'affects_stock' => true],
        );
        $finishedProduct = Product::create([
            'sku_code' => 'FG-001',
            'name_th' => 'สินค้าผลิตสำเร็จ',
            'base_unit_id' => $rawMaterial->base_unit_id,
            'average_cost' => 0,
            'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $recipe = ProductionRecipe::create([
            'code' => 'BOM-001',
            'name' => 'สูตรทดสอบต้นทุนจริง',
            'finished_product_id' => $finishedProduct->id,
            'output_qty' => 2,
            'is_active' => true,
        ]);
        $recipe->items()->create([
            'product_id' => $rawMaterial->id,
            'qty' => 3,
        ]);
        $order = ProductionOrder::create([
            'doc_no' => 'MO-001',
            'doc_date' => now()->toDateString(),
            'production_recipe_id' => $recipe->id,
            'finished_product_id' => $finishedProduct->id,
            'branch_id' => $branch->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'planned_qty' => 2,
            'produced_qty' => 0,
            'status' => 'planned',
        ]);

        $rawLot = app(FifoStockService::class)->receive(
            $rawMaterial->id,
            (int) $branch->default_warehouse_location_id,
            10,
            null,
            unitCost: 12.34567891,
        );
        $document = app(ProductionReceiptService::class)->receive($order, 2);
        $outputLot = $finishedProduct->stockLots()->firstOrFail();

        $this->assertSame(7.0, (float) $rawLot->fresh()->remaining_qty);
        $this->assertSame(2.0, (float) $outputLot->remaining_qty);
        $this->assertSame(18.51851837, (float) $outputLot->unit_cost);
        $this->assertSame(37.03703673, (float) $document->total_amount);
        $this->assertSame(3.0, (float) DB::table('stock_lot_lineages')->where('output_lot_id', $outputLot->id)->sum('input_qty'));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $document->id, 'movement_type' => 'transform_out']);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $document->id, 'movement_type' => 'transform_in']);
    }

    /** @return array{Branch,Supplier,Product,Product} */
    private function masters(): array
    {
        DocumentType::create(['code' => 'PURCHASE', 'name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFORM'], ['name_th' => 'ใบแปรรูป']);
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-HQ', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-1', 'name_th' => 'ผู้จำหน่ายทดสอบ', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $vatProduct = Product::create([
            'sku_code' => 'VAT-1', 'name_th' => 'สินค้า VAT', 'base_unit_id' => $unit->id,
            'default_price' => 160.50, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $nonVatProduct = Product::create([
            'sku_code' => 'NOVAT-1', 'name_th' => 'สินค้าไม่คิด VAT', 'base_unit_id' => $unit->id,
            'default_price' => 50, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);

        return [$branch->fresh(), $supplier, $vatProduct, $nonVatProduct];
    }
}
