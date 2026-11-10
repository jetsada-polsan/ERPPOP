<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockTransformService;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockTransformFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private WarehouseLocation $location;
    private Supplier $supplier;
    private ProductUnit $kgUnit;
    private ProductUnit $boxUnit;
    private ProductUnit $pieceUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user);

        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFORM'], ['name_th' => 'ใบแปรรูป']);

        $this->branch = Branch::create(['code' => 'B01', 'name_th' => 'สาขาหลัก', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH01', 'name' => 'คลังสินค้า']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC01', 'name' => 'ห้องเย็น']);
        $this->branch->update(['default_warehouse_location_id' => $this->location->id]);

        $this->supplier = Supplier::create(['code' => 'SUP01', 'name_th' => 'ผู้จำหน่ายเนื้อ', 'is_active' => true]);

        $this->kgUnit = ProductUnit::create(['code' => 'KG', 'name' => 'กก.', 'qty_per_base_unit' => 1]);
        $this->boxUnit = ProductUnit::create(['code' => 'BOX', 'name' => 'กล่อง', 'qty_per_base_unit' => 1]);
        $this->pieceUnit = ProductUnit::create(['code' => 'PCS', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
    }

    /**
     * (1.1) วัตถุดิบที่เป็น กก. (กก., กิโลกรัม, kg) รวมน้ำหนักให้อัตโนมัติ (Auto-sum weight)
     */
    public function test_kg_raw_materials_auto_sum_weight_when_input_weight_qty_omitted(): void
    {
        $rawA = Product::create([
            'sku_code' => 'RAW-KG-A', 'name_th' => 'เนื้อหมูสันนอก (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 120,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $rawB = Product::create([
            'sku_code' => 'RAW-KG-B', 'name_th' => 'มันหมูแข็ง (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 60,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $output = Product::create([
            'sku_code' => 'OUT-KG', 'name_th' => 'หมูบดแปรรูป (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 0,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);

        app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'is_credit' => false,
            'items' => [
                ['product_id' => $rawA->id, 'qty' => 10, 'unit_price' => 120],
                ['product_id' => $rawB->id, 'qty' => 10, 'unit_price' => 60],
            ],
        ]);

        // แปรรูปโดยใช้วัตถุดิบ A 4 กก. + วัตถุดิบ B 6 กก. = รวม 10 กก. โดยไม่ส่ง input_weight_qty
        $service = app(StockTransformService::class);
        $document = $service->create([
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'batch_mode' => true,
            'raw_items' => [
                ['product_id' => $rawA->id, 'qty' => 4], // ทุน 4 x 120 = 480
                ['product_id' => $rawB->id, 'qty' => 6], // ทุน 6 x 60 = 360 => รวม 840
            ],
            'output_items' => [
                ['product_id' => $output->id, 'qty' => 8, 'percent' => 100],
            ],
        ]);

        $batch = $document->productionBatch;
        $this->assertNotNull($batch);
        $this->assertEquals(10.0, (float) $batch->input_weight_qty);
        $this->assertEquals(8.0, (float) $batch->output_weight_qty);
        $this->assertEquals(2.0, (float) $batch->loss_weight_qty);
        $this->assertEquals(80.0, (float) $batch->yield_percent);
        $this->assertEquals(840.0, (float) $batch->total_input_cost);
        $this->assertEquals(105.0, (float) $batch->output_unit_cost);
    }

    /**
     * (1.2) วัตถุดิบที่เป็นกล่อง/ชิ้น ถ้าไม่ใส่น้ำหนักชั่งจริง (input_weight_qty) ต้องถูกปฏิเสธด้วยข้อความภาษาไทย
     */
    public function test_box_piece_raw_materials_without_input_weight_qty_are_refused_with_thai_error(): void
    {
        $rawBox = Product::create([
            'sku_code' => 'RAW-BOX', 'name_th' => 'เนื้อนำเข้าบรรจุกล่อง',
            'base_unit_id' => $this->boxUnit->id, 'average_cost' => 500,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $rawPiece = Product::create([
            'sku_code' => 'RAW-PCS', 'name_th' => 'ซี่โครงบรรจุชิ้น',
            'base_unit_id' => $this->pieceUnit->id, 'average_cost' => 300,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $output = Product::create([
            'sku_code' => 'OUT-SLICED', 'name_th' => 'เนื้อสไลซ์ (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 0,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);

        app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'is_credit' => false,
            'items' => [
                ['product_id' => $rawBox->id, 'qty' => 5, 'unit_price' => 500],
                ['product_id' => $rawPiece->id, 'qty' => 5, 'unit_price' => 300],
            ],
        ]);

        // 1. ทดสอบระดับ Service สำหรับกล่อง
        try {
            app(StockTransformService::class)->create([
                'branch_id' => $this->branch->id,
                'warehouse_location_id' => $this->location->id,
                'batch_mode' => true,
                'raw_items' => [['product_id' => $rawBox->id, 'qty' => 2]],
                'output_items' => [['product_id' => $output->id, 'qty' => 15, 'percent' => 100]],
            ]);
            $this->fail('ต้องปฏิเสธการแปลงวัตถุดิบกล่องที่ไม่มี input_weight_qty');
        } catch (RuntimeException $e) {
            $this->assertSame('วัตถุดิบไม่ได้นับเป็น กก. กรุณาชั่งแล้วใส่น้ำหนักวัตถุดิบรวมจริง (กก.)', $e->getMessage());
        }

        // 2. ทดสอบระดับ Service สำหรับชิ้น
        try {
            app(StockTransformService::class)->create([
                'branch_id' => $this->branch->id,
                'warehouse_location_id' => $this->location->id,
                'batch_mode' => true,
                'raw_items' => [['product_id' => $rawPiece->id, 'qty' => 2]],
                'output_items' => [['product_id' => $output->id, 'qty' => 5, 'percent' => 100]],
            ]);
            $this->fail('ต้องปฏิเสธการแปลงวัตถุดิบชิ้นที่ไม่มี input_weight_qty');
        } catch (RuntimeException $e) {
            $this->assertSame('วัตถุดิบไม่ได้นับเป็น กก. กรุณาชั่งแล้วใส่น้ำหนักวัตถุดิบรวมจริง (กก.)', $e->getMessage());
        }

        // 3. ทดสอบระดับ Controller HTTP POST
        $response = $this->post(route('stock-transforms.store'), [
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'batch_mode' => 1,
            'raw_items' => [['product_id' => $rawBox->id, 'qty' => 2]],
            'output_items' => [['product_id' => $output->id, 'qty' => 15, 'percent' => 100]],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['raw_items' => 'วัตถุดิบไม่ได้นับเป็น กก. กรุณาชั่งแล้วใส่น้ำหนักวัตถุดิบรวมจริง (กก.)']);
    }

    /**
     * (1.3) เมื่อระบุน้ำหนักชั่งจริงเป็น กก. ค่า Yield, ส่วนสูญเสีย (Loss) และต้นทุนผลผลิตต่อหน่วย ถูกต้องระดับสตางค์
     */
    public function test_with_weighed_kg_yield_loss_and_output_unit_cost_are_correct_to_the_satang(): void
    {
        $rawMeat = Product::create([
            'sku_code' => 'RAW-PREMIUM-PORK', 'name_th' => 'หมูสันนอกตัดแต่งเกรดเอ (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 120.00,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $output = Product::create([
            'sku_code' => 'OUT-STEAK', 'name_th' => 'เนื้อสเต็กพรีเมียม (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'default_price' => 250.00, 'average_cost' => 0,
            'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        ProductBarcode::create([
            'product_id' => $output->id, 'barcode' => '801234', 'unit_id' => $output->base_unit_id,
            'unit_factor' => 1, 'price' => 250.00, 'is_active' => true,
        ]);

        // ซื้อ 12.5000 กก. ต้นทุนรวม 12.5000 x 120.00 = 1,500.00 บาท
        app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'is_credit' => false,
            'items' => [
                ['product_id' => $rawMeat->id, 'qty' => 12.5000, 'unit_price' => 120.00],
            ],
        ]);

        // นำมาชั่งน้ำหนักจริงก่อนตัดแต่งได้ 12.5000 กก.
        // ผลผลิตที่ได้หลังตัดแต่งคือ 11.2500 กก.
        // เกณฑ์สูญเสียปกติ 5% (= 0.6250 กก.)
        $document = app(StockTransformService::class)->create([
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'batch_mode' => true,
            'input_weight_qty' => 12.5000,
            'loss_reason_code' => 'trim',
            'expected_loss_percent' => 5.0,
            'loss_note' => 'ตัดแต่งเอ็นและมันส่วนเกิน',
            'raw_items' => [['product_id' => $rawMeat->id, 'qty' => 12.5000]],
            'output_items' => [['product_id' => $output->id, 'qty' => 11.2500, 'percent' => 100]],
        ]);

        $batch = $document->productionBatch;
        $this->assertNotNull($batch);

        // 1. ต้นทุนรวมวัตถุดิบ: 1,500.00 บาท
        $this->assertEquals(1500.00, (float) $batch->total_input_cost);

        // 2. น้ำหนักและส่วนสูญเสีย:
        // Input: 12.5000 กก., Output: 11.2500 กก. -> สูญเสีย 1.2500 กก.
        $this->assertEquals(12.5, (float) $batch->input_weight_qty);
        $this->assertEquals(11.25, (float) $batch->output_weight_qty);
        $this->assertEquals(1.25, (float) $batch->loss_weight_qty);

        // 3. Yield % = (11.25 / 12.5) * 100 = 90.00%
        $this->assertEquals(90.0, (float) $batch->yield_percent);

        // 4. มูลค่าสูญเสีย = (1,500.00 * 1.2500) / 12.5000 = 150.00 บาท
        $this->assertEquals(150.00, (float) $batch->loss_cost_amount);

        // 5. สูญเสียผิดปกติ:
        // เกณฑ์ปกติ 5% ของ 12.5 กก. = 0.625 กก. -> สูญเสียจริง 1.25 กก. -> ผิดปกติ 0.625 กก.
        $this->assertEquals(0.625, (float) $batch->abnormal_loss_qty);
        // มูลค่าสูญเสียผิดปกติ = (1,500 * 0.625) / 12.5 = 75.00 บาท
        $this->assertEquals(75.00, (float) $batch->abnormal_loss_cost_amount);

        // 6. ต้นทุนผลผลิตต่อหน่วย (Output Unit Cost):
        // 1,500.00 / 11.2500 = 133.3333... บาท / กก. -> ถูกต้องถึงสตางค์ 133.33 บาท
        $this->assertEqualsWithDelta(133.33, (float) $batch->output_unit_cost, 0.01);
        $this->assertEqualsWithDelta(133.33, (float) $output->fresh()->average_cost, 0.01);

        // 7. กำไรขั้นต้นประมาณการต่อหน่วย:
        // ราคาขาย 250.00 - ทุน 133.33 = 116.67 บาท / กก.
        $this->assertEqualsWithDelta(116.67, (float) $batch->estimated_profit_per_unit, 0.01);
    }

    /**
     * (1.4) หน้าจอ Stock Transform (resources/views/stock-transforms) เปิดและแสดงผลได้ครบถ้วน
     */
    public function test_stock_transform_screen_renders_properly(): void
    {
        $raw = Product::create([
            'sku_code' => 'RAW-UI', 'name_th' => 'หมูดำ (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 100,
            'is_vat' => false, 'is_active' => true,
        ]);
        $output = Product::create([
            'sku_code' => 'OUT-UI', 'name_th' => 'หมูดำสไลซ์ (กก.)',
            'base_unit_id' => $this->kgUnit->id, 'average_cost' => 0,
            'is_vat' => false, 'is_active' => true,
        ]);

        app(PurchaseService::class)->create([
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'is_credit' => false,
            'items' => [['product_id' => $raw->id, 'qty' => 10, 'unit_price' => 100]],
        ]);

        // 1. หน้า index
        $resIndex = $this->get(route('stock-transforms.index'));
        $resIndex->assertOk();
        $resIndex->assertSee('แปรรูปสินค้า');
        $resIndex->assertSee('บันทึกการแปรรูป');

        // 2. บันทึกแปรรูปผ่าน route
        $resStore = $this->post(route('stock-transforms.store'), [
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $this->location->id,
            'batch_mode' => 1,
            'input_weight_qty' => 5,
            'raw_items' => [['product_id' => $raw->id, 'qty' => 5]],
            'output_items' => [['product_id' => $output->id, 'qty' => 4, 'percent' => 100]],
        ]);
        $resStore->assertRedirect();
        $document = Document::whereHas('documentType', fn ($q) => $q->where('code', 'STOCK_TRANSFORM'))->firstOrFail();

        // 3. หน้า show
        $resShow = $this->get(route('stock-transforms.show', $document));
        $resShow->assertOk();
        $resShow->assertSee($document->doc_number);
        $resShow->assertSee('น้ำหนักวัตถุดิบ');
        $resShow->assertSee('ผลผลิตจริง');
        $resShow->assertSee('Yield');
    }
}
