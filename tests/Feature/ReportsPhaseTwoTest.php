<?php

namespace Tests\Feature;

use App\Models\AccountingExportRun;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\PaymentDocument;
use App\Models\PaymentLine;
use App\Models\Permission;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\TransportJob;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\MonthlyAccountingExportService;
use App\Services\Reports\StockQuantityFormatter;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private function branch(string $code): Branch
    {
        return Branch::create(['code' => $code, 'name_th' => 'สาขา '.$code, 'is_active' => true]);
    }

    private function viewer(?Branch $branch, bool $profit = false, bool $all = false): User
    {
        $user = User::factory()->create(['branch_id' => $branch?->id, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'R'.uniqid(), 'name' => 'ทดสอบ']);
        foreach (array_merge(['reports.view', 'reports.export', 'sales.manage', 'finance.manage', 'stock.manage', 'transport.manage'], $profit ? ['management.view'] : [], $all ? ['reports.all_branches'] : []) as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function document(Branch $branch, string $code, string $number, string $amount, string $vat = '0', string $status = 'active', array $extra = []): Document
    {
        $type = DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code]);

        return Document::create($extra + ['document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => $number, 'doc_date' => '2026-11-09', 'total_amount' => $amount, 'vat_amount' => $vat, 'status' => $status]);
    }

    private function stock(Document $document, ?string $cost): Product
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'KG'], ['name' => 'กก.', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'P'.uniqid(), 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'average_cost' => 999, 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'W'.uniqid(), 'name' => 'คลัง', 'branch_id' => $document->branch_id]);
        $location = WarehouseLocation::create(['code' => 'L'.uniqid(), 'name' => 'ชั้น', 'warehouse_id' => $warehouse->id]);
        $sd = DB::table('stock_documents')->insertGetId(['document_id' => $document->id, 'total_qty' => 1, 'total_items' => 1]);
        DB::table('stock_document_items')->insert(['stock_document_id' => $sd, 'seq' => 1, 'product_id' => $product->id, 'warehouse_location_id' => $location->id, 'qty' => 1, 'unit_price' => $document->total_amount, 'vat_amount' => $document->vat_amount, 'cost_amount' => $cost]);

        return $product;
    }

    private function report(string $report, string $category = 'sales', array $extra = [])
    {
        DB::table('report_definitions')->where('code', $category.'.'.$report)->update(['enabled' => true, 'status' => 'available']);

        return $this->get('/reports?'.http_build_query($extra + ['category' => $category, 'report' => $report, 'from' => '2026-11-01', 'to' => '2026-11-30', 'branch_id' => 'all']));
    }

    public function test_daily_signed_totals_use_snapshots_and_do_not_duplicate_pos(): void
    {
        $branch = $this->branch('A');
        $this->actingAs($this->viewer($branch, true, true));
        $sale = $this->document($branch, 'CASH_SALE', 'POS-DOC', '1070', '70');
        $product = $this->stock($sale, '600');
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T', 'name' => 'T']);
        PosReceipt::create(['pos_terminal_id' => $terminal->id, 'document_id' => $sale->id, 'receipt_no' => 'POS1', 'receipt_date' => '2026-11-09 10:00', 'net_sales' => 1070, 'vat_amount' => 70, 'status' => 'completed']);
        $return = $this->document($branch, 'SALE_RETURN', 'RET', '107', '7');
        $this->stock($return, '60');
        $this->document($branch, 'CREDIT_NOTE', 'CN', '10.70', '.70');
        $this->document($branch, 'DEBIT_NOTE', 'DN', '21.40', '1.40');
        $this->document($branch, 'CREDIT_SALE', 'PENDING', '999', '0', 'pending');
        $this->document($branch, 'CREDIT_SALE', 'VOID', '999', '0', 'cancelled');
        $result = $this->report('daily_by_channel')->assertOk()->viewData('result');
        $this->assertSame('973.70', $result['summary']['net_sales']);
        $this->assertSame('63.70', $result['summary']['vat_amount']);
        $this->assertSame('370.00', DecimalMath::sum($result['rows']->pluck('gross_profit'), 2));
        $this->assertSame(4, $result['rows']->sum('bill_count'));
        $product->update(['average_cost' => 1]);
        DB::table('vat_rates')->update(['rate_percent' => 15]);
        $again = $this->report('daily_by_channel')->assertOk()->viewData('result');
        $this->assertEquals($result['rows']->toArray(), $again['rows']->toArray());
        $branchRows = $this->report('sales_by_branch')->assertOk()->viewData('result')['rows'];
        $this->assertSame('973.70', $branchRows->sole()->amount);
    }

    public function test_sales_admin_payload_print_csv_and_xlsx_contain_no_cost_fields_or_other_branch(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->stock($this->document($own, 'CASH_SALE', 'OWN', '107', '7'), '58.34');
        $this->stock($this->document($other, 'CASH_SALE', 'SECRET', '9999', '0'), '7777');
        $this->actingAs($this->viewer($own));
        foreach ([[], ['print' => 1], ['branch_id' => $other->id]] as $extra) {
            $result = $this->report('daily_by_channel', 'sales', $extra)->assertOk()->viewData('result');
            $this->assertSame('107.00', $result['summary']['net_sales']);
            $this->assertNotContains('cogs_amount', array_column($result['columns'], 'key'));
            $this->assertNotContains('gross_profit', array_keys((array) $result['rows']->sole()));
            $this->assertStringNotContainsString('7777', json_encode($result));
            $this->assertStringNotContainsString('58.34', json_encode($result));
        }
        $csv = $this->report('daily_by_channel', 'sales', ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringNotContainsString('ต้นทุน', $csv);
        $this->assertStringNotContainsString('58.34', $csv);
        $this->assertStringNotContainsString('9999', $csv);
        $xlsx = $this->report('daily_by_channel', 'sales', ['export' => 'xlsx'])->assertOk();
        $zip = new \ZipArchive;
        $zip->open($xlsx->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('ต้นทุน', $xml);
        $this->assertStringNotContainsString('58.34', $xml);
        $this->assertStringNotContainsString('9999', $xml);
        $this->getJson('/executive/data')->assertOk()->assertJsonMissingPath('today.profit')->assertJsonMissingPath('today.cost');
    }

    public function test_missing_cost_is_unknown_even_after_product_cost_changes(): void
    {
        $branch = $this->branch('A');
        $this->actingAs($this->viewer($branch, true, true));
        $product = $this->stock($this->document($branch, 'CASH_SALE', 'MISSING', '107', '7'), null);
        foreach ([999, 5] as $cost) {
            $product->update(['average_cost' => $cost]);
            $row = $this->report('daily_by_channel')->assertOk()->viewData('result')['rows']->sole();
            $this->assertNull($row->cogs_amount);
            $this->assertNull($row->gross_profit);
            $this->assertSame(1, $row->missing_cost_count);
        }
    }

    public function test_daily_kpi_covers_all_pages_and_export_uses_same_filters(): void
    {
        $branch = $this->branch('A');
        $this->actingAs($this->viewer($branch, true, true));
        for ($i = 1; $i <= 12; $i++) {
            $doc = $this->document($branch, 'CASH_SALE', 'DOC'.$i, '10.01');
            $doc->update(['doc_date' => sprintf('2026-11-%02d', $i)]);
        }
        $first = $this->report('daily_by_channel', 'sales', ['per_page' => 10])->assertOk()->viewData('result');
        $second = $this->report('daily_by_channel', 'sales', ['per_page' => 10, 'page' => 2])->assertOk()->viewData('result');
        $this->assertSame(12, $first['total']);
        $this->assertCount(10, $first['rows']);
        $this->assertCount(2, $second['rows']);
        $this->assertSame('120.12', $first['summary']['net_sales']);
        $csv = $this->report('daily_by_channel', 'sales', ['export' => 'csv', 'per_page' => 10, 'page' => 2])->assertOk()->streamedContent();
        $this->assertSame(12, substr_count($csv, '10.01'));
        $filtered = $this->report('daily_by_channel', 'sales', ['q' => 'DOC12'])->assertOk()->viewData('result');
        $this->assertSame('10.01', $filtered['summary']['net_sales']);
    }

    public function test_booking_split_uses_booking_date_and_excludes_cancelled_or_unconfirmed_sales(): void
    {
        $branch = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($branch));
        $booking = $this->document($branch, 'BOOKING', 'BK', '160.50');
        $sb = SaleBooking::create(['document_id' => $booking->id, 'status' => 'converted_to_sale']);
        $type = DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ขายเชื่อ']);
        foreach ([['DS', '107', '7', false], ['DSN', '53.50', '0', true]] as [$code, $amount, $vat, $noVat]) {
            $book = DocumentBook::create(['code' => $code, 'name' => $code, 'document_type_id' => $type->id, 'prefix' => $code, 'no_vat' => $noVat]);
            $sale = $this->document($branch, 'CREDIT_SALE', $code.'-1', $amount, $vat, 'active', ['source_document_id' => $booking->id, 'document_book_id' => $book->id]);
            $sale->update(['doc_date' => '2026-12-01']);
            $sb->update(['confirmed_document_id' => $sale->id]);
        }
        $this->document($branch, 'CREDIT_SALE', 'CANCELLED', '900', '0', 'cancelled', ['source_document_id' => $booking->id]);
        $hidden = $this->document($other, 'BOOKING', 'HIDDEN', '999');
        SaleBooking::create(['document_id' => $hidden->id, 'status' => 'pending']);
        $result = $this->report('sales_by_booking')->assertOk()->viewData('result');
        $row = $result['rows']->sole();
        $this->assertSame('107.00', $row->ds_amount);
        $this->assertSame('53.50', $row->dsn_amount);
        $this->assertSame('160.50', $row->total_amount);
        $this->assertSame('0.00', $row->variance);
        $this->assertStringContainsString('DS-1', $row->sale_doc_no);
        $this->assertStringContainsString('DSN-1', $row->sale_doc_no);
        $csv = $this->report('sales_by_booking', 'sales', ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringNotContainsString('HIDDEN', $csv);
        $this->assertStringNotContainsString('CANCELLED', $csv);
        $sb->update(['status' => 'pending', 'confirmed_document_id' => null]);
        $row = $this->report('sales_by_booking')->assertOk()->viewData('result')['rows']->sole();
        $this->assertSame('0.00', $row->total_amount);
        $this->assertSame('160.50', DecimalMath::round($row->booking_amount, 2));
    }

    public function test_stock_units_use_recorded_conversion_not_barcode_factor_or_unit_name(): void
    {
        $branch = $this->branch('A');
        $product = $this->stock($this->document($branch, 'CASH_SALE', 'STOCK', '1'), '1');
        $pack = ProductUnit::create(['code' => 'BAG', 'name' => 'ถุง x999', 'qty_per_base_unit' => 25]);
        foreach (['B1', 'B2'] as $barcode) {
            ProductBarcode::create(['product_id' => $product->id, 'unit_id' => $pack->id, 'barcode' => $barcode, 'unit_factor' => 999, 'is_active' => true]);
        }
        $rows = collect([(object) ['product_id' => $product->id, 'on_hand_qty' => '305.00000001', 'unit_name' => 'กก.'], (object) ['product_id' => $product->id, 'on_hand_qty' => '-26.00000001', 'unit_name' => 'กก.']]);
        app(StockQuantityFormatter::class)->format($rows);
        $this->assertSame('12 ถุง 5.00000001 กก.', $rows[0]->quantity_display);
        $this->assertSame('-1 ถุง -1.00000001 กก.', $rows[1]->quantity_display);
    }

    public function test_aging_is_scoped_by_invoice_branch_even_when_customer_moves(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $customer = Customer::create(['code' => 'C', 'name_th' => 'ลูกค้า', 'branch_id' => $other->id, 'is_active' => true]);
        foreach ([[$own, '100.01'], [$other, '999.99']] as [$branch, $amount]) {
            $doc = $this->document($branch, 'CREDIT_SALE', 'AR-'.$branch->code, $amount, '0', 'active', ['customer_id' => $customer->id]);
            DB::table('customer_open_items')->insert(['customer_id' => $customer->id, 'document_id' => $doc->id, 'gross_amount' => $amount, 'net_amount' => $amount, 'balance_amount' => $amount, 'due_date' => now()->subDays(30)->toDateString(), 'status' => 'partial']);
        }
        $this->actingAs($this->viewer($own));
        foreach ([[], ['branch_id' => $other->id]] as $extra) {
            $rows = $this->report('ar_aging', 'ar', $extra)->assertOk()->viewData('result')['rows'];
            $this->assertSame('100.01', DecimalMath::sum($rows->pluck('amount'), 2));
        }
        $csv = $this->report('ar_aging', 'ar', ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringNotContainsString('999.99', $csv);
    }

    public function test_monthly_download_rejects_other_branch_and_company_wide_files(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($own));
        foreach ([$other->id, null] as $id) {
            $run = AccountingExportRun::create(['period' => '2026-11', 'branch_id' => $id, 'file_name' => 'nonexistent.zip', 'file_hash' => str_repeat('a', 64), 'file_size' => 0, 'exported_at' => now()]);
            $this->get(route('monthly-accounting.exports.download', $run))->assertForbidden();
        }
    }

    public function test_gl_and_financial_statements_scope_by_source_document_not_a_nonexistent_journal_branch(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $account = ChartOfAccount::firstOrCreate(['code' => 'TEST-CASH'], ['name_th' => 'เงินสดทดสอบ', 'account_type' => 'asset']);
        foreach ([[$own, '100.01'], [$other, '999.99']] as [$branch, $amount]) {
            $doc = $this->document($branch, 'CASH_SALE', 'GL-'.$branch->code, $amount);
            DB::table('gl_journals')->insert(['document_id' => $doc->id, 'account_id' => $account->id, 'entry_date' => '2026-11-09', 'debit' => $amount, 'credit' => 0, 'remark' => 'GL-'.$branch->code]);
        }
        $this->actingAs($this->viewer($own));
        DB::table('report_definitions')->where('code', 'payment.gl_journals')->update(['enabled' => true, 'status' => 'available']);
        $response = $this->report('gl_journals', 'payment')->assertOk()->assertViewHas('selectedReport', 'gl_journals');
        $this->assertSame('100.01', DecimalMath::sum($response->viewData('result')['rows']->pluck('debit'), 2));
        $csv = $this->report('gl_journals', 'payment', ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringNotContainsString('GL-B', $csv);
        $financial = $this->get('/financial-statements?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->assertSame('100.01', $financial->viewData('totalDebit'));
        $this->get('/financial-statements?branch_id='.$other->id)->assertForbidden();
        $this->get('/monthly-accounting?period=2026-11&branch_id='.$other->id)->assertForbidden();
    }

    public function test_vat_uses_document_snapshots_and_preserves_explicit_zero_pos_vat(): void
    {
        $branch = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($branch));
        $this->document($branch, 'CASH_SALE', 'TAX', '110', '10');
        $this->document($branch, 'CREDIT_NOTE', 'CN-TAX', '11', '1');
        $this->document($branch, 'DEBIT_NOTE', 'DN-TAX', '22', '2');
        $this->document($other, 'CASH_SALE', 'HIDDEN-TAX', '9999', '999');
        $terminal = PosTerminal::create(['branch_id' => $branch->id, 'code' => 'T', 'name' => 'T']);
        PosReceipt::create(['pos_terminal_id' => $terminal->id, 'receipt_no' => 'ZERO-VAT', 'receipt_date' => '2026-11-09 10:00', 'net_sales' => 50, 'vat_amount' => 0, 'status' => 'completed']);
        $rows = $this->report('vat_sales', 'tax')->assertOk()->assertViewHas('selectedReport', 'vat_sales')->viewData('result')['rows'];
        $this->assertSame('0.00', data_get($rows->firstWhere('doc_number', 'ZERO-VAT'), 'vat_amount'));
        $this->assertSame('11.00', data_get($rows->last(), 'vat_amount'));
        $this->assertSame('171.00', data_get($rows->last(), 'total_amount'));
        $this->document($branch, 'PURCHASE', 'CLAIM', '110', '10', 'active', ['claim_input_vat' => true]);
        $this->document($branch, 'PURCHASE', 'NO-CLAIM', '220', '20', 'active', ['claim_input_vat' => false]);
        $this->document($branch, 'PURCHASE', 'PENDING-CLAIM', '330', '30', 'pending', ['claim_input_vat' => true]);
        $purchase = $this->report('vat_purchase', 'tax')->assertOk()->viewData('result')['rows'];
        $this->assertCount(2, $purchase);
        $this->assertSame('10.00', data_get($purchase->last(), 'vat_amount'));
        DB::table('vat_rates')->update(['rate_percent' => 25]);
        $again = $this->report('vat_sales', 'tax')->assertOk()->viewData('result')['rows'];
        $this->assertEquals($rows->toArray(), $again->toArray());
    }

    public function test_stock_card_opening_plus_movements_equals_balance_and_cannot_drill_other_branch(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $doc = $this->document($own, 'CASH_SALE', 'SC', '1');
        $product = $this->stock($doc, '1');
        $locationId = DB::table('stock_document_items')->where('product_id', $product->id)->value('warehouse_location_id');
        foreach ([['2026-10-31', 'opening', '100'], ['2026-11-09', 'in', '20.12500001'], ['2026-11-10', 'out', '5']] as [$date, $type, $qty]) {
            DB::table('stock_movements')->insert(['product_id' => $product->id, 'warehouse_location_id' => $locationId, 'document_id' => $doc->id, 'movement_type' => $type, 'movement_date' => $date, 'qty' => $qty, 'created_at' => $date.' 10:00:00']);
        }
        DB::table('stock_balances')->insert(['product_id' => $product->id, 'warehouse_location_id' => $locationId, 'on_hand_qty' => '115.12500001', 'reserved_qty' => 0]);
        $this->actingAs($this->viewer($own));
        $response = $this->report('stock_card_detail', 'inventory', ['product_id' => $product->id, 'location_id' => $locationId])->assertOk()->assertViewHas('selectedReport', 'stock_card_detail');
        $rows = $response->viewData('result')['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame('100.00000000', DecimalMath::round($rows->first()->balance_before, 8));
        $this->assertSame('115.12500001', DecimalMath::round($rows->last()->balance_after, 8));
        $this->assertObjectNotHasProperty('unit_cost', $rows->last());
        $otherProduct = $this->stock($this->document($other, 'CASH_SALE', 'OTHER', '1'), '1');
        $otherLocation = DB::table('stock_document_items')->where('product_id', $otherProduct->id)->value('warehouse_location_id');
        $forged = $this->report('stock_card_detail', 'inventory', ['product_id' => $otherProduct->id, 'location_id' => $otherLocation])->assertOk()->viewData('result');
        $this->assertCount(0, $forged['rows']);
        $stock = $this->report('stock_balance', 'inventory', ['q' => $product->sku_code])->assertOk()->viewData('result')['rows']->firstWhere('location_id', $locationId);
        $this->assertStringContainsString('product_id='.$product->id, $stock->stock_card_url);
        $this->assertStringContainsString('location_id='.$locationId, $stock->stock_card_url);
    }

    public function test_payment_lines_do_not_multiply_by_allocations_and_matching_does_not_create_receipts(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($own));
        $doc = $this->document($own, 'RECEIPT', 'PAY', '120.03');
        $pd = PaymentDocument::create(['document_id' => $doc->id, 'branch_id' => $own->id, 'party_type' => 'customer', 'status' => 'active']);
        PaymentLine::create(['payment_document_id' => $pd->id, 'seq' => 1, 'method' => 'cash', 'amount' => '20.01']);
        $transfer = PaymentLine::create(['payment_document_id' => $pd->id, 'seq' => 2, 'method' => 'transfer', 'amount' => '100.02']);
        foreach ([50, 70.03] as $amount) {
            DB::table('payment_allocations')->insert(['payment_document_id' => $pd->id, 'allocated_amount' => $amount]);
        }
        $hiddenDoc = $this->document($other, 'RECEIPT', 'HIDDEN-PAY', '999');
        $hidden = PaymentDocument::create(['document_id' => $hiddenDoc->id, 'branch_id' => $other->id, 'party_type' => 'customer', 'status' => 'active']);
        PaymentLine::create(['payment_document_id' => $hidden->id, 'seq' => 1, 'method' => 'cash', 'amount' => 999]);
        $rows = $this->report('received_and_unidentified', 'payment')->assertOk()->viewData('result')['rows'];
        $this->assertSame('120.03', DecimalMath::sum($rows->where('bucket', 'รับแล้ว')->pluck('amount'), 2));
        $this->assertSame('100.02', $rows->firstWhere('bucket', 'รอพิสูจน์')->amount);
        $bank = BankAccount::create(['branch_id' => $own->id, 'bank_name' => 'TEST', 'account_no' => '123']);
        $statement = BankStatement::create(['bank_account_id' => $bank->id, 'statement_date' => '2026-11-09', 'amount' => '100.02', 'description' => 'PAY']);
        BankReconciliation::create(['bank_statement_id' => $statement->id, 'branch_id' => $own->id, 'source_type' => 'payment_line', 'source_id' => $transfer->id, 'expected_amount' => '100.02', 'status' => 'matched']);
        $again = $this->report('received_and_unidentified', 'payment')->assertOk()->viewData('result')['rows'];
        $this->assertCount(0, $again->where('bucket', 'รอพิสูจน์'));
        $this->assertSame('120.03', DecimalMath::sum($again->where('bucket', 'รับแล้ว')->pluck('amount'), 2));
        $pd->update(['status' => 'bounced']);
        $doc->update(['status' => 'void']);
        $this->assertCount(0, $this->report('received_and_unidentified', 'payment')->assertOk()->viewData('result')['rows']);
    }

    public function test_delivery_report_tracks_actual_dates_without_turning_collection_into_revenue(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($own));
        $doc = $this->document($own, 'BOOKING', 'SHIP', '107');
        $booking = SaleBooking::create(['document_id' => $doc->id, 'status' => 'pending', 'fulfillment_type' => 'delivery', 'delivery_due_at' => '2026-11-09 12:00:00']);
        $job = TransportJob::create(['booking_id' => $booking->id, 'status' => 'loaded', 'loaded_at' => '2026-11-09 10:00:00']);
        $hidden = $this->document($other, 'BOOKING', 'HIDDEN-SHIP', '999');
        SaleBooking::create(['document_id' => $hidden->id, 'status' => 'pending', 'fulfillment_type' => 'delivery']);
        foreach (['loaded', 'in_transit', 'delivered', 'paid'] as $state) {
            $job->update(['status' => $state, 'delivered_at' => in_array($state, ['delivered', 'paid']) ? '2026-11-11 12:00:00' : null, 'paid_amount' => $state === 'paid' ? 107 : null]);
            $response = $this->report('delivery_status', 'booking')->assertOk()->assertViewHas('selectedReport', 'delivery_status');
            $row = $response->viewData('result')['rows']->sole();
            $this->assertSame($state, $row->transport_status);
            if ($state === 'paid') {
                $this->assertSame(2, $row->late_days);
            }
        }
        $csv = $this->report('delivery_status', 'booking', ['export' => 'csv'])->assertOk()->streamedContent();
        $this->assertStringNotContainsString('HIDDEN-SHIP', $csv);
        $this->assertCount(0, $this->report('daily_by_channel')->assertOk()->viewData('result')['rows']);
        $booking->update(['status' => 'cancelled']);
        $this->assertCount(0, $this->report('delivery_status', 'booking')->assertOk()->viewData('result')['rows']);
    }

    public function test_phase_two_query_switch_stops_reports_without_disabling_scope_rules(): void
    {
        $this->actingAs($this->viewer($this->branch('A')));
        config(['reports.phase_two_enabled' => false]);
        $this->report('daily_by_channel')->assertStatus(503);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_seeded_branch_manager_accountant_executive_and_it_follow_owner_policy(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->stock($this->document($own, 'CASH_SALE', 'OWN-POLICY', '100'), '40');
        $this->stock($this->document($other, 'CASH_SALE', 'OTHER-POLICY', '200'), '70');
        foreach (['BRANCH_MGR' => '100.00', 'ACC' => '300.00', 'EXECUTIVE' => '300.00', 'IT_MGR' => '300.00'] as $code => $expected) {
            $user = User::factory()->create(['branch_id' => $own->id, 'is_active' => true, 'must_change_password' => false]);
            $user->roles()->attach(Role::where('code', $code)->sole()->id);
            $result = $this->actingAs($user)->report('daily_by_channel')->assertOk()->assertViewHas('selectedReport', 'daily_by_channel')->viewData('result');
            $this->assertSame($expected, $result['summary']['net_sales'], $code);
            $this->assertContains('cogs_amount', array_column($result['columns'], 'key'), $code);
        }
    }

    public function test_expired_branch_assignment_never_restores_home_branch_or_company_scope(): void
    {
        $own = $this->branch('A');
        $other = $this->branch('B');
        $user = $this->viewer($own, true);
        DB::table('user_branch_roles')->insert(['user_id' => $user->id, 'branch_id' => $other->id, 'role_id' => $user->roles()->first()->id, 'is_active' => true, 'effective_to' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->stock($this->document($own, 'CASH_SALE', 'HOME', '100'), '40');
        $this->actingAs($user->fresh());
        $this->assertCount(0, $this->report('daily_by_channel')->assertOk()->viewData('result')['rows']);
        $this->get('/monthly-accounting?branch_id='.$own->id)->assertForbidden();
        $this->get('/financial-statements?branch_id='.$other->id)->assertForbidden();
    }

    public function test_monthly_export_matches_snapshot_vat_and_excludes_other_branch(): void
    {
        Storage::fake('local');
        $own = $this->branch('A');
        $other = $this->branch('B');
        $this->actingAs($this->viewer($own));
        $this->document($own, 'CASH_SALE', 'EXPORT-SALE', '110', '10');
        $this->document($own, 'CREDIT_NOTE', 'EXPORT-CN', '11', '1');
        $this->document($other, 'CASH_SALE', 'HIDDEN-EXPORT', '999', '90');
        $this->document($own, 'PURCHASE', 'NO-CLAIM', '330', '30', 'active', ['claim_input_vat' => false]);
        $run = app(MonthlyAccountingExportService::class)->create('2026-11', $own->id);
        $this->assertSame('99.00', $run->summary['sales_total']);
        $this->assertSame('9.00', $run->summary['sales_vat']);
        $this->assertSame('0.00', $run->summary['purchase_vat']);
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($run->file_name));
        $csv = $zip->getFromName('03_SALES_VAT.csv');
        $zip->close();
        $this->assertStringNotContainsString('HIDDEN-EXPORT', $csv);
        $this->assertStringContainsString('EXPORT-CN', $csv);
    }

    public function test_historical_loss_and_margin_use_frozen_cost_and_vat_when_product_cost_changes(): void
    {
        $branch = $this->branch('A');
        $this->actingAs($this->viewer($branch, true));
        $doc = $this->document($branch, 'CASH_SALE', 'LOSS', '107', '7');
        $product = $this->stock($doc, '120');
        foreach ([0, 99999] as $cost) {
            $product->update(['average_cost' => $cost]);
            $loss = $this->report('loss_sales', 'management')->assertOk()->assertViewHas('selectedReport', 'loss_sales')->viewData('result')['rows']->sole();
            $this->assertSame('20.00', DecimalMath::round($loss->loss_amount, 2));
            $margin = $this->report('gross_margin', 'management')->assertOk()->viewData('result')['rows']->sole();
            $this->assertSame('-20.00', $margin->gross_profit);
            $months = $this->report('loss_sales_6m', 'management')->assertOk()->viewData('result')['rows']->sole();
            $this->assertSame('20.00', DecimalMath::round($months->loss_amount, 2));
        }
    }
}
