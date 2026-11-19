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
        $this->assertNull(DB::table('bplus_sync_documents')->where('ref', 'B18-6911/00383')->value('problem'), 'ใบจองที่ยกเลิกก่อนเปิดขายไม่นับเป็นปัญหา');

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

    public function test_bplus_credit_note_reduces_ar_posts_reverse_gl_and_nets_reports(): void
    {
        config(['reports.phase_two_enabled' => true]);
        $export = $this->withOpeningBill($this->export());
        $note = [
            'bplus_key' => 2716001, 'kind' => 'credit_note', 'book_code' => 'IS', 'ref' => 'IS256911/00001',
            'doc_date' => '2026-11-16', 'customer_code' => '37120-0005', 'sale_ref' => 'DS256911/06364',
            'vatable_amount' => 100, 'exempt_amount' => 0, 'vat_amount' => 7, 'total_amount' => 107,
            'salesman_code' => '09', 'bplus_updated_at' => '2026-11-16 12:00:00',
            'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 1, 'unit_price' => 107, 'amount' => 107]],
        ];
        $export['documents'][] = $note;
        $this->importFile($export);

        $noteDocument = Document::where('doc_number', $note['ref'])->firstOrFail();
        $sale = Document::where('doc_number', $note['sale_ref'])->firstOrFail();
        $this->assertSame('CREDIT_NOTE', $noteDocument->documentType->code);
        $this->assertSame('IS', $noteDocument->documentBook->code);
        $this->assertSame($sale->id, $noteDocument->source_document_id);
        $this->assertSame($sale->doc_number, $noteDocument->reference);
        $this->assertSame('100.00', number_format((float) $noteDocument->subtotal_amount, 2, '.', ''));
        $this->assertSame('7.00', number_format((float) $noteDocument->vat_amount, 2, '.', ''));
        $this->assertSame('DS256911/06364', DB::table('bplus_sync_documents')->where('ref', $note['ref'])->value('sale_ref'));
        $this->actingAs(User::where('username', 'bplus_docs')->first()->forceFill(['branch_id' => $this->branch->id]));
        $this->get(\App\Http\Controllers\DocumentBrowserController::detailUrl($noteDocument->fresh(['documentType'])))
            ->assertOk()->assertSee($note['ref']);
        $syncNoteId = DB::table('bplus_sync_documents')->where('ref', $note['ref'])->value('id');
        $this->get(route('bplus.sync.show', $syncNoteId))->assertOk()->assertSee('ใบลดหนี้')->assertSee($note['sale_ref']);
        $this->assertSame('1837.00', number_format((float) DB::table('customer_open_items')->where('document_id', $sale->id)->value('gross_amount'), 2, '.', ''), 'ไม่แก้ยอดบิลขายเดิม');
        $this->assertSame('1730.00', number_format((float) DB::table('customer_open_items')->where('document_id', $sale->id)->value('net_amount'), 2, '.', ''), 'ยอดบิลสุทธิหลังลดหนี้');
        $this->assertSame(['partial', '730.00'], $this->openItem($sale));
        $this->assertSame('930.00', number_format((float) DB::table('customer_open_items')->sum('balance_amount'), 2, '.', ''), 'ยกมา + ขาย - ลดหนี้ - รับชำระ');

        $ar = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_AR)->firstOrFail();
        $revenue = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_SALES_REVENUE)->firstOrFail();
        $vat = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_VAT_OUTPUT)->firstOrFail();
        $journals = DB::table('gl_journals')->where('document_id', $noteDocument->id)->get();
        $this->assertCount(3, $journals);
        $this->assertSame('107.00', number_format((float) $journals->firstWhere('account_id', $ar->id)->credit, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $journals->firstWhere('account_id', $revenue->id)->debit, 2, '.', ''));
        $this->assertSame('7.00', number_format((float) $journals->firstWhere('account_id', $vat->id)->debit, 2, '.', ''));
        $this->assertSame('107.00', DecimalMath::sum($journals->pluck('debit'), 2));
        $this->assertSame('107.00', DecimalMath::sum($journals->pluck('credit'), 2));
        $this->assertSame(0, DB::table('stock_movements')->count(), 'ใบลดหนี้ BPlus ไม่ตัดหรือคืนสต๊อก');
        $this->assertSame(0, DB::table('stock_documents')->where('document_id', $noteDocument->id)->count(), 'ใบลดหนี้เป็นเอกสารการเงิน');

        // ซิงค์ซ้ำ: เอกสาร/GL ใช้รายการเดิม ไม่สร้างรายการซ้ำ
        $this->importFile($export);
        $this->assertSame(3, DB::table('gl_journals')->where('document_id', $noteDocument->id)->count());

        $role = Role::where('code', 'BPLUS_DOCS')->firstOrFail();
        $role->permissions()->sync(Permission::pluck('id')->all());
        DB::table('report_definitions')->whereIn('code', [
            'sales.sales_summary_by_customer', 'sales.sales_returns_by_document', 'sales.bplus_daily_sales_summary',
            'sales.bplus_credit_sales_by_document', 'sales.bplus_credit_sales_by_product',
            'tax.vat_sales', 'ar.ar_statement',
        ])->update(['enabled' => true, 'status' => 'available']);
        $user = User::where('username', 'bplus_docs')->firstOrFail();
        $user->branch_id = $this->branch->id;
        $user->save();
        $this->actingAs($user->fresh());
        $areaId = (int) DB::table('sales_areas')->where('code', 'B18')->value('id');
        $openReport = function (string $category, string $report, array $extra = []) use ($areaId) {
            $response = $this->get(route('reports.index', [
                'category' => $category, 'report' => $report, 'from' => '2026-11-01', 'to' => '2026-11-30',
                'branch_id' => $this->branch->id, 'sales_area_id' => $areaId,
            ] + $extra))->assertOk()->assertViewHas('selectedReport', $report);

            return $response->viewData('result');
        };
        $rows = fn (array $result) => collect($result['rows']);
        $salesSummary = $openReport('sales', 'sales_summary_by_customer');
        $documentSales = $openReport('sales', 'sales_returns_by_document');
        $dailySummary = $openReport('sales', 'bplus_daily_sales_summary');
        $bplusDocuments = $openReport('sales', 'bplus_credit_sales_by_document');
        $bplusProducts = $openReport('sales', 'bplus_credit_sales_by_product');
        $vatSales = $openReport('tax', 'vat_sales');
        $statement = $openReport('ar', 'ar_statement', ['customer_id' => Customer::where('code', '37120-0005')->value('id')]);

        $this->assertSame('1779.00', DecimalMath::sum($rows($salesSummary)->pluck('net_amount'), 2));
        $this->assertSame('107.00', DecimalMath::sum($rows($salesSummary)->pluck('return_amount'), 2));
        $this->assertSame('1779.00', number_format((float) $rows($documentSales)->firstWhere('salesman_name', 'รวมทั้งสิ้น')->total_amount, 2, '.', ''));
        $dailyRows = $rows($dailySummary);
        $this->assertSame('1763.43', DecimalMath::sum($dailyRows->pluck('subtotal_amount'), 2));
        $this->assertSame('15.57', DecimalMath::sum($dailyRows->pluck('vat_amount'), 2));
        $this->assertSame('1779.00', DecimalMath::sum($dailyRows->pluck('net_sales'), 2));
        $this->assertSame('1886.00', $bplusDocuments['summary']['net_sales'], 'รายงานใบขายเชื่อคงเป็นยอดขายขั้นต้น');
        $this->assertSame('1886.00', $bplusProducts['summary']['net_sales'], 'รายงานขายเชื่อแยกสินค้าไม่หักใบลดหนี้');
        $vatRows = $rows($vatSales)->reject(fn ($row) => data_get($row, 'party_name') === 'รวมทั้งสิ้น');
        $this->assertSame('15.57', DecimalMath::sum($vatRows->pluck('vat_amount'), 2));
        $creditRow = $rows($statement)->firstWhere('doc_number', $note['ref']);
        $this->assertSame('ใบลดหนี้', data_get($creditRow, 'document_type'));
        $this->assertSame('107.00', number_format((float) data_get($creditRow, 'credit_amount'), 2, '.', ''));
        $this->assertSame('107.00', $statement['summary']['credit_note_amount']);
        $this->assertSame('930.00', $statement['summary']['closing_balance']);

        $dailyService = app(\App\Services\Reports\DailySalesReport::class)->run('2026-11-01', '2026-11-30', [
            'branch_id' => $this->branch->id, 'q' => '', 'paginate' => false, 'per_page' => 50, 'page' => 1,
        ]);
        $this->assertSame('1779.00', $dailyService['summary']['net_sales']);

        // ยกเลิกจาก BPlus: ลูกหนี้และรายงานกลับเป็นยอดก่อนมีใบลดหนี้ และ GL กลับสุทธิเป็นศูนย์
        $cancelled = $export;
        $cancelled['documents'][count($cancelled['documents']) - 1]['is_cancelled'] = true;
        $cancelled['documents'][count($cancelled['documents']) - 1]['bplus_updated_at'] = '2026-11-17 09:00:00';
        $this->importFile($cancelled);
        $this->assertSame('cancelled', $noteDocument->fresh()->status);
        $this->assertSame(['partial', '837.00'], $this->openItem($sale));
        $this->assertSame('1037.00', number_format((float) DB::table('customer_open_items')->sum('balance_amount'), 2, '.', ''));
        $netByAccount = DB::table('gl_journals')->where('document_id', $noteDocument->id)
            ->selectRaw('account_id, sum(debit) - sum(credit) as net')->groupBy('account_id')->pluck('net');
        $this->assertTrue($netByAccount->every(fn ($net) => round((float) $net, 2) === 0.0));
        $this->assertSame(6, DB::table('gl_journals')->where('document_id', $noteDocument->id)->count());
        $this->importFile($cancelled);
        $this->assertSame(6, DB::table('gl_journals')->where('document_id', $noteDocument->id)->count(), 'ซิงค์ยกเลิกซ้ำไม่สร้างรายการกลับบัญชีซ้ำ');
        $this->assertSame('1886.00', DecimalMath::sum($rows($openReport('sales', 'sales_summary_by_customer'))->pluck('net_amount'), 2));
        $this->assertSame('22.57', DecimalMath::sum($rows($openReport('tax', 'vat_sales'))->reject(fn ($row) => data_get($row, 'party_name') === 'รวมทั้งสิ้น')->pluck('vat_amount'), 2));
    }

    public function test_credit_note_import_requires_a_sale_reference(): void
    {
        $export = $this->export();
        $export['documents'][] = ['bplus_key' => 2716002, 'kind' => 'credit_note', 'book_code' => 'IS', 'ref' => 'IS256911/00002', 'doc_date' => '2026-11-16'];

        $response = $this->post(route('bplus.sync.import.preview'), ['file' => $this->upload($export)]);

        $response->assertRedirect()->assertSessionHasErrors('file');
        $this->assertStringContainsString('ไม่มี sale_ref', session('errors')->first('file'));
    }

    public function test_bplus_isn_credit_note_preserves_zero_vat_in_gl_and_print(): void
    {
        $export = $this->export();
        $export['documents'][] = [
            'bplus_key' => 2716003, 'kind' => 'credit_note', 'book_code' => 'IS-N', 'ref' => 'ISN256911/00001',
            'doc_date' => '2026-11-16', 'customer_code' => '37120-0005', 'sale_ref' => 'DSN256911/4861',
            'vatable_amount' => 0, 'exempt_amount' => 49, 'vat_amount' => 0, 'total_amount' => 49,
            'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 49, 'amount' => 49, 'has_vat' => false]],
        ];
        $this->importFile($export);

        $note = Document::where('doc_number', 'ISN256911/00001')->firstOrFail();
        $this->assertSame('IS-N', $note->documentBook->code);
        $this->assertTrue((bool) $note->documentBook->no_vat);
        $this->assertSame('49.00', number_format((float) $note->subtotal_amount, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $note->vat_amount, 2, '.', ''));
        $journals = DB::table('gl_journals')->where('document_id', $note->id)->get();
        $this->assertCount(2, $journals, 'เล่ม IS-N ไม่มีรายการ VAT');
        $this->assertSame('49.00', number_format((float) $journals->sum('debit'), 2, '.', ''));
        $this->assertSame('49.00', number_format((float) $journals->sum('credit'), 2, '.', ''));

        $this->get(route('credit-debit-notes.print', $note))->assertOk()
            ->assertViewHas('baseAmount', 49.0)->assertViewHas('vatAmount', 0.0)->assertViewHas('vatRate', 0.0);
    }

    public function test_credit_note_exporter_keeps_bplus_reference_and_positive_amount_contract(): void
    {
        // ตรวจสัญญาแบบ offline เพราะ exporter นี้อ่าน BPlus จริงและห้ามรันระหว่างงานพัฒนา
        $exporter = file_get_contents(base_path('scripts/bplus-route-export.py'));
        foreach ([
            "t.DT_DOCCODE IN ('IS', 'IS-N')",
            'l.TRD_REFER_DI IN ({ROUTE_SALES})',
            '"sale_ref": sale_refs[0]',
            '"total_amount": abs(raw_total)',
            '"vat_amount": abs(num(note["ARD_B_VAT"]))',
            '"is_cancelled": note["DI_ACTIVE"] != 0',
            '"bplus_updated_at": stamp(note["DI_UPD_DATE"] or note["DI_CRE_DATE"])',
            '"lines": positive_lines',
            'if raw_total < 0:',
        ] as $contract) {
            $this->assertStringContainsString($contract, $exporter, 'exporter ต้องรักษาสัญญาใบลดหนี้: '.$contract);
        }
    }

    public function test_documents_show_in_the_normal_erp_lists(): void
    {
        $this->importFile($this->export());
        $this->actingAs(User::where('username', 'bplus_docs')->first()->forceFill(['branch_id' => $this->branch->id]));

        $html = $this->get(route('bookings.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('B18-6911/00382')->getContent();
        $total = strip_tags(substr($html, strpos($html, 'data-testid="bookings-total"'), 600));
        $this->assertMatchesRegularExpression('/1 ใบ\s+1,886\.00/u', preg_replace('/\s+/u', ' ', $total), 'ยอดรวมใบจองตามตัวกรอง');
        $booking = Document::where('doc_number', 'B18-6911/00382')->firstOrFail();
        $sale = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->get(route('bookings.show', $booking))->assertOk()->assertSee('DS256911/06364')->assertSee('มาจาก BPlus');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('DS256911/06364')->assertSee('1,837.00');
        $html = $this->get(route('ar-receipts.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->assertSee('RR26911/000800')->assertSee('837.00')->getContent();
        $block = fn (string $id) => preg_replace('/\s+/u', ' ', strip_tags(substr($html, strpos($html, 'data-testid="'.$id.'"'), 500)));
        $this->assertMatchesRegularExpression('/1 ใบ\s*1,049\.00/u', $block('receipts-total'), 'ยอดรวมใบเสร็จตามช่วง');
        $this->assertMatchesRegularExpression('/1 ราย · 1 บิล · รวม\s*837\.00/u', $block('outstanding-total'), 'ลูกหนี้ค้างรวม');
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

    public function test_bplus_salesman_code_maps_to_credit_sale_and_open_item_without_route_fallback(): void
    {
        $export = $this->export();
        $export['documents'][2]['salesman_code'] = '09';
        $export['documents'][3]['salesman_code'] = null;
        $this->importFile($export);

        $salesmanId = (int) DB::table('salesmen')->where('code', '09')->value('id');
        $ds = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $dsn = Document::where('doc_number', 'DSN256911/4861')->firstOrFail();
        $this->assertSame($salesmanId, (int) $ds->salesman_id);
        $this->assertSame($salesmanId, (int) DB::table('customer_open_items')->where('document_id', $ds->id)->value('salesman_id'));
        $this->assertNull($dsn->salesman_id, 'รหัสว่างต้องไม่รับพนักงานขายตั้งต้นจากสายขาย');
        $this->assertNull(DB::table('customer_open_items')->where('document_id', $dsn->id)->value('salesman_id'));

        $export['documents'][2]['salesman_code'] = 'UNKNOWN';
        $this->importFile($export);
        $this->assertNull(Document::where('doc_number', 'DS256911/06364')->value('salesman_id'), 'รหัสที่จับคู่ไม่ได้ต้องเป็นค่าว่าง');
        $this->assertNull(DB::table('customer_open_items')->where('document_id', $ds->id)->value('salesman_id'));
    }

    public function test_bplus_credit_sale_reports_filter_and_total_by_seller_customer_and_book(): void
    {
        $export = $this->export();
        $export['documents'][2]['salesman_code'] = '09';
        $export['documents'][3]['salesman_code'] = null;
        $export['documents'][] = [
            'bplus_key' => 2714606, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256911/06365', 'doc_date' => '2026-11-15',
            'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'exempt_amount' => 100, 'total_amount' => 100,
            'due_date' => '2026-12-15', 'salesman_code' => '09',
            'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 100, 'amount' => 100]],
        ];
        $this->importFile($export);

        Role::where('code', 'BPLUS_DOCS')->first()->permissions()->sync(Permission::pluck('id')->all());
        $this->actingAs(User::where('username', 'bplus_docs')->first()->fresh()->forceFill(['branch_id' => $this->branch->id]));
        $customerId = (int) Customer::where('code', '37120-0005')->value('id');

        $unassigned = $this->get(route('reports.index', [
            'category' => 'sales', 'report' => 'bplus_credit_sales_by_document',
            'from' => '2026-11-15', 'to' => '2026-11-15', 'salesman_id' => 'unassigned',
            'customer_id' => $customerId,
        ]))->assertOk()->viewData('result');
        $this->assertSame(1, $unassigned['total']);
        $this->assertSame('DSN256911/4861', $unassigned['rows'][0]->doc_number);
        $this->assertSame('49.00', number_format((float) $unassigned['summary']['net_sales'], 2, '.', ''));

        $document = $this->get(route('reports.index', [
            'category' => 'sales', 'report' => 'bplus_credit_sales_by_document',
            'from' => '2026-11-15', 'to' => '2026-11-15', 'salesman_id' => (string) DB::table('salesmen')->where('code', '09')->value('id'),
            'customer_id' => $customerId, 'document_book' => 'DS',
        ]))->assertOk()->viewData('result');
        $this->assertSame(2, $document['total']);
        $this->assertSame('DS256911/06364', $document['rows'][0]->doc_number);
        $this->assertSame('2', rtrim(rtrim(number_format((float) $document['rows'][0]->qty, 4, '.', ''), '0'), '.'));
        $this->assertSame('1814.43', number_format((float) $document['rows'][0]->subtotal_amount, 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $document['rows'][0]->vat_amount, 2, '.', ''));
        $this->assertSame('1837.00', number_format((float) $document['rows'][0]->total_amount, 2, '.', ''));
        $this->assertSame('DS256911/06365', $document['rows'][1]->doc_number);
        $this->assertSame('3', rtrim(rtrim(number_format((float) $document['rows'][2]->qty, 4, '.', ''), '0'), '.'));
        $this->assertSame('1914.43', number_format((float) $document['rows'][2]->subtotal_amount, 2, '.', ''), 'บรรทัดรวมท้ายรายงาน');
        $this->assertSame('1937.00', number_format((float) $document['rows'][2]->total_amount, 2, '.', ''));

        $product = $this->get(route('reports.index', [
            'category' => 'sales', 'report' => 'bplus_credit_sales_by_product',
            'from' => '2026-11-15', 'to' => '2026-11-15', 'salesman_id' => (string) DB::table('salesmen')->where('code', '09')->value('id'),
            'customer_id' => $customerId, 'document_book' => 'DS',
        ]))->assertOk()->viewData('result');
        $this->assertSame(2, $product['total']);
        $this->assertCount(4, $product['rows'], 'สองบรรทัดสินค้า + subtotal สินค้า + grand total');
        $this->assertSame('104032 - สินค้า 104032', $product['rows'][0]->product);
        $this->assertSame('2', rtrim(rtrim(number_format((float) $product['rows'][0]->qty, 4, '.', ''), '0'), '.'));
        $this->assertSame('830.00', number_format((float) $product['rows'][0]->unit_price, 2, '.', ''));
        $this->assertSame('1660.00', number_format((float) $product['rows'][0]->amount, 2, '.', ''));
        $this->assertSame('DS256911/06365', $product['rows'][1]->doc_number);
        $this->assertSame('รวมสินค้า 104032 - สินค้า 104032', $product['rows'][2]->product);
        $this->assertSame('3', rtrim(rtrim(number_format((float) $product['rows'][2]->qty, 4, '.', ''), '0'), '.'));
        $this->assertSame('1760.00', number_format((float) $product['rows'][2]->amount, 2, '.', ''));
        $this->assertSame('1760.00', number_format((float) $product['rows'][3]->amount, 2, '.', ''));
        $this->assertSame('21', rtrim(rtrim(number_format((float) $product['rows'][3]->qty, 4, '.', ''), '0'), '.'), 'รวมทั้งหมดเป็นหน่วยฐานแบบ BPlus: 2 ถุง*10 + 1 ถุง');

        Document::where('doc_number', 'DSN256911/4861')->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $withoutCancelled = $this->get(route('reports.index', [
            'category' => 'sales', 'report' => 'bplus_credit_sales_by_document',
            'from' => '2026-11-15', 'to' => '2026-11-15',
        ]))->assertOk()->viewData('result');
        $this->assertSame(2, $withoutCancelled['total'], 'รายงานไม่นับใบขายเชื่อที่ยกเลิก');
        $this->assertSame('DS256911/06364', $withoutCancelled['rows'][0]->doc_number);
        $this->assertSame('DS256911/06365', $withoutCancelled['rows'][1]->doc_number);
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

    public function test_open_booking_without_a_sale_is_reported_until_billed(): void
    {
        $export = $this->export();
        $export['documents'][1]['is_cancelled'] = false;
        $this->importFile($export);

        $this->assertNull(Document::where('doc_number', 'B18-6911/00383')->first());
        $this->assertStringContainsString('ยังไม่รู้ลูกค้า', (string) DB::table('bplus_sync_documents')->where('ref', 'B18-6911/00383')->value('problem'));
    }

    public function test_every_synced_document_opens_from_the_document_browser(): void
    {
        $this->importFile($this->withOpeningBill($this->export()));
        $this->actingAs(User::where('username', 'bplus_docs')->first()->forceFill(['branch_id' => $this->branch->id]));

        $docs = Document::where('source_system', 'bplus')->with(['documentType', 'saleBooking'])->get();
        $this->assertSame(['BOOKING', 'CREDIT_SALE', 'OPENING_AR', 'RECEIPT'], $docs->map(fn ($d) => $d->documentType->code)->unique()->sort()->values()->all());
        foreach ($docs as $doc) {
            $url = \App\Http\Controllers\DocumentBrowserController::detailUrl($doc);
            $this->assertNotNull($url, $doc->documentType->code.' '.$doc->doc_number.' ต้องเปิดดูได้');
            $this->get($url)->assertOk()->assertSee($doc->doc_number);
        }
        $opening = $docs->first(fn ($d) => $d->documentType->code === 'OPENING_AR');
        $this->get(\App\Http\Controllers\DocumentBrowserController::detailUrl($opening))->assertSee('ลูกหนี้ยกมา');
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

    public function test_sync_token_is_stored_hashed_and_shown_once(): void
    {
        $token = $this->issueSyncToken('B18');

        $this->assertStringStartsWith('bps_', $token);
        $this->assertSame(hash('sha256', $token), DB::table('bplus_sync_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($token, json_encode(DB::table('bplus_sync_tokens')->get()));
    }

    public function test_all_routes_sync_token_can_be_issued_without_a_route_and_is_listed_as_unscoped(): void
    {
        Artisan::call('bplus:sync-token', ['--issue' => true, '--all-routes' => true]);
        preg_match('/bps_\w+/', Artisan::output(), $match);

        $this->assertNotEmpty($match[0] ?? null);
        $token = DB::table('bplus_sync_tokens')->first();
        $this->assertNull($token->route_code);
        $this->assertSame('เครื่องซิงค์ทุกสาย', $token->name);

        Artisan::call('bplus:sync-token', ['--list' => true]);
        $this->assertStringContainsString('ทุกสาย', Artisan::output());
    }

    public function test_all_routes_token_provisions_missing_route_and_booking_book_on_hq(): void
    {
        $bookingTypeId = DB::table('document_types')->where('code', 'BOOKING')->value('id');
        DB::table('sales_areas')->where('code', 'B20')->delete();
        DB::table('document_books')->where('document_type_id', $bookingTypeId)->where('code', 'B20')->delete();
        Artisan::call('bplus:sync-token', ['--issue' => true, '--all-routes' => true]);
        preg_match('/bps_\w+/', Artisan::output(), $match);
        $export = ['route' => 'B20', 'documents' => []];

        $this->postJson(route('api.bplus.sync'), $export, ['Authorization' => 'Bearer '.$match[0]])
            ->assertStatus(202)->assertJson(['ok' => true, 'status' => 'processing', 'received' => 0]);

        $area = DB::table('sales_areas')->where('code', 'B20')->first();
        $book = DB::table('document_books')->where('id', $area->document_book_id)->first();
        $this->assertSame((int) $this->branch->id, (int) $area->branch_id);
        $this->assertSame('B20', $area->code);
        $this->assertSame('route', $area->area_type);
        $this->assertSame('B20', $book->code);
        $this->assertSame('B20', $book->prefix);
        $this->assertSame((int) $bookingTypeId, (int) $book->document_type_id);
        $this->assertSame('ok', DB::table('bplus_sync_runs')->value('status'));
    }

    public function test_b18_scoped_token_still_cannot_send_b20(): void
    {
        $bookingTypeId = DB::table('document_types')->where('code', 'BOOKING')->value('id');
        DB::table('sales_areas')->where('code', 'B20')->delete();
        DB::table('document_books')->where('document_type_id', $bookingTypeId)->where('code', 'B20')->delete();
        $token = $this->issueSyncToken('B18');

        $this->postJson(route('api.bplus.sync'), ['route' => 'B20', 'documents' => []], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)->assertJson(['ok' => false]);

        $this->assertDatabaseMissing('sales_areas', ['code' => 'B20']);
        $this->assertSame('rejected', DB::table('bplus_sync_runs')->value('status'));
    }

    public function test_sync_ping_accepts_valid_token_and_rejects_invalid_token_without_updating_usage(): void
    {
        $token = $this->issueSyncToken('B18');

        $this->getJson(route('api.bplus.sync.ping'), ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJson(['ok' => true]);
        $this->getJson(route('api.bplus.sync.ping'), ['Authorization' => 'Bearer bps_invalid'])
            ->assertUnauthorized()->assertJson(['ok' => false]);
        DB::table('bplus_sync_tokens')->where('token_hash', hash('sha256', $token))->update(['revoked_at' => now()]);
        $this->getJson(route('api.bplus.sync.ping'), ['Authorization' => "Bearer {$token}"])
            ->assertUnauthorized()->assertJson(['ok' => false]);

        $this->assertNull(DB::table('bplus_sync_tokens')->value('last_used_at'));
    }

    public function test_sync_api_refuses_missing_wrong_and_revoked_tokens(): void
    {
        $this->postJson(route('api.bplus.sync'), $this->export())->assertStatus(401);
        $this->postJson(route('api.bplus.sync'), $this->export(), ['Authorization' => 'Bearer bps_wrong'])->assertStatus(401);

        $token = $this->issueSyncToken('B18');
        Artisan::call('bplus:sync-token', ['--revoke' => DB::table('bplus_sync_tokens')->value('id')]);
        $this->postJson(route('api.bplus.sync'), $this->export(), ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        $this->assertSame(0, DB::table('bplus_sync_documents')->count());
    }

    public function test_sync_token_cannot_send_another_route(): void
    {
        DB::table('sales_areas')->insert(['code' => 'B20', 'name' => 'B20', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $token = $this->issueSyncToken('B20');

        $this->postJson(route('api.bplus.sync'), $this->export(), ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)->assertJson(['ok' => false]);

        $this->assertSame(0, DB::table('bplus_sync_documents')->count());
        $this->assertSame('rejected', DB::table('bplus_sync_runs')->value('status'));
    }

    public function test_sync_api_imports_gzip_upload_like_the_upload_page_and_records_the_run(): void
    {
        $token = $this->issueSyncToken('B18');
        $export = $this->export();

        $response = $this->call('POST', route('api.bplus.sync'), [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_CONTENT_ENCODING' => 'gzip',
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], gzencode(json_encode($export, JSON_UNESCAPED_UNICODE)));

        // ตอบ 202 ทันที แล้วบันทึกหลังตอบกลับ (ทั้งสายใช้เวลาเกิน timeout 60 วินาทีของ gateway)
        $response->assertStatus(202)->assertJson(['ok' => true, 'status' => 'processing', 'received' => count($export['documents'])]);
        $this->assertSame(count($export['documents']), DB::table('bplus_sync_documents')->count());
        $sale = Document::where('doc_number', 'DS256911/06364')->firstOrFail();
        $this->assertSame('1837.00', number_format((float) $sale->total_amount, 2, '.', ''));
        $run = DB::table('bplus_sync_runs')->first();
        $this->assertSame('ok', $run->status);
        $this->assertNotNull(DB::table('bplus_sync_tokens')->value('last_used_at'));
        $this->get(route('bplus.sync.index'))->assertOk()->assertSee('สำเร็จ');
    }

    public function test_sync_run_status_is_readable_only_with_the_token_that_sent_it(): void
    {
        $token = $this->issueSyncToken('B18');
        $response = $this->postJson(route('api.bplus.sync'), $this->export(), ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(202);
        $runId = $response->json('run_id');

        $this->getJson(route('api.bplus.sync.run', $runId), ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJson(['id' => $runId, 'status' => 'ok', 'ok' => true])
            ->assertJsonPath('erp_documents', DB::table('bplus_sync_runs')->where('id', $runId)->value('erp_documents'));

        $other = $this->issueSyncToken('B18');
        $this->getJson(route('api.bplus.sync.run', $runId), ['Authorization' => "Bearer {$other}"])->assertNotFound();
        $this->getJson(route('api.bplus.sync.run', $runId))->assertUnauthorized();

        DB::table('bplus_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'message' => 'ทดสอบล้ม']);
        $this->getJson(route('api.bplus.sync.run', $runId), ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJson(['status' => 'failed', 'ok' => false, 'message' => 'ทดสอบล้ม']);
    }

    public function test_sync_run_counts_new_changed_and_unchanged_documents(): void
    {
        $token = $this->issueSyncToken('B18');
        $send = fn (array $export) => $this->getJson(route('api.bplus.sync.run',
            $this->postJson(route('api.bplus.sync'), $export, ['Authorization' => "Bearer {$token}"])->assertStatus(202)->json('run_id')),
            ['Authorization' => "Bearer {$token}"])->assertOk();

        // รอบแรก: ทุกใบใหม่
        $send($this->export())->assertJson(['status' => 'ok', 'received' => 5, 'new_documents' => 5, 'changed_documents' => 0, 'unchanged_documents' => 0]);

        // ส่งไฟล์เดิมซ้ำ (แบบที่โปรแกรมในร้านส่งใบ 1-2 วันล่าสุดซ้ำทุกรอบ): ไม่มีอะไรเปลี่ยน
        $send($this->export())->assertJson(['received' => 5, 'new_documents' => 0, 'changed_documents' => 0, 'unchanged_documents' => 5]);

        // แก้ยอดใบจอง 1 ใบ + ใบจองใหม่ 1 ใบ
        $edited = $this->export();
        $edited['documents'][0]['total_amount'] = 2000;
        $edited['documents'][0]['lines'] = [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'qty' => 1, 'unit_price' => 2000, 'amount' => 2000]];
        $edited['documents'][] = ['bplus_key' => 2713600, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00390', 'doc_date' => '2026-11-16',
            'customer_code' => '37120-0005', 'total_amount' => 226, 'created_by' => 'BANKKY',
            'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 226, 'amount' => 226]]];
        $run = $send($edited)->assertJson(['received' => 6, 'new_documents' => 1, 'changed_documents' => 1, 'unchanged_documents' => 4]);

        $this->get(route('bplus.sync.index'))->assertOk()
            ->assertSee('ใหม่ 1 · แก้ไข 1 · เหมือนเดิม 4')
            ->assertSee('เอกสารในระบบ '.number_format($run->json('erp_documents')).' ใบ');
    }

    private function issueSyncToken(string $route): string
    {
        Artisan::call('bplus:sync-token', ['--issue' => true, '--route' => $route, '--name' => 'BK Server']);
        preg_match('/bps_\w+/', Artisan::output(), $match);

        return $match[0];
    }

    public function test_bplus_report_catalog_and_totals_match_one_imported_fixture(): void
    {
        config(['reports.phase_two_enabled' => true]);
        $export = $this->withOpeningBill($this->export());
        $export['documents'][] = [
            'bplus_key' => 9002715001, 'kind' => 'opening_ar', 'book_code' => 'DS', 'ref' => 'DS256911/10001',
            'doc_date' => '2026-11-10', 'customer_code' => '37120-0005', 'total_amount' => 20, 'original_amount' => 20,
            'due_date' => '2026-12-10',
        ];
        $export['documents'][] = [
            'bplus_key' => 2715002, 'kind' => 'booking', 'book_code' => 'B18', 'ref' => 'B18-6911/00384',
            'doc_date' => '2026-11-16', 'customer_code' => '37120-0005', 'total_amount' => 250, 'salesman_code' => '09',
        ];
        $export['documents'][2]['due_date'] = '2020-01-01';
        $export['documents'][3]['due_date'] = '2020-01-01';
        $export['documents'][3]['salesman_code'] = null;
        $export['documents'][4]['cash_amount'] = 150;
        $export['documents'][4]['transfer_amount'] = 1178.63;
        $export['documents'][4]['other_amount'] = 20;
        $export['documents'][4]['short_amount'] = 0.37;
        $export['documents'][] = [
            'bplus_key' => 2720001, 'kind' => 'credit_sale', 'book_code' => 'DS', 'ref' => 'DS256911/99998',
            'doc_date' => '2026-11-15', 'customer_code' => '37120-0005', 'exempt_amount' => 100,
            'total_amount' => 100, 'due_date' => '2020-01-01', 'salesman_code' => '09',
            'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 100, 'amount' => 100]],
        ];
        $export['documents'][] = [
            'bplus_key' => 2715003, 'kind' => 'receipt', 'book_code' => 'RR2', 'ref' => 'RR26911/000801',
            'doc_date' => '2026-11-17', 'customer_code' => '37120-0005', 'total_amount' => 1,
            'cash_amount' => 1, 'allocations' => [['sale_ref' => 'DS256911/99998', 'paid_amount' => 1, 'cash_amount' => 1]],
        ];
        $cancelledSale = $export['documents'][2];
        $cancelledSale['bplus_key'] = 2719999;
        $cancelledSale['ref'] = 'DS256911/99999';
        $cancelledSale['is_cancelled'] = true;
        $cancelledSale['total_amount'] = 900;
        $export['documents'][] = $cancelledSale;
        $this->importFile($export);

        // ทำให้ subtotal ต่างจาก total - VAT เพื่อยืนยันว่ารายงานภาษีอ่าน field ตามเอกสารโดยตรง
        DB::table('documents')->where('doc_number', 'DS256911/06364')->update(['subtotal_amount' => 1800]);
        $otherAreaId = DB::table('sales_areas')->insertGetId([
            'code' => 'B17', 'name' => 'สายทดสอบอื่น', 'branch_id' => $this->branch->id,
            'default_salesman_id' => null, 'is_active' => true,
        ]);
        DB::table('documents')->where('doc_number', 'DS256911/99998')->update(['sales_area_id' => $otherAreaId]);
        DB::table('documents')->where('doc_number', 'RR26911/000801')->update(['sales_area_id' => $otherAreaId]);
        DB::table('documents')->where('doc_number', 'DSN256911/4861')->update([
            'sales_user_id' => User::where('username', 'bplus_docs')->value('id'),
        ]);
        $bookingNumbers = ['B18-6911/00382', 'B18-6911/00384'];
        $bookingDocumentIds = Document::whereIn('doc_number', $bookingNumbers)->pluck('id');
        $salesmanId = DB::table('salesmen')->where('code', '09')->value('id');
        DB::table('documents')->whereIn('id', $bookingDocumentIds)->update(['salesman_id' => $salesmanId]);
        DB::table('sale_bookings')->whereIn('document_id', $bookingDocumentIds)->update([
            'salesman_id' => null,
            'sales_area_id' => $otherAreaId,
        ]);
        $bookingSalesmanName = DB::table('salesmen')->where('id', $salesmanId)->value('name');
        $role = Role::where('code', 'BPLUS_DOCS')->firstOrFail();
        $role->permissions()->sync(Permission::pluck('id')->all());
        DB::table('report_definitions')->whereIn('code', ['sales.sales_summary_by_customer'])->update(['enabled' => true]);
        $user = User::where('username', 'bplus_docs')->firstOrFail();
        $user->branch_id = $this->branch->id;
        $user->save();
        $this->actingAs($user->fresh());

        $customerId = (int) Customer::where('code', '37120-0005')->value('id');
        $areaId = (int) DB::table('sales_areas')->where('code', 'B18')->value('id');
        $openReport = function (string $category, string $report, array $extra = []) use ($areaId) {
            $response = $this->get(route('reports.index', ['category' => $category, 'report' => $report,
                'from' => '2026-11-01', 'to' => '2026-11-30', 'branch_id' => $this->branch->id,
                'sales_area_id' => $areaId] + $extra))->assertOk()->assertViewHas('selectedReport', $report);
            return $response->viewData('result');
        };
        $rows = fn (array $result) => collect($result['rows']);
        $sum = fn (array $result, string $field) => DecimalMath::sum($rows($result)->pluck($field), 2);

        $reports = [
            'credit_sales' => ['sales', 'credit_sales'],
            'credit_sale_documents' => ['documents', 'credit_sale_documents'],
            'sales_by_seller' => ['sales', 'sales_by_seller'],
            'sales_summary_by_customer' => ['sales', 'sales_summary_by_customer'],
            'top_products' => ['sales', 'top_products'],
            'sales_by_category' => ['sales', 'sales_by_category'],
            'booking_documents' => ['documents', 'booking_documents'],
            'pending_bookings' => ['sales', 'pending_bookings'],
            'by_branch_seller' => ['booking', 'by_branch_seller'],
            'bplus_credit_sales_by_document' => ['sales', 'bplus_credit_sales_by_document'],
            'bplus_credit_sales_by_product' => ['sales', 'bplus_credit_sales_by_product'],
            'ar_summary' => ['ar', 'ar_summary'],
            'ar_summary_bplus' => ['ar', 'ar_summary_bplus'],
            'ar_aging' => ['ar', 'ar_aging'],
            'open_items' => ['ar', 'open_items'],
            'overdue_customers' => ['ar', 'overdue_customers'],
            'ar_detail_short' => ['ar', 'ar_detail_short'],
            'ar_detail_full' => ['ar', 'ar_detail_full'],
            'ar_overdue_detail' => ['ar', 'ar_overdue_detail'],
            'receipt_documents' => ['documents', 'receipt_documents'],
            'payment_documents' => ['payment', 'payment_documents'],
            'payment_allocations' => ['payment', 'payment_allocations'],
            'vat_sales' => ['tax', 'vat_sales'],
            'gl_journals' => ['payment', 'gl_journals'],
            'document_list' => ['documents', 'document_list'],
            'documents_summary' => ['documents', 'documents_summary'],
            'bplus_daily_sales_summary' => ['sales', 'bplus_daily_sales_summary'],
            'bplus_receipt_allocations' => ['payment', 'bplus_receipt_allocations'],
        ];
        $reportCodes = collect($reports)->map(fn ($report, $key) => $report[0].'.'.$key)
            ->push('ar.ar_statement')->all();
        DB::table('report_definitions')->whereIn('code', $reportCodes)->update(['enabled' => true, 'status' => 'available']);
        $results = [];
        foreach ($reports as $key => [$category, $report]) {
            $results[$key] = $openReport($category, $report);
        }
        $results['ar_statement'] = $openReport('ar', 'ar_statement', ['customer_id' => $customerId]);
        $areaColumnReports = [
            'credit_sales', 'credit_sale_documents', 'sales_by_seller', 'sales_summary_by_customer', 'sales_by_category',
            'booking_documents', 'pending_bookings', 'by_branch_seller', 'bplus_credit_sales_by_document',
            'bplus_credit_sales_by_product', 'ar_summary', 'ar_summary_bplus', 'ar_aging', 'open_items',
            'overdue_customers', 'ar_detail_short', 'ar_detail_full', 'ar_overdue_detail', 'ar_statement',
            'receipt_documents', 'payment_documents', 'payment_allocations', 'vat_sales', 'gl_journals',
            'document_list', 'documents_summary', 'bplus_daily_sales_summary', 'bplus_receipt_allocations',
        ];
        foreach ($areaColumnReports as $reportCode) {
            $this->assertTrue(
                collect($results[$reportCode]['columns'])->contains('key', 'sales_area_name'),
                $reportCode.' ต้องแสดงสายขาย',
            );
        }

        $this->assertSame(1886.0, (float) $sum($results['credit_sales'], 'total_amount'), 'credit_sales');
        $this->assertSame(1886.0, (float) $sum($results['credit_sale_documents'], 'total_amount'), 'credit_sale_documents');
        $this->assertSame(1886.0, (float) $sum($results['sales_by_seller'], 'amount'), 'sales_by_seller');
        $this->assertSame(1886.0, (float) $sum($results['sales_summary_by_customer'], 'net_amount'), 'sales_summary_by_customer '.json_encode($results['sales_summary_by_customer']['rows']));
        $this->assertSame(1886.0, (float) $sum($results['sales_by_category'], 'amount'), 'sales_by_category');
        $this->assertSame(0, $results['top_products']['total'], 'สินค้าขายดีเป็นรายงาน POS โดยเฉพาะ ไม่มีเอกสาร BPlus');
        $this->assertSame(2136.0, (float) $sum($results['booking_documents'], 'total_amount'), 'ใบจองแสดงแยกและไม่นำไปรวมยอดขาย');
        $this->assertSame(1, $results['pending_bookings']['total'], 'ใบจองที่ยังไม่แปลงขายแสดงแยกจากยอดขาย');
        $this->assertSame(250.0, (float) $sum($results['pending_bookings'], 'total_amount'));
        $pendingBooking = $rows($results['pending_bookings'])->first();
        $this->assertSame('B18 - ม่วง-เลิง-อำนาจ-มุก', $pendingBooking->sales_area_name);
        $this->assertSame($bookingSalesmanName, $pendingBooking->salesman_name);
        $branchSellerRows = $rows($results['by_branch_seller']);
        $this->assertSame(2136.0, (float) $sum($results['by_branch_seller'], 'amount'));
        $this->assertTrue($branchSellerRows->every(fn ($row) => $row->sales_area_name === 'B18 - ม่วง-เลิง-อำนาจ-มุก'));
        $this->assertSame(2136.0, (float) DecimalMath::sum($branchSellerRows->where('salesman_name', $bookingSalesmanName)->pluck('amount'), 2));
        $this->assertSame('1886.00', $results['bplus_credit_sales_by_document']['summary']['net_sales']);
        $this->assertSame('1886.00', $results['bplus_credit_sales_by_product']['summary']['net_sales']);
        $this->assertSame('1849.00', $results['bplus_credit_sales_by_product']['summary']['subtotal_amount']);
        $this->assertSame('22.57', $results['bplus_credit_sales_by_product']['summary']['vat_amount']);
        $this->assertTrue($rows($results['sales_by_seller'])->contains(fn ($row) => $row->seller_name === 'ไม่ระบุ'));
        $this->assertSame(1057.0, (float) $results['ar_summary']['summary']['net_sales']);
        $this->assertSame(1057.0, (float) $results['ar_summary_bplus']['summary']['net_sales']);
        $this->assertSame(1057.0, (float) $sum($results['ar_aging'], 'amount'));
        $this->assertSame(1057.0, (float) $sum($results['open_items'], 'balance_amount'));
        $this->assertSame(1037.0, (float) $sum($results['overdue_customers'], 'balance_amount'));
        $this->assertSame(1057.0, (float) $sum($results['ar_detail_short'], 'balance_amount'));
        $this->assertSame(1057.0, (float) $sum($results['ar_detail_full'], 'balance_amount'));
        $this->assertSame(1037.0, (float) $sum($results['ar_overdue_detail'], 'balance_amount'));
        $this->assertSame('500.00', $results['ar_statement']['summary']['opening_balance']);
        $this->assertSame('20.00', $results['ar_statement']['summary']['opening_document_amount']);
        $this->assertSame('1886.00', $results['ar_statement']['summary']['sales_amount']);
        $this->assertSame('1349.00', $results['ar_statement']['summary']['receipt_amount']);
        $this->assertSame('1057.00', $results['ar_statement']['summary']['closing_balance']);
        $this->assertSame(1349.0, (float) $sum($results['receipt_documents'], 'total_amount'));
        $this->assertSame(1, $results['payment_documents']['total']);
        $this->assertSame(1349.0, (float) $sum($results['payment_allocations'], 'allocated_amount'));
        $this->assertSame(1800.0, (float) data_get($rows($results['vat_sales'])->firstWhere('doc_number', 'DS256911/06364'), 'base_amount'));
        $vatDocuments = $rows($results['vat_sales'])->reject(fn ($row) => data_get($row, 'party_name') === 'รวมทั้งสิ้น');
        $this->assertSame(22.57, (float) DecimalMath::sum($vatDocuments->pluck('vat_amount'), 2));
        $this->assertSame(1849.0, (float) DecimalMath::sum($vatDocuments->pluck('base_amount'), 2));
        $creditSummary = $rows($results['documents_summary'])->first(fn ($row) => str_starts_with($row->document_type, 'CREDIT_SALE -'));
        $this->assertSame(1886.0, (float) $creditSummary->amount);
        $this->assertTrue($rows($results['document_list'])->contains(fn ($row) => $row->doc_number === 'DS256911/99999' && $row->status === 'cancelled'));
        $this->assertFalse($rows($results['document_list'])->contains(fn ($row) => $row->doc_number === 'DS256911/99998'));
        $this->assertSame(1, $results['bplus_daily_sales_summary']['total']);

        $daily = $rows($results['bplus_daily_sales_summary'])->first();
        $this->assertSame(2, (int) $daily->bill_count);
        $this->assertSame('1849.00', number_format((float) $daily->subtotal_amount, 2, '.', ''));
        $this->assertSame('22.57', number_format((float) $daily->vat_amount, 2, '.', ''));
        $this->assertSame('1886.00', number_format((float) $daily->net_sales, 2, '.', ''));
        $this->assertSame('150.00', number_format((float) $daily->cash_amount, 2, '.', ''));
        $this->assertSame('1178.63', number_format((float) $daily->transfer_amount, 2, '.', ''));

        $receipt = $rows($results['bplus_receipt_allocations'])->first();
        $this->assertSame('RR26911/000800', $receipt->receipt_no);
        $this->assertSame(1, $results['bplus_receipt_allocations']['total']);
        $this->assertSame('1349.00', number_format((float) $receipt->applied_total, 2, '.', ''));
        $this->assertSame('150.00', number_format((float) $receipt->cash_amount, 2, '.', ''));
        $this->assertSame('1178.63', number_format((float) $receipt->transfer_amount, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $receipt->discount_amount, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $receipt->wht_amount, 2, '.', ''));
        $this->assertSame('20.00', number_format((float) $receipt->other_amount, 2, '.', ''));
        $this->assertSame('0.37', number_format((float) $receipt->short_amount, 2, '.', ''));

        $glDebits = DecimalMath::sum($rows($results['gl_journals'])->pluck('debit'), 2);
        $glCredits = DecimalMath::sum($rows($results['gl_journals'])->pluck('credit'), 2);
        $this->assertSame('3255.00', $glDebits);
        $this->assertSame($glDebits, $glCredits);
        $this->assertTrue($rows($results['credit_sales'])->every(fn ($row) => $row->sales_area_name === 'B18 - ม่วง-เลิง-อำนาจ-มุก'));
    }

    public function test_resync_skips_unchanged_documents_but_reprocesses_changed_problem_and_full_runs(): void
    {
        $export = $this->export();
        $this->importFile($export);
        $materializer = app(\App\Services\Bplus\BplusDocumentMaterializer::class);
        $total = DB::table('bplus_sync_documents')->where('route_code', 'B18')->count();
        $glBefore = DB::table('gl_journals')->count();
        $balanceBefore = DB::table('customer_open_items')->sum('balance_amount');

        $again = $materializer->run('B18');
        $this->assertSame(0, $again['processed'], 'ส่งไฟล์เดิมซ้ำ ไม่ต้องประมวลผลใบไหนใหม่');
        $this->assertSame($glBefore, DB::table('gl_journals')->count());
        $this->assertEquals($balanceBefore, DB::table('customer_open_items')->sum('balance_amount'));

        foreach ($export['documents'] as &$doc) {
            if ($doc['ref'] === 'DS256911/06364') {
                $doc['total_amount'] = 1900;
                $doc['bplus_updated_at'] = '2026-11-16 09:00:00';
            }
        }
        unset($doc);
        $this->importFile($export);
        $this->assertSame('1900.00', number_format((float) Document::where('doc_number', 'DS256911/06364')->value('total_amount'), 2, '.', ''));
        $changed = $materializer->run('B18');
        $this->assertSame(0, $changed['processed'], 'หลังนำเข้าใบที่แก้แล้ว รอบถัดไปไม่ต้องทำซ้ำอีก');

        DB::table('bplus_sync_documents')->where('ref', 'DSN256911/4861')->update(['problem' => 'ทดสอบปัญหาค้าง']);
        $retry = $materializer->run('B18');
        $this->assertSame(1, $retry['processed'], 'ใบที่มีปัญหาค้างต้องลองใหม่ทุกรอบ');

        $full = $materializer->run('B18', true);
        $this->assertSame($total, $full['processed']);
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
                'vat_amount' => 22.57, 'total_amount' => 1837, 'due_date' => '2026-12-15', 'salesman_code' => '09',
                'lines' => [['seq' => 1, 'sku_code' => '104032', 'unit_name' => 'ถุง*10', 'unit_factor' => 10, 'qty' => 2, 'unit_price' => 830, 'amount' => 1660]]],
            ['bplus_key' => 2714605, 'kind' => 'credit_sale', 'book_code' => 'DSN', 'ref' => 'DSN256911/4861', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'booking_ref' => 'B18-6911/00382', 'exempt_amount' => 49, 'total_amount' => 49, 'due_date' => '2026-12-15',
                'salesman_code' => '09',
                'lines' => [['seq' => 1, 'sku_code' => '501035', 'unit_name' => 'ถุง', 'unit_factor' => 1, 'qty' => 1, 'unit_price' => 49, 'amount' => 49]]],
            ['bplus_key' => 2715000, 'kind' => 'receipt', 'book_code' => 'RR2', 'ref' => 'RR26911/000800', 'doc_date' => '2026-11-15',
                'customer_code' => '37120-0005', 'total_amount' => 1049, 'transfer_amount' => 1049,
                'allocations' => [['sale_ref' => 'DS256911/06364', 'paid_amount' => 1000, 'transfer_amount' => 1000],
                    ['sale_ref' => 'DSN256911/4861', 'paid_amount' => 49, 'transfer_amount' => 49]]],
        ]];
    }
}
