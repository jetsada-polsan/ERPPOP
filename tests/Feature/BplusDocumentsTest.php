<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * เอกสารสาย B18 จาก BPlus กลายเป็นเอกสาร ERP จริง เห็นในเมนูและรายงานปกติ
 * โดยไม่ตัดสต๊อก ไม่จองสต๊อก ไม่ลง GL และแก้ใน ERP ไม่ได้
 */
class BplusDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['BOOKING' => 'ใบจอง', 'CREDIT_SALE' => 'ใบขายเชื่อ', 'RECEIPT' => 'ใบเสร็จรับเงิน'] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name]);
        }
        $this->branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $location->id]);

        $saleType = DocumentType::where('code', 'CREDIT_SALE')->value('id');
        $bookingType = DocumentType::where('code', 'BOOKING')->value('id');
        foreach ([[$saleType, 'DS', false], [$saleType, 'DSN', true], [$bookingType, 'B18', false]] as [$type, $code, $noVat]) {
            DB::table('document_books')->updateOrInsert(['document_type_id' => $type, 'code' => $code],
                ['name' => $code, 'prefix' => $code, 'is_active' => true, 'is_default' => false, 'no_vat' => $noVat]);
        }
        DB::table('salesmen')->updateOrInsert(['code' => '09'], ['name' => 'แบงค์', 'is_active' => true]);
        $salesmanId = DB::table('salesmen')->where('code', '09')->value('id');
        DB::table('sales_areas')->where('code', 'B18')->delete();
        DB::table('sales_areas')->insert(['code' => 'B18', 'name' => 'ม่วง-เลิง-อำนาจ-มุก', 'branch_id' => $this->branch->id,
            'default_salesman_id' => $salesmanId, 'is_active' => true]);

        Customer::create(['code' => '37120-0005', 'name_th' => 'ร้านตัวอย่าง', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $bag = ProductUnit::firstOrCreate(['code' => 'BAG'], ['name' => 'ถุง', 'qty_per_base_unit' => 1]);
        $bag10 = ProductUnit::firstOrCreate(['code' => 'BAG10'], ['name' => 'ถุง*10', 'qty_per_base_unit' => 10]);
        foreach (['104032', '501035'] as $sku) {
            $product = Product::create(['sku_code' => $sku, 'name_th' => 'สินค้า '.$sku, 'base_unit_id' => $bag->id, 'is_active' => true]);
            ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'B1-'.$sku, 'unit_id' => $bag->id, 'unit_factor' => 1, 'is_active' => true]);
            ProductBarcode::create(['product_id' => $product->id, 'barcode' => 'B10-'.$sku, 'unit_id' => $bag10->id, 'unit_factor' => 10, 'is_active' => true]);
        }

        $user = User::factory()->create(['username' => 'bplus_docs']);
        $role = Role::create(['code' => 'BPLUS_DOCS', 'name' => 'BPlus docs']);
        $role->permissions()->sync(collect(['finance.manage', 'reports.view'])
            ->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
        $user->roles()->attach($role->id);
        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }

    public function test_route_documents_become_erp_bookings_sales_and_receipts_without_stock_or_gl(): void
    {
        $this->importFile($this->export());

        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $receipt = Document::where('doc_number', 'RR26911/000800')->firstOrFail();

        $this->assertSame('BOOKING', $booking->documentType->code);
        $this->assertSame('CREDIT_SALE', $ds->documentType->code);
        $this->assertSame('DS', $ds->documentBook->code);
        $this->assertSame('DSN', $dsn->documentBook->code);
        $this->assertSame($booking->id, $ds->source_document_id);
        $this->assertSame('22.57', number_format((float) $ds->vat_amount, 2, '.', ''));
        $this->assertSame('1814.43', number_format((float) $ds->subtotal_amount, 2, '.', ''));

        $sale = SaleBooking::where('document_id', $booking->id)->firstOrFail();
        $this->assertSame('converted_to_sale', $sale->status);
        $this->assertSame($ds->id, $sale->confirmed_document_id);
        $this->assertNull(Document::where('doc_number', 'B18-6911/00383')->first(), 'ใบจองที่ยังไม่รู้ลูกค้ายังไม่สร้าง');
        $this->assertStringContainsString('ยังไม่รู้ลูกค้า', (string) DB::table('bplus_sync_documents')->where('ref', 'B18-6911/00383')->value('problem'));

        // หน่วยใหญ่: 2 ถุง*10 = 20 ถุง ราคาต่อถุง 83 รวม 1,660
        $line = DB::table('stock_document_items as i')->join('stock_documents as s', 's.id', '=', 'i.stock_document_id')
            ->where('s.document_id', $ds->id)->first(['i.qty', 'i.unit_qty', 'i.unit_factor', 'i.unit_price']);
        $this->assertSame(['20', '2', '10', '83'], array_map(fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'),
            [$line->qty, $line->unit_qty, $line->unit_factor, $line->unit_price]));

        // ลูกหนี้: DS ชำระ 1,000 จาก 1,837 คงค้าง 837 / DSN ชำระครบ
        $this->assertSame(['partial', '837.00'], $this->openItem($ds));
        $this->assertSame(['paid', '0.00'], $this->openItem($dsn));
        $this->assertSame(1049.0, (float) DB::table('payment_lines as l')->join('payment_documents as p', 'p.id', '=', 'l.payment_document_id')
            ->where('p.document_id', $receipt->id)->where('l.method', 'transfer')->sum('l.amount'));

        $this->assertSame(0, DB::table('stock_movements')->count(), 'ต้องไม่ตัดสต๊อก');
        $this->assertSame(0, (int) DB::table('stock_balances')->sum('reserved_qty'), 'ต้องไม่จองสต๊อก');
        $this->assertGreaterThan(0, DB::table('gl_journals')->count(), 'ต้องลง GL');

        Artisan::call('erp:health');
        $this->assertStringNotContainsString('ไม่มีรายการ GL', Artisan::output(), 'ใบขายจาก BPlus ไม่ทำให้ตรวจ GL ไม่ผ่าน');
    }

    public function test_daily_sales_and_vat_reports_use_the_bplus_bill_amounts(): void
    {
        $this->importFile($this->export());

        $daily = DB::table('sales_postings')->where('sale_date', '2026-11-15')->get();
        $this->assertSame('1886.00', number_format((float) $daily->sum('net_sales'), 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $daily->sum('vat_amount'), 2, '.', ''));
    }

    public function test_documents_show_in_the_normal_erp_lists(): void
    {
        $this->importFile($this->export());
        $this->actingAs(User::where('username', 'bplus_docs')->first()->forceFill(['branch_id' => $this->branch->id]));

        $this->get(route('bookings.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('B18-6911/00382');
        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $sale = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('DS256911/06364')->assertSee('มาจาก BPlus');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('DS256911/06364')->assertSee('1,837.00');
        $this->get(route('ar-receipts.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('RR26911/000800')->assertSee('837.00');
    }

    public function test_reports_by_seller_credit_the_route_salesman(): void
    {
        $this->importFile($this->export());
        // รายงานตัวนี้ต้องมีสิทธิ์ตามทะเบียนรายงาน ไม่งั้นหน้าจะเด้งไปรายงานแรกที่เห็นได้
        Role::where('code', 'BPLUS_DOCS')->first()->permissions()->sync(Permission::pluck('id')->all());
        $this->actingAs(User::where('username', 'bplus_docs')->first()->fresh()->forceFill(['branch_id' => $this->branch->id]));

        foreach (['sales_by_seller', 'sales_by_category_seller'] as $report) {
            $this->get(route('reports.index', ['category' => 'sales', 'report' => $report, 'from' => '2026-11-01', 'to' => '2026-11-30']))
                ->assertOk()->assertSee('แบงค์')->assertDontSee('ไม่ระบุผู้ขายหลังบ้าน');
        }
    }

    public function test_opening_receivable_lets_receipts_settle_bills_from_before_the_period(): void
    {
        $this->importFile($this->withOpeningBill($this->export()));

        $opening = Document::where('doc_number', 'DS256910/16576')->firstOrFail();
        $this->assertSame('OPENING_AR', $opening->documentType->code);
        $this->assertSame(['partial', '200.00'], $this->openItem($opening), 'ยอดยกมา 500 รับชำระในช่วง 300 เหลือ 200');
        $this->assertNull(DB::table('bplus_sync_documents')->where('ref', 'RR26911/000800')->value('problem'), 'ใบเสร็จตัดหนี้บิลเก่าได้ครบ');
        $this->assertSame(0.0, (float) DB::table('sales_postings')->where('sale_date', '2026-10-20')->sum('net_sales'), 'ยอดยกมาไม่ใช่ยอดขาย');

        $gl = DB::table('gl_journals as g')->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')->where('g.document_id', $opening->id)
            ->selectRaw('a.default_role as role, sum(g.debit) as dr, sum(g.credit) as cr')->groupBy('a.default_role')->get()->keyBy('role');
        $this->assertSame('500.00', number_format((float) $gl['ar']->dr, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $gl['opening_balance']->cr, 2, '.', ''));
    }

    public function test_real_bill_replaces_its_opening_receivable(): void
    {
        $this->importFile($this->withOpeningBill($this->export()));
        $export = $this->withOpeningBill($this->export());
        // วันหลังดึงย้อนไปถึงบิลตัวจริง: ยอดยกมาต้องถูกเลิกใช้ และใบเสร็จไปตัดที่บิลตัวจริงแทน
        $export['documents'][] = ['bplus_key' => 2700000, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256910/16576', 'doc_date' => '2026-10-20',
            'customer_code' => '37120-0005', 'exempt_amount' => 500, 'total_amount' => 500, 'due_date' => '2026-11-10'];
        $this->importFile($export);

        $docs = Document::where('doc_number', 'DS256910/16576')->get();
        $this->assertCount(1, $docs, 'บิลตัวจริงใช้เอกสารเดิมของยอดยกมา ไม่สร้างซ้ำ');
        $sale = $docs->first();
        $this->assertSame('CREDIT_SALE', $sale->documentType->code);
        $this->assertSame(['partial', '200.00'], $this->openItem($sale), 'การตัดหนี้ 300 จากใบเสร็จยังอยู่');
        $net = DB::table('gl_journals as g')->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')->where('g.document_id', $sale->id)
            ->selectRaw('a.default_role as role, sum(g.debit) - sum(g.credit) as net')->groupBy('a.default_role')->pluck('net', 'role');
        $this->assertSame(0.0, round((float) ($net['opening_balance'] ?? 0), 2), 'ยอดยกมาถูกกลับรายการ');
        $this->assertSame(500.0, round((float) $net['ar'], 2));
        $this->assertSame(-500.0, round((float) $net['sales_revenue'], 2));
    }

    public function test_customers_match_by_bplus_key_then_unique_name(): void
    {
        // ใน ERP จริง รหัสลูกค้าคือ AR_KEY ของ BPlus เช่น 8049 ไม่ใช่ AR_CODE
        $byKey = Customer::create(['code' => '8049', 'name_th' => 'ร้านคีย์', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $byName = Customer::create(['code' => '5412', 'name_th' => 'ร้านชื่อตรง', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $export = $this->export();
        $export['documents'][2]['customer_code'] = '34140-0121';
        $export['documents'][2]['customer_key'] = 8049;
        $export['documents'][3]['customer_code'] = '35120-0045';
        $export['documents'][3]['customer_name'] = 'ร้านชื่อตรง';
        $this->importFile($export);

        $this->assertSame($byKey->id, Document::where('doc_number', 'DS256911/06364')->value('customer_id'));
        $this->assertSame($byName->id, Document::where('doc_number', 'DSN256911/4861')->value('customer_id'));
    }

    public function test_customer_names_match_when_only_spacing_differs(): void
    {
        $spaced = Customer::create(['code' => '6503', 'name_th' => 'พี่ แดง  ร้านค้า', 'branch_id' => $this->branch->id, 'is_active' => true]);
        Customer::create(['code' => '7001', 'name_th' => 'ร้าน คู่', 'branch_id' => $this->branch->id, 'is_active' => true]);
        Customer::create(['code' => '7002', 'name_th' => 'ร้านคู่ ', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $export = $this->export();
        $export['documents'][2]['customer_code'] = '34140-0091';
        $export['documents'][2]['customer_name'] = "พี่แดง\u{00A0}ร้านค้า ";
        $export['documents'][3]['customer_code'] = '35120-0099';
        $export['documents'][3]['customer_name'] = 'ร้านคู่';
        $this->importFile($export);

        $this->assertSame($spaced->id, Document::where('doc_number', 'DS256911/06364')->value('customer_id'));
        $this->assertNull(Document::where('doc_number', 'DSN256911/4861')->value('customer_id'), 'ชื่อที่ตัดช่องว่างแล้วซ้ำสองรายต้องไม่เดา');
    }

    public function test_resync_updates_in_place_and_cancels_follow_bplus(): void
    {
        $this->importFile($this->export());
        $before = DB::table('documents')->count();

        $edited = $this->export();
        $edited['documents'][2]['total_amount'] = 1900;
        $edited['documents'][2]['exempt_amount'] = 1555;
        $edited['documents'][3]['is_cancelled'] = true;
        $this->importFile($edited);

        $this->assertSame($before, DB::table('documents')->count(), 'ซิงค์ซ้ำต้องไม่สร้างเอกสารซ้ำ');
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame('1900.00', number_format((float) $ds->total_amount, 2, '.', ''));
        $this->assertSame(['partial', '900.00'], $this->openItem($ds));
        $this->assertSame('cancelled', Document::where('doc_number', 'DSN256911/4861')->value('status'));
    }

    public function test_bplus_documents_cannot_be_changed_in_the_erp(): void
    {
        $this->importFile($this->export());
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();

        $this->expectException(RuntimeException::class);
        $ds->update(['status' => 'cancelled']);
    }

    public function test_preview_lists_customers_and_products_missing_from_the_erp(): void
    {
        $export = $this->export();
        $export['documents'][2]['customer_code'] = '99999-0001';
        $export['documents'][2]['lines'][] = ['seq' => 2, 'sku_code' => '999999', 'unit_name' => 'ถุง', 'qty' => 1, 'amount' => 0];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()->assertSee('99999-0001')->assertSee('999999')->assertSee('สำนักงานใหญ่');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')]);
        $this->assertNull(Document::where('doc_number', 'DS256911/06364')->first(), 'ไม่มีลูกค้าใน ERP ต้องไม่สร้างใบขาย');
        $this->assertStringContainsString('99999-0001', (string) DB::table('bplus_sync_documents')->where('ref', 'DS256911/06364')->value('problem'));
    }

    public function test_new_customer_from_bplus_is_created_with_ar_key_as_code_and_linked_to_sale(): void
    {
        $export = $this->export();
        $export['documents'][2]['customer_code'] = '34140-9999';
        $export['documents'][2]['customer_key'] = 9001;
        $export['documents'][2]['customer_name'] = 'ลูกค้ารายใหม่';
        $export['customers'] = [
            [
                'ar_key' => 9001,
                'ar_code' => '34140-9999',
                'ar_name' => 'ลูกค้ารายใหม่',
                'tax_id' => '1234567890123',
                'address' => '123 ถ.ชยางกูร อุบลราชธานี 34000',
                'phone' => '0812345678',
                'is_active' => true,
            ],
        ];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()
            ->assertSee('จะสร้างลูกค้าใหม่ 1 ราย')
            ->assertDontSee('ไม่พบลูกค้า');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();

        $customer = Customer::where('code', '9001')->firstOrFail();
        $this->assertSame('ลูกค้ารายใหม่', $customer->name_th);
        $this->assertSame('1234567890123', $customer->tax_id);
        $this->assertSame($this->branch->id, $customer->branch_id);
        $this->assertTrue($customer->is_active);

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address_line' => '123 ถ.ชยางกูร อุบลราชธานี 34000',
            'is_default' => 1,
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'phone' => '0812345678',
        ]);

        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame($customer->id, $ds->customer_id);
    }

    public function test_existing_customer_updates_name_and_tax_while_preserving_credit_limit_and_terms(): void
    {
        $customer = Customer::create([
            'code' => '8049',
            'name_th' => 'ชื่อเดิมในระบบ',
            'branch_id' => $this->branch->id,
            'credit_limit' => 50000.00,
            'is_active' => true,
        ]);
        DB::table('customer_terms')->insert([
            'customer_id' => $customer->id,
            'name' => 'เครดิต 30 วัน',
            'credit_days' => 30,
            'trade_discount_percent' => 5.0,
            'cash_discount_percent' => 2.0,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->export();
        $export['documents'][2]['customer_code'] = '34140-0121';
        $export['documents'][2]['customer_key'] = 8049;
        $export['documents'][2]['customer_name'] = 'ชื่อใหม่จาก BPlus';
        $export['customers'] = [
            [
                'ar_key' => 8049,
                'ar_code' => '34140-0121',
                'ar_name' => 'ชื่อใหม่จาก BPlus',
                'tax_id' => '9876543210987',
                'address' => '999 ถ.แจ้งสนิท อุบลราชธานี 34000',
                'phone' => '0898765432',
                'is_active' => true,
            ],
        ];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()
            ->assertSee('อัปเดตชื่อ 1 ราย');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();

        $customer->refresh();
        $this->assertSame('ชื่อใหม่จาก BPlus', $customer->name_th);
        $this->assertSame('9876543210987', $customer->tax_id);

        $this->assertSame('50000.0000', (string) $customer->credit_limit);
        $this->assertSame($this->branch->id, $customer->branch_id);

        $term = DB::table('customer_terms')->where('customer_id', $customer->id)->first();
        $this->assertNotNull($term);
        $this->assertSame('เครดิต 30 วัน', $term->name);
        $this->assertSame(30, (int) $term->credit_days);
        $this->assertEquals(5.0, (float) $term->trade_discount_percent);

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address_line' => '999 ถ.แจ้งสนิท อุบลราชธานี 34000',
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'phone' => '0898765432',
        ]);
    }

    public function test_missing_product_co8110_is_created_and_shown_in_document_lines(): void
    {
        $this->assertDatabaseMissing('products', ['sku_code' => 'CO8110']);

        $export = $this->export();
        $export['documents'][2]['lines'][] = [
            'seq' => 2,
            'sku_code' => 'CO8110',
            'unit_name' => 'ถุง',
            'unit_factor' => 1,
            'qty' => 3,
            'unit_price' => 150,
            'amount' => 450,
        ];
        $export['products'] = [
            [
                'sku_code' => 'CO8110',
                'name' => 'ปุ๋ยสูตร CO8110',
                'unit_name' => 'ถุง',
            ],
        ];

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()
            ->assertSee('สร้างสินค้าใหม่ 1 รายการ')
            ->assertDontSee('ไม่พบสินค้า');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();

        $product = Product::where('sku_code', 'CO8110')->firstOrFail();
        $this->assertSame('ปุ๋ยสูตร CO8110', $product->name_th);
        $this->assertFalse($product->is_active, 'ปิดการขายหน้าร้าน POS ไว้ก่อน');

        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $items = DB::table('stock_document_items as i')
            ->join('stock_documents as s', 's.id', '=', 'i.stock_document_id')
            ->where('s.document_id', $ds->id)
            ->where('i.product_id', $product->id)
            ->first();
        $this->assertNotNull($items, 'บรรทัด CO8110 ต้องถูกสร้างใน stock_document_items');
        $this->assertEquals(3.0, (float) $items->qty);
        $this->assertNull(DB::table('bplus_sync_documents')->where('ref', 'DS256911/06364')->value('problem'));
    }

    public function test_legacy_export_file_without_customers_or_products_works_identically(): void
    {
        $export = $this->export();
        $this->assertArrayNotHasKey('customers', $export);
        $this->assertArrayNotHasKey('products', $export);

        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $preview->assertOk()
            ->assertSee('จะสร้างลูกค้าใหม่ 0 ราย · อัปเดตชื่อ 0 ราย · สร้างสินค้าใหม่ 0 รายการ');

        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();

        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame($booking->id, $ds->source_document_id);
    }

    public function test_ds_credit_sale_with_vat_posts_balanced_gl_by_roles(): void
    {
        $this->importFile($this->export());

        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $journals = DB::table('gl_journals')->where('document_id', $ds->id)->get();

        $arAccount = ChartOfAccount::where('default_role', 'ar')->firstOrFail();
        $revenueAccount = ChartOfAccount::where('default_role', 'sales_revenue')->firstOrFail();
        $vatAccount = ChartOfAccount::where('default_role', 'vat_output')->firstOrFail();

        $this->assertCount(3, $journals);

        $arEntry = $journals->firstWhere('account_id', $arAccount->id);
        $revenueEntry = $journals->firstWhere('account_id', $revenueAccount->id);
        $vatEntry = $journals->firstWhere('account_id', $vatAccount->id);

        $this->assertNotNull($arEntry);
        $this->assertNotNull($revenueEntry);
        $this->assertNotNull($vatEntry);

        $this->assertSame('1837.00', number_format((float) $arEntry->debit, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $arEntry->credit, 2, '.', ''));

        $this->assertSame('0.00', number_format((float) $revenueEntry->debit, 2, '.', ''));
        $this->assertSame('1814.43', number_format((float) $revenueEntry->credit, 2, '.', ''));

        $this->assertSame('0.00', number_format((float) $vatEntry->debit, 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $vatEntry->credit, 2, '.', ''));

        $totalDebit = DecimalMath::sum($journals->pluck('debit'), 2);
        $totalCredit = DecimalMath::sum($journals->pluck('credit'), 2);
        $this->assertSame('1837.00', $totalDebit);
        $this->assertSame('1837.00', $totalCredit);
        $this->assertSame(0, DecimalMath::compare($totalDebit, $totalCredit));
    }

    public function test_dsn_credit_sale_has_zero_vat_in_gl(): void
    {
        $this->importFile($this->export());

        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $journals = DB::table('gl_journals')->where('document_id', $dsn->id)->get();

        $arAccount = ChartOfAccount::where('default_role', 'ar')->firstOrFail();
        $revenueAccount = ChartOfAccount::where('default_role', 'sales_revenue')->firstOrFail();
        $vatAccount = ChartOfAccount::where('default_role', 'vat_output')->first();

        $arEntry = $journals->firstWhere('account_id', $arAccount->id);
        $revenueEntry = $journals->firstWhere('account_id', $revenueAccount->id);
        $vatEntry = $vatAccount ? $journals->firstWhere('account_id', $vatAccount->id) : null;

        $this->assertNotNull($arEntry);
        $this->assertNotNull($revenueEntry);
        $this->assertNull($vatEntry, 'DSN ต้องไม่มีรายการภาษีขาย');

        $this->assertSame('49.00', number_format((float) $arEntry->debit, 2, '.', ''));
        $this->assertSame('49.00', number_format((float) $revenueEntry->credit, 2, '.', ''));

        $totalDebit = DecimalMath::sum($journals->pluck('debit'), 2);
        $totalCredit = DecimalMath::sum($journals->pluck('credit'), 2);
        $this->assertSame('49.00', $totalDebit);
        $this->assertSame('49.00', $totalCredit);
    }

    public function test_receipt_with_cash_transfer_and_shortfall_posts_balanced_gl(): void
    {
        $export = $this->export();
        $export['documents'][4] = [
            'bplus_key' => 2715000,
            'kind' => 'receipt',
            'book_code' => 'RR2',
            'ref' => 'RR26911/000800',
            'doc_date' => '2026-11-15',
            'customer_code' => '37120-0005',
            'total_amount' => 1000,
            'cash_amount' => 500,
            'transfer_amount' => 499.63,
            'short_amount' => 0.37,
            'allocations' => [
                ['sale_ref' => 'DS256911/06364', 'paid_amount' => 1000],
            ],
        ];

        $this->importFile($export);

        $receipt = Document::where('doc_number', 'RR26911/000800')->firstOrFail();
        $journals = DB::table('gl_journals')->where('document_id', $receipt->id)->get();

        $cashAccount = ChartOfAccount::where('default_role', 'cash')->firstOrFail();
        $bankAccount = ChartOfAccount::where('default_role', 'bank')->firstOrFail();
        $arAccount = ChartOfAccount::where('default_role', 'ar')->firstOrFail();
        $roundingAccount = ChartOfAccount::where('name_th', 'like', '%เศษสตางค์%')
            ->orWhere('default_role', 'rounding_diff')
            ->orWhere('default_role', 'expense')
            ->firstOrFail();

        $cashEntry = $journals->firstWhere('account_id', $cashAccount->id);
        $bankEntry = $journals->firstWhere('account_id', $bankAccount->id);
        $roundingEntry = $journals->firstWhere('account_id', $roundingAccount->id);
        $arEntry = $journals->firstWhere('account_id', $arAccount->id);

        $this->assertNotNull($cashEntry);
        $this->assertNotNull($bankEntry);
        $this->assertNotNull($roundingEntry);
        $this->assertNotNull($arEntry);

        $this->assertSame('500.00', number_format((float) $cashEntry->debit, 2, '.', ''));
        $this->assertSame('499.63', number_format((float) $bankEntry->debit, 2, '.', ''));
        $this->assertSame('0.37', number_format((float) $roundingEntry->debit, 2, '.', ''));
        $this->assertSame('1000.00', number_format((float) $arEntry->credit, 2, '.', ''));

        $totalDebit = DecimalMath::sum($journals->pluck('debit'), 2);
        $totalCredit = DecimalMath::sum($journals->pluck('credit'), 2);
        $this->assertSame('1000.00', $totalDebit);
        $this->assertSame('1000.00', $totalCredit);
    }

    public function test_resync_with_same_amount_does_not_increase_gl_journals(): void
    {
        $this->importFile($this->export());
        $countBefore = DB::table('gl_journals')->count();
        $this->assertGreaterThan(0, $countBefore);

        $this->importFile($this->export());
        $countAfter = DB::table('gl_journals')->count();

        $this->assertSame($countBefore, $countAfter, 'ซิงค์ซ้ำยอดเท่าเดิม จำนวน journal ต้องไม่เพิ่มขึ้น');
    }

    public function test_resync_with_changed_amount_reverses_old_and_posts_new_gl(): void
    {
        $this->importFile($this->export());
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $countInitial = DB::table('gl_journals')->where('document_id', $ds->id)->count();
        $this->assertSame(3, $countInitial);

        $edited = $this->export();
        $edited['documents'][2]['total_amount'] = 2000;
        $edited['documents'][2]['bplus_updated_at'] = '2026-11-16 10:00:00';
        $this->importFile($edited);

        $journals = DB::table('gl_journals')->where('document_id', $ds->id)->get();
        $reversals = $journals->filter(fn ($j) => str_starts_with($j->remark, 'REPOST REVERSAL:'));
        $this->assertNotEmpty($reversals, 'ต้องมีรายการกลับรายการ REPOST REVERSAL');

        $arAccount = ChartOfAccount::where('default_role', 'ar')->firstOrFail();
        $arEntries = $journals->where('account_id', $arAccount->id);
        $netAr = DecimalMath::subtract(
            DecimalMath::sum($arEntries->pluck('debit')),
            DecimalMath::sum($arEntries->pluck('credit')),
            2
        );
        $this->assertSame('2000.00', $netAr);
    }

    public function test_cancelled_document_in_bplus_reverses_gl_to_zero_net_balance(): void
    {
        $this->importFile($this->export());
        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $this->assertGreaterThan(0, DB::table('gl_journals')->where('document_id', $dsn->id)->count());

        $cancelledExport = $this->export();
        $cancelledExport['documents'][3]['is_cancelled'] = true;
        $cancelledExport['documents'][3]['bplus_updated_at'] = '2026-11-16 11:00:00';
        $this->importFile($cancelledExport);

        $journals = DB::table('gl_journals')->where('document_id', $dsn->id)->get();
        $voidEntries = $journals->filter(fn ($j) => str_starts_with($j->remark, 'VOID REVERSAL:'));
        $this->assertNotEmpty($voidEntries, 'ต้องมีรายการกลับรายการ VOID REVERSAL');

        foreach ($journals->groupBy('account_id') as $accountId => $entries) {
            $net = DecimalMath::subtract(
                DecimalMath::sum($entries->pluck('debit')),
                DecimalMath::sum($entries->pluck('credit')),
                2
            );
            $this->assertSame('0.00', $net, "ยอดสุทธิของบัญชี {$accountId} ต้องเป็น 0");
        }
    }

    public function test_unmapped_vat_output_role_skips_gl_without_failing_sync_and_records_problem(): void
    {
        ChartOfAccount::where('default_role', 'vat_output')->update(['default_role' => null]);

        $this->importFile($this->export());

        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame(0, DB::table('gl_journals')->where('document_id', $ds->id)->count(), 'เอกสารที่มี VAT ต้องข้ามการลง GL เมื่อยังไม่ผูก vat_output');

        $problem = DB::table('bplus_sync_documents')->where('ref', 'DS256911/06364')->value('problem');
        $this->assertStringContainsString('ยังไม่ผูกบัญชี vat_output', (string) $problem);

        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $this->assertGreaterThan(0, DB::table('gl_journals')->where('document_id', $dsn->id)->count());
    }

    public function test_bplus_repost_gl_moves_balances_to_new_sales_revenue_account(): void
    {
        $this->importFile($this->export());

        $oldRevAccount = ChartOfAccount::where('default_role', 'sales_revenue')->firstOrFail();

        $newRevAccount = ChartOfAccount::create([
            'code' => '4019',
            'name_th' => 'รายได้จากการขายสาย B18',
            'name_en' => 'Sales Revenue Route B18',
            'account_type' => 'revenue',
            'default_role' => null,
        ]);

        $oldRevAccount->update(['default_role' => null]);
        $newRevAccount->update(['default_role' => 'sales_revenue']);

        Artisan::call('bplus:repost-gl', ['route' => 'B18']);

        $oldJournals = DB::table('gl_journals')->where('account_id', $oldRevAccount->id)->get();
        $netOld = DecimalMath::subtract(
            DecimalMath::sum($oldJournals->pluck('credit')),
            DecimalMath::sum($oldJournals->pluck('debit')),
            2
        );
        $this->assertSame('0.00', $netOld, 'ยอดในบัญชีเดิมต้องกลับรายการหมดจนสุทธิเป็น 0');

        $newJournals = DB::table('gl_journals')->where('account_id', $newRevAccount->id)->get();
        $netNew = DecimalMath::subtract(
            DecimalMath::sum($newJournals->pluck('credit')),
            DecimalMath::sum($newJournals->pluck('debit')),
            2
        );
        $this->assertSame('1863.43', $netNew, 'ยอดรายได้ต้องย้ายไปบัญชีใหม่');
    }

    public function test_bplus_repost_gl_dry_run_displays_summary_without_saving(): void
    {
        $this->importFile($this->export());
        $initialJournalsCount = DB::table('gl_journals')->count();

        Artisan::call('bplus:repost-gl', ['route' => 'B18', '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('1030', $output);
        $this->assertStringContainsString('4010', $output);
        $this->assertStringContainsString('2,935.00', $output);

        $this->assertSame($initialJournalsCount, DB::table('gl_journals')->count(), 'dry-run ต้องไม่เพิ่มหรือแก้ journal');
    }

    public function test_erp_health_and_readiness_pass_sales_gl_check_when_all_documents_posted(): void
    {
        $this->importFile($this->export());

        $this->artisan('erp:health')
            ->expectsOutputToContain('เอกสารขายลง GL ครบ');

        $this->artisan('erp:readiness')
            ->expectsOutputToContain('เอกสารขายลง GL ครบ');
    }

    private function openItem(Document $document): array
    {
        $item = DB::table('customer_open_items')->where('document_id', $document->id)->first();

        return [$item->status, number_format((float) $item->balance_amount, 2, '.', '')];
    }

    private function importFile(array $export): void
    {
        $preview = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);
        $this->post(route('bplus.sync.import.store'), ['token' => $preview->viewData('token')])->assertRedirect();
    }

    private function upload(array $data): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('b18.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function withOpeningBill(array $export): array
    {
        $export['documents'][] = ['bplus_key' => 9000000000 + 2700000, 'kind' => 'opening_ar', 'book_code' => 'DS', 'ref' => 'DS256910/16576',
            'doc_date' => '2026-10-20', 'customer_code' => '37120-0005', 'total_amount' => 500, 'original_amount' => 800, 'due_date' => '2026-11-10'];
        foreach ($export['documents'] as &$doc) {
            if ($doc['ref'] === 'RR26911/000800') {
                $doc['total_amount'] += 300;
                $doc['transfer_amount'] += 300;
                $doc['allocations'][] = ['sale_ref' => 'DS256910/16576', 'paid_amount' => 300, 'transfer_amount' => 300];
            }
        }

        return $export;
    }

    private function export(): array
    {
        return ['route' => 'B18', 'documents' => [
            ['bplus_key' => 2713489, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00382', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1886, 'created_by' => 'BANKKY',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660],
                    ['seq' => 2, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 226, 'amount' => 226]]],
            ['bplus_key' => 2713494, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00383', 'doc_date' => '2026-11-15',
                'is_cancelled' => true, 'total_amount' => 500],
            ['bplus_key' => 2714599, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256911/06364', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'vatable_amount' => 322.43, 'exempt_amount' => 1492,
                'vat_amount' => 22.57, 'total_amount' => 1837, 'due_date' => '2026-12-15',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660]]],
            ['bplus_key' => 2714605, 'kind' => 'credit_sale', 'book_code' => 'DSN', 'ref' => 'DSN256911/4861', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'exempt_amount' => 49, 'total_amount' => 49, 'due_date' => '2026-12-15',
                'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 49, 'amount' => 49]]],
            ['bplus_key' => 2715000, 'kind' => 'receipt', 'book_code' => 'RR2', 'ref' => 'RR26911/000800', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1049, 'transfer_amount' => 1049,
                'allocations' => [['sale_ref' => 'DS256911/06364', 'paid_amount' => 1000, 'transfer_amount' => 1000],
                    ['sale_ref' => 'DSN256911/4861', 'paid_amount' => 49, 'transfer_amount' => 49]]],
        ]];
    }
}
