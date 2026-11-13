<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\SupplierOpenItem;
use App\Models\TransportJob;
use App\Models\TransportLoadItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierNoteService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * เกณฑ์ความสำเร็จตาม CLAUDE_LEGACY_REBUILD_BRIEF.md:
 * "เอกสารหนึ่งใบไหลจากต้นทางถึง stock, revenue, debtor/cash, cost, GL และรายงาน
 *  ได้หนึ่งครั้งอย่างถูกต้อง"
 *
 * เทสต์ชุดนี้ไล่ทั้งเส้นจริงตั้งแต่ซื้อของเข้ามาจนถึงยอดในรายงาน ไม่ใช่ทดสอบทีละชิ้น
 * เพราะข้อผิดพลาดที่แพงที่สุดของ ERP อยู่ที่รอยต่อระหว่างโมดูล ไม่ใช่ในโมดูล
 */
class SalesDocumentEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_notes_adjust_ap_and_gl_only_after_approval(): void
    {
        $this->stockedBranch('APNOTE', 10, 60);
        $item = SupplierOpenItem::sole();
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $role = Role::create(['code' => 'AP_NOTE', 'name' => 'AP']);
        foreach (['purchasing.manage', 'finance.note.approve'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code]));
        }
        $maker->roles()->attach($role);
        $checker->roles()->attach($role);
        $service = app(SupplierNoteService::class);
        foreach (['credit' => 493.0, 'debit' => 600.0] as $kind => $expected) {
            $doc = $service->create([
                'supplier_open_item_id' => $item->id, 'kind' => $kind,
                'account_id' => ChartOfAccount::where('default_role', ChartOfAccount::ROLE_COGS)->sole()->id,
                'base_amount' => 100, 'vat_amount' => 7, 'reason' => 'Supplier adjustment',
            ], $maker);
            $before = $item->fresh()->balance_amount;
            $this->assertDatabaseMissing('gl_journals', ['document_id' => $doc->id]);
            try {
                $service->approve($doc, $maker);
                $this->fail('Self approval must fail');
            } catch (\RuntimeException $e) {
                $this->assertSame($before, $item->fresh()->balance_amount);
            }
            $service->approve($doc, $checker);
            $this->assertSame($expected, (float) $item->fresh()->balance_amount);
            $this->assertGlBalanced($doc);
            $this->assertGlHas($doc, ChartOfAccount::ROLE_AP, debit: $kind === 'credit' ? 107 : 0, credit: $kind === 'debit' ? 107 : 0);
            try {
                $service->approve($doc, $checker);
                $this->fail('Duplicate approval must fail');
            } catch (\RuntimeException $e) {
                $this->assertSame($expected, (float) $item->fresh()->balance_amount);
            }
        }
    }

    public function test_a_fully_reserved_booking_can_consume_its_own_stock(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('RES', 3, 60, true);
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'on_hand_qty' => 0, 'reserved_qty' => 0]);
        $this->assertGlBalanced($sale);
    }

    public function test_return_waits_for_another_authorized_user_and_posts_exactly_once(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('RET', 10, 60, true);
        DocumentType::firstOrCreate(['code' => 'SALE_RETURN'], ['name_th' => 'Return']);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $role = Role::create(['code' => 'RETURN_APPROVER', 'name' => 'Return approver']);
        $permission = Permission::firstOrCreate(['code' => 'finance.note.approve'], ['name' => 'Approve']);
        $role->permissions()->attach($permission);
        $maker->roles()->attach($role);
        $checker->roles()->attach($role);
        $this->actingAs($maker);
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
        $service = app(SaleReturnService::class);
        $return = $service->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_open_item_id' => $sale->openItem->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);
        $this->assertSame('pending_approval', $return->status);
        $this->assertSame($maker->id, $return->created_by);
        $this->assertSame($sale->doc_number, $return->reference);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'on_hand_qty' => 7]);
        $this->assertSame(300.0, (float) $sale->openItem->fresh()->balance_amount);
        $this->assertDatabaseMissing('gl_journals', ['document_id' => $return->id]);
        try {
            $service->approve($return, $maker->id);
            $this->fail('Maker must not approve their own return');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ผู้สร้าง', $e->getMessage());
        }
        $this->actingAs($checker);
        $service->approve($return, $checker->id);
        $this->assertDatabaseHas('stock_balances', ['product_id' => $product->id, 'on_hand_qty' => 8]);
        $this->assertSame(200.0, (float) $sale->openItem->fresh()->balance_amount);
        $this->assertGlBalanced($return);
        $this->assertGlHas($return, ChartOfAccount::ROLE_AR, credit: 100);
        $this->assertDatabaseHas('customer_ledger', ['document_id' => $return->id, 'entry_type' => 'credit', 'amount' => 100]);
        try {
            $service->approve($return, $checker->id);
            $this->fail('Duplicate approval must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(200.0, (float) $sale->openItem->fresh()->balance_amount);
        }
    }

    public function test_a_back_office_cash_sale_reaches_stock_cost_gl_and_the_report(): void
    {
        [$branch, $location, $product] = $this->stockedBranch('E2E1', qty: 10, unitCost: 60);

        $document = app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]],
        ]);

        // 1. สต๊อกลดจริง
        $balance = StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(8.0, (float) $balance->on_hand_qty);

        // 2. ต้นทุนถูกบันทึกลงบรรทัดเอกสาร ไม่ใช่คำนวณทีหลัง
        $line = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.document_id', $document->id)->sole();
        $this->assertSame(60.0, (float) $line->unit_cost);
        $this->assertSame(120.0, (float) $line->cost_amount);

        // 3. GL ลงครบและดุล
        $this->assertGlBalanced($document);
        $this->assertGlHas($document, ChartOfAccount::ROLE_CASH, debit: 200.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_SALES_REVENUE, credit: 200.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_COGS, debit: 120.0);
        $this->assertGlHas($document, ChartOfAccount::ROLE_INVENTORY, credit: 120.0);

        // 4. ปรากฏใน sales_postings ครั้งเดียว
        $postings = DB::table('sales_postings')->where('document_id', $document->id)->get();
        $this->assertCount(1, $postings);
        $this->assertSame('CASH_SALE', $postings[0]->channel);
        $this->assertSame(200.0, (float) $postings[0]->net_sales);
        $this->assertSame(120.0, (float) $postings[0]->cogs_amount);
        $this->assertSame(80.0, (float) $postings[0]->gross_profit);
    }

    public function test_a_booking_converted_to_a_credit_sale_opens_a_receivable_and_posts_to_ar(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('E2E2', qty: 10, unitCost: 60, withCustomer: true);

        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);

        // ใบจองต้องจองของ ไม่ตัดของ
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(10.0, (float) $balance->on_hand_qty, 'ใบจองต้องไม่ตัดสต๊อก');
        $this->assertSame(3.0, (float) $balance->reserved_qty, 'ใบจองต้องจองสต๊อก');
        $this->assertSame(0, DB::table('sales_postings')->where('document_id', $bookingDocument->id)->count(),
            'ใบจองต้องไม่ถูกนับเป็นยอดขาย');

        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $saleDocument = app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        // ยืนยันแล้วจึงตัดของ
        $balance = $balance->fresh();
        $this->assertSame(7.0, (float) $balance->on_hand_qty);
        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
        $this->assertSame($saleDocument->id, (int) $booking->fresh()->confirmed_document_id);

        // เปิดลูกหนี้พร้อมวันครบกำหนดชำระ
        $openItem = CustomerOpenItem::where('document_id', $saleDocument->id)->sole();
        $this->assertSame(300.0, (float) $openItem->balance_amount);
        $this->assertNotNull($openItem->due_date, 'ลูกหนี้ต้องมีวันครบกำหนดชำระ');

        $this->assertGlBalanced($saleDocument);
        $this->assertGlHas($saleDocument, ChartOfAccount::ROLE_AR, debit: 300.0);

        $postings = DB::table('sales_postings')->where('document_id', $saleDocument->id)->get();
        $this->assertCount(1, $postings);
        $this->assertSame('CREDIT_SALE', $postings[0]->channel);
    }

    public function test_cancelling_a_pending_booking_releases_its_reservation(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('CANBK', qty: 10, unitCost: 60, withCustomer: true);
        $document = app(BookingService::class)->create(['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 100]]]);
        $booking = SaleBooking::where('document_id', $document->id)->sole();
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(4.0, (float) $balance->reserved_qty);

        app(BookingService::class)->cancel($booking, 'ลูกค้ายกเลิก', null);

        $this->assertSame(0.0, (float) $balance->fresh()->reserved_qty);
        $this->assertSame(SaleBooking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->expectException(\RuntimeException::class);
        app(BookingService::class)->cancel($booking->fresh(), null, null);
    }

    public function test_a_delivery_booking_can_be_billed_in_the_afternoon_before_delivery_finishes()
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('DELSALE', qty: 10, unitCost: 60, withCustomer: true);
        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'fulfillment_type' => SaleBooking::FULFILLMENT_DELIVERY,
            'delivery_due_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();

        app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
        $this->assertSame(7.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->on_hand_qty);
    }

    public function test_every_posted_document_keeps_the_ledger_balanced(): void
    {
        [$branch, , $product] = $this->stockedBranch('E2E3', qty: 20, unitCost: 60);

        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);
        app(CashSaleService::class)->create([
            'branch_id' => $branch->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 250]],
        ]);

        $unbalanced = DB::table('gl_journals')
            ->selectRaw('document_id, sum(debit) as total_debit, sum(credit) as total_credit')
            ->groupBy('document_id')
            ->havingRaw('round(sum(debit), 2) <> round(sum(credit), 2)')
            ->get();

        $this->assertCount(0, $unbalanced, 'มีเอกสารที่ debit ไม่เท่า credit: '.$unbalanced->pluck('document_id')->implode(', '));
        $this->assertGreaterThan(0, GlJournal::count(), 'ต้องมีรายการ GL เกิดขึ้นจริง ไม่ใช่ผ่านเพราะไม่มีข้อมูล');
    }

    private function assertGlBalanced(Document $document): void
    {
        $lines = GlJournal::where('document_id', $document->id)->get();
        $this->assertNotEmpty($lines, "เอกสาร {$document->doc_number} ไม่ได้ลง GL เลย");
        $this->assertSame(
            round((float) $lines->sum('debit'), 2),
            round((float) $lines->sum('credit'), 2),
            "GL ของ {$document->doc_number} ไม่ดุล",
        );
    }

    private function assertGlHas(Document $document, string $role, float $debit = 0, float $credit = 0): void
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');
        $this->assertNotNull($accountId, "ยังไม่ได้ผูกบัญชีสำหรับ role {$role}");
        $line = GlJournal::where('document_id', $document->id)->where('account_id', $accountId)->get();
        $this->assertNotEmpty($line, "ไม่พบรายการ GL ของ role {$role} ในเอกสาร {$document->doc_number}");
        $this->assertSame($debit, round((float) $line->sum('debit'), 2), "ยอด debit ของ {$role} ไม่ตรง");
        $this->assertSame($credit, round((float) $line->sum('credit'), 2), "ยอด credit ของ {$role} ไม่ตรง");
    }

    /** สาขาที่มีของในคลังจริงโดยซื้อเข้ามา ไม่ใช่ยัดตัวเลขลงตาราง */
    public function test_one_booking_with_vat_and_non_vat_goods_splits_into_ds_and_dsn_bills(): void
    {
        [$branch, , $plain, $customer] = $this->stockedBranch('SPLIT', qty: 10, unitCost: 60, withCustomer: true);
        $vatGoods = Product::create([
            'sku_code' => 'SKUVAT', 'name_th' => 'สินค้ามี VAT', 'base_unit_id' => $plain->base_unit_id,
            'default_price' => 50, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => Supplier::sole()->id, 'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $vatGoods->id, 'qty' => 10, 'unit_price' => 30]],
        ]);
        $typeId = DocumentType::where('code', 'CREDIT_SALE')->value('id');
        $ds = \App\Models\DocumentBook::create(['code' => 'DS', 'name' => 'DS', 'prefix' => 'DS', 'document_type_id' => $typeId, 'is_default' => true, 'is_active' => true, 'no_vat' => false]);
        $dsn = \App\Models\DocumentBook::create(['code' => 'DSN', 'name' => 'DSN', 'prefix' => 'DSN', 'document_type_id' => $typeId, 'is_default' => false, 'is_active' => true, 'no_vat' => true]);

        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $plain->id, 'qty' => 3, 'unit_price' => 100.25],
                ['product_id' => $vatGoods->id, 'qty' => 2, 'unit_price' => 50.10],
            ],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class)->actingAs(User::factory()->create())
            ->get(route('bookings.show', $booking))->assertOk()
            ->assertSee(route('bookings.bill', $booking), false)
            ->assertDontSee('name="document_book_id"', false);
        app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        $sales = Document::where('source_document_id', $bookingDocument->id)->orderBy('id')->get();
        $this->assertCount(2, $sales, 'ใบจองเดียวต้องแยกเป็น DS + DSN');
        $byBook = $sales->keyBy('document_book_id');
        $this->assertSame(100.2, (float) $byBook[$ds->id]->total_amount, 'DS เก็บเฉพาะสินค้ามี VAT');
        $this->assertSame(300.75, (float) $byBook[$dsn->id]->total_amount, 'DSN เก็บเฉพาะสินค้าไม่มี VAT');
        $this->assertSame(round((float) $bookingDocument->total_amount, 2), round($sales->sum(fn ($d) => (float) $d->total_amount), 2));
        $this->assertSame(2, CustomerOpenItem::whereIn('document_id', $sales->pluck('id'))->count());
        foreach ($sales as $sale) {
            $this->assertGlBalanced($sale);
        }

        // ใบส่งของของบิลที่มาจากใบจอง ต้องอ้างเลขใบจอง ไม่ใช่ DS/DSN (ส่งของก่อนคอนเฟิร์มบิล)
        $viewer = $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class)->actingAs(User::factory()->create());
        $dsnSale = $byBook[$dsn->id];
        $viewer->get(route('sales.show', $dsnSale))->assertOk()
            ->assertSee(route('documents.delivery-note', $bookingDocument), false)
            ->assertDontSee(route('documents.delivery-note', $dsnSale), false);

        // ใบเสร็จรับเงินต้องแสดงรายการสินค้าของบิลที่รับชำระ
        DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน']);
        $openItem = CustomerOpenItem::where('document_id', $dsnSale->id)->sole();
        $receipt = app(\App\Services\Sales\CustomerPaymentService::class)->create([
            'customer_id' => $customer->id, 'branch_id' => $branch->id, 'method' => 'cash',
            'allocations' => [['customer_open_item_id' => $openItem->id, 'amount' => 300.75]],
        ]);
        $viewer->get(route('payments.print', $receipt))->assertOk()
            ->assertSee($plain->name_th)
            ->assertSee($dsnSale->doc_number)
            ->assertDontSee($vatGoods->name_th);
    }

    public function test_staff_can_choose_ds_or_dsn_per_line_before_billing(): void
    {
        [$branch, , $plain, $customer] = $this->stockedBranch('PICK', qty: 10, unitCost: 60, withCustomer: true);
        $vatGoods = Product::create([
            'sku_code' => 'SKUVAT2', 'name_th' => 'สินค้ามี VAT', 'base_unit_id' => $plain->base_unit_id,
            'default_price' => 50, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => Supplier::sole()->id, 'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $vatGoods->id, 'qty' => 10, 'unit_price' => 30]],
        ]);
        $typeId = DocumentType::where('code', 'CREDIT_SALE')->value('id');
        \App\Models\DocumentBook::create(['code' => 'DS', 'name' => 'DS', 'prefix' => 'DS', 'document_type_id' => $typeId, 'is_default' => true, 'is_active' => true, 'no_vat' => false]);
        $dsn = \App\Models\DocumentBook::create(['code' => 'DSN', 'name' => 'DSN', 'prefix' => 'DSN', 'document_type_id' => $typeId, 'is_default' => false, 'is_active' => true, 'no_vat' => true]);

        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $plain->id, 'qty' => 3, 'unit_price' => 100.25],
                ['product_id' => $vatGoods->id, 'qty' => 2, 'unit_price' => 50.10],
            ],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $itemIds = $bookingDocument->stockDocument->items()->orderBy('id')->pluck('id')->all();

        // ส่งรายการไม่ครบ = ปฏิเสธ ไม่สร้างบิล
        try {
            app(CreditSaleService::class)->convertBookingToCreditSale($booking, null, [$itemIds[0] => 'DSN']);
            $this->fail('ต้องปฏิเสธเมื่อรายการไม่ครบ');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Document::where('source_document_id', $bookingDocument->id)->count());

        // พนักงานย้ายสินค้ามี VAT ไปลง DSN ด้วย → ได้บิล DSN ใบเดียว ยอดเท่าใบจอง ไม่มี VAT
        $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class)->actingAs(User::factory()->create())
            ->post(route('bookings.convert', $booking), ['lines' => [$itemIds[0] => 'DSN', $itemIds[1] => 'DSN']])
            ->assertRedirect();

        $sales = Document::where('source_document_id', $bookingDocument->id)->get();
        $this->assertCount(1, $sales);
        $this->assertSame($dsn->id, $sales->first()->document_book_id);
        $this->assertSame(round((float) $bookingDocument->total_amount, 2), round((float) $sales->first()->total_amount, 2));
        $this->assertGlBalanced($sales->first());
    }

    /** แบบ BPlus "อ่านจาก": แก้จำนวน/ราคาได้ ออกบิลไม่ครบได้ ส่วนที่เหลือค้างในใบจองและยังกันสต๊อก */
    public function test_editable_billing_bills_part_of_a_booking_and_keeps_the_rest_reserved(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('PART', qty: 10, unitCost: 60, withCustomer: true);
        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 100]],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $itemId = $bookingDocument->stockDocument->items()->value('id');
        $staff = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $web = $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class)->actingAs($staff);

        $web->get(route('bookings.bill', $booking))->assertOk()->assertSee('ออกใบขายจากใบจอง');

        // ราคาเช็กต่ำกว่าทุน: ตอบแค่ true/false ไม่มีตัวเลขทุน
        $check = $web->postJson(route('bookings.price-check', $booking), ['prices' => [$itemId => 50]])->assertOk();
        $this->assertTrue($check->json("below_cost.$itemId"));
        $this->assertStringNotContainsString('60', $check->getContent());

        // พนักงานทั่วไปบันทึกราคาต่ำกว่าทุนได้ แต่ยังไม่ออกใบขาย — ส่งให้ผู้จัดการอนุมัติ (เจ้าของ 13 พ.ย.)
        $web->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => ['lines' => [['booking_item_id' => $itemId, 'qty' => 2, 'unit_price' => 50]]]]])
            ->assertRedirect(route('bookings.show', $booking))->assertSessionHas('success');
        $this->assertSame(0, Document::where('source_document_id', $bookingDocument->id)->count());
        $this->assertSame('pending', $booking->fresh()->price_approval_status);
        $booking->update(['price_approval_status' => 'rejected']);

        // จำนวนเกินยอดค้างไม่ได้
        $web->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => ['lines' => [['booking_item_id' => $itemId, 'qty' => 6, 'unit_price' => 120]]]]])
            ->assertSessionHas('error');

        // ออก 2 จาก 5 ราคาที่เซลล์ต่อรองใหม่ 120 + เครดิต 15 วัน
        $web->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => [
            'credit_days' => 15, 'remark' => 'ลูกค้าขอรับก่อน 2',
            'lines' => [['booking_item_id' => $itemId, 'qty' => 2, 'unit_price' => 120]],
        ]]])->assertRedirect(route('bookings.show', $booking));

        $first = Document::where('source_document_id', $bookingDocument->id)->sole();
        $this->assertSame(240.0, (float) $first->total_amount);
        $this->assertSame('ลูกค้าขอรับก่อน 2', $first->remark);
        $this->assertSame(now()->addDays(15)->toDateString(), CustomerOpenItem::where('document_id', $first->id)->sole()->due_date->toDateString());
        $this->assertGlBalanced($first);
        $this->assertSame(SaleBooking::STATUS_PENDING, $booking->fresh()->status, 'ยังค้าง 3 ใบจองต้องยังเปิดอยู่');
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(8.0, (float) $balance->on_hand_qty);
        $this->assertSame(3.0, (float) $balance->reserved_qty, 'ส่วนค้างยังกันสต๊อก');

        // ใบจองที่ออกบิลไปบางส่วน ยกเลิก/แก้ไม่ได้
        $this->expectsCancelToFail($booking);

        // รอบสอง: ออกที่เหลือ 3 → ใบจองจบ
        $web->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => ['lines' => [['booking_item_id' => $itemId, 'qty' => 3, 'unit_price' => 100]]]]])
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
        $this->assertSame(0.0, (float) $balance->fresh()->reserved_qty);
        $this->assertSame(5.0, (float) $balance->fresh()->on_hand_qty);
        $this->assertSame(2, Document::where('source_document_id', $bookingDocument->id)->where('status', 'active')->count());

        // ผู้จัดการถอย Confirm: ยกเลิกทั้ง 2 บิล สต๊อกกลับมากันไว้ครบ 5
        app(BookingService::class)->revertConfirmation($booking->fresh(), 'ทดสอบ', $staff->id);
        $this->assertSame(SaleBooking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertSame(10.0, (float) $balance->fresh()->on_hand_qty);
        $this->assertSame(5.0, (float) $balance->fresh()->reserved_qty);
        $this->assertSame(0.0, (float) $bookingDocument->stockDocument->items()->value('billed_qty'));
    }

    public function test_a_manager_may_bill_below_cost_and_close_the_remainder(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('MGRB', qty: 10, unitCost: 60, withCustomer: true);
        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 4, 'unit_price' => 100]],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $itemId = $bookingDocument->stockDocument->items()->value('id');
        $manager = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $manager->roles()->attach(\App\Models\Role::where('code', 'GM')->value('id'));
        $web = $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class)->actingAs($manager);

        $web->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => ['lines' => [['booking_item_id' => $itemId, 'qty' => 1, 'unit_price' => 50]]]]])
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(50.0, (float) Document::where('source_document_id', $bookingDocument->id)->sole()->total_amount);

        $web->post(route('bookings.close-remainder', $booking))->assertRedirect(route('bookings.show', $booking));
        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(0.0, (float) $balance->reserved_qty, 'ปิดยอดค้างแล้วต้องคืนสต๊อกที่กัน');
        $this->assertSame(9.0, (float) $balance->on_hand_qty);
    }

    public function test_staff_below_cost_bill_waits_for_a_manager_who_approves_and_bills_it(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('APRV', qty: 10, unitCost: 60, withCustomer: true);
        $bookingDocument = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 100]],
        ]);
        $booking = SaleBooking::where('document_id', $bookingDocument->id)->sole();
        $itemId = $bookingDocument->stockDocument->items()->value('id');
        $staff = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $manager = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $manager->roles()->attach(\App\Models\Role::where('code', 'GM')->value('id'));
        $this->withoutMiddleware(\App\Http\Middleware\ErpAuthorize::class);

        // ฝ่ายขาย: ปีกบน 10 กก. ราคา 55 (ทุน 60) — บันทึกได้ เด้งไปรออนุมัติ
        $this->actingAs($staff)->get(route('bookings.bill', $booking))->assertOk()->assertSee('ส่งให้ผู้จัดการอนุมัติราคา');
        $this->actingAs($staff)->post(route('bookings.bill.store', $booking), ['bills' => ['DSN' => ['credit_days' => 7,
            'lines' => [['booking_item_id' => $itemId, 'qty' => 10, 'unit_price' => 55]]]]])
            ->assertRedirect(route('bookings.show', $booking));
        $this->assertSame('pending', $booking->fresh()->price_approval_status);
        $this->assertSame(0, Document::where('source_document_id', $bookingDocument->id)->count());
        $this->actingAs($staff)->post(route('bookings.price-approval', $booking), ['decision' => 'approve'])->assertForbidden();

        // ผู้จัดการเห็นในหน้าใบจองแล้วกดอนุมัติ → ออกใบขายตามราคาที่ขอ
        $this->actingAs($manager)->get(route('bookings.show', $booking))->assertOk()->assertSee('รอผู้จัดการอนุมัติ')->assertSee('อนุมัติ ออกใบขาย');
        $this->actingAs($manager)->post(route('bookings.price-approval', $booking), ['decision' => 'approve', 'note' => 'ลูกค้าประจำ'])
            ->assertRedirect(route('bookings.show', $booking))->assertSessionHas('success');

        $sale = Document::where('source_document_id', $bookingDocument->id)->sole();
        $this->assertSame(550.0, (float) $sale->total_amount);
        $this->assertGlBalanced($sale);
        $this->assertSame('approved', $booking->fresh()->price_approval_status);
        $this->assertSame($manager->id, (int) $booking->fresh()->price_approval_decided_by);
        $this->assertSame(SaleBooking::STATUS_CONVERTED, $booking->fresh()->status);
    }

    private function expectsCancelToFail(SaleBooking $booking): void
    {
        try {
            app(BookingService::class)->cancel($booking->fresh(), 'x', null);
            $this->fail('ใบจองที่ออกบิลบางส่วนต้องยกเลิกไม่ได้');
        } catch (\RuntimeException) {
        }
    }

    public function test_a_short_booking_needs_a_manager_to_confirm_in_erp_before_billing_and_may_go_negative(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('SHORT', qty: 2, unitCost: 60, withCustomer: true);
        $service = app(BookingService::class);
        $items = [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 100]];

        try {
            $service->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'items' => $items]);
            $this->fail('สต๊อกไม่พอและไม่ได้ขอ ต้องบันทึกไม่ได้');
        } catch (\RuntimeException) {
        }

        $doc = $service->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'items' => $items, 'allow_stock_shortage' => true]);
        $booking = SaleBooking::where('document_id', $doc->id)->sole();
        $this->assertSame('pending', $booking->stock_shortage_status);

        try {
            app(CreditSaleService::class)->convertBookingToCreditSale($booking);
            $this->fail('ยังไม่ได้ยืนยัน ห้ามออกใบขาย');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ผู้จัดการ', $e->getMessage());
        }

        $clerk = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $clerkRole = Role::create(['code' => 'SALES_T', 'name' => 'ขาย']);
        $clerkRole->permissions()->attach(Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'sales']));
        $clerk->roles()->attach($clerkRole);
        $this->actingAs($clerk)->post(route('bookings.approve-stock-shortage', $booking))->assertForbidden();

        $manager = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $managerRole = Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผจก.']);
        $managerRole->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'sales']));
        $manager->roles()->attach($managerRole);
        $this->actingAs($manager)->post(route('bookings.approve-stock-shortage', $booking))->assertRedirect();
        $this->assertSame('approved', $booking->fresh()->stock_shortage_status);

        app(CreditSaleService::class)->convertBookingToCreditSale($booking->fresh());
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(-3.0, (float) $balance->on_hand_qty, 'อนุมัติแล้วสต๊อกติดลบชั่วคราวได้');
    }

    public function test_a_manager_can_revert_a_confirmed_booking_back_to_a_reservation(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('REV', qty: 10, unitCost: 60, withCustomer: true);
        $doc = app(BookingService::class)->create(['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100]]]);
        $booking = SaleBooking::where('document_id', $doc->id)->sole();
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        $clerk = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $clerkRole = Role::create(['code' => 'SALES_R', 'name' => 'ขาย']);
        $clerkRole->permissions()->attach(Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'sales']));
        $clerk->roles()->attach($clerkRole);
        $this->actingAs($clerk)->post(route('bookings.revert-confirm', $booking), ['reason' => 'x'])->assertForbidden();

        $manager = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผจก.']);
        $role->permissions()->syncWithoutDetaching(Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'sales']));
        $manager->roles()->attach($role);
        $this->actingAs($manager)->post(route('bookings.revert-confirm', $booking), ['reason' => 'ลูกค้าเปลี่ยนรายการ'])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame(SaleBooking::STATUS_PENDING, $booking->fresh()->status);
        $balance = StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole();
        $this->assertSame(10.0, (float) $balance->on_hand_qty, 'ต้องคืนสต๊อกในมือ');
        $this->assertSame(3.0, (float) $balance->reserved_qty, 'ใบจองกลับมากันของไว้');
        $this->assertSame(0.0, (float) CustomerOpenItem::where('document_id', $sale->id)->sole()->balance_amount);
        foreach (GlJournal::where('document_id', $sale->id)->get()->groupBy('account_id') as $accountId => $rows) {
            $this->assertSame(0.0, round((float) $rows->sum('debit') - (float) $rows->sum('credit'), 2), "บัญชี {$accountId} ต้องกลับรายการจนเป็นศูนย์");
        }

        app(CreditSaleService::class)->convertBookingToCreditSale($booking->fresh());
        $this->assertSame(7.0, (float) $balance->fresh()->on_hand_qty, 'Confirm ใหม่ได้');
    }

    private function stockedBranch(string $suffix, float $qty, float $unitCost, bool $withCustomer = false): array
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'BOOKING'], ['name_th' => 'ใบจอง', 'stock_effect' => 'none']);

        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$suffix, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP'.$suffix, 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);

        app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'prices_include_vat' => false,
            'items' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $unitCost]],
        ]);

        $customer = $withCustomer
            ? Customer::create(['code' => 'CUS'.$suffix, 'name_th' => 'ลูกค้าทดสอบ', 'branch_id' => $branch->id, 'is_active' => true])
            : null;

        return [$branch->fresh(), $location, $product->fresh(), $customer];
    }
}
