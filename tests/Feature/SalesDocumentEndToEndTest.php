<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\PaymentAllocation;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Http\Middleware\ErpAuthorize;
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
use App\Services\Sales\CustomerPaymentService;
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

    public function test_split_booking_creates_two_balanced_sales_once_and_rolls_back_on_failure(): void
    {
        [$branch, $location, $product, $customer] = $this->stockedBranch('SPLIT', 10, 60, true);
        $actor = User::factory()->create();
        $type = DocumentType::where('code', DocumentType::CREDIT_SALE)->sole();
        $ds = DocumentBook::create(['code' => 'DS-TEST', 'name' => 'DS', 'prefix' => 'DS', 'document_type_id' => $type->id, 'is_active' => true, 'no_vat' => false]);
        $dsn = DocumentBook::create(['code' => 'DSN-TEST', 'name' => 'DSN', 'prefix' => 'DSN', 'document_type_id' => $type->id, 'is_active' => true, 'no_vat' => true]);
        $vatProduct = Product::create([
            'sku_code' => 'SKUVATSPLIT', 'name_th' => 'สินค้าทดสอบคิด VAT', 'base_unit_id' => $product->base_unit_id,
            'default_price' => 80, 'average_cost' => 0, 'is_vat' => true, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => Supplier::where('code', 'SUPSPLIT')->sole()->id,
            'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $vatProduct->id, 'qty' => 4, 'unit_price' => 60]],
        ]);
        $document = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
                ['product_id' => $vatProduct->id, 'qty' => 1, 'unit_price' => 80],
            ],
        ]);
        $booking = $document->saleBooking;
        $items = $document->stockDocument->items->sortBy('seq')->values();
        $this->assertCount(2, $items);
        $service = app(CreditSaleService::class);
        try {
            $service->splitBookingToCreditSales($booking, [$items[0]->id => $ds->id]);
            $this->fail('Incomplete allocation must fail');
        } catch (\RuntimeException $error) {
            $this->assertSame(SaleBooking::STATUS_PENDING, $booking->fresh()->status);
            $this->assertSame(0, Document::where('source_document_id', $document->id)->count());
            $this->assertSame(10.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->on_hand_qty);
        }
        DB::table('stock_lots')->where('product_id', $vatProduct->id)->where('warehouse_location_id', $location->id)->update(['remaining_qty' => 0]);
        DB::table('stock_balances')->where('product_id', $vatProduct->id)->where('warehouse_location_id', $location->id)->update(['on_hand_qty' => 0]);
        try {
            $service->splitBookingToCreditSales($booking, [$items[0]->id => $dsn->id, $items[1]->id => $ds->id]);
            $this->fail('A FIFO failure in the second sale must roll back the first sale too');
        } catch (\RuntimeException $error) {
            $this->assertSame(SaleBooking::STATUS_PENDING, $booking->fresh()->status);
            $this->assertSame(0, Document::where('source_document_id', $document->id)->count());
            $this->assertSame(10.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->on_hand_qty);
            $this->assertSame(2.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->reserved_qty);
        }
        DB::table('stock_lots')->where('product_id', $vatProduct->id)->where('warehouse_location_id', $location->id)->update(['remaining_qty' => 4]);
        DB::table('stock_balances')->where('product_id', $vatProduct->id)->where('warehouse_location_id', $location->id)->update(['on_hand_qty' => 4]);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($actor);
        $this->post(route('customers.trade-agreements.store', $customer), [
            'name' => 'เงินสด', 'vat_mode' => 'standard', 'prices_include_vat' => '0',
            'payment_terms' => 'เงินสด', 'credit_days' => '0', 'is_default' => '1', 'is_active' => '1',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $standard = $customer->tradeAgreements()->sole();
        $zero = $customer->tradeAgreements()->create(['name' => 'ส่งออก', 'vat_mode' => 'zero', 'prices_include_vat' => false]);
        $none = $customer->tradeAgreements()->create(['name' => 'N-เงินสด', 'vat_mode' => 'none', 'prices_include_vat' => true]);
        $this->put(route('customers.trade-agreements.update', [$customer, $none->id]), [
            'name' => $none->name, 'vat_mode' => 'none', 'prices_include_vat' => '1', 'is_default' => '1', 'is_active' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertFalse($standard->fresh()->is_default);
        $assignments = [$items[0]->id => $dsn->id, $items[1]->id => $ds->id];
        $zeroPreview = $service->previewBookingToCreditSales($booking, $assignments, [$ds->id => $zero->id]);
        $this->assertSame('0.00', collect($zeroPreview['groups'])->first(fn ($group) => $group['book']->id === $ds->id)['tax']['vat_amount']);
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('ข้อตกลงภาษีของลูกค้าแยกตามเล่ม');
        $request = ['split' => '1', 'item_book_ids' => $assignments, 'agreement_ids' => [$ds->id => $standard->id]];
        $this->post(route('bookings.convert.preview', $booking), $request)->assertRedirect(route('bookings.show', $booking));
        $stale = session('booking-conversion-preview-'.$booking->id);
        $standard->update(['credit_days' => 1]);
        $this->post(route('bookings.convert', $booking), ['preview_token' => $stale['token']])->assertSessionHas('error');
        $this->assertSame(0, $booking->salesDocuments()->count());
        $standard->update(['credit_days' => 0]);
        $this->post(route('bookings.convert.preview', $booking), $request)->assertRedirect(route('bookings.show', $booking));
        $preview = session('booking-conversion-preview-'.$booking->id);
        $this->assertNotNull($preview);
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('ตัวอย่างใบขายก่อนยืนยัน')->assertSee('DSN-TEST')->assertSee('DS-TEST');
        $this->post(route('bookings.convert', $booking), ['preview_token' => $preview['token']])
            ->assertRedirect(route('bookings.show', $booking))
            ->assertSessionHas('success');
        $sales = $booking->salesDocuments()->get();
        $this->assertCount(2, $sales);
        $this->assertSame(285.6, (float) $sales->sum('total_amount'));
        $this->assertSame(285.6, (float) CustomerOpenItem::whereIn('document_id', $sales->pluck('id')->all())->sum('balance_amount'));
        $this->assertSame(8.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->on_hand_qty);
        $this->assertSame(0.0, (float) StockBalance::where('product_id', $product->id)->where('warehouse_location_id', $location->id)->sole()->reserved_qty);
        foreach ($sales as $sale) {
            $this->assertSame($document->id, (int) $sale->source_document_id);
            $this->assertGlBalanced($sale);
        }
        $nonVatSale = $sales->firstWhere('document_book_id', $dsn->id);
        $vatSale = $sales->firstWhere('document_book_id', $ds->id);
        $this->assertSame(0.0, (float) $nonVatSale->vat_amount);
        $this->assertSame('200.00', $nonVatSale->trade_tax['exempt_amount']);
        $this->assertSame(5.60, (float) $vatSale->vat_amount);
        $this->assertSame('80.00', $vatSale->trade_tax['taxable_base']);
        $this->assertSame($vatSale->doc_date->toDateString(), $vatSale->openItem->due_date->toDateString());
        $this->get(route('documents.tax-invoice', $nonVatSale))->assertOk()->assertViewHas('vatAmount', 0)->assertSee('ใบแจ้งหนี้ / ใบส่งของ');
        $this->get(route('documents.tax-invoice', $vatSale))->assertOk()->assertViewHas('vatAmount', 5.6);
        $this->assertSame(2, $booking->salesDocuments()->count());
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'sale_bookings', 'record_id' => $booking->id, 'action' => 'booking_converted_split']);
        $openItems = CustomerOpenItem::whereIn('document_id', $sales->pluck('id')->all())->orderBy('document_id')->get();
        DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน']);
        $receipt = app(CustomerPaymentService::class)->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'method' => 'cash',
            'allocations' => [
                ['customer_open_item_id' => $openItems[0]->id, 'amount' => 10],
                ['customer_open_item_id' => $openItems[1]->id, 'amount' => 20],
            ],
        ]);
        $this->assertSame(2, PaymentAllocation::where('payment_document_id', $receipt->paymentDocument->id)->count());
        $this->assertSame(30.0, (float) $receipt->total_amount);
        $this->expectException(\RuntimeException::class);
        $service->splitBookingToCreditSales($booking, [$items[0]->id => $dsn->id, $items[1]->id => $ds->id]);
    }

    public function test_supplier_agreement_sets_purchase_tax_and_keeps_the_original_terms(): void
    {
        [$branch, , $product] = $this->stockedBranch('TAXAP', 4, 100);
        $product->update(['is_vat' => true]);
        $supplier = Supplier::where('code', 'SUPTAXAP')->sole();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs(User::factory()->create());
        $this->post(route('suppliers.trade-agreements.store', $supplier), [
            'name' => 'N-เงินสด', 'vat_mode' => 'none', 'prices_include_vat' => '0', 'payment_terms' => 'เงินสด',
            'credit_days' => '10', 'is_active' => '1', 'is_default' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $none = $supplier->tradeAgreements()->sole();
        $zero = $supplier->tradeAgreements()->create(['name' => 'อัตราศูนย์', 'vat_mode' => 'zero', 'prices_include_vat' => false]);
        $standard = $supplier->tradeAgreements()->create(['name' => 'VAT ปกติ', 'vat_mode' => 'standard', 'prices_include_vat' => false]);
        $this->getJson(route('search.suppliers', ['q' => $supplier->code, 'with_trade_agreements' => 1]))
            ->assertOk()->assertJsonPath('0.trade_agreements.0.id', $none->id);
        $this->get(route('purchases.index'))->assertOk()->assertSee('ข้อตกลงผู้จำหน่าย');

        $payload = ['branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'is_credit' => '1', 'claim_input_vat' => '1',
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]]];
        foreach ([null, $zero->id, $standard->id] as $agreementId) {
            $this->post(route('purchases.store'), $payload + ['trade_agreement_id' => $agreementId])
                ->assertSessionHasNoErrors()->assertSessionHas('success');
        }

        [$exemptPurchase, $zeroPurchase, $standardPurchase] = Document::where('supplier_id', $supplier->id)
            ->whereNotNull('trade_tax')->orderBy('id')->get()->all();
        $this->assertSame('none', $exemptPurchase->trade_tax['vat_mode']);
        $this->assertSame('100.00', $exemptPurchase->trade_tax['exempt_amount']);
        $this->assertFalse($exemptPurchase->claim_input_vat);
        $this->assertSame('zero', $zeroPurchase->trade_tax['vat_mode']);
        $this->assertSame('100.00', $zeroPurchase->trade_tax['taxable_base']);
        $this->assertSame('0.00', $zeroPurchase->trade_tax['vat_amount']);
        $this->assertSame(107.0, (float) $standardPurchase->total_amount);
        $this->assertSame(7.0, (float) $standardPurchase->vat_amount);
        $this->assertGlBalanced($standardPurchase);
        $payable = SupplierOpenItem::where('source_document_id', $exemptPurchase->id)->sole();
        $this->assertSame('เงินสด', $payable->payment_terms);
        $this->assertSame(now()->addDays(10)->toDateString(), $payable->due_date->toDateString());
        $none->update(['is_active' => false, 'name' => 'แก้ทีหลัง']);
        $this->get(route('purchases.show', $exemptPurchase))->assertOk()->assertSee('N-เงินสด');
        $this->post(route('purchases.store'), $payload + ['trade_agreement_id' => $none->id])->assertSessionHas('error');
        $this->assertSame(3, Document::where('supplier_id', $supplier->id)->whereNotNull('trade_tax')->count());
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
