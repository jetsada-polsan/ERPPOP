<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\BillingNote;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\OcrDocument;
use App\Models\Permission;
use App\Models\PosOfflineSale;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\ListDateRange;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListDefaultTodayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'B001',
            'name_th' => 'สาขาหลัก',
            'is_active' => true,
        ]);

        $permAdmin = Permission::firstOrCreate(['code' => 'system.admin'], ['name' => 'Admin']);
        $permFinance = Permission::firstOrCreate(['code' => 'finance.manage'], ['name' => 'Finance']);
        $permPurchase = Permission::firstOrCreate(['code' => 'purchasing.manage'], ['name' => 'Purchasing']);
        $permSales = Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'Sales']);

        $role = Role::firstOrCreate(['code' => 'ADMIN'], ['name' => 'Admin']);
        $role->permissions()->syncWithoutDetaching([
            $permAdmin->id,
            $permFinance->id,
            $permPurchase->id,
            $permSales->id,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin_test_today',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->admin->roles()->syncWithoutDetaching([$role->id]);
    }

    public function test_list_date_range_helper_rules(): void
    {
        // 1. Default (no params) -> today..today
        $r1 = ListDateRange::fromRequest(new Request);
        $today = now()->toDateString();
        $this->assertEquals($today, $r1->from);
        $this->assertEquals($today, $r1->to);
        $this->assertTrue($r1->isDefault);
        $this->assertFalse($r1->isUnrestricted);
        $this->assertNull($r1->warning);

        // 2. Search q without dates -> unrestricted
        $r2 = ListDateRange::fromRequest(new Request(['q' => 'DOC123']));
        $this->assertNull($r2->from);
        $this->assertNull($r2->to);
        $this->assertTrue($r2->isUnrestricted);
        $this->assertFalse($r2->isDefault);
        $this->assertEquals('ทั้งหมด (ค้นหา)', $r2->label());

        // 3. Search q WITH explicit dates -> respects date range
        $r3 = ListDateRange::fromRequest(new Request(['q' => 'DOC123', 'date_from' => '2026-11-01', 'date_to' => '2026-11-05']));
        $this->assertEquals('2026-11-01', $r3->from);
        $this->assertEquals('2026-11-05', $r3->to);
        $this->assertFalse($r3->isUnrestricted);

        // 4. Swap to < from
        $r4 = ListDateRange::fromRequest(new Request(['date_from' => '2026-11-10', 'date_to' => '2026-11-02']));
        $this->assertEquals('2026-11-02', $r4->from);
        $this->assertEquals('2026-11-10', $r4->to);

        // 5. Trim range exceeding 92 days to 92 days backwards from 'to'
        $r5 = ListDateRange::fromRequest(new Request(['date_from' => '2026-01-01', 'date_to' => '2026-11-01']));
        $expectedFrom = Carbon::parse('2026-11-01')->subDays(92)->toDateString();
        $this->assertEquals($expectedFrom, $r5->from);
        $this->assertEquals('2026-11-01', $r5->to);
        $this->assertNotNull($r5->warning);
        $this->assertStringContainsString('92 วัน', $r5->warning);

        // 6. ArrayAccess / destructuring
        ['from' => $f, 'to' => $t, 'isDefault' => $d] = $r1;
        $this->assertEquals($today, $f);
        $this->assertEquals($today, $t);
        $this->assertTrue($d);
    }

    public function test_booking_index_defaults_to_today_and_counts_scoped(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $bookingType = DocumentType::firstOrCreate(
            ['code' => 'BOOKING'],
            ['name_th' => 'ใบจองสินค้า', 'stock_effect' => 'none']
        );
        $customer = Customer::create(['code' => 'C-BK', 'name_th' => 'ลูกค้า BK', 'branch_id' => $this->branch->id, 'is_active' => true]);

        $docYesterday = Document::create([
            'branch_id' => $this->branch->id,
            'document_type_id' => $bookingType->id,
            'customer_id' => $customer->id,
            'doc_number' => 'BK-YESTERDAY-01',
            'doc_date' => $yesterday,
            'status' => 'active',
        ]);
        SaleBooking::create([
            'document_id' => $docYesterday->id,
            'status' => 'pending',
        ]);

        $docToday = Document::create([
            'branch_id' => $this->branch->id,
            'document_type_id' => $bookingType->id,
            'customer_id' => $customer->id,
            'doc_number' => 'BK-TODAY-01',
            'doc_date' => $today,
            'status' => 'active',
        ]);
        SaleBooking::create([
            'document_id' => $docToday->id,
            'status' => 'pending',
        ]);

        // 1. Open without date -> sees today only, status count = 1
        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('bookings.index'));
        $res->assertOk();
        $res->assertSee('BK-TODAY-01');
        $res->assertDontSee('BK-YESTERDAY-01');
        $this->assertEquals(1, $res->viewData('counts')['all']);

        // 2. Filter date range for yesterday
        $resRange = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('bookings.index', [
            'date_from' => $yesterday,
            'date_to' => $yesterday,
        ]));
        $resRange->assertOk();
        $resRange->assertSee('BK-YESTERDAY-01');
        $resRange->assertDontSee('BK-TODAY-01');
        $this->assertEquals(1, $resRange->viewData('counts')['all']);

        // 3. Search q without date -> finds yesterday doc across all dates
        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('bookings.index', [
            'q' => 'BK-YESTERDAY',
        ]));
        $resQ->assertOk();
        $resQ->assertSee('BK-YESTERDAY-01');

        // 4. Over 200 days range trimmed to 92 days with warning message on page
        $res200 = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('bookings.index', [
            'date_from' => now()->subDays(200)->toDateString(),
            'date_to' => $today,
        ]));
        $res200->assertOk();
        $res200->assertSee('ช่วงวันที่ถูกจำกัดไม่เกิน 92 วัน');
    }

    public function test_cash_sale_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'CS-YEST', 'doc_date' => $yesterday, 'status' => 'active']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'CS-TODAY', 'doc_date' => $today, 'status' => 'active']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('cash-sales.index'));
        $res->assertOk();
        $res->assertSee('CS-TODAY');
        $res->assertDontSee('CS-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('cash-sales.index', ['q' => 'CS-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('CS-YEST');
    }

    public function test_sale_return_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'SALE_RETURN'], ['name_th' => 'ใบรับคืน', 'stock_effect' => 'in']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SR-YEST', 'doc_date' => $yesterday, 'status' => 'active']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SR-TODAY', 'doc_date' => $today, 'status' => 'active']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('sale-returns.index'));
        $res->assertOk();
        $res->assertSee('SR-TODAY');
        $res->assertDontSee('SR-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('sale-returns.index', ['q' => 'SR-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('SR-YEST');
    }

    public function test_quotation_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        Quotation::create(['doc_number' => 'QT-YEST', 'doc_date' => $yesterday, 'customer_name' => 'Old Cust', 'branch_id' => $this->branch->id, 'status' => 'open']);
        Quotation::create(['doc_number' => 'QT-TODAY', 'doc_date' => $today, 'customer_name' => 'New Cust', 'branch_id' => $this->branch->id, 'status' => 'open']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('quotations.index'));
        $res->assertOk();
        $res->assertSee('QT-TODAY');
        $res->assertDontSee('QT-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('quotations.index', ['q' => 'QT-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('QT-YEST');
    }

    public function test_billing_note_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $cust = Customer::create(['code' => 'C-BN', 'name_th' => 'ลูกค้า BN', 'branch_id' => $this->branch->id, 'is_active' => true]);

        BillingNote::create(['doc_number' => 'BN-YEST', 'doc_date' => $yesterday, 'customer_id' => $cust->id, 'branch_id' => $this->branch->id, 'status' => 'open']);
        BillingNote::create(['doc_number' => 'BN-TODAY', 'doc_date' => $today, 'customer_id' => $cust->id, 'branch_id' => $this->branch->id, 'status' => 'open']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('billing-notes.index'));
        $res->assertOk();
        $res->assertSee('BN-TODAY');
        $res->assertDontSee('BN-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('billing-notes.index', ['q' => 'BN-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('BN-YEST');
    }

    public function test_credit_debit_note_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_NOTE'], ['name_th' => 'ใบลดหนี้', 'stock_effect' => 'none']);
        $cust = Customer::create(['code' => 'C-CD', 'name_th' => 'ลูกค้า CD', 'branch_id' => $this->branch->id, 'is_active' => true]);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'customer_id' => $cust->id, 'doc_number' => 'CN-YEST', 'doc_date' => $yesterday, 'status' => 'active']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'customer_id' => $cust->id, 'doc_number' => 'CN-TODAY', 'doc_date' => $today, 'status' => 'active']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('credit-debit-notes.index', ['type' => 'credit']));
        $res->assertOk();
        $res->assertSee('CN-TODAY');
        $res->assertDontSee('CN-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('credit-debit-notes.index', ['type' => 'credit', 'q' => 'CN-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('CN-YEST');
    }

    public function test_ar_receipt_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน', 'stock_effect' => 'none']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'RC-YEST', 'doc_date' => $yesterday, 'status' => 'active', 'total_amount' => 100]);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'RC-TODAY', 'doc_date' => $today, 'status' => 'active', 'total_amount' => 200]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('ar-receipts.index'));
        $res->assertOk();
        $res->assertSee('RC-TODAY');
        $res->assertDontSee('RC-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('ar-receipts.index', ['q' => 'RC-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('RC-YEST');
    }

    public function test_ap_payment_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'PAYMENT_VOUCHER'], ['name_th' => 'ใบจ่ายชำระ', 'stock_effect' => 'none']);
        $sup = Supplier::create(['code' => 'SUP-AP', 'name_th' => 'ซัพพลายเออร์ AP', 'branch_id' => $this->branch->id, 'is_active' => true]);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'supplier_id' => $sup->id, 'doc_number' => 'PV-YEST', 'doc_date' => $yesterday, 'status' => 'active', 'total_amount' => 300]);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'supplier_id' => $sup->id, 'doc_number' => 'PV-TODAY', 'doc_date' => $today, 'status' => 'active', 'total_amount' => 400]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('ap-payments.index'));
        $res->assertOk();
        $res->assertSee('PV-TODAY');
        $res->assertDontSee('PV-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('ap-payments.index', ['q' => 'PV-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('PV-YEST');
    }

    public function test_purchase_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        $sup = Supplier::create(['code' => 'SUP-PC', 'name_th' => 'ซัพพลายเออร์ PC', 'branch_id' => $this->branch->id, 'is_active' => true]);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'supplier_id' => $sup->id, 'doc_number' => 'PC-YEST', 'doc_date' => $yesterday, 'status' => 'active', 'total_amount' => 500]);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'supplier_id' => $sup->id, 'doc_number' => 'PC-TODAY', 'doc_date' => $today, 'status' => 'active', 'total_amount' => 600]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('purchases.index'));
        $res->assertOk();
        $res->assertSee('PC-TODAY');
        $res->assertDontSee('PC-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('purchases.index', ['q' => 'PC-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('PC-YEST');
    }

    public function test_purchase_order_index_defaults_to_today_and_scopes_status_counts(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $sup = Supplier::create(['code' => 'SUP-PO', 'name_th' => 'ซัพพลายเออร์ PO', 'branch_id' => $this->branch->id, 'is_active' => true]);

        PurchaseOrder::create(['branch_id' => $this->branch->id, 'supplier_id' => $sup->id, 'doc_number' => 'PO-YEST', 'doc_date' => $yesterday, 'status' => 'requested']);
        PurchaseOrder::create(['branch_id' => $this->branch->id, 'supplier_id' => $sup->id, 'doc_number' => 'PO-TODAY', 'doc_date' => $today, 'status' => 'requested']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('purchase-orders.index'));
        $res->assertOk();
        $res->assertSee('PO-TODAY');
        $res->assertDontSee('PO-YEST');
        $this->assertEquals(1, $res->viewData('statusCounts')['requested'] ?? 0);

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('purchase-orders.index', ['q' => 'PO-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('PO-YEST');
    }

    public function test_supplier_note_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'SUPPLIER_CREDIT_NOTE'], ['name_th' => 'ใบลดหนี้ผู้ขาย', 'stock_effect' => 'none']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SCN-YEST', 'doc_date' => $yesterday, 'status' => 'active', 'total_amount' => 100]);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SCN-TODAY', 'doc_date' => $today, 'status' => 'active', 'total_amount' => 100]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('supplier-notes.index'));
        $res->assertOk();
        $res->assertSee('SCN-TODAY');
        $res->assertDontSee('SCN-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('supplier-notes.index', ['q' => 'SCN-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('SCN-YEST');
    }

    public function test_stock_transfer_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFER'], ['name_th' => 'โอนย้ายสต็อก', 'stock_effect' => 'transfer']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'ST-YEST', 'doc_date' => $yesterday, 'status' => 'completed']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'ST-TODAY', 'doc_date' => $today, 'status' => 'completed']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-transfers.index'));
        $res->assertOk();
        $res->assertSee('ST-TODAY');
        $res->assertDontSee('ST-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-transfers.index', ['q' => 'ST-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('ST-YEST');
    }

    public function test_stock_adjustment_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'STOCK_ADJUSTMENT'], ['name_th' => 'ปรับปรุงสต็อก', 'stock_effect' => 'adjust']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SA-YEST', 'doc_date' => $yesterday, 'status' => 'completed']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SA-TODAY', 'doc_date' => $today, 'status' => 'completed']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-adjustments.index'));
        $res->assertOk();
        $res->assertSee('SA-TODAY');
        $res->assertDontSee('SA-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-adjustments.index', ['q' => 'SA-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('SA-YEST');
    }

    public function test_stock_issue_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'STOCK_REQUISITION'], ['name_th' => 'ใบเบิกสินค้า', 'stock_effect' => 'out']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SI-YEST', 'doc_date' => $yesterday, 'status' => 'active']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'SI-TODAY', 'doc_date' => $today, 'status' => 'active']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-issues.index', ['type' => 'requisition']));
        $res->assertOk();
        $res->assertSee('SI-TODAY');
        $res->assertDontSee('SI-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-issues.index', ['type' => 'requisition', 'q' => 'SI-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('SI-YEST');
    }

    public function test_stock_transform_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'STOCK_TRANSFORM'], ['name_th' => 'แปรรูปสินค้า', 'stock_effect' => 'adjust']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'STF-YEST', 'doc_date' => $yesterday, 'status' => 'active']);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'STF-TODAY', 'doc_date' => $today, 'status' => 'active']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-transforms.index'));
        $res->assertOk();
        $res->assertSee('STF-TODAY');
        $res->assertDontSee('STF-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-transforms.index', ['q' => 'STF-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('STF-YEST');
    }

    public function test_stock_count_index_defaults_to_today_and_searches_q(): void
    {
        $wh = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH1', 'name' => 'คลังหลัก', 'is_active' => true]);
        $loc = WarehouseLocation::create(['warehouse_id' => $wh->id, 'code' => 'L1', 'name' => 'ล็อกเกอร์ 1', 'is_active' => true]);

        $scYest = new StockCount([
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $loc->id,
            'doc_number' => 'SC-YEST',
            'count_mode' => 'partial',
            'status' => 'draft',
        ]);
        $scYest->created_at = now()->subDay()->setTime(10, 0);
        $scYest->save();

        $scToday = new StockCount([
            'branch_id' => $this->branch->id,
            'warehouse_location_id' => $loc->id,
            'doc_number' => 'SC-TODAY',
            'count_mode' => 'partial',
            'status' => 'draft',
        ]);
        $scToday->created_at = now()->setTime(10, 0);
        $scToday->save();

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-counts.index'));
        $res->assertOk();
        $res->assertSee('SC-TODAY');
        $res->assertDontSee('SC-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('stock-counts.index', ['q' => 'SC-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('SC-YEST');
    }

    public function test_cash_transfer_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $type = DocumentType::firstOrCreate(['code' => 'CASH_DEPOSIT'], ['name_th' => 'ฝากเงินสด', 'stock_effect' => 'none']);

        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'CT-YEST', 'doc_date' => $yesterday, 'status' => 'active', 'total_amount' => 500]);
        Document::create(['branch_id' => $this->branch->id, 'document_type_id' => $type->id, 'doc_number' => 'CT-TODAY', 'doc_date' => $today, 'status' => 'active', 'total_amount' => 600]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('cash-transfers.index'));
        $res->assertOk();
        $res->assertSee('CT-TODAY');
        $res->assertDontSee('CT-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('cash-transfers.index', ['q' => 'CT-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('CT-YEST');
    }

    public function test_gl_journal_index_defaults_to_today_and_scopes_debit_credit_totals(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $account = ChartOfAccount::create(['code' => '1111', 'name_th' => 'เงินสด', 'account_type' => 'asset']);

        GlJournal::create(['account_id' => $account->id, 'entry_date' => $yesterday, 'debit' => 100, 'credit' => 100, 'remark' => 'GL-YEST']);
        GlJournal::create(['account_id' => $account->id, 'entry_date' => $today, 'debit' => 50, 'credit' => 50, 'remark' => 'GL-TODAY']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('gl-journals.index'));
        $res->assertOk();
        $res->assertSee('GL-TODAY');
        $res->assertDontSee('GL-YEST');
        // Total debit and credit must be scoped to today's 50 (not 150)
        $this->assertEquals(50.0, (float) $res->viewData('totalDebit'));
        $this->assertEquals(50.0, (float) $res->viewData('totalCredit'));

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('gl-journals.index', ['q' => 'GL-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('GL-YEST');
    }

    public function test_pos_offline_sale_index_defaults_to_today_and_scopes_counts(): void
    {
        PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'sold_at' => now()->subDay()->setTime(10, 0),
            'status' => 'needs_review',
            'offline_total' => 100,
            'payload' => [],
        ]);

        PosOfflineSale::create([
            'offline_uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'sold_at' => now()->setTime(10, 0),
            'status' => 'needs_review',
            'offline_total' => 200,
            'payload' => [],
        ]);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('pos.offline-sales.index', ['status' => 'all']));
        $res->assertOk();
        $this->assertCount(1, $res->viewData('sales'));
        $this->assertEquals(1, $res->viewData('counts')['needs_review'] ?? 0);
    }

    public function test_ocr_document_index_defaults_to_today_and_scopes_counts(): void
    {
        $ocrYest = new OcrDocument([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'document_type' => 'supplier_delivery_note',
            'file_mime_type' => 'application/pdf',
            'original_file_path' => 'ocr/test1.pdf',
            'original_file_name' => 'test-yesterday.pdf',
            'original_file_sha256' => Str::random(64),
            'status' => 'uploaded',
        ]);
        $ocrYest->created_at = now()->subDay()->setTime(10, 0);
        $ocrYest->save();

        $ocrToday = new OcrDocument([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'document_type' => 'supplier_delivery_note',
            'file_mime_type' => 'application/pdf',
            'original_file_path' => 'ocr/test2.pdf',
            'original_file_name' => 'test-today.pdf',
            'original_file_sha256' => Str::random(64),
            'status' => 'uploaded',
        ]);
        $ocrToday->created_at = now()->setTime(10, 0);
        $ocrToday->save();

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('ocr.documents.index'));
        $res->assertOk();
        $res->assertSee('test-today.pdf');
        $res->assertDontSee('test-yesterday.pdf');
        $this->assertEquals(1, $res->viewData('statusCounts')['uploaded'] ?? 0);
    }

    public function test_production_index_defaults_to_today_and_searches_q(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $unit = ProductUnit::firstOrCreate(['code' => 'BASE'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $prod = Product::create([
            'sku_code' => 'SKU-PRD',
            'name_th' => 'สินค้าผลิต',
            'base_unit_id' => $unit->id,
            'default_price' => 100,
            'average_cost' => 50,
            'is_vat' => false,
            'negative_stock_policy' => 'allow',
        ]);

        ProductionOrder::create(['doc_no' => 'PRD-YEST', 'doc_date' => $yesterday, 'finished_product_id' => $prod->id, 'planned_qty' => 5, 'status' => 'draft']);
        ProductionOrder::create(['doc_no' => 'PRD-TODAY', 'doc_date' => $today, 'finished_product_id' => $prod->id, 'planned_qty' => 10, 'status' => 'draft']);

        $res = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('production.index'));
        $res->assertOk();
        $res->assertSee('PRD-TODAY');
        $res->assertDontSee('PRD-YEST');

        $resQ = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($this->admin)->get(route('production.index', ['q' => 'PRD-YEST']));
        $resQ->assertOk();
        $resQ->assertSee('PRD-YEST');
    }
}
