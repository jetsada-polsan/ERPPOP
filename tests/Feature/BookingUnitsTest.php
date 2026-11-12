<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Sales\CreditSaleService;
use App\Support\DecimalMath;
use App\Support\UnitQty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingUnitsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private WarehouseLocation $location;

    private Customer $customer;

    private User $user;

    private ProductUnit $bagUnit;

    private ProductUnit $crateUnit;

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

        $this->bagUnit = ProductUnit::firstOrCreate(['code' => 'BAG'], ['name' => 'ถุง', 'qty_per_base_unit' => 1]);
        $this->crateUnit = ProductUnit::firstOrCreate(['code' => 'CRATE'], ['name' => 'ลังx18', 'qty_per_base_unit' => 18]);
    }

    private function createProductWithCrate(string $sku = 'SKU01', float $price = 30.50, int $factor = 18, ?string $barcode = null): Product
    {
        $product = Product::create([
            'sku_code' => $sku,
            'name_th' => 'สินค้า '.$sku,
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => $price,
            'is_active' => true,
        ]);

        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BASE-'.$sku,
            'unit_id' => $this->bagUnit->id,
            'unit_factor' => 1,
            'is_active' => true,
        ]);

        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => $barcode ?: 'BAR-CRATE-'.$sku,
            'unit_id' => $this->crateUnit->id,
            'unit_factor' => $factor,
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
     * 1. จอง 2 ลัง (factor 18, ราคาถุง 30.50) -> qty 36, unit_price 30.5000, ยอดบรรทัด 1,098.00 เท่ากับจอง 36 ถุง ถึงสตางค์ และกันสต็อก 36
     */
    public function test_booking_2_crates_factor_18_bag_price_30_50_sets_qty_36_unit_price_30_50_total_1098_and_reserves_36(): void
    {
        $product = $this->createProductWithCrate('SKU-CRATE-1', 30.50, 18);

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
                        'unit_price' => 549.00, // 30.50 * 18 = 549.00
                    ],
                ],
            ]);

        $response->assertRedirect();

        $booking = SaleBooking::latest('id')->firstOrFail();
        $item = $booking->document->stockDocument->items->sole();

        $this->assertEquals($this->crateUnit->id, $item->unit_id);
        $this->assertEquals(2.0, (float) $item->unit_qty);
        $this->assertEquals(18.0, (float) $item->unit_factor);
        $this->assertEquals(36.0, (float) $item->qty, 'qty ต้องเก็บเป็นหน่วยหลัก 2 * 18 = 36');
        $this->assertEquals(30.50, (float) $item->unit_price, 'unit_price ต้องเก็บเป็นราคาต่อหน่วยหลัก 549 / 18 = 30.50');

        $lineTotal = round((float) $item->qty * (float) $item->unit_price, 2);
        $this->assertEquals(1098.00, $lineTotal, 'ยอดบรรทัดต้องเท่ากับ 1,098.00');
        $this->assertEquals(1098.00, (float) $booking->document->total_amount, 'ยอดใบจองต้องเท่ากับ 1,098.00');

        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $this->location->id)
            ->firstOrFail();
        $this->assertEquals(36.0, (float) $balance->reserved_qty, 'ต้องกันสต็อกเป็นหน่วยหลัก 36');
    }

    /**
     * 2. ยิงบาร์ลังแล้วได้หน่วยลัง จาก search.products
     */
    public function test_search_products_returns_units_and_detects_crate_barcode(): void
    {
        $product = $this->createProductWithCrate('SKU-SEARCH', 30.50, 18, 'BAR-CRATE-SEARCH');

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('search.products', ['q' => 'BAR-CRATE-SEARCH']));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);

        $matched = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($matched);
        $this->assertEquals($this->crateUnit->id, $matched['matched_unit_id']);
        $this->assertIsArray($matched['units']);

        $crateOption = collect($matched['units'])->firstWhere('unit_id', $this->crateUnit->id);
        $this->assertNotNull($crateOption);
        $this->assertEquals(18.0, (float) $crateOption['factor']);
    }

    /**
     * 3. Client ส่ง qty/factor ปลอมมา server ไม่เชื่อ (คำนวณจาก DB เสมอ)
     */
    public function test_server_ignores_fake_client_qty_and_factor(): void
    {
        $product = $this->createProductWithCrate('SKU-FAKE', 30.50, 18);

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
                        'qty' => 999,          // client ปลอม qty มา 999
                        'unit_factor' => 50,   // client ปลอม factor มา 50
                        'unit_price' => 549.00,
                    ],
                ],
            ]);

        $response->assertRedirect();

        $booking = SaleBooking::latest('id')->firstOrFail();
        $item = $booking->document->stockDocument->items->sole();

        // Server ต้องคำนวณจาก DB: factor = 18, qty = 2 * 18 = 36
        $this->assertEquals(18.0, (float) $item->unit_factor);
        $this->assertEquals(36.0, (float) $item->qty);
        $this->assertEquals(30.50, (float) $item->unit_price);
    }

    /**
     * 4. unit_id ของสินค้าอื่นถูกปฏิเสธ
     */
    public function test_foreign_unit_id_is_rejected(): void
    {
        $productA = $this->createProductWithCrate('SKU-A', 30.50, 18);

        $otherUnit = ProductUnit::create(['code' => 'OTHER', 'name' => 'หน่วยอื่น', 'qty_per_base_unit' => 10]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $productA->id,
                        'unit_id' => $otherUnit->id,
                        'unit_qty' => 2,
                        'unit_price' => 300,
                    ],
                ],
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('ไม่ถูกต้องสำหรับสินค้า', session('error'));
    }

    /**
     * 5. Factor ไม่ใช่จำนวนเต็มเลือกไม่ได้
     */
    public function test_fractional_unit_factor_cannot_be_booked(): void
    {
        $product = Product::create([
            'sku_code' => 'SKU-FRAC',
            'name_th' => 'สินค้าแบ่งชั่ง',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 50,
            'is_active' => true,
        ]);
        $packUnit = ProductUnit::create(['code' => 'PACK', 'name' => 'แพ็ค', 'qty_per_base_unit' => 1.5]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-PACK',
            'unit_id' => $packUnit->id,
            'unit_factor' => 1.5, // ทศนิยม
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'unit_id' => $packUnit->id,
                        'unit_qty' => 2,
                        'unit_price' => 75,
                    ],
                ],
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('อัตราส่วนบรรจุไม่ถูกต้อง', session('error'));
    }

    /**
     * 6. ราคาที่พิมพ์หารไม่ลงตัวที่ 4 ตำแหน่งถูกปฏิเสธพร้อมข้อความ
     */
    public function test_indivisible_price_is_rejected_with_message(): void
    {
        $boxUnit = ProductUnit::create(['code' => 'BOX3', 'name' => 'กล่องx3', 'qty_per_base_unit' => 3]);
        $product = Product::create([
            'sku_code' => 'SKU-BOX3',
            'name_th' => 'สินค้ากล่อง 3',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 33.3333,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'barcode' => 'BAR-BOX3',
            'unit_id' => $boxUnit->id,
            'unit_factor' => 3,
            'is_active' => true,
        ]);

        // 100 / 3 = 33.3333333... หารไม่ลงตัวที่ 4 ตำแหน่ง -> ต้องถูกปฏิเสธ
        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'unit_id' => $boxUnit->id,
                        'unit_qty' => 1,
                        'unit_price' => 100.00,
                    ],
                ],
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('ไม่ลงตัวที่ทศนิยม 4 ตำแหน่ง', session('error'));

        // 99 / 3 = 33.0000 หารลงตัว -> ต้องผ่าน
        $responseOk = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'unit_id' => $boxUnit->id,
                        'unit_qty' => 1,
                        'unit_price' => 99.00,
                    ],
                ],
            ]);

        $responseOk->assertRedirect();
    }

    /**
     * ตรวจราคาหารลงตัวด้วย bcmath ตามผลตรวจงาน 32:
     * - 540 ÷ 18 = 30.0000 ผ่าน
     * - 100 ÷ 3 ไม่ผ่าน
     * - 10 ÷ 3 ไม่ผ่าน
     * - 30.50 × 18 = 549.00 ÷ 18 ผ่าน
     */
    public function test_divisibility_bcmath_rules_with_benchmark_cases(): void
    {
        $crate18 = $this->createProductWithCrate('SKU-DIV-18', 30.0, 18);

        $box3Unit = ProductUnit::firstOrCreate(['code' => 'BOX3-DIV'], ['name' => 'กล่องx3', 'qty_per_base_unit' => 3]);
        $product3 = Product::create([
            'sku_code' => 'SKU-DIV-3',
            'name_th' => 'สินค้ากล่อง 3 ตัวคัดกรอง',
            'base_unit_id' => $this->bagUnit->id,
            'default_price' => 10.0,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $product3->id,
            'barcode' => 'BAR-DIV-3',
            'unit_id' => $box3Unit->id,
            'unit_factor' => 3,
            'is_active' => true,
        ]);
        StockBalance::create([
            'product_id' => $product3->id,
            'warehouse_location_id' => $this->location->id,
            'on_hand_qty' => 1000,
            'reserved_qty' => 0,
        ]);

        // 1. 540 ÷ 18 ผ่าน
        $res540 = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $crate18->id, 'unit_id' => $this->crateUnit->id, 'unit_qty' => 1, 'unit_price' => 540.00]],
            ]);
        $res540->assertRedirect();
        $this->assertEquals(30.0, (float) SaleBooking::latest('id')->first()->document->stockDocument->items->first()->unit_price);

        // 2. 100 ÷ 3 ไม่ผ่าน
        $res100 = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $product3->id, 'unit_id' => $box3Unit->id, 'unit_qty' => 1, 'unit_price' => 100.00]],
            ]);
        $res100->assertSessionHas('error');
        $this->assertStringContainsString('ไม่ลงตัวที่ทศนิยม 4 ตำแหน่ง', session('error'));

        // 3. 10 ÷ 3 ไม่ผ่าน
        $res10 = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $product3->id, 'unit_id' => $box3Unit->id, 'unit_qty' => 1, 'unit_price' => 10.00]],
            ]);
        $res10->assertSessionHas('error');
        $this->assertStringContainsString('ไม่ลงตัวที่ทศนิยม 4 ตำแหน่ง', session('error'));

        // 4. 30.50 × 18 = 549.00 ÷ 18 ผ่าน
        $res549 = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [['product_id' => $crate18->id, 'unit_id' => $this->crateUnit->id, 'unit_qty' => 1, 'unit_price' => 549.00]],
            ]);
        $res549->assertRedirect();
        $this->assertEquals(30.50, (float) SaleBooking::latest('id')->first()->document->stockDocument->items->first()->unit_price);
    }

    /**
     * กรณีหน่วยหลัก ห้ามแปลงจำนวน/ราคาผ่าน float:
     * จองสินค้าชั่ง 1.235 กก. ราคา 89.75 ได้ qty/ราคาเท่าเดิมทุกหลัก และยอดตรงเป๊ะ
     */
    public function test_base_unit_preserves_exact_strings_without_float_casting_for_scale_item(): void
    {
        $kgUnit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กก.', 'qty_per_base_unit' => 1]);
        $scaleProduct = Product::create([
            'sku_code' => 'SCALE-PORK',
            'name_th' => 'หมูเนื้อแดงชั่ง',
            'base_unit_id' => $kgUnit->id,
            'default_price' => 89.75,
            'is_active' => true,
        ]);
        ProductBarcode::create([
            'product_id' => $scaleProduct->id,
            'barcode' => 'SCALE-BAR-1',
            'unit_id' => $kgUnit->id,
            'unit_factor' => 1,
            'is_active' => true,
        ]);
        StockBalance::create([
            'product_id' => $scaleProduct->id,
            'warehouse_location_id' => $this->location->id,
            'on_hand_qty' => 100,
            'reserved_qty' => 0,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => [
                    [
                        'product_id' => $scaleProduct->id,
                        'qty' => '1.235',
                        'unit_price' => '89.75',
                    ],
                ],
            ]);

        $response->assertRedirect();

        $booking = SaleBooking::latest('id')->firstOrFail();
        $item = $booking->document->stockDocument->items->sole();

        // ตรวจสอบค่าที่เก็บว่าไม่ถูกแปลงเพี้ยน
        $this->assertEquals(1.235, (float) $item->qty);
        $this->assertEquals(89.75, (float) $item->unit_price);
        $this->assertEquals($kgUnit->id, $item->unit_id);
        $this->assertEquals(1.0, (float) $item->unit_factor);
        $this->assertEquals(1.235, (float) $item->unit_qty);

        // ยอดรวมเอกสารต้องเท่ากับ 1.235 * 89.75 = 110.84125
        $expectedTotal = DecimalMath::multiply('1.235', '89.75');
        $this->assertSame($expectedTotal, (string) $booking->document->total_amount);
    }

    /**
     * 7. แก้ใบจองแล้วหน่วยเดิมกลับมา
     */
    public function test_edit_booking_restores_original_unit_and_price(): void
    {
        $product = $this->createProductWithCrate('SKU-EDIT', 30.50, 18);

        $this->withoutMiddleware(ErpAuthorize::class)
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
                    ],
                ],
            ]);

        $booking = SaleBooking::latest('id')->firstOrFail();

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('bookings.edit', $booking));

        $response->assertOk();
        $initialBooking = $response->viewData('initialBooking');
        $this->assertNotEmpty($initialBooking['items']);

        $firstItem = $initialBooking['items'][0];
        $this->assertEquals($this->crateUnit->id, $firstItem['unit_id']);
        $this->assertEquals(2.0, (float) $firstItem['unit_qty']);
        $this->assertEquals(18.0, (float) $firstItem['unit_factor']);
        $this->assertEquals(549.00, (float) $firstItem['unit_price'], 'ราคาที่แสดงต่อหน่วยในฟอร์มแก้ต้องเป็นราคาต่อหน่วยที่เลือก');
    }

    /**
     * 8. ยืนยันเป็นใบขายแล้วหน่วยติดไป และยอดใบขายเท่าเดิม
     */
    public function test_confirm_booking_to_sale_copies_unit_fields_and_preserves_total(): void
    {
        $product = $this->createProductWithCrate('SKU-SALE', 30.50, 18);

        $this->withoutMiddleware(ErpAuthorize::class)
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
                    ],
                ],
            ]);

        $booking = SaleBooking::latest('id')->firstOrFail();
        $bookingItem = $booking->document->stockDocument->items->sole();

        // ออกใบขายผ่าน CreditSaleService
        $creditSaleService = app(CreditSaleService::class);
        $bills = [
            'DS' => [
                'lines' => [
                    [
                        'booking_item_id' => $bookingItem->id,
                        'qty' => 36.0,
                        'unit_price' => 30.50,
                    ],
                ],
            ],
        ];

        $sales = $creditSaleService->billFromBooking($booking, $bills, $this->user);
        $saleDoc = $sales->sole();

        $this->assertEquals(1098.00, (float) $saleDoc->total_amount, 'ยอดใบขายต้องเท่ากับ 1,098.00 เป๊ะ');

        $saleItem = $saleDoc->stockDocument->items->sole();
        $this->assertEquals($this->crateUnit->id, $saleItem->unit_id);
        $this->assertEquals(2.0, (float) $saleItem->unit_qty);
        $this->assertEquals(18.0, (float) $saleItem->unit_factor);
        $this->assertEquals(36.0, (float) $saleItem->qty);
        $this->assertEquals(30.50, (float) $saleItem->unit_price);
    }

    /**
     * 9. หน้าแสดงใบจอง 50 บรรทัด จำนวน query ไม่โตตามบรรทัด (ต่างไม่เกิน 3 เทียบ 5 บรรทัด)
     * และพิสูจน์ว่าเทสต์แดงเมื่อถอด eager load
     */
    public function test_booking_show_50_items_query_count_does_not_scale_with_rows(): void
    {
        // สร้างใบจอง 5 บรรทัด
        $items5 = [];
        for ($i = 1; $i <= 5; $i++) {
            $p = $this->createProductWithCrate("SKU-Q5-{$i}", 10.0, 18);
            $items5[] = [
                'product_id' => $p->id,
                'unit_id' => $this->crateUnit->id,
                'unit_qty' => 1,
                'unit_price' => 180.0,
            ];
        }

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => $items5,
            ]);
        $booking5 = SaleBooking::latest('id')->firstOrFail();

        // สร้างใบจอง 50 บรรทัด
        $items50 = [];
        for ($i = 1; $i <= 50; $i++) {
            $p = $this->createProductWithCrate("SKU-Q50-{$i}", 10.0, 18);
            $items50[] = [
                'product_id' => $p->id,
                'unit_id' => $this->crateUnit->id,
                'unit_qty' => 1,
                'unit_price' => 180.0,
            ];
        }

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->post(route('bookings.store'), [
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'items' => $items50,
            ]);
        $booking50 = SaleBooking::latest('id')->firstOrFail();

        // วัด queries สำหรับ 5 รายการ
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('bookings.show', $booking5));
        $queries5 = count(DB::getQueryLog());

        // วัด queries สำหรับ 50 รายการ
        DB::flushQueryLog();
        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($this->user)
            ->get(route('bookings.show', $booking50));
        $queries50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $diff = abs($queries50 - $queries5);
        $this->assertLessThanOrEqual(
            3,
            $diff,
            "Query count difference between 5 items ({$queries5}) and 50 items ({$queries50}) must be <= 3 (was {$diff})"
        );

        // พิสูจน์ว่าถ้าถอด eager load บาร์โค้ดออก จะเกิด N+1 query (query จะโตตามบรรทัด)
        $unloadedBooking = SaleBooking::with(['document.stockDocument.items.product'])->find($booking50->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($unloadedBooking->document->stockDocument->items as $item) {
            // จำลองการเรียก UnitQty::format โดยไม่มี eager load barcodes
            UnitQty::format($item->product, $item->qty);
        }
        $unloadedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(
            40,
            $unloadedQueries,
            "Without eager loading barcodes, queries should scale with N (got {$unloadedQueries} queries for 50 items)"
        );
    }
}
