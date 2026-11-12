<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoadingSheetFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private SalesArea $areaA;
    private SalesArea $areaB;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private WarehouseLocation $locationA;
    private WarehouseLocation $locationB;
    private ProductUnit $unit;
    private Product $product1;
    private Product $product2;
    private Customer $customerA;
    private Customer $customerB;
    private User $adminUser;
    private User $branchUser;
    private User $unauthorizedUser;
    private DocumentType $bookingType;
    private DocumentType $creditSaleType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingType = DocumentType::firstOrCreate(
            ['code' => DocumentType::BOOKING],
            ['name_th' => 'ใบจองสินค้า', 'stock_effect' => 'none']
        );
        $this->creditSaleType = DocumentType::firstOrCreate(
            ['code' => DocumentType::CREDIT_SALE],
            ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']
        );

        $this->branchA = Branch::create(['code' => 'B001', 'name_th' => 'สาขาสำนักงานใหญ่', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'B002', 'name_th' => 'สาขาขอนแก่น', 'is_active' => true]);

        $this->warehouseA = Warehouse::create(['branch_id' => $this->branchA->id, 'code' => 'WH-A', 'name' => 'คลังหลักสาขาใหญ่']);
        $this->warehouseB = Warehouse::create(['branch_id' => $this->branchB->id, 'code' => 'WH-B', 'name' => 'คลังขอนแก่น']);

        $this->locationA = WarehouseLocation::create(['warehouse_id' => $this->warehouseA->id, 'code' => 'LOC-A', 'name' => 'ช่องวาง A']);
        $this->locationB = WarehouseLocation::create(['warehouse_id' => $this->warehouseB->id, 'code' => 'LOC-B', 'name' => 'ช่องวาง B']);

        $this->areaA = SalesArea::create(['code' => 'SA-01', 'name' => 'สายส่งเมือง', 'branch_id' => $this->branchA->id]);
        $this->areaB = SalesArea::create(['code' => 'SA-02', 'name' => 'สายส่งรอบนอก', 'branch_id' => $this->branchB->id]);

        $this->unit = ProductUnit::firstOrCreate(['code' => 'PACK'], ['name' => 'แพ็ค', 'qty_per_base_unit' => 1]);

        $this->product1 = Product::create([
            'sku_code' => 'SKU-001',
            'name_th' => 'น้ำดื่มสิงห์ 600ml',
            'base_unit_id' => $this->unit->id,
            'default_price' => 120.00,
            'average_cost' => 80.00,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        ProductBarcode::create([
            'product_id' => $this->product1->id,
            'unit_id' => $this->unit->id,
            'barcode' => '885099900001',
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'sku_code' => 'SKU-002',
            'name_th' => 'มาม่ารสต้มยำกุ้ง',
            'base_unit_id' => $this->unit->id,
            'default_price' => 150.00,
            'average_cost' => 100.00,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        ProductBarcode::create([
            'product_id' => $this->product2->id,
            'unit_id' => $this->unit->id,
            'barcode' => '885099900002',
            'is_active' => true,
        ]);

        $this->customerA = Customer::create(['code' => 'CUST-001', 'name_th' => 'ร้านสมศรี มินิมาร์ท', 'branch_id' => $this->branchA->id, 'is_active' => true]);
        $this->customerB = Customer::create(['code' => 'CUST-002', 'name_th' => 'ร้านเจริญพาณิชย์', 'branch_id' => $this->branchB->id, 'is_active' => true]);

        // Permissions
        $permSalesManage = Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการงานขาย']);
        $permAdmin = Permission::firstOrCreate(['code' => 'system.admin'], ['name' => 'ผู้ดูแลระบบ']);

        $roleSales = Role::firstOrCreate(['code' => 'SALES_USER'], ['name' => 'พนักงานขาย']);
        $roleSales->permissions()->syncWithoutDetaching([$permSalesManage->id]);

        $roleAdmin = Role::firstOrCreate(['code' => 'ADMIN'], ['name' => 'แอดมิน']);
        $roleAdmin->permissions()->syncWithoutDetaching([$permSalesManage->id, $permAdmin->id]);

        $this->adminUser = User::factory()->create([
            'username' => 'admin_user',
            'branch_id' => null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->adminUser->roles()->syncWithoutDetaching([$roleAdmin->id]);

        $this->branchUser = User::factory()->create([
            'username' => 'branch_user',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->branchUser->roles()->syncWithoutDetaching([$roleSales->id]);

        $this->unauthorizedUser = User::factory()->create([
            'username' => 'no_perm_user',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function createBooking(
        Branch $branch,
        SalesArea $area,
        Customer $customer,
        string $docNumber,
        float $totalAmount,
        string $docDate = '2026-11-12',
        string $status = 'pending',
        array $items = []
    ): SaleBooking {
        $doc = Document::create([
            'document_type_id' => $this->bookingType->id,
            'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
            'customer_id' => $customer->id,
            'doc_number' => $docNumber,
            'doc_date' => $docDate,
            'status' => 'approved',
            'total_amount' => $totalAmount,
            'total_items' => count($items),
        ]);

        $booking = SaleBooking::create([
            'document_id' => $doc->id,
            'sales_area_id' => $area->id,
            'status' => $status,
        ]);

        if (! empty($items)) {
            $stockDoc = StockDocument::create([
                'document_id' => $doc->id,
                'total_qty' => array_sum(array_column($items, 'qty')),
                'total_items' => count($items),
            ]);

            foreach ($items as $idx => $it) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDoc->id,
                    'seq' => $idx + 1,
                    'product_id' => $it['product']->id,
                    'warehouse_location_id' => $it['location_id'] ?? $this->locationA->id,
                    'qty' => $it['qty'],
                    'unit_id' => $it['product']->base_unit_id,
                    'unit_price' => $it['price'] ?? 100.00,
                ]);
            }
        }

        return $booking;
    }

    private function createCreditSaleForBooking(
        SaleBooking $booking,
        string $docNumber,
        float $amount,
        string $status = 'approved',
        string $docDate = '2026-11-12'
    ): Document {
        return Document::create([
            'document_type_id' => $this->creditSaleType->id,
            'branch_id' => $booking->document->branch_id,
            'sales_area_id' => $booking->sales_area_id,
            'customer_id' => $booking->document->customer_id,
            'doc_number' => $docNumber,
            'doc_date' => $docDate,
            'status' => $status,
            'total_amount' => $amount,
            'source_document_id' => $booking->document_id,
        ]);
    }

    /**
     * Requirement 1: Separate print buttons and print isolation CSS
     */
    public function test_separate_print_buttons_exist_and_target_sections(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.summary'));

        $response->assertOk();
        // Check 3 separate print buttons and original print all button
        $response->assertSee('พิมพ์ใบรวมจอง');
        $response->assertSee('พิมพ์ใบขึ้นของ (ทีละบิล)');
        $response->assertSee('พิมพ์รายการสินค้าที่ต้องเตรียม');
        $response->assertSee('พิมพ์ใบเช็คส่งของประจำสาย');

        // Check Javascript printSection calls
        $response->assertSee("printSection('all')", false);
        $response->assertSee("printSection('bills')", false);
        $response->assertSee("printSection('items')", false);
        $response->assertSee("printSection('route')", false);

        // Check CSS print rules exist
        $response->assertSee('print-section-bills', false);
        $response->assertSee('print-section-items', false);
        $response->assertSee('print-section-route', false);
        $response->assertSee('html body .app-sidebar { display: none !important; }', false);
    }

    /**
     * Requirement 2: Load bill header styling (>= 16px, bold) and dashed divider
     */
    public function test_load_bill_head_styling_and_dashed_divider(): void
    {
        $this->createBooking(
            $this->branchA,
            $this->areaA,
            $this->customerA,
            'BK-202611-0001',
            4487.55,
            '2026-11-12',
            'pending',
            [['product' => $this->product1, 'qty' => 10, 'location_id' => $this->locationA->id]]
        );

        $response = $this->actingAs($this->adminUser)->get(route('bookings.summary', ['date' => '2026-11-12']));

        $response->assertOk();
        $response->assertSee('BK-202611-0001');
        $response->assertSee('ร้านสมศรี มินิมาร์ท');
        $response->assertSee('฿4,487.55');

        // Ensure CSS defines load-bill styling: border-bottom dashed and font-size >= 16px
        $response->assertSee('.load-bill-head { font-size: 16px !important;', false);
        $response->assertSee('font-weight: 800 !important;', false);
        $response->assertSee('border-bottom: 2px dashed', false);
        $response->assertSee('.summary-table th { background: #eee !important; color: #111 !important; font-weight: bold !important; font-size: 13px !important; }', false);
    }

    /**
     * Requirement 3: Real-time search & filters in items to prepare + drilldown
     */
    public function test_items_to_prepare_has_search_filters_and_drilldown(): void
    {
        // Booking 1: 15 packs of product1 in warehouseA, areaA
        $this->createBooking(
            $this->branchA,
            $this->areaA,
            $this->customerA,
            'BK-TEST-001',
            1800.00,
            '2026-11-12',
            'pending',
            [['product' => $this->product1, 'qty' => 15, 'location_id' => $this->locationA->id]]
        );

        // Booking 2: 25 packs of product1 in warehouseB, areaB
        $this->createBooking(
            $this->branchA,
            $this->areaB,
            $this->customerB,
            'BK-TEST-002',
            3000.00,
            '2026-11-12',
            'pending',
            [['product' => $this->product1, 'qty' => 25, 'location_id' => $this->locationB->id]]
        );

        $response = $this->actingAs($this->adminUser)->get(route('bookings.summary', ['date' => '2026-11-12']));

        $response->assertOk();

        // Search inputs and filters
        $response->assertSee('id="prep-search"', false);
        $response->assertSee('id="prep-area-filter"', false);
        $response->assertSee('id="prep-warehouse-filter"', false);
        $response->assertSee('id="toggle-barcode"', false);

        // Product row displays total quantity: 15 + 25 = 40.00
        $response->assertSee('SKU-001');
        $response->assertSee('น้ำดื่มสิงห์ 600ml');
        $response->assertSee('40.00');

        // Drilldown sub-row contains individual bookings
        $response->assertSee('BK-TEST-001');
        $response->assertSee('BK-TEST-002');
        $response->assertSee('15.00 แพ็ค');
        $response->assertSee('25.00 แพ็ค');
        $response->assertSee('คลังหลักสาขาใหญ่');
        $response->assertSee('คลังขอนแก่น');
    }

    /**
     * Requirement 4: Barcode column toggleable without changing SKU code
     */
    public function test_barcode_column_toggleable_without_changing_sku(): void
    {
        $this->createBooking(
            $this->branchA,
            $this->areaA,
            $this->customerA,
            'BK-BARCODE-01',
            120.00,
            '2026-11-12',
            'pending',
            [['product' => $this->product1, 'qty' => 1, 'location_id' => $this->locationA->id]]
        );

        $response = $this->actingAs($this->adminUser)->get(route('bookings.summary', ['date' => '2026-11-12']));

        $response->assertOk();
        // sku_code must still be displayed
        $response->assertSee('SKU-001');
        // Barcode is present in barcode-col, initially hidden
        $response->assertSee('885099900001');
        $response->assertSee('class="barcode-col font-monospace" style="display: none;"', false);
        $response->assertSee('แสดงบาร์โค้ดหน่วยที่จอง');
    }

    /**
     * Requirement 5: Booking vs Credit Sale Comparison Report
     * Tests exact matches, variances, partials, and excluded cancelled bookings from totals down to exact satang.
     */
    public function test_comparison_report_accurately_compares_bookings_and_credit_sales(): void
    {
        // 1. Fully converted booking: converted_to_sale, exactly matching 4,487.55
        $booking1 = $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-MATCH-01', 4487.55, '2026-11-12', SaleBooking::STATUS_CONVERTED);
        $this->createCreditSaleForBooking($booking1, 'CS-MATCH-01', 4487.55, 'approved', '2026-11-12');

        // 2. Converted booking with price/qty adjusted during conversion: booking 5,000.00, credit sale 3,500.50, variance 1,499.50
        // Status remains converted_to_sale (แปลงเป็นใบขายแล้ว) while highlighting 'ยอดไม่ตรง'
        $booking2 = $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-PARTIAL-02', 5000.00, '2026-11-12', SaleBooking::STATUS_CONVERTED);
        $this->createCreditSaleForBooking($booking2, 'CS-PARTIAL-02', 3500.50, 'approved', '2026-11-12');

        // 3. Pending booking: booking 2,500.00, no sales yet, variance 2,500.00
        $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-PENDING-03', 2500.00, '2026-11-12', SaleBooking::STATUS_PENDING);

        // 4. Cancelled credit sale should NOT be counted
        $booking4 = $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-CANCELLED-SALE-04', 1000.00, '2026-11-12', SaleBooking::STATUS_PENDING);
        $this->createCreditSaleForBooking($booking4, 'CS-VOID-04', 1000.00, 'cancelled', '2026-11-12');

        // 5. Cancelled booking: amount 800.00, should be displayed in table but EXCLUDED from totals row
        $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-CANCELLED-05', 800.00, '2026-11-12', SaleBooking::STATUS_CANCELLED);

        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        $response->assertOk();

        // Check BK-MATCH-01 (Converted to sale)
        $response->assertSee('BK-MATCH-01');
        $response->assertSee('4,487.55');
        $response->assertSee('CS-MATCH-01');
        $response->assertSee('แปลงเป็นใบขายแล้ว');

        // Check BK-PARTIAL-02 (Converted to sale with variance: status is 'แปลงเป็นใบขายแล้ว', variance badge 'ยอดไม่ตรง')
        $response->assertSee('BK-PARTIAL-02');
        $response->assertSee('5,000.00');
        $response->assertSee('CS-PARTIAL-02');
        $response->assertSee('3,500.50');
        $response->assertSee('1,499.50');
        $response->assertSee('ยอดไม่ตรง');

        // Check BK-PENDING-03 (Pending)
        $response->assertSee('BK-PENDING-03');
        $response->assertSee('2,500.00');
        $response->assertSee('ยังไม่ออกบิล');

        // Check BK-CANCELLED-SALE-04 (Cancelled sale not counted -> stays pending with 0 sale amount)
        $response->assertSee('BK-CANCELLED-SALE-04');
        $response->assertDontSee('CS-VOID-04');

        // Check BK-CANCELLED-05 (Cancelled booking displayed in table)
        $response->assertSee('BK-CANCELLED-05');
        $response->assertSee('ยกเลิก');

        // Check Totals: Cancelled bookings MUST BE EXCLUDED!
        // Booking sum: 4487.55 + 5000.00 + 2500.00 + 1000.00 = 12,987.55 (800.00 cancelled excluded!)
        // Sale sum: 4487.55 + 3500.50 + 0 + 0 = 7,988.05
        // Variance sum: 12987.55 - 7988.05 = 4,999.50 (NOT 5,799.50!)
        $response->assertSee('12,987.55');
        $response->assertSee('7,988.05');
        $response->assertSee('4,999.50');
        $response->assertDontSee('13,787.55');
        $response->assertDontSee('5,799.50');
    }

    /**
     * Requirement 5: CSV Export with UTF-8 BOM
     */
    public function test_comparison_report_csv_export_has_utf8_bom(): void
    {
        $booking = $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-CSV-01', 4487.55, '2026-11-12', SaleBooking::STATUS_CONVERTED);
        $this->createCreditSaleForBooking($booking, 'CS-CSV-01', 4487.55, 'approved', '2026-11-12');

        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'export' => 'csv',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        // Check UTF-8 BOM \xEF\xBB\xBF
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"), 'CSV must begin with UTF-8 BOM');

        // Check CSV content headers and rows
        $this->assertStringContainsString('ลำดับ,เลขที่ใบจอง,วันที่จอง,ลูกค้า,สายขาย,ยอดใบจอง,เลขที่ใบขาย,ยอดใบขาย,ส่วนต่าง,สถานะ', $content);
        $this->assertStringContainsString('BK-CSV-01', $content);
        $this->assertStringContainsString('4,487.55', $content);
        $this->assertStringContainsString('CS-CSV-01', $content);
        $this->assertStringContainsString('แปลงเป็นใบขายแล้ว', $content);
        $this->assertStringContainsString('รวมทั้งหมด (ไม่รวมใบยกเลิก)', $content);
    }

    /**
     * Requirement 6: Permission enforcement & branch isolation
     */
    public function test_permissions_and_branch_isolation(): void
    {
        // 1. Unauthorized user cannot access summary or comparison
        $this->actingAs($this->unauthorizedUser)->get(route('bookings.summary'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('bookings.comparison'))->assertForbidden();

        // 2. Branch isolation: branchUser has branchA
        // Create booking in branchB
        $this->createBooking($this->branchB, $this->areaB, $this->customerB, 'BK-BRANCH-B-99', 9999.00, '2026-11-12');

        // branchUser requests summary -> should NOT see branchB booking
        $summaryResp = $this->actingAs($this->branchUser)->get(route('bookings.summary', ['date' => '2026-11-12']));
        $summaryResp->assertOk();
        $summaryResp->assertDontSee('BK-BRANCH-B-99');

        // branchUser attempts to access branchB explicitly -> 403 Forbidden
        $this->actingAs($this->branchUser)->get(route('bookings.summary', ['branch_id' => $this->branchB->id]))->assertForbidden();
        $this->actingAs($this->branchUser)->get(route('bookings.comparison', ['branch_id' => $this->branchB->id]))->assertForbidden();

        // branchUser in comparison report -> should NOT see branchB booking
        $comparisonResp = $this->actingAs($this->branchUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));
        $comparisonResp->assertOk();
        $comparisonResp->assertDontSee('BK-BRANCH-B-99');
    }

    /**
     * Review Point 2.1: Prove N+1 query elimination:
     * Comparing 5 bookings vs 50 bookings query count difference must be <= 3.
     */
    public function test_comparison_report_eager_loads_and_avoids_n_plus_one_queries(): void
    {
        // Create 5 bookings with credit sales
        for ($i = 1; $i <= 5; $i++) {
            $b = $this->createBooking($this->branchA, $this->areaA, $this->customerA, "BK-N1-{$i}", 100.00 * $i, '2026-11-12');
            $this->createCreditSaleForBooking($b, "CS-N1-{$i}", 100.00 * $i, 'approved', '2026-11-12');
        }

        // Warm-up request to initialize auth and menu permission caching
        $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resp5 = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));
        $resp5->assertOk();
        $queries5 = count(DB::getQueryLog());

        // Create 45 more bookings (total 50)
        for ($i = 6; $i <= 50; $i++) {
            $b = $this->createBooking($this->branchA, $this->areaA, $this->customerA, "BK-N1-{$i}", 100.00 * $i, '2026-11-12');
            $this->createCreditSaleForBooking($b, "CS-N1-{$i}", 100.00 * $i, 'approved', '2026-11-12');
        }

        DB::flushQueryLog();

        $resp50 = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));
        $resp50->assertOk();
        $queries50 = count(DB::getQueryLog());

        // Query count difference must not scale with N (difference <= 3 queries)
        $diff = abs($queries50 - $queries5);
        $this->assertLessThanOrEqual(
            3,
            $diff,
            "N+1 detected! Query count for 5 bookings was {$queries5}, but for 50 bookings was {$queries50} (diff: {$diff} > 3)."
        );
    }

    /**
     * Review Point 2.5: Date range limit of at most 92 days
     */
    public function test_comparison_report_warns_when_date_range_exceeds_92_days(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-06-01', // ~151 days > 92 days
        ]));

        $response->assertOk();
        $response->assertSee('ช่วงวันที่ที่เลือกเกิน 92 วัน');
    }

    /**
     * Review Point 2.4: Exact satang math without float drifting in comparison report
     */
    public function test_comparison_report_exact_satang_math_with_precision_prone_floats(): void
    {
        // 0.10 + 0.20 + 4487.55 + 1182.54 = 5,670.39
        $amounts = [0.10, 0.20, 4487.55, 1182.54];
        foreach ($amounts as $idx => $amt) {
            $b = $this->createBooking($this->branchA, $this->areaA, $this->customerA, "BK-SATANG-{$idx}", $amt, '2026-11-12');
            $this->createCreditSaleForBooking($b, "CS-SATANG-{$idx}", $amt, 'approved', '2026-11-12');
        }

        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        $response->assertOk();
        // Total booking amount and sale amount must be exact 5,670.39 and variance 0.00
        $response->assertSee('5,670.39');
        $response->assertSee('0.00');

        // CSV export must also show exact satang
        $csvResp = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'export' => 'csv',
        ]));
        $csvResp->assertOk();
        $csvContent = $csvResp->streamedContent();
        $this->assertStringContainsString('5,670.39', $csvContent);
    }

    /**
     * ใบรวมจองต้องเปิดได้เมื่อมีบรรทัดที่จองเป็นหน่วยใหญ่ (production 12 พ.ย. ล่มเพราะเรียก formatSingle ที่ไม่มีอยู่)
     */
    public function test_summary_opens_when_a_booking_line_is_in_a_larger_unit(): void
    {
        $carton = ProductUnit::firstOrCreate(['code' => 'CTN'], ['name' => 'ลัง', 'qty_per_base_unit' => 12]);
        ProductBarcode::create([
            'product_id' => $this->product1->id,
            'unit_id' => $carton->id,
            'unit_factor' => 12,
            'barcode' => '885099900012',
            'is_active' => true,
        ]);
        $booking = $this->createBooking($this->branchA, $this->areaA, $this->customerA, 'BK-202611-0099', 2880.00, '2026-11-12', 'pending',
            [['product' => $this->product1, 'qty' => 24, 'location_id' => $this->locationA->id]]);
        StockDocumentItem::where('stock_document_id', $booking->document->stockDocument->id)
            ->update(['unit_id' => $carton->id, 'unit_qty' => 2, 'unit_factor' => 12]);

        $this->actingAs($this->adminUser)->get(route('bookings.summary', ['date' => '2026-11-12']))
            ->assertOk()
            ->assertSee('BK-202611-0099')
            ->assertSee('(2 ลัง)');
    }
}
