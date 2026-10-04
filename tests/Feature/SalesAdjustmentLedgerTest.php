<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DepreciationRecord;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\FixedAsset;
use App\Models\GlJournal;
use App\Models\PaymentDocument;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\GlPostingService;
use App\Services\Accounting\TaxComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * รับคืน ใบลด/เพิ่มหนี้ รับชำระ และค่าเสื่อม ต้องลงบัญชีและรายงานภาษีตรงกับของจริง
 *
 * ที่มา: ตรวจสูตรทั้งระบบ 2026-10-04 — เดิมรับคืน/ใบลดหนี้แยก VAT 7% จากยอดเต็มเสมอ
 * (ของสดที่ไม่มี VAT ก็ถูกกลับภาษีขาย) และไม่เก็บยอด VAT ลงเอกสาร รายงานภาษีขายจึงไม่นับ
 * รับชำระด้วยโอนลงเป็นเงินสด และค่าเสื่อมไม่เคยลงบัญชี
 */
class SalesAdjustmentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private WarehouseLocation $location;

    private Customer $customer;

    private Product $vatProduct;

    private Product $freshProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH', 'name' => 'คลัง']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'หลัก']);
        $this->customer = Customer::create(['code' => 'C001', 'name_th' => 'ร้านทดสอบ']);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->vatProduct = Product::create([
            'sku_code' => 'VAT1', 'name_th' => 'น้ำปลา', 'base_unit_id' => $unit->id,
            'default_price' => 107, 'is_vat' => true, 'is_active' => true,
        ]);
        $this->freshProduct = Product::create([
            'sku_code' => 'FRESH1', 'name_th' => 'หมูสด', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'is_vat' => false, 'is_active' => true,
        ]);
        foreach (['CREDIT_SALE' => 'ขายเชื่อ', 'SALE_RETURN' => 'รับคืน', 'CREDIT_NOTE' => 'ใบลดหนี้',
            'DEBIT_NOTE' => 'ใบเพิ่มหนี้', 'RECEIPT' => 'ใบเสร็จรับเงิน'] as $code => $name) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name]);
        }
        ChartOfAccount::where('code', '1020')->update(['default_role' => ChartOfAccount::ROLE_BANK]);
        foreach ([
            ['5340-00', 'ค่าเสื่อมราคา', 'expense', ChartOfAccount::ROLE_DEPRECIATION_EXPENSE],
            ['1420-00', 'ค่าเสื่อมราคาสะสม', 'asset', ChartOfAccount::ROLE_ACCUMULATED_DEPRECIATION],
        ] as [$code, $name, $type, $role]) {
            ChartOfAccount::updateOrCreate(['code' => $code], ['name_th' => $name, 'account_type' => $type, 'default_role' => $role]);
        }
    }

    public function test_a_return_only_reverses_vat_on_goods_that_carried_vat(): void
    {
        // คืนน้ำปลา 107 (มี VAT 7) + หมูสด 100 (ยกเว้น VAT) — เดิมกลับภาษีขาย 13.54 จาก 207
        $return = $this->document('SALE_RETURN', 'RT-001', [[$this->vatProduct, 1, 107], [$this->freshProduct, 1, 100]]);

        app(GlPostingService::class)->postSaleReturn($return, againstAr: true);

        $this->assertGl($return, ChartOfAccount::ROLE_VAT_OUTPUT, debit: 7.00);
        $this->assertGl($return, ChartOfAccount::ROLE_SALES_RETURN, debit: 200.00);
        $this->assertGl($return, ChartOfAccount::ROLE_AR, credit: 207.00);
        $this->assertSame([200.0, 7.0], [(float) $return->fresh()->subtotal_amount, (float) $return->fresh()->vat_amount]);
        $this->assertSame(-7.0, $this->taxRow($return)['tax_amount'], 'รายงานภาษีขายต้องหักภาษีของใบรับคืน');
    }

    public function test_a_credit_note_takes_the_vat_share_of_the_sale_it_reduces(): void
    {
        $sale = $this->document('CREDIT_SALE', 'CR-001', [[$this->vatProduct, 1, 107], [$this->freshProduct, 1, 100]]);
        app(GlPostingService::class)->postCreditSale($sale);
        $note = $this->note('CREDIT_NOTE', 'CN-001', 20.70, $sale->doc_number);

        app(GlPostingService::class)->postCreditNote($note);

        // 20.70 x (7 / 207) = 0.70
        $this->assertGl($note, ChartOfAccount::ROLE_VAT_OUTPUT, debit: 0.70);
        $this->assertGl($note, ChartOfAccount::ROLE_SALES_RETURN, debit: 20.00);
        $this->assertGl($note, ChartOfAccount::ROLE_AR, credit: 20.70);
        $this->assertSame(-0.70, $this->taxRow($note)['tax_amount']);
    }

    public function test_a_credit_note_on_a_fresh_food_sale_has_no_vat_to_reverse(): void
    {
        $sale = $this->document('CREDIT_SALE', 'CR-002', [[$this->freshProduct, 2, 100]]);
        app(GlPostingService::class)->postCreditSale($sale);
        $note = $this->note('CREDIT_NOTE', 'CN-002', 50, $sale->doc_number);

        app(GlPostingService::class)->postCreditNote($note);

        $this->assertGl($note, ChartOfAccount::ROLE_SALES_RETURN, debit: 50.00);
        $this->assertSame(0, GlJournal::where('document_id', $note->id)
            ->where('account_id', ChartOfAccount::where('default_role', ChartOfAccount::ROLE_VAT_OUTPUT)->value('id'))->count());
        $this->assertSame(0.0, $this->taxRow($note)['tax_amount']);
    }

    public function test_a_debit_note_without_a_reference_uses_the_standard_rate_and_reaches_the_tax_report(): void
    {
        $note = $this->note('DEBIT_NOTE', 'DN-001', 107, null);

        app(GlPostingService::class)->postDebitNote($note);

        $this->assertGl($note, ChartOfAccount::ROLE_VAT_OUTPUT, credit: 7.00);
        $this->assertGl($note, ChartOfAccount::ROLE_SALES_REVENUE, credit: 100.00);
        $this->assertSame(7.0, $this->taxRow($note)['tax_amount'], 'เดิมรายงานภาษีขายขึ้น 0 ทั้งที่บัญชีลงภาษีขาย 7');
    }

    public function test_a_transfer_or_cheque_receipt_lands_in_the_bank_not_the_cash_drawer(): void
    {
        foreach (['transfer' => ChartOfAccount::ROLE_BANK, 'cheque' => ChartOfAccount::ROLE_BANK, 'cash' => ChartOfAccount::ROLE_CASH] as $method => $role) {
            $document = Document::create([
                'document_type_id' => DocumentType::where('code', 'RECEIPT')->value('id'),
                'branch_id' => $this->branch->id, 'doc_number' => 'RC-'.$method, 'doc_date' => now()->toDateString(),
                'customer_id' => $this->customer->id, 'status' => 'active', 'total_items' => 1, 'total_amount' => 500,
            ]);
            $payment = PaymentDocument::create([
                'document_id' => $document->id, 'party_type' => 'customer', 'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id, 'status' => 'active',
            ]);

            app(GlPostingService::class)->postCustomerReceipt($payment, 500, now()->toDateString(), 'RC-'.$method, $method);

            $debitAccount = GlJournal::where('payment_document_id', $payment->id)->where('debit', '>', 0)->value('account_id');
            $this->assertSame(ChartOfAccount::where('default_role', $role)->value('id'), $debitAccount, "รับชำระแบบ {$method} ลงบัญชีผิด");
        }
    }

    public function test_depreciation_is_posted_to_the_ledger_and_balances(): void
    {
        $asset = FixedAsset::create([
            'asset_code' => 'FA-001', 'name' => 'ตู้แช่', 'branch_id' => $this->branch->id,
            'acquired_date' => '2026-01-01', 'cost' => 12100, 'salvage_value' => 100, 'useful_life_months' => 60,
        ]);

        $result = app(DepreciationService::class)->runForPeriod(Carbon::parse('2026-09-01'));

        $this->assertSame(200.0, $result['amount']);
        $lines = GlJournal::where('remark', 'like', 'ค่าเสื่อมราคา FA-001%')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(200.0, round((float) $lines->sum('debit'), 2));
        $this->assertSame(200.0, round((float) $lines->sum('credit'), 2));
        $this->assertSame('2026-09-30', Carbon::parse($lines->first()->entry_date)->toDateString());
        $this->assertSame(
            ChartOfAccount::where('default_role', ChartOfAccount::ROLE_DEPRECIATION_EXPENSE)->value('id'),
            $lines->firstWhere('debit', '>', 0)->account_id,
        );
        $this->assertSame(200.0, (float) $asset->fresh()->accumulated_depreciation);
    }

    public function test_depreciation_refuses_to_run_without_its_accounts_and_records_nothing(): void
    {
        ChartOfAccount::where('default_role', ChartOfAccount::ROLE_ACCUMULATED_DEPRECIATION)->update(['default_role' => null]);
        FixedAsset::create([
            'asset_code' => 'FA-002', 'name' => 'รถเข็น', 'acquired_date' => '2026-01-01',
            'cost' => 6000, 'salvage_value' => 0, 'useful_life_months' => 60,
        ]);

        try {
            app(DepreciationService::class)->runForPeriod(Carbon::parse('2026-09-01'));
            $this->fail('ต้องหยุดเมื่อยังไม่ได้ผูกบัญชีค่าเสื่อม');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ค่าเสื่อมราคาสะสม', $e->getMessage());
        }
        $this->assertSame(0, DepreciationRecord::count());
    }

    /** @param array<int, array{Product,float,float}> $lines */
    private function document(string $type, string $number, array $lines): Document
    {
        $document = Document::create([
            'document_type_id' => DocumentType::where('code', $type)->value('id'),
            'branch_id' => $this->branch->id, 'doc_number' => $number, 'doc_date' => now()->toDateString(),
            'customer_id' => $this->customer->id, 'status' => 'active', 'total_items' => count($lines),
            'total_amount' => array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines)),
        ]);
        $stock = StockDocument::create(['document_id' => $document->id, 'total_qty' => array_sum(array_column($lines, 1)), 'total_items' => count($lines)]);
        foreach ($lines as $index => [$product, $qty, $price]) {
            StockDocumentItem::create([
                'stock_document_id' => $stock->id, 'seq' => $index + 1, 'product_id' => $product->id,
                'warehouse_location_id' => $this->location->id, 'qty' => $qty, 'unit_price' => $price,
                'unit_cost' => 0, 'cost_amount' => 0,
            ]);
        }

        return $document->fresh();
    }

    private function note(string $type, string $number, float $amount, ?string $reference): Document
    {
        return Document::create([
            'document_type_id' => DocumentType::where('code', $type)->value('id'),
            'branch_id' => $this->branch->id, 'doc_number' => $number, 'doc_date' => now()->toDateString(),
            'customer_id' => $this->customer->id, 'reference' => $reference, 'status' => 'active',
            'total_items' => 1, 'total_amount' => $amount,
        ]);
    }

    private function assertGl(Document $document, string $role, float $debit = 0, float $credit = 0): void
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');
        $lines = GlJournal::where('document_id', $document->id)->where('account_id', $accountId)->get();
        $this->assertSame($debit, round((float) $lines->sum('debit'), 2), "debit ของ {$role} ไม่ตรง");
        $this->assertSame($credit, round((float) $lines->sum('credit'), 2), "credit ของ {$role} ไม่ตรง");
        $all = GlJournal::where('document_id', $document->id)->get();
        $this->assertSame(round((float) $all->sum('debit'), 2), round((float) $all->sum('credit'), 2), 'GL ไม่ดุล');
    }

    /** @return array<string,mixed> */
    private function taxRow(Document $document): array
    {
        $rows = (new ReflectionMethod(TaxComplianceService::class, 'rows'))
            ->invoke(app(TaxComplianceService::class), now()->startOfMonth(), now()->endOfMonth(), null, 'PP30');
        $row = collect($rows)->firstWhere('document_no', $document->doc_number);
        $this->assertNotNull($row, "รายงานภาษีไม่มีเอกสาร {$document->doc_number}");

        return ['tax_amount' => round((float) $row['tax_amount'], 2)];
    }
}
