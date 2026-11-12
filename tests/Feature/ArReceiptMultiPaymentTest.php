<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\PaymentDocument;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\DocumentVoidService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ArReceiptMultiPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customerA;

    private Customer $customerB;

    private BankAccount $bankAccount;

    private Salesman $salesmanA;

    private Salesman $salesmanB;

    private ChartOfAccount $cashCoa;

    private ChartOfAccount $arCoa;

    private ChartOfAccount $bankCoa;

    private ChartOfAccount $whtCoa;

    private ChartOfAccount $discountCoa;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['PURCHASE', 'in'],
            ['CREDIT_SALE', 'out'],
            ['BOOKING', 'none'],
            ['RECEIPT', 'none'],
            ['PAYMENT_VOUCHER', 'none'],
        ] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }

        $this->branch = Branch::create(['code' => 'BR-01', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH-01', 'name' => 'คลังหลัก']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-01', 'name' => 'ที่ตั้งหลัก']);
        $this->branch->update(['default_warehouse_location_id' => $location->id]);

        $this->customerA = Customer::create(['code' => 'CUST-A', 'name_th' => 'สมเกียรติ ฟาร์ม', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->customerB = Customer::create(['code' => 'CUST-B', 'name_th' => 'รุ่งเรือง ค้าส่ง', 'branch_id' => $this->branch->id, 'is_active' => true]);

        $this->salesmanA = Salesman::create(['branch_id' => $this->branch->id, 'code' => 'SM01', 'name' => 'สมชาย ขยันเก็บ', 'is_active' => true]);
        $this->salesmanB = Salesman::create(['branch_id' => $this->branch->id, 'code' => 'SM02', 'name' => 'ประสิทธิ์ รวดเร็ว', 'is_active' => true]);

        // Setup Chart of Accounts
        $this->cashCoa = ChartOfAccount::firstOrCreate(
            ['default_role' => ChartOfAccount::ROLE_CASH],
            ['code' => '1010-01', 'name_th' => 'เงินสดในมือ', 'account_type' => 'asset']
        );
        $this->arCoa = ChartOfAccount::firstOrCreate(
            ['default_role' => ChartOfAccount::ROLE_AR],
            ['code' => '1030-01', 'name_th' => 'ลูกหนี้การค้า', 'account_type' => 'asset']
        );
        $this->bankCoa = ChartOfAccount::firstOrCreate(
            ['code' => '1020-01'],
            ['name_th' => 'เงินฝากธนาคารกสิกรไทย', 'account_type' => 'asset']
        );
        $this->whtCoa = ChartOfAccount::firstOrCreate(
            ['default_role' => ChartOfAccount::ROLE_WHT_RECEIVABLE],
            ['code' => '1160', 'name_th' => 'ภาษีเงินได้ถูกหัก ณ ที่จ่าย', 'account_type' => 'asset']
        );
        $this->discountCoa = ChartOfAccount::firstOrCreate(
            ['default_role' => ChartOfAccount::ROLE_SALES_RETURN],
            ['code' => '4020', 'name_th' => 'รับคืนและส่วนลดจ่าย', 'account_type' => 'revenue']
        );

        $this->bankAccount = BankAccount::create([
            'branch_id' => $this->branch->id,
            'bank_name' => 'กสิกรไทย',
            'account_no' => '123-4-56789-0',
            'chart_of_account_id' => $this->bankCoa->id,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'username' => 'finance_user',
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::firstOrCreate(['code' => 'ACC_MGR'], ['name' => 'ผู้จัดการฝ่ายบัญชี']);
        $this->user->roles()->attach($role);
        $this->actingAs($this->user);
    }

    private function createCreditSaleBill(Customer $customer, float $amount, bool $isVat = false, string $sku = 'ITEM'): CustomerOpenItem
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => $sku.'-'.uniqid(),
            'name_th' => 'สินค้าทดสอบ',
            'base_unit_id' => $unit->id,
            'default_price' => $amount,
            'average_cost' => round($amount * 0.7, 4),
            'is_vat' => $isVat,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $supplier = Supplier::firstOrCreate(['code' => 'SUP-01'], ['name_th' => 'ผู้จำหน่ายหลัก', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => round($amount * 0.7, 4)]],
        ]);

        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $amount]],
        ]);

        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);

        return CustomerOpenItem::where('document_id', $sale->id)->sole();
    }

    /** 1. สด+โอนในใบเดียว ลงบัญชีถูก เดบิตเท่าเครดิต */
    public function test_mixed_cash_and_transfer_posts_correct_gl_journals(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 3550.50);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1500.25],
                ['method' => 'transfer', 'amount' => 2050.25, 'bank_account_id' => $this->bankAccount->id, 'transfer_ref' => 'REF12345'],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 3550.50],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertCount(2, $payDoc->lines);

        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        $this->assertNotEmpty($journals);

        $totalDebit = round((float) $journals->sum('debit'), 2);
        $totalCredit = round((float) $journals->sum('credit'), 2);
        $this->assertEquals(3550.50, $totalDebit);
        $this->assertEquals(3550.50, $totalCredit);

        // Dr Cash 1500.25
        $cashDebit = round((float) $journals->where('account_id', $this->cashCoa->id)->sum('debit'), 2);
        $this->assertEquals(1500.25, $cashDebit);

        // Dr Bank 2050.25
        $bankDebit = round((float) $journals->where('account_id', $this->bankCoa->id)->sum('debit'), 2);
        $this->assertEquals(2050.25, $bankDebit);

        // Cr AR 3550.50
        $arCredit = round((float) $journals->where('account_id', $this->arCoa->id)->sum('credit'), 2);
        $this->assertEquals(3550.50, $arCredit);
    }

    /** 2. สด+โอน+เช็ค */
    public function test_mixed_cash_transfer_and_cheque_creates_all_records(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 5138.25);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1000.00],
                ['method' => 'transfer', 'amount' => 2000.00, 'bank_account_id' => $this->bankAccount->id, 'transfer_ref' => 'KBANK-99'],
                [
                    'method' => 'cheque',
                    'amount' => 2138.25,
                    'cheque_bank' => 'กรุงเทพ',
                    'cheque_no' => 'CHQ-888999',
                    'cheque_due_date' => now()->addDays(7)->toDateString(),
                ],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 5138.25],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertCount(3, $payDoc->lines);

        $cheque = Cheque::where('payment_document_id', $payDoc->id)->sole();
        $this->assertEquals('CHQ-888999', $cheque->cheque_no);
        $this->assertEquals('in', $cheque->direction);
        $this->assertEquals('on_hand', $cheque->status);
        $this->assertEquals(2138.25, (float) $cheque->amount);

        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        $this->assertEquals(5138.25, round((float) $journals->sum('debit'), 2));
        $this->assertEquals(5138.25, round((float) $journals->sum('credit'), 2));
    }

    /** 3. หัก ณ ที่จ่าย (ตัวอย่างบิลมี VAT: ฐานก่อน VAT, อัตรา 3%, ผลปัดสตางค์) เดบิตบัญชีภาษีถูก เครดิตลูกหนี้ตามยอดตัดหนี้ */
    public function test_withholding_tax_with_vat_proportion_and_posts_correct_gl(): void
    {
        // บิลมี VAT 7%: สัดส่วน subtotal ต่อ total
        $bill = $this->createCreditSaleBill($this->customerA, 1070.00, true);
        $doc = $bill->document;

        $allocatedAmount = 1070.00;
        // ฐานภาษีคิดจากมูลค่าก่อน VAT: 1070 * (1000 / 1070) = 1000.00 -> หัก 3% = 30.00
        $whtRate = 3.0;
        $preVatRatio = (float) $doc->subtotal_amount / (float) $doc->total_amount;
        $taxBase = round($allocatedAmount * $preVatRatio, 2);
        $whtAmount = round($taxBase * ($whtRate / 100), 2);
        $this->assertEquals(30.00, $whtAmount);

        $netToPay = round($allocatedAmount - $whtAmount, 2); // 1040.00

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'wht_cert_no' => 'WHT-2026/001',
            'wht_cert_date' => now()->toDateString(),
            'payments' => [
                ['method' => 'transfer', 'amount' => $netToPay, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [
                [
                    'customer_open_item_id' => $bill->id,
                    'amount' => $allocatedAmount,
                    'wht_amount' => $whtAmount,
                    'discount_amount' => 0,
                ],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertEquals('WHT-2026/001', $payDoc->wht_cert_no);

        $alloc = $payDoc->allocations->first();
        $this->assertEquals(1070.00, (float) $alloc->allocated_amount);
        $this->assertEquals(30.00, (float) $alloc->wht_amount);

        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        // Dr Bank 1040.00
        $this->assertEquals(1040.00, round((float) $journals->where('account_id', $this->bankCoa->id)->sum('debit'), 2));
        // Dr WHT Receivable 30.00
        $this->assertEquals(30.00, round((float) $journals->where('account_id', $this->whtCoa->id)->sum('debit'), 2));
        // Cr AR 1070.00 (ยอดตัดหนี้เต็ม)
        $this->assertEquals(1070.00, round((float) $journals->where('account_id', $this->arCoa->id)->sum('credit'), 2));

        $this->assertEquals(1070.00, round((float) $journals->sum('debit'), 2));
        $this->assertEquals(1070.00, round((float) $journals->sum('credit'), 2));
    }

    /** 4. ส่วนลดเงินสด */
    public function test_cash_discount_posts_to_sales_return_gl(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 2000.00);
        $discount = 100.00;
        $netToPay = 1900.00;

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => $netToPay],
            ],
            'allocations' => [
                [
                    'customer_open_item_id' => $bill->id,
                    'amount' => 2000.00,
                    'discount_amount' => $discount,
                    'wht_amount' => 0,
                ],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();

        // Dr Cash 1900.00
        $this->assertEquals(1900.00, round((float) $journals->where('account_id', $this->cashCoa->id)->sum('debit'), 2));
        // Dr Sales Return 100.00
        $this->assertEquals(100.00, round((float) $journals->where('account_id', $this->discountCoa->id)->sum('debit'), 2));
        // Cr AR 2000.00
        $this->assertEquals(2000.00, round((float) $journals->where('account_id', $this->arCoa->id)->sum('credit'), 2));

        $this->assertEquals(2000.00, round((float) $journals->sum('debit'), 2));
        $this->assertEquals(2000.00, round((float) $journals->sum('credit'), 2));
    }

    /** 5. ทั้งหัก ณ ที่จ่ายและส่วนลดและหลายวิธี */
    public function test_both_wht_and_discount_with_multiple_payment_methods(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 4000.00);
        $discount = 110.00;
        $wht = 90.00;
        $netToPay = 4000.00 - $discount - $wht; // 3800.00

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1800.00],
                ['method' => 'transfer', 'amount' => 2000.00, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [
                [
                    'customer_open_item_id' => $bill->id,
                    'amount' => 4000.00,
                    'discount_amount' => $discount,
                    'wht_amount' => $wht,
                ],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();

        // Dr Cash 1800 + Dr Bank 2000 + Dr WHT 90 + Dr Discount 110 = 4000
        $this->assertEquals(4000.00, round((float) $journals->sum('debit'), 2));
        // Cr AR = 4000
        $this->assertEquals(4000.00, round((float) $journals->sum('credit'), 2));
    }

    /** 6. ชำระบางส่วนหลายบิล ปิดบิลเมื่อครบ */
    public function test_partial_payment_across_multiple_bills_closes_when_fully_paid(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customerA, 1000.00);
        $bill2 = $this->createCreditSaleBill($this->customerA, 500.00);

        // ตัดบิล 1 บางส่วน (600 จาก 1000) และตัดบิล 2 เต็มจำนวน (500)
        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1100.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 600.00],
                ['customer_open_item_id' => $bill2->id, 'amount' => 500.00],
            ],
        ]);

        $bill1->refresh();
        $bill2->refresh();

        $this->assertEquals(400.00, (float) $bill1->balance_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_PARTIAL, $bill1->status);

        $this->assertEquals(0.00, (float) $bill2->balance_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_PAID, $bill2->status);

        // ตัดบิล 1 ส่วนที่เหลือ (400)
        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 400.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 400.00],
            ],
        ]);

        $bill1->refresh();
        $this->assertEquals(0.00, (float) $bill1->balance_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_PAID, $bill1->status);
    }

    /** 7. ผลรวมรับเงินไม่เท่ายอดที่ต้องรับ → ปฏิเสธและไม่มีอะไรถูกเขียน */
    public function test_mismatched_payment_sum_and_net_to_receive_is_rejected_and_writes_nothing(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 2000.00);
        $initialDocCount = Document::count();
        $initialJournalCount = GlJournal::count();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ไม่เท่ากับยอดจัดสรร');

        try {
            app(CustomerPaymentService::class)->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customerA->id,
                'payments' => [
                    ['method' => 'cash', 'amount' => 1500.00], // ขาด 500.00
                ],
                'allocations' => [
                    ['customer_open_item_id' => $bill->id, 'amount' => 2000.00],
                ],
            ]);
        } finally {
            $this->assertSame($initialDocCount, Document::count());
            $this->assertSame($initialJournalCount, GlJournal::count());
            $bill->refresh();
            $this->assertEquals(2000.00, (float) $bill->balance_amount);
        }
    }

    /** 8. หัก/ลด เกินยอดตัดหนี้ → ปฏิเสธ */
    public function test_deductions_exceeding_allocated_amount_is_rejected(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1000.00);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ยอดหัก ณ ที่จ่ายหรือส่วนลดเกินยอดตัดหนี้');

        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 100.00],
            ],
            'allocations' => [
                [
                    'customer_open_item_id' => $bill->id,
                    'amount' => 1000.00,
                    'discount_amount' => 600.00,
                    'wht_amount' => 500.00, // 600 + 500 = 1100 > 1000
                ],
            ],
        ]);
    }

    /** 9. บิลของลูกค้าคนอื่น → ปฏิเสธ */
    public function test_foreign_customer_bill_is_rejected(): void
    {
        $billA = $this->createCreditSaleBill($this->customerA, 1000.00);
        $billB = $this->createCreditSaleBill($this->customerB, 1500.00);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('มีรายการบิลที่ไม่ใช่ของลูกค้ารายนี้');

        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 2500.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $billA->id, 'amount' => 1000.00],
                ['customer_open_item_id' => $billB->id, 'amount' => 1500.00],
            ],
        ]);
    }

    /** 10. ไม่มีบัญชีภาษีหัก ณ ที่จ่าย → ปฏิเสธด้วยข้อความชัด */
    public function test_missing_wht_receivable_account_is_rejected_with_clear_message(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1000.00);

        // จำลองสถานะไม่มีบัญชี wht_receivable
        $this->whtCoa->update(['default_role' => null]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ผังบัญชีภาษีหัก ณ ที่จ่าย (wht_receivable) ยังตั้งค่าไม่ครบ');

            app(CustomerPaymentService::class)->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customerA->id,
                'payments' => [
                    ['method' => 'cash', 'amount' => 970.00],
                ],
                'allocations' => [
                    [
                        'customer_open_item_id' => $bill->id,
                        'amount' => 1000.00,
                        'wht_amount' => 30.00,
                    ],
                ],
            ]);
        } finally {
            $this->whtCoa->update(['default_role' => ChartOfAccount::ROLE_WHT_RECEIVABLE]);
        }
    }

    /** 11. ยกเลิกใบเสร็จแล้วยอดค้างและบัญชีกลับเท่าเดิม */
    public function test_void_receipt_restores_open_items_and_reverses_gl_journals(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 3000.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1000.00],
                ['method' => 'transfer', 'amount' => 1700.00, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [
                [
                    'customer_open_item_id' => $bill->id,
                    'amount' => 3000.00,
                    'discount_amount' => 200.00,
                    'wht_amount' => 100.00,
                ],
            ],
        ]);

        $bill->refresh();
        $this->assertEquals(0.00, (float) $bill->balance_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_PAID, $bill->status);

        // ยกเลิกใบเสร็จ
        app(DocumentVoidService::class)->void($receipt, 'ทดสอบยกเลิก', $this->user->id);

        $bill->refresh();
        // ยอดหนี้ค้างต้องกลับมา 3000.00 บาทเต็มจำนวนเดิม (รวม discount และ wht)
        $this->assertEquals(3000.00, (float) $bill->balance_amount);
        $this->assertEquals(0.00, (float) $bill->paid_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_OPEN, $bill->status);

        // ตรวจสอบการกลับรายการ GL
        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertEquals('cancelled', $payDoc->status);

        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        // ผลรวมสุทธิของ Debit และ Credit แต่ละบัญชีหลังกลับรายการต้องเป็น 0
        foreach ($journals->groupBy('account_id') as $accountId => $entries) {
            $net = round((float) $entries->sum('debit') - (float) $entries->sum('credit'), 2);
            $this->assertEquals(0.00, $net, "Account ID {$accountId} must net to zero after reversal");
        }
    }

    /** 12. เส้นทางเดิมวิธีเดียว ผลเดิม */
    public function test_legacy_single_payment_without_payments_array_works_identically(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1250.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'method' => 'cash',
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 1250.00],
            ],
        ]);

        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertCount(1, $payDoc->lines);
        $this->assertEquals('cash', $payDoc->lines->first()->method);
        $this->assertEquals(1250.00, (float) $payDoc->lines->first()->amount);

        $bill->refresh();
        $this->assertEquals(0.00, (float) $bill->balance_amount);
        $this->assertEquals(CustomerOpenItem::STATUS_PAID, $bill->status);
    }

    /** 13. รายงานสรุปรายวันนับถูก ไม่นับใบที่ยกเลิก */
    public function test_daily_summary_report_counts_correctly_and_excludes_voided(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customerA, 2000.00);
        $bill2 = $this->createCreditSaleBill($this->customerA, 1000.00);
        $bill3 = $this->createCreditSaleBill($this->customerB, 3000.00);

        // ใบที่ 1: Active (Salesman A) สด 1000 + โอน 1000
        $receipt1 = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1000.00],
                ['method' => 'transfer', 'amount' => 1000.00, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [['customer_open_item_id' => $bill1->id, 'amount' => 2000.00]],
        ]);

        // ใบที่ 2: Voided (Salesman A)
        $receipt2 = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'payments' => [['method' => 'cash', 'amount' => 1000.00]],
            'allocations' => [['customer_open_item_id' => $bill2->id, 'amount' => 1000.00]],
        ]);
        app(DocumentVoidService::class)->void($receipt2, 'ยกเลิกเพื่อทดสอบ', $this->user->id);

        // ใบที่ 3: Active (Salesman B) โอน 2800 + WHT 100 + Discount 100 = 3000
        $receipt3 = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerB->id,
            'salesman_id' => $this->salesmanB->id,
            'payments' => [
                ['method' => 'transfer', 'amount' => 2800.00, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [[
                'customer_open_item_id' => $bill3->id,
                'amount' => 3000.00,
                'discount_amount' => 100.00,
                'wht_amount' => 100.00,
            ]],
        ]);

        // เรียกหน้าสรุปรายวันผ่าน Controller
        $res = $this->get(route('ar-receipts.daily-summary', [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $res->assertOk();
        $res->assertViewHas('totals', function ($totals) {
            // นับเฉพาะใบที่ 1 และ 3 = 2 ใบ (ไม่นับใบที่ 2 ที่ยกเลิก)
            return $totals['receipt_count'] === 2
                && round($totals['net_paid'], 2) === 4800.00 // 1000 + 1000 + 2800
                && round($totals['discount'], 2) === 100.00
                && round($totals['wht'], 2) === 100.00
                && round($totals['allocated'], 2) === 5000.00; // 2000 + 3000
        });

        // ทดสอบส่งออก CSV
        $csvRes = $this->get(route('ar-receipts.daily-summary', [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'export' => 'csv',
        ]));
        $csvRes->assertOk();
        $this->assertEquals('text/csv; charset=utf-8', $csvRes->headers->get('Content-Type'));
    }

    /** 14. ผู้รับเงินถูกบันทึกและแสดง */
    public function test_collector_salesman_is_recorded_and_displayed(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1500.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesmanA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1500.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 1500.00],
            ],
        ]);

        $this->assertEquals($this->salesmanA->id, $receipt->salesman_id);
        $payDoc = PaymentDocument::where('document_id', $receipt->id)->sole();
        $this->assertEquals($this->salesmanA->id, $payDoc->salesman_id);

        // หน้า index แสดงชื่อผู้รับเงิน
        $resIndex = $this->get(route('ar-receipts.index'));
        $resIndex->assertOk();
        $resIndex->assertSee($this->salesmanA->name);

        // หน้าพิมพ์แสดงชื่อผู้รับเงิน
        $resPrint = $this->get(route('payments.print', $receipt));
        $resPrint->assertOk();
        $resPrint->assertSee($this->salesmanA->name);
    }
    public function test_backdated_receipt_into_a_closed_accounting_period_is_rejected(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1000.00);
        \App\Models\AccountingPeriod::create([
            'name' => 'งวดปิด', 'starts_on' => now()->subMonthNoOverflow()->startOfMonth(), 'ends_on' => now()->subMonthNoOverflow()->endOfMonth(),
            'status' => 'closed', 'closed_at' => now(),
        ]);

        try {
            app(CustomerPaymentService::class)->create([
                'branch_id' => $this->branch->id, 'customer_id' => $this->customerA->id,
                'doc_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString(),
                'payments' => [['method' => 'transfer', 'amount' => 1000.00, 'bank_account_id' => $this->bankAccount->id]],
                'allocations' => [['customer_open_item_id' => $bill->id, 'amount' => 1000.00]],
            ]);
            $this->fail('ใบเสร็จที่ลงวันที่ในงวดปิดแล้วต้องถูกปฏิเสธ');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame(0, PaymentDocument::count());
        $this->assertEquals(1000.00, (float) $bill->fresh()->balance_amount);
    }
}
