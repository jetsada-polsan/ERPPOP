<?php

namespace Tests\Feature;

use App\Http\Controllers\BookingController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
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
use Tests\TestCase;

class BookingComparisonWarehouseFilterTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private WarehouseLocation $locationA;

    private WarehouseLocation $locationB;

    private SalesArea $areaA;

    private SalesArea $areaB;

    private Customer $customerA;

    private ProductUnit $unit;

    private Product $product1;

    private Product $product2;

    private DocumentType $bookingType;

    private DocumentType $creditSaleType;

    private User $adminUser;

    private User $branchAUser;

    private SaleBooking $bookingA;

    private SaleBooking $bookingB;

    private SaleBooking $bookingMixed;

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
        $this->branchB = Branch::create(['code' => 'B002', 'name_th' => 'สาขากันทรลักษ์', 'is_active' => true]);

        $this->warehouseA = Warehouse::create(['branch_id' => $this->branchA->id, 'code' => 'WH-A', 'name' => 'คลัง A', 'is_active' => true]);
        $this->warehouseB = Warehouse::create(['branch_id' => $this->branchB->id, 'code' => 'WH-B', 'name' => 'ช็อป B', 'is_active' => true]);

        $this->locationA = WarehouseLocation::create(['warehouse_id' => $this->warehouseA->id, 'code' => 'LOC-A', 'name' => 'ช่องวาง A', 'is_active' => true]);
        $this->locationB = WarehouseLocation::create(['warehouse_id' => $this->warehouseB->id, 'code' => 'LOC-B', 'name' => 'ช่องวาง B', 'is_active' => true]);

        $this->areaA = SalesArea::create(['code' => 'SA-01', 'name' => 'สายส่งในเมือง', 'branch_id' => $this->branchA->id]);
        $this->areaB = SalesArea::create(['code' => 'SA-02', 'name' => 'สายส่งกันทรลักษ์', 'branch_id' => $this->branchB->id]);

        $this->unit = ProductUnit::firstOrCreate(['code' => 'PACK'], ['name' => 'แพ็ค', 'qty_per_base_unit' => 1]);

        $this->product1 = Product::create([
            'sku_code' => 'SKU-001',
            'name_th' => 'สินค้า 1',
            'base_unit_id' => $this->unit->id,
            'default_price' => 100.00,
            'average_cost' => 60.00,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $this->product2 = Product::create([
            'sku_code' => 'SKU-002',
            'name_th' => 'สินค้า 2',
            'base_unit_id' => $this->unit->id,
            'default_price' => 200.00,
            'average_cost' => 120.00,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $this->customerA = Customer::create([
            'code' => 'CUST-001',
            'name_th' => 'ลูกค้าร้านตัวอย่าง',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
        ]);

        // Permissions & Roles
        $permSalesManage = Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการงานขาย']);
        $permAdmin = Permission::firstOrCreate(['code' => 'system.admin'], ['name' => 'ผู้ดูแลระบบ']);

        $roleAdmin = Role::firstOrCreate(['code' => 'ADMIN'], ['name' => 'แอดมิน']);
        $roleAdmin->permissions()->syncWithoutDetaching([$permSalesManage->id, $permAdmin->id]);

        $roleSales = Role::firstOrCreate(['code' => 'SALES_USER'], ['name' => 'พนักงานขาย']);
        $roleSales->permissions()->syncWithoutDetaching([$permSalesManage->id]);

        $this->adminUser = User::factory()->create([
            'username' => 'admin_comparison',
            'branch_id' => null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->adminUser->roles()->syncWithoutDetaching([$roleAdmin->id]);

        $this->branchAUser = User::factory()->create([
            'username' => 'branch_a_user',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->branchAUser->roles()->syncWithoutDetaching([$roleSales->id]);

        // 3 Bookings:
        // 1) Booking A: stock from Warehouse A only (amount 1,000.50)
        $this->bookingA = $this->createBookingWithItems('BK-WH-001', 1000.50, [
            ['product' => $this->product1, 'location_id' => $this->locationA->id, 'qty' => 10],
        ]);
        $this->createCreditSaleForBooking($this->bookingA, 'CS-WH-001', 1000.50);

        // 2) Booking B: stock from Shop B only (amount 2,000.25)
        $this->bookingB = $this->createBookingWithItems('BK-WH-002', 2000.25, [
            ['product' => $this->product2, 'location_id' => $this->locationB->id, 'qty' => 10],
        ]);
        $this->createCreditSaleForBooking($this->bookingB, 'CS-WH-002', 2000.25);

        // 3) Booking Mixed: stock from both Warehouse A and Shop B (amount 3,000.75)
        $this->bookingMixed = $this->createBookingWithItems('BK-WH-003', 3000.75, [
            ['product' => $this->product1, 'location_id' => $this->locationA->id, 'qty' => 5],
            ['product' => $this->product2, 'location_id' => $this->locationB->id, 'qty' => 5],
        ]);
        $this->createCreditSaleForBooking($this->bookingMixed, 'CS-WH-003', 3000.75);
    }

    private function createBookingWithItems(string $docNumber, float $amount, array $items): SaleBooking
    {
        $doc = Document::create([
            'document_type_id' => $this->bookingType->id,
            'branch_id' => $this->branchA->id,
            'sales_area_id' => $this->areaA->id,
            'customer_id' => $this->customerA->id,
            'doc_number' => $docNumber,
            'doc_date' => '2026-11-12',
            'status' => 'approved',
            'total_amount' => $amount,
            'total_items' => count($items),
        ]);

        $booking = SaleBooking::create([
            'document_id' => $doc->id,
            'sales_area_id' => $this->areaA->id,
            'status' => SaleBooking::STATUS_CONVERTED,
        ]);

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
                'warehouse_location_id' => $it['location_id'],
                'qty' => $it['qty'],
                'unit_id' => $it['product']->base_unit_id,
                'unit_price' => 100.00,
            ]);
        }

        return $booking;
    }

    private function createCreditSaleForBooking(SaleBooking $booking, string $docNumber, float $amount): Document
    {
        $saleDoc = Document::create([
            'document_type_id' => $this->creditSaleType->id,
            'branch_id' => $this->branchA->id,
            'sales_area_id' => $this->areaA->id,
            'customer_id' => $this->customerA->id,
            'doc_number' => $docNumber,
            'doc_date' => '2026-11-12',
            'status' => 'approved',
            'total_amount' => $amount,
            'total_items' => 1,
            'source_document_id' => $booking->document_id,
        ]);

        $booking->update([
            'confirmed_document_id' => $saleDoc->id,
            'confirmed_at' => now(),
        ]);

        return $saleDoc;
    }

    /**
     * Requirement 4.1: Filter Warehouse A returns Booking A and Mixed, but not B
     */
    public function test_comparison_filter_warehouse_a_returns_booking_a_and_mixed(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'warehouse_id' => $this->warehouseA->id,
        ]));

        $response->assertOk();
        $response->assertSee('BK-WH-001');
        $response->assertSee('BK-WH-003');
        $response->assertDontSee('BK-WH-002');

        // Total booking amount: 1000.50 + 3000.75 = 4,001.25
        $response->assertSee('4,001.25');
    }

    /**
     * Requirement 4.2: Filter Warehouse B returns Booking B and Mixed, but not A
     */
    public function test_comparison_filter_warehouse_b_returns_booking_b_and_mixed(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'warehouse_id' => $this->warehouseB->id,
        ]));

        $response->assertOk();
        $response->assertSee('BK-WH-002');
        $response->assertSee('BK-WH-003');
        $response->assertDontSee('BK-WH-001');

        // Total booking amount: 2000.25 + 3000.75 = 5,001.00
        $response->assertSee('5,001.00');
    }

    /**
     * Requirement 4.3: No warehouse filter (blank) returns all 3 bookings
     */
    public function test_comparison_without_warehouse_filter_returns_all_three_bookings(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        $response->assertOk();
        $response->assertSee('BK-WH-001');
        $response->assertSee('BK-WH-002');
        $response->assertSee('BK-WH-003');

        // Total booking amount: 1000.50 + 2000.25 + 3000.75 = 6,001.50
        $response->assertSee('6,001.50');
    }

    /**
     * Test empty string warehouse_id (?warehouse_id=) acts as no filter
     */
    public function test_comparison_with_empty_string_warehouse_filter_returns_all_bookings(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'warehouse_id' => '',
        ]));

        $response->assertOk();
        $response->assertSee('BK-WH-001');
        $response->assertSee('BK-WH-002');
        $response->assertSee('BK-WH-003');
        $response->assertSee('6,001.50');
    }

    /**
     * Requirement 2: Table displays comma-separated warehouse names
     */
    public function test_comparison_displays_comma_separated_warehouse_names(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        $response->assertOk();
        // Booking 3 has both "คลัง A" and "ช็อป B"
        $response->assertSee('คลัง A, ช็อป B');
    }

    /**
     * Requirement 4.4: CSV export matches filtered rows and exact satang totals
     */
    public function test_comparison_csv_export_respects_warehouse_filter_and_includes_warehouse_column(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'warehouse_id' => $this->warehouseA->id,
            'export' => 'csv',
        ]));

        $response->assertOk();
        $csvContent = $response->streamedContent();

        // Check header has คลัง
        $this->assertStringContainsString('ลำดับ,เลขที่ใบจอง,วันที่จอง,ลูกค้า,สายขาย,ยอดใบจอง,เลขที่ใบขาย,ยอดใบขาย,ส่วนต่าง,สถานะ,คลัง', $csvContent);

        // Check rows
        $this->assertStringContainsString('BK-WH-001', $csvContent);
        $this->assertStringContainsString('BK-WH-003', $csvContent);
        $this->assertStringNotContainsString('BK-WH-002', $csvContent);

        // Check exact satang total row: 4,001.25
        $this->assertStringContainsString('4,001.25', $csvContent);
        // Mixed booking warehouse names in CSV
        $this->assertStringContainsString('คลัง A, ช็อป B', $csvContent);
    }

    /**
     * Requirement 4.5: Branch user cannot select another branch's warehouse (403 Forbidden)
     */
    public function test_branch_user_cannot_select_another_branch_warehouse(): void
    {
        // branchAUser is assigned to branchA, warehouseB is branchB's warehouse
        $response = $this->actingAs($this->branchAUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
            'warehouse_id' => $this->warehouseB->id,
        ]));

        $response->assertForbidden();
    }

    /**
     * Requirement 4.6: Branch user sees only own branch warehouse in dropdown
     */
    public function test_branch_user_sees_only_own_branch_warehouses_in_dropdown(): void
    {
        $response = $this->actingAs($this->branchAUser)->get(route('bookings.comparison', [
            'date_from' => '2026-11-12',
            'date_to' => '2026-11-12',
        ]));

        $response->assertOk();
        $response->assertSee('WH-A');
        $response->assertSee('คลัง A');
        $response->assertDontSee('WH-B');
    }

    /**
     * Requirement 1: Shared query pendingBookingsQuery respects warehouse_id
     */
    public function test_shared_pending_bookings_query_respects_warehouse_filter(): void
    {
        // Update bookingA to pending
        $this->bookingA->update(['status' => SaleBooking::STATUS_PENDING]);
        $this->bookingMixed->update(['status' => SaleBooking::STATUS_PENDING]);

        $bookingsWH_A = BookingController::pendingBookingsQuery(
            date: '2026-11-12',
            branchId: $this->branchA->id,
            warehouseId: $this->warehouseA->id
        )->get();

        $docNumbers = $bookingsWH_A->map(fn ($b) => $b->document->doc_number)->all();
        $this->assertContains('BK-WH-001', $docNumbers);
        $this->assertContains('BK-WH-003', $docNumbers);
        $this->assertNotContains('BK-WH-002', $docNumbers);
    }
}
