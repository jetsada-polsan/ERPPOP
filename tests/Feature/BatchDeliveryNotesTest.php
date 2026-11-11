<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\Salesman;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchDeliveryNotesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    private Branch $branchB;

    private Product $product;

    private Customer $customerA;

    private Customer $customerB;

    private SalesArea $salesArea;

    private Salesman $salesman;

    private WarehouseLocation $location;

    private User $authorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['BOOKING', 'none'],
            ['CASH_SALE', 'out'],
            ['CREDIT_SALE', 'out'],
        ] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }

        $this->branchA = Branch::create(['code' => 'B-A', 'name_th' => 'สาขา ก', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'B-B', 'name_th' => 'สาขา ข', 'is_active' => true]);

        $this->salesArea = SalesArea::create(['code' => 'SA-01', 'name' => 'สายส่งเมือง', 'branch_id' => $this->branchA->id]);
        $this->salesman = Salesman::create(['code' => 'SM-01', 'name' => 'สมชาย ขยันส่ง', 'branch_id' => $this->branchA->id, 'is_active' => true]);

        $warehouse = Warehouse::create(['branch_id' => $this->branchA->id, 'code' => 'WH-A', 'name' => 'คลังหลัก']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-A', 'name' => 'ที่ตั้งหลัก']);
        $this->branchA->update(['default_warehouse_location_id' => $this->location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product = Product::create([
            'sku_code' => 'PROD-01',
            'name_th' => 'ไก่สดตัดแต่ง',
            'base_unit_id' => $unit->id,
            'default_price' => 120.00,
            'average_cost' => 80.00,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);
        ProductBarcode::create(['product_id' => $this->product->id, 'unit_id' => $unit->id, 'barcode' => '885000000001', 'is_active' => true]);

        $this->customerA = Customer::create(['code' => 'CUST-A', 'name_th' => 'กิตติพงษ์ ฟู้ดส์', 'branch_id' => $this->branchA->id, 'is_active' => true]);
        CustomerAddress::create(['customer_id' => $this->customerA->id, 'address_line' => '123 ถนนสุขุมวิท', 'is_default' => true]);

        $this->customerB = Customer::create(['code' => 'CUST-B', 'name_th' => 'บดินทร์ เบเกอรี่', 'branch_id' => $this->branchB->id, 'is_active' => true]);
        CustomerAddress::create(['customer_id' => $this->customerB->id, 'address_line' => '456 ถนนพหลโยธิน', 'is_default' => true]);

        // Permissions & Roles
        $perm = Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการงานขาย']);
        $role = Role::firstOrCreate(['code' => 'SALES_MGR'], ['name' => 'ผู้จัดการฝ่ายขาย']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        $this->authorizedUser = User::factory()->create([
            'username' => 'sales_admin',
            'branch_id' => null, // Global user
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->authorizedUser->roles()->attach($role);
    }

    private function createDoc(
        string $typeCode,
        string $docNumber,
        Branch $branch,
        Customer $customer,
        string $status = 'active',
        bool $hasItems = true,
        string $docDate = '2026-11-11'
    ): Document {
        $type = DocumentType::where('code', $typeCode)->firstOrFail();

        $doc = Document::create([
            'document_type_id' => $type->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'salesman_id' => $this->salesman->id,
            'sales_area_id' => $this->salesArea->id,
            'doc_number' => $docNumber,
            'doc_date' => $docDate,
            'status' => $status,
            'total_items' => $hasItems ? 1 : 0,
            'total_amount' => $hasItems ? 240.00 : 0.00,
            'remark' => 'ทดสอบส่งของ',
        ]);

        if ($typeCode === 'BOOKING') {
            SaleBooking::create([
                'document_id' => $doc->id,
                'salesman_id' => $this->salesman->id,
                'sales_area_id' => $this->salesArea->id,
                'status' => $status === 'cancelled' ? 'cancelled' : 'pending',
                'fulfillment_type' => 'delivery',
                'delivery_status' => 'pending',
            ]);
        }

        if ($hasItems) {
            $stockDoc = StockDocument::create([
                'document_id' => $doc->id,
                'total_qty' => 2,
                'total_items' => 1,
            ]);

            StockDocumentItem::create([
                'stock_document_id' => $stockDoc->id,
                'warehouse_location_id' => $this->location->id,
                'seq' => 1,
                'product_id' => $this->product->id,
                'qty' => 2,
                'unit_price' => 120.00,
            ]);
        }

        return $doc;
    }

    public function test_batch_delivery_notes_renders_correct_pages_for_bookings_and_sales(): void
    {
        $booking1 = $this->createDoc('BOOKING', 'BK-001', $this->branchA, $this->customerA);
        $booking2 = $this->createDoc('BOOKING', 'BK-002', $this->branchA, $this->customerA);
        $sale1 = $this->createDoc('CASH_SALE', 'CS-001', $this->branchA, $this->customerA);

        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('documents.delivery-notes.batch', [
            'ids' => [$booking1->id, $booking2->id, $sale1->id],
        ]));

        $response->assertOk();

        // 2 bookings * 2 copies + 1 sale * 1 copy = 5 pages total
        $content = $response->getContent();
        $pageCount = substr_count($content, 'class="page delivery-note-page"');
        $this->assertSame(5, $pageCount, 'Expected 5 delivery note pages (2 bookings * 2 + 1 sale * 1)');

        // Header shows count of documents and pages
        $response->assertSee('จำนวน 3 ใบ');
        $response->assertSee('จำนวน 5 หน้า');
        $response->assertSee('🖨️ พิมพ์ทั้งหมด (A5)');

        // Check copy labels
        $response->assertSee('สำหรับพนักงาน (นำกลับ)');
        $response->assertSee('สำหรับลูกค้า');
    }

    public function test_batch_delivery_notes_skips_voided_and_empty_documents_with_summary(): void
    {
        $validBooking = $this->createDoc('BOOKING', 'BK-VAL-01', $this->branchA, $this->customerA);
        $voidedBooking = $this->createDoc('BOOKING', 'BK-VOID-02', $this->branchA, $this->customerA, 'cancelled');
        $emptyBooking = $this->createDoc('BOOKING', 'BK-EMPTY-03', $this->branchA, $this->customerA, 'active', false);

        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('documents.delivery-notes.batch', [
            'ids' => [$validBooking->id, $voidedBooking->id, $emptyBooking->id],
        ]));

        $response->assertOk();

        // Only valid booking is printed (2 copies for booking)
        $content = $response->getContent();
        $pageCount = substr_count($content, 'class="page delivery-note-page"');
        $this->assertSame(2, $pageCount);

        // Header shows printable count = 1
        $response->assertSee('จำนวน 1 ใบ');
        $response->assertSee('จำนวน 2 หน้า');

        // Skipped banner shows 2 skipped docs
        $response->assertSee('ข้าม 2 ใบ: เลขที่');
        $response->assertSee('BK-VOID-02');
        $response->assertSee('BK-EMPTY-03');
    }

    public function test_batch_delivery_notes_rejects_more_than_150_documents_with_message(): void
    {
        $this->actingAs($this->authorizedUser);

        // Pass 151 dummy IDs
        $ids = range(1, 151);

        $response = $this->get(route('documents.delivery-notes.batch', [
            'ids' => $ids,
        ]));

        $response->assertStatus(422);
        $response->assertSee('จำกัดการพิมพ์ไม่เกิน 150 ใบต่อครั้ง');
        $response->assertSee('กรุณาแบ่งตัวกรองใหม่');
    }

    public function test_batch_delivery_notes_enforces_branch_isolation(): void
    {
        $docA = $this->createDoc('BOOKING', 'BK-BRANCH-A', $this->branchA, $this->customerA);
        $docB = $this->createDoc('BOOKING', 'BK-BRANCH-B', $this->branchB, $this->customerB);

        // User scoped only to branch A
        $userA = User::factory()->create([
            'username' => 'branch_a_user',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::where('code', 'SALES_MGR')->firstOrFail();
        $userA->roles()->attach($role);

        $this->actingAs($userA);

        // Requesting both docs -> user only sees docA, docB from branch B is hidden
        $response = $this->get(route('documents.delivery-notes.batch', [
            'ids' => [$docA->id, $docB->id],
        ]));

        $response->assertOk();
        $response->assertSee('BK-BRANCH-A');
        $response->assertDontSee('BK-BRANCH-B');

        // Requesting branch B directly -> 403 Forbidden
        $this->get(route('documents.delivery-notes.batch', [
            'branch_id' => $this->branchB->id,
            'date' => '2026-11-11',
        ]))->assertForbidden();
    }

    public function test_batch_delivery_notes_requires_sales_manage_permission(): void
    {
        $booking = $this->createDoc('BOOKING', 'BK-NO-PERM', $this->branchA, $this->customerA);

        // Cashier user without sales.manage
        $cashierRole = Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์']);
        $cashierUser = User::factory()->create([
            'username' => 'cashier_only',
            'branch_id' => $this->branchA->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $cashierUser->roles()->attach($cashierRole);

        $this->actingAs($cashierUser);

        $this->get(route('documents.delivery-notes.batch', [
            'ids' => [$booking->id],
        ]))->assertForbidden();
    }

    public function test_batch_delivery_notes_filter_by_date_and_type(): void
    {
        $todayBooking = $this->createDoc('BOOKING', 'BK-TODAY', $this->branchA, $this->customerA, 'active', true, '2026-11-11');
        $yesterdayBooking = $this->createDoc('BOOKING', 'BK-YEST', $this->branchA, $this->customerA, 'active', true, '2026-11-10');
        $todaySale = $this->createDoc('CASH_SALE', 'CS-TODAY', $this->branchA, $this->customerA, 'active', true, '2026-11-11');

        $this->actingAs($this->authorizedUser);

        // Filter type=booking on 2026-11-11
        $response = $this->get(route('documents.delivery-notes.batch', [
            'date' => '2026-11-11',
            'type' => 'booking',
            'branch_id' => $this->branchA->id,
        ]));

        $response->assertOk();
        $response->assertSee('BK-TODAY');
        $response->assertDontSee('BK-YEST');
        $response->assertDontSee('CS-TODAY');
    }

    public function test_query_count_does_not_grow_with_document_count(): void
    {
        $this->actingAs($this->authorizedUser);

        // Create 5 documents
        $ids5 = [];
        for ($i = 1; $i <= 5; $i++) {
            $doc = $this->createDoc('BOOKING', sprintf('BK-Q5-%02d', $i), $this->branchA, $this->customerA);
            $ids5[] = $doc->id;
        }

        // Create 45 more documents (total 50)
        $ids50 = $ids5;
        for ($i = 6; $i <= 50; $i++) {
            $doc = $this->createDoc('BOOKING', sprintf('BK-Q50-%02d', $i), $this->branchA, $this->customerA);
            $ids50[] = $doc->id;
        }

        // Warm up session, auth, permissions, and app settings cache
        $this->get(route('documents.delivery-notes.batch', ['ids' => [$ids5[0]]]))->assertOk();

        // Measure query count for 5 documents
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res5 = $this->get(route('documents.delivery-notes.batch', ['ids' => $ids5]));
        $res5->assertOk();
        $queryCount5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Measure query count for 50 documents
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res50 = $this->get(route('documents.delivery-notes.batch', ['ids' => $ids50]));
        $res50->assertOk();
        $queryCount50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $diff = abs($queryCount50 - $queryCount5);

        $this->assertLessThanOrEqual(
            3,
            $diff,
            "Query count difference between 5 docs ({$queryCount5} queries) and 50 docs ({$queryCount50} queries) must be <= 3, got {$diff}"
        );
    }

    public function test_single_delivery_note_remains_unchanged(): void
    {
        $booking = $this->createDoc('BOOKING', 'BK-SINGLE-01', $this->branchA, $this->customerA);

        $this->actingAs($this->authorizedUser);

        $response = $this->get(route('documents.delivery-note', $booking));
        $response->assertOk();
        $response->assertSee('BK-SINGLE-01');
        $response->assertSee('สำหรับพนักงาน (นำกลับ)');
        $response->assertSee('สำหรับลูกค้า');
        $response->assertSee('ไก่สดตัดแต่ง');
    }
    public function test_summary_button_prints_the_same_bookings_the_summary_page_shows(): void
    {
        $pending = $this->createDoc('BOOKING', 'BK-PENDING', $this->branchA, $this->customerA);
        $converted = $this->createDoc('BOOKING', 'BK-CONVERTED', $this->branchA, $this->customerA);
        SaleBooking::where('document_id', $converted->id)->update(['status' => SaleBooking::STATUS_CONVERTED]);
        // สายขายของใบจองต่างจากสายขายบนเอกสาร: หน้าใบรวมจองยึดสายของใบจอง
        $otherArea = SalesArea::create(['code' => 'A-OTHER', 'name' => 'สายอื่น']);
        $otherAreaBooking = $this->createDoc('BOOKING', 'BK-OTHERAREA', $this->branchA, $this->customerA);
        SaleBooking::where('document_id', $otherAreaBooking->id)->update(['sales_area_id' => $otherArea->id]);

        $this->actingAs($this->authorizedUser);
        $params = ['date' => '2026-11-11', 'branch_id' => $this->branchA->id, 'sales_area_id' => $this->salesArea->id];

        $this->get(route('bookings.summary', $params))->assertOk()
            ->assertSee('BK-PENDING')->assertDontSee('BK-CONVERTED')->assertDontSee('BK-OTHERAREA');
        $this->get(route('documents.delivery-notes.batch', $params + ['type' => 'booking']))->assertOk()
            ->assertSee('BK-PENDING')->assertDontSee('BK-CONVERTED')->assertDontSee('BK-OTHERAREA');
    }

    public function test_ids_of_other_document_types_are_not_printed_as_delivery_notes(): void
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        $purchase = $this->createDoc('PURCHASE', 'PO-NOT-A-DELIVERY', $this->branchA, $this->customerA);
        $sale = $this->createDoc('CASH_SALE', 'CS-OK', $this->branchA, $this->customerA);

        $this->actingAs($this->authorizedUser);
        $this->get(route('documents.delivery-notes.batch', ['ids' => [$purchase->id, $sale->id]]))->assertOk()
            ->assertSee('CS-OK')->assertDontSee('PO-NOT-A-DELIVERY');
    }
}
