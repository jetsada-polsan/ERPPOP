<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Sales\CashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ขายแล้วต้องมีต้นทุนเสมอ — ไม่ว่าจะมี lot ให้ตัดหรือไม่
 *
 * ถ้าไม่มี lot ระบบต้องถอยไปใช้ต้นทุนถัวเฉลี่ยของสินค้า ไม่ใช่บันทึกศูนย์
 * ต้นทุนศูนย์ทำให้กำไรขั้นต้นเท่ากับยอดขายทั้งก้อน ซึ่งดูเหมือนกำไรดีมาก
 * จนกว่าจะมีคนปิดงบแล้วพบว่าสินค้าคงเหลือกับต้นทุนขายไม่ตรงกันเลย
 */
class SaleCostFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_without_lots_still_records_the_average_cost(): void
    {
        [$branch, $product] = $this->fixtures(averageCost: 40);

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
            'allow_negative_stock' => true,
        ]);

        $cost = (float) DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.document_id', $document->id)
            ->sum('sdi.cost_amount');

        $this->assertSame(120.0, $cost, 'ไม่มี lot ต้องใช้ต้นทุนถัวเฉลี่ย 40 × 3 ไม่ใช่ศูนย์');
    }

    public function test_a_sale_with_lots_costs_from_the_lot_not_the_average(): void
    {
        [$branch, $product, $location] = $this->fixtures(averageCost: 40);
        // FIFO เช็คยอดคงเหลือก่อนถึงจะไปหา lot ขาดอันใดอันหนึ่งก็ตัดไม่ได้
        DB::table('stock_balances')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 100, 'reserved_qty' => 0,
        ]);
        DB::table('stock_lots')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-1', 'received_date' => now()->toDateString(),
            'initial_qty' => 100, 'remaining_qty' => 100, 'unit_cost' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);

        $cost = (float) DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.document_id', $document->id)
            ->sum('sdi.cost_amount');

        $this->assertSame(75.0, $cost, 'มี lot ต้องคิดจากต้นทุน lot จริง 25 × 3');
    }

    public function test_a_balance_larger_than_its_lots_is_costed_at_nothing(): void
    {
        [$branch, $product, $location] = $this->fixtures(averageCost: 40);
        // ยอดคงเหลือ 100 แต่มี lot รองรับแค่ 10 — ส่วนต่าง 90 ไม่มีต้นทุนอยู่จริง
        DB::table('stock_balances')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'on_hand_qty' => 100, 'reserved_qty' => 0,
        ]);
        DB::table('stock_lots')->insert([
            'product_id' => $product->id, 'warehouse_location_id' => $location->id,
            'lot_number' => 'LOT-REAL', 'received_date' => now()->toDateString(),
            'initial_qty' => 10, 'remaining_qty' => 10, 'unit_cost' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);

        // ระบบเติม lot ยกมาต้นทุนศูนย์ให้ส่วนต่าง ลงวันที่ 1900 ซึ่งเก่าที่สุด จึงถูกตัดก่อน lot จริง
        $opening = DB::table('stock_lots')->where('lot_number', 'like', 'OPENING-%')->first();
        $this->assertNotNull($opening, 'ยอดคงเหลือที่ไม่มี lot รองรับ ระบบจะสร้าง lot ยกมาให้เอง');
        $this->assertSame(0.0, (float) $opening->unit_cost);
        $this->assertSame('1900-01-01', substr((string) $opening->received_date, 0, 10));

        $cost = (float) DB::table('stock_document_items')->sum('cost_amount');
        $this->assertSame(0.0, $cost,
            'ขายจาก lot ยกมาต้นทุนศูนย์ ต้นทุนขายจึงเป็นศูนย์ — ตั้งยอดคงเหลือโดยไม่มี lot คือกับดักนี้');
    }

    /** @return array{0:Branch, 1:Product, 2:WarehouseLocation} */
    private function fixtures(float $averageCost): array
    {
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด']);
        $branch = Branch::create(['code' => 'CST', 'name_th' => 'สาขาทดสอบต้นทุน', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-CST', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-CST', 'name' => 'พื้นที่']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA-CST'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'CST-1', 'name_th' => 'สินค้าทดสอบต้นทุน', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => $averageCost, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        return [$branch, $product, $location];
    }
}
