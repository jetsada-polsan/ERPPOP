<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductPickerUnitRowsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private WarehouseLocation $location;

    private Customer $customer;

    private User $user;

    private ProductUnit $bagUnit;

    private ProductUnit $packUnit;

    private ProductUnit $crateUnit;

    private PriceTable $defaultPriceTable;

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(
            ['code' => DocumentType::BOOKING],
            ['name_th' => 'ใบจอง', 'stock_effect' => 'none']
        );
        DocumentType::firstOrCreate(
            ['code' => DocumentType::CREDIT_SALE],
            ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']
        );

        $this->branch = Branch::create([
            'code' => 'B01',
            'name_th' => 'สาขาหลัก',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $this->branch->id,
            'code' => 'WH01',
            'name' => 'คลังหลัก',
        ]);
        $this->location = WarehouseLocation::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'MAIN',
            'name' => 'พื้นที่หลัก',
        ]);
        $this->branch->update(['default_warehouse_location_id' => $this->location->id]);

        $this->customer = Customer::create([
            'code' => 'CUS01',
            'name_th' => 'ลูกค้าทดสอบ',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $permission = Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการงานขาย']);
        $role = Role::firstOrCreate(['code' => 'SALES_MGR'], ['name' => 'ผู้จัดการฝ่ายขาย']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $this->user = User::factory()->create([
            'username' => 'sales_test',
            'branch_id' => $this->branch->id,
        ]);
        $this->user->roles()->syncWithoutDetaching([$role->id]);

        $this->defaultPriceTable = PriceTable::firstOrCreate(
            ['is_default' => true],
            ['code' => 'STD', 'name' => 'ราคามาตรฐาน', 'is_active' => true]
        );

        $this->bagUnit = ProductUnit::firstOrCreate(['code' => 'BAG'], ['name' => 'ถุง', 'qty_per_base_unit' => 1]);
        $this->packUnit = ProductUnit::firstOrCreate(['code' => 'PACK6'], ['name' => 'ยกหิ้ว', 'qty_per_base_unit' => 6]);
        $this->crateUnit = ProductUnit::firstOrCreate(['code' => 'CRATE18'], ['name' => 'ลัง', 'qty_per_base_unit' => 18]);
    }

    private function createProductWithUnits(string $sku, float $defaultPrice = 30.50): Product
    {
        $product = Product::create([
            'sku_code' => $sku,
            'name_th' => 'ขนมปีบทอง '.$sku,
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => $defaultPrice,
            'is_active' => true,
        ]);

        // Base unit barcode
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-BASE-'.$sku,
            'unit_id' => $this->bagUnit->id,
            'unit_factor' => 1,
            'price' => $defaultPrice,
            'is_active' => true,
        ]);

        // Pack 6 barcode with explicit price
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-PACK-'.$sku,
            'unit_id' => $this->packUnit->id,
            'unit_factor' => 6,
            'price' => 180.00,
            'is_active' => true,
        ]);

        // Crate 18 barcode with explicit price
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-CRATE-'.$sku,
            'unit_id' => $this->crateUnit->id,
            'unit_factor' => 18,
            'price' => 549.00,
            'is_active' => true,
        ]);

        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $this->location->id,
            'on_hand_qty' => 1000,
            'reserved_qty' => 0,
        ]);

        return $product;
    }

    /**
     * 1. API SearchController::products ส่ง units ครบถ้วน:
     *    unit_id, unit_name, factor (string), barcode, price (ราคาของหน่วยนั้น)
     *    และฟิลด์เดิมทุกตัวคงอยู่ครบถ้วน
     */
    public function test_api_products_returns_units_with_price_and_string_factor(): void
    {
        $product = $this->createProductWithUnits('PIP-01', 30.50);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'PIP-01']));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        // ตรวจฟิลด์เดิมครบถ้วน
        $this->assertSame($product->id, $row['id']);
        $this->assertSame('PIP-01', $row['sku_code']);
        $this->assertSame('ขนมปีบทอง PIP-01', $row['name_th']);
        $this->assertSame(30.5, (float) $row['default_price']);
        $this->assertSame($this->bagUnit->id, $row['base_unit_id']);
        $this->assertSame('ถุง', $row['base_unit_name']);
        $this->assertArrayHasKey('barcode', $row);
        $this->assertArrayHasKey('scale_plu', $row);
        $this->assertArrayHasKey('is_scale', $row);
        $this->assertArrayHasKey('on_hand_qty', $row);
        $this->assertArrayHasKey('reserved_qty', $row);
        $this->assertArrayHasKey('available_qty', $row);
        $this->assertArrayHasKey('lots', $row);

        // ตรวจ array units
        $this->assertIsArray($row['units']);
        $this->assertCount(3, $row['units']);

        // 1. หน่วยฐาน (ถุง)
        $baseUnit = collect($row['units'])->firstWhere('unit_id', $this->bagUnit->id);
        $this->assertNotNull($baseUnit);
        $this->assertSame('ถุง', $baseUnit['unit_name']);
        $this->assertSame('1', $baseUnit['factor']);
        $this->assertSame('BAR-BASE-PIP-01', $baseUnit['barcode']);
        $this->assertSame(30.5, (float) $baseUnit['price']);
        $this->assertTrue($baseUnit['is_base']);

        // 2. ยกหิ้ว (factor 6)
        $packUnit = collect($row['units'])->firstWhere('unit_id', $this->packUnit->id);
        $this->assertNotNull($packUnit);
        $this->assertSame('ยกหิ้ว', $packUnit['unit_name']);
        $this->assertSame('6', $packUnit['factor']);
        $this->assertSame('BAR-PACK-PIP-01', $packUnit['barcode']);
        $this->assertSame(180.0, (float) $packUnit['price']);
        $this->assertFalse($packUnit['is_base']);

        // 3. ลัง (factor 18)
        $crateUnit = collect($row['units'])->firstWhere('unit_id', $this->crateUnit->id);
        $this->assertNotNull($crateUnit);
        $this->assertSame('ลัง', $crateUnit['unit_name']);
        $this->assertSame('18', $crateUnit['factor']);
        $this->assertSame('BAR-CRATE-PIP-01', $crateUnit['barcode']);
        $this->assertSame(549.0, (float) $crateUnit['price']);
        $this->assertFalse($crateUnit['is_base']);
    }

    /**
     * 2. สินค้าที่ไม่มีหน่วยใหญ่: ส่งเฉพาะหน่วยหลัก ไม่สร้างแถวหน่วยใหญ่
     */
    public function test_product_without_larger_unit_returns_only_base_unit(): void
    {
        $product = Product::create([
            'sku_code' => 'SINGLE-01',
            'name_th' => 'สินค้าชิ้นเดียว SINGLE-01',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 25.00,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-SINGLE-01',
            'unit_id' => $this->bagUnit->id,
            'unit_factor' => 1,
            'price' => 25.00,
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'SINGLE-01']));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        $largerUnits = collect($row['units'])->filter(fn ($u) => ! $u['is_base'] && (int) $u['factor'] > 1);
        $this->assertEmpty($largerUnits);
        $this->assertCount(1, $row['units']);
        $this->assertTrue($row['units'][0]['is_base']);
    }

    /**
     * 3. หน่วยใหญ่ที่ไม่มีราคาในระบบ: คืนค่า price เป็น null (ห้ามคิดสูตรราคาใหม่)
     */
    public function test_larger_unit_without_price_returns_null(): void
    {
        $product = Product::create([
            'sku_code' => 'NOPRICE-01',
            'name_th' => 'สินค้าไม่มีราคาหน่วย NOPRICE-01',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 50.00,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-NOPRICE-BASE',
            'unit_id' => $this->bagUnit->id,
            'unit_factor' => 1,
            'price' => 50.00,
            'is_active' => true,
        ]);
        // Crate with price = null
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-NOPRICE-CRATE',
            'unit_id' => $this->crateUnit->id,
            'unit_factor' => 18,
            'price' => null,
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'NOPRICE-01']));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $product->id);
        $crate = collect($row['units'])->firstWhere('unit_id', $this->crateUnit->id);
        $this->assertNotNull($crate);
        $this->assertNull($crate['price'], 'เมื่อไม่มีราคาหน่วยต้องคืน null ห้ามคิดสูตรราคาขึ้นมาเอง');
    }

    /**
     * 4. หน่วยใหญ่ที่มีราคาจากตารางราคา (ProductPrice) ส่งราคาถูกต้อง
     */
    public function test_larger_unit_gets_price_from_product_price_table_if_barcode_price_null(): void
    {
        $product = Product::create([
            'sku_code' => 'TABLEPRICE-01',
            'name_th' => 'สินค้ามีราคาตาราง TABLEPRICE-01',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 20.00,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-TP-CRATE',
            'unit_id' => $this->crateUnit->id,
            'unit_factor' => 18,
            'price' => null, // ไม่มีราคาใน barcode
            'is_active' => true,
        ]);
        // กำหนดราคาใน product_prices
        ProductPrice::create([
            'product_id' => $product->id,
            'price_table_id' => $this->defaultPriceTable->id,
            'unit_id' => $this->crateUnit->id,
            'price' => 350.00,
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'TABLEPRICE-01']));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $product->id);
        $crate = collect($row['units'])->firstWhere('unit_id', $this->crateUnit->id);
        $this->assertNotNull($crate);
        $this->assertSame(350.0, (float) $crate['price']);
    }

    /**
     * 5. ยิงบาร์โค้ดหน่วยใหญ่แล้วค้นหา: API คืน matched_unit_id ตรงกับหน่วยนั้น
     */
    public function test_barcode_search_matches_larger_unit(): void
    {
        $product = $this->createProductWithUnits('SCAN-01', 40.00);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'BAR-PACK-SCAN-01']));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);

        $row = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame($this->packUnit->id, $row['matched_unit_id']);
    }

    /**
     * 6. จำนวน query ไม่เพิ่มตามจำนวนสินค้า (ป้องกัน N+1 queries)
     */
    public function test_query_count_does_not_scale_with_product_count(): void
    {
        // Warm-up Laravel auth/session/route boot
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'WARMUP']));

        // สร้าง 1 สินค้า และวัด query
        $p1 = $this->createProductWithUnits('QCOUNT-01', 30.00);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'QCOUNT-01']));
        $queriesForOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        // สร้างเพิ่มอีก 4 สินค้า (รวมเป็น 5)
        for ($i = 2; $i <= 5; $i++) {
            $this->createProductWithUnits("QCOUNT-0{$i}", 30.00);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'QCOUNT']));
        $queriesForFive = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertCount(5, $response->json());

        // จำนวน query ต้องไม่เพิ่มขึ้นตามจำนวนสินค้า (ห้ามมี query per product)
        $this->assertEquals(
            $queriesForOne,
            $queriesForFive,
            "Query count increased from {$queriesForOne} to {$queriesForFive} (potential N+1 query issue)"
        );
    }

    /**
     * 7. บันทึกใบจองด้วยหน่วยใหญ่ที่เลือกจาก picker:
     *    ได้ qty ฐาน และยอดเงินถูกต้องถึงสตางค์
     */
    public function test_booking_saved_with_larger_unit_selected_has_correct_base_qty_and_total_to_satang(): void
    {
        // ปีบทอง ถุงละ 30.50, ลัง 18 ถุง = 549.00 บาท
        $product = $this->createProductWithUnits('BOOKING-UNIT-1', 30.50);

        // จอง 2 ลัง ที่ราคา 549.00 บาทต่อลัง
        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'unit_id' => $this->crateUnit->id,
                        'unit_qty' => 2,
                        'unit_price' => 549.00,
                        'unit_factor' => 18,
                        'qty' => 36,
                    ],
                ],
            ]);

        $response->assertRedirect();

        $booking = SaleBooking::latest('id')->first();
        $this->assertNotNull($booking);

        $doc = $booking->document;
        $this->assertSame($this->customer->id, $doc->customer_id);
        // ยอดรวม = 2 * 549.00 = 1,098.00 บาท
        $this->assertSame('1098.00', number_format((float) $doc->total_amount, 2, '.', ''));

        $item = $doc->stockDocument->items->first();
        $this->assertNotNull($item);
        $this->assertSame($this->crateUnit->id, $item->unit_id);
        $this->assertSame('2.0000', number_format((float) $item->unit_qty, 4, '.', ''));
        $this->assertSame('18.0000', number_format((float) $item->unit_factor, 4, '.', ''));
        $this->assertSame('36.0000', number_format((float) $item->qty, 4, '.', ''));
        $this->assertSame('30.5000', number_format((float) $item->unit_price, 4, '.', ''));
        $lineTotal = round((float) $item->qty * (float) $item->unit_price, 2);
        $this->assertSame('1098.00', number_format($lineTotal, 2, '.', ''));

        // สต็อกถูกจอง 36 หน่วยฐาน
        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $this->location->id)
            ->first();
        $this->assertSame(36.0, (float) $balance->reserved_qty);
    }

    /**
     * 8. ราคาแถวหน่วยหลักต้องใช้ default_price เสมอ ไม่ใช้ราคาบาร์โค้ด
     * (ตามผลตรวจงาน 37: default_price 20.00, บาร์โค้ดหน่วยหลักมี price 25.00 -> ต้องได้ 20.00)
     */
    public function test_base_unit_price_always_uses_default_price_ignoring_barcode_price(): void
    {
        $product = Product::create([
            'sku_code' => 'DIFFPRICE-01',
            'name_th' => 'สินค้าทดสอบราคาบาร์โค้ดไม่ตรง DIFFPRICE-01',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 20.00,
            'is_active' => true,
        ]);
        // บาร์โค้ดหน่วยหลักมี price 25.00 (ต่างจาก default_price 20.00)
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-DIFF-BASE',
            'unit_id' => $this->bagUnit->id,
            'unit_factor' => 1,
            'price' => 25.00,
            'is_active' => true,
        ]);
        // บาร์โค้ดหน่วยใหญ่มี price 300.00
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-DIFF-CRATE',
            'unit_id' => $this->crateUnit->id,
            'unit_factor' => 18,
            'price' => 300.00,
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'DIFFPRICE-01']));

        $response->assertOk();
        $row = collect($response->json())->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        $baseUnit = collect($row['units'])->firstWhere('is_base', true);
        $this->assertNotNull($baseUnit);
        $this->assertSame(20.0, (float) $baseUnit['price'], 'แถวหน่วยหลักต้องได้ default_price 20.00 ห้ามใช้ราคาบาร์โค้ด 25.00');

        $crateUnit = collect($row['units'])->firstWhere('unit_id', $this->crateUnit->id);
        $this->assertNotNull($crateUnit);
        $this->assertSame(300.0, (float) $crateUnit['price'], 'หน่วยใหญ่ใช้ราคาบาร์โค้ดของหน่วยนั้น 300.00');
    }
}
