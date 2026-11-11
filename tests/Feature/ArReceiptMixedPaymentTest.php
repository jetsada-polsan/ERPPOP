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
use App\Models\PaymentLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ArReceiptMixedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customerA;

    private Customer $customerB;

    private BankAccount $bankAccount;

    private Salesman $salesman;

    private ChartOfAccount $cashCoa;

    private ChartOfAccount $arCoa;

    private ChartOfAccount $bankCoa;

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

        $this->salesman = Salesman::create(['branch_id' => $this->branch->id, 'code' => 'SM01', 'name' => 'สมชาย ขยันเก็บ', 'is_active' => true]);

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

    private function createCreditSaleBill(Customer $customer, float $amount, string $sku = 'ITEM-01'): CustomerOpenItem
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => $sku.'-'.uniqid(),
            'name_th' => 'สินค้าทดสอบ',
            'base_unit_id' => $unit->id,
            'default_price' => $amount,
            'average_cost' => $amount * 0.7,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $supplier = Supplier::firstOrCreate(['code' => 'SUP-01'], ['name_th' => 'ผู้จำหน่ายหลัก', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => $amount * 0.7]],
        ]);

        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $amount]],
        ]);

        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);

        return CustomerOpenItem::where('document_id', $sale->id)->sole();
    }

    public function test_mixed_cash_and_transfer_posts_correct_gl_journals(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 3000.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesman->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1000.00],
                ['method' => 'transfer', 'amount' => 2000.00, 'bank_account_id' => $this->bankAccount->id, 'transfer_ref' => 'REF12345'],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 3000.00],
            ],
        ]);

        $this->assertInstanceOf(Document::class, $receipt);
        $this->assertSame(3000.00, (float) $receipt->total_amount);

        // ตรวจสอบ Payment Lines
        $paymentDoc = $receipt->paymentDocument;
        $this->assertNotNull($paymentDoc);
        $lines = $paymentDoc->lines()->orderBy('seq')->get();
        $this->assertCount(2, $lines);

        $this->assertSame('cash', $lines[0]->method);
        $this->assertSame(1000.00, (float) $lines[0]->amount);
        $this->assertNull($lines[0]->bank_account_id);

        $this->assertSame('transfer', $lines[1]->method);
        $this->assertSame(2000.00, (float) $lines[1]->amount);
        $this->assertSame($this->bankAccount->id, (int) $lines[1]->bank_account_id);
        $this->assertSame('REF12345', $lines[1]->transfer_ref);

        // ตรวจสอบ GL Journals
        $glEntries = GlJournal::where('payment_document_id', $paymentDoc->id)->get();
        $totalDebit = (float) $glEntries->sum('debit');
        $totalCredit = (float) $glEntries->sum('credit');

        // เดบิตต้องเท่ากับเครดิต
        $this->assertSame(3000.00, $totalDebit);
        $this->assertSame(3000.00, $totalCredit);

        // เดบิตเงินสด 1,000, เดบิตธนาคาร 2,000, เครดิตลูกหนี้ 3,000
        $debitCash = (float) $glEntries->where('account_id', $this->cashCoa->id)->sum('debit');
        $debitBank = (float) $glEntries->where('account_id', $this->bankCoa->id)->sum('debit');
        $creditAr = (float) $glEntries->where('account_id', $this->arCoa->id)->sum('credit');

        $this->assertSame(1000.00, $debitCash);
        $this->assertSame(2000.00, $debitBank);
        $this->assertSame(3000.00, $creditAr);

        // ตรวจสอบ CashBook รับเงินสด 1,000
        $cashIn = (float) DB::table('cash_books')->where('source_id', $receipt->id)->sum('cash_in');
        $this->assertSame(1000.00, $cashIn);

        // ตรวจสอบบิลถูกปิดยอดแล้ว
        $freshBill = $bill->fresh();
        $this->assertSame(0.00, (float) $freshBill->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_PAID, $freshBill->status);
    }

    public function test_mixed_cash_transfer_and_cheque_creates_all_records(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 6000.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1000.00],
                ['method' => 'transfer', 'amount' => 2000.00, 'bank_account_id' => $this->bankAccount->id],
                [
                    'method' => 'cheque',
                    'amount' => 3000.00,
                    'cheque_bank' => 'ธนาคารกรุงเทพ',
                    'cheque_no' => 'CHQ-889900',
                    'cheque_due_date' => '2026-11-25',
                ],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 6000.00],
            ],
        ]);

        $paymentDoc = $receipt->paymentDocument;
        $this->assertCount(3, $paymentDoc->lines);

        // ตรวจสอบเช็คถูกลงทะเบียนในตาราง cheques
        $cheque = Cheque::where('payment_document_id', $paymentDoc->id)->first();
        $this->assertNotNull($cheque);
        $this->assertSame('in', $cheque->direction);
        $this->assertSame('on_hand', $cheque->status);
        $this->assertSame('CHQ-889900', $cheque->cheque_no);
        $this->assertSame('ธนาคารกรุงเทพ', $cheque->bank_name);
        $this->assertSame(3000.00, (float) $cheque->amount);
        $this->assertSame('2026-11-25', $cheque->cheque_date->toDateString());

        // ตรวจสอบผลรวมเดบิต = เครดิต = 6,000
        $glEntries = GlJournal::where('payment_document_id', $paymentDoc->id)->get();
        $this->assertSame(6000.00, (float) $glEntries->sum('debit'));
        $this->assertSame(6000.00, (float) $glEntries->sum('credit'));
    }

    public function test_mismatched_payment_sum_and_allocation_sum_is_rejected_and_writes_nothing(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 3000.00);

        $docCountBefore = Document::count();
        $linesCountBefore = PaymentLine::count();
        $glCountBefore = GlJournal::count();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ยอดชำระรวม (2,500.00) ไม่เท่ากับยอดจัดสรร (3,000.00)');

        try {
            app(CustomerPaymentService::class)->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customerA->id,
                'payments' => [
                    ['method' => 'cash', 'amount' => 1000.00],
                    ['method' => 'transfer', 'amount' => 1500.00], // รวมได้ 2,500 ไม่เท่ากับ 3,000!
                ],
                'allocations' => [
                    ['customer_open_item_id' => $bill->id, 'amount' => 3000.00],
                ],
            ]);
        } finally {
            // พิสูจน์ว่าไม่มีอะไรถูกเขียนลงฐานข้อมูล (Rollback สะอาด)
            $this->assertSame($docCountBefore, Document::count());
            $this->assertSame($linesCountBefore, PaymentLine::count());
            $this->assertSame($glCountBefore, GlJournal::count());
            $this->assertSame(3000.00, (float) $bill->fresh()->balance_amount);
        }
    }

    public function test_partial_payment_across_multiple_bills(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customerA, 2000.00, 'SKU-P1');
        $bill2 = $this->createCreditSaleBill($this->customerA, 3000.00, 'SKU-P2');

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1500.00],
                ['method' => 'transfer', 'amount' => 1000.00, 'bank_account_id' => $this->bankAccount->id],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 1500.00], // ค้าง 2000 จ่าย 1500 เหลือ 500
                ['customer_open_item_id' => $bill2->id, 'amount' => 1000.00], // ค้าง 3000 จ่าย 1000 เหลือ 2000
            ],
        ]);

        $this->assertSame(2500.00, (float) $receipt->total_amount);

        // ตรวจสอบยอดคงค้างและสถานะของทั้งสองบิล
        $fresh1 = $bill1->fresh();
        $this->assertSame(500.00, (float) $fresh1->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_PARTIAL, $fresh1->status);

        $fresh2 = $bill2->fresh();
        $this->assertSame(2000.00, (float) $fresh2->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_PARTIAL, $fresh2->status);
    }

    public function test_legacy_single_payment_without_payments_array_works_identically(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1000.00);

        // เรียกแบบดั้งเดิม โดยไม่ส่ง key 'payments'
        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'method' => 'cash',
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 1000.00],
            ],
        ]);

        $this->assertSame(1000.00, (float) $receipt->total_amount);
        $paymentDoc = $receipt->paymentDocument;
        $this->assertCount(1, $paymentDoc->lines);
        $this->assertSame('cash', $paymentDoc->lines->first()->method);

        $glEntries = GlJournal::where('payment_document_id', $paymentDoc->id)->get();
        $this->assertSame(1000.00, (float) $glEntries->sum('debit'));
        $this->assertSame(1000.00, (float) $glEntries->sum('credit'));

        $this->assertSame(0.00, (float) $bill->fresh()->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_PAID, $bill->fresh()->status);
    }

    public function test_foreign_customer_bill_is_rejected(): void
    {
        $billOfCustomerB = $this->createCreditSaleBill($this->customerB, 2000.00);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ไม่พบรายการลูกหนี้ที่เลือก');

        // Customer A พยายามจ่ายบิลของ Customer B
        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'method' => 'cash',
            'allocations' => [
                ['customer_open_item_id' => $billOfCustomerB->id, 'amount' => 2000.00],
            ],
        ]);
    }

    public function test_overpayment_exceeding_open_item_balance_is_rejected(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1000.00);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ยอดชำระเกินยอดค้าง');

        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'method' => 'cash',
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 1500.00], // ค้าง 1000 แต่จ่าย 1500!
            ],
        ]);
    }

    public function test_bill_is_closed_when_fully_paid(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 1500.00);

        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 1500.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 1500.00],
            ],
        ]);

        $fresh = $bill->fresh();
        $this->assertSame(0.00, (float) $fresh->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_PAID, $fresh->status);
    }

    public function test_collector_salesman_is_recorded_on_document(): void
    {
        $bill = $this->createCreditSaleBill($this->customerA, 2000.00);

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customerA->id,
            'salesman_id' => $this->salesman->id,
            'payments' => [
                ['method' => 'cash', 'amount' => 2000.00],
            ],
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 2000.00],
            ],
        ]);

        $this->assertSame($this->salesman->id, (int) $receipt->salesman_id);
        $this->assertSame('สมชาย ขยันเก็บ', $receipt->salesman?->name);
    }

    public function test_controller_store_handles_mixed_payments_and_redirects_to_show(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customerA, 2000.00, 'SKU-CTRL1');
        $bill2 = $this->createCreditSaleBill($this->customerA, 3000.00, 'SKU-CTRL2');

        $response = $this->post(route('ar-receipts.store'), [
            'customer_id' => $this->customerA->id,
            'branch_id' => $this->branch->id,
            'salesman_id' => $this->salesman->id,
            'cash_amount' => 1000.00,
            'transfer_amount' => 2000.00,
            'transfer_bank_account_id' => $this->bankAccount->id,
            'transfer_ref' => 'TXN7788',
            'cheques' => [
                [
                    'cheque_bank' => 'ธนาคารกรุงไทย',
                    'cheque_no' => 'KTB-12345',
                    'cheque_due_date' => '2026-11-30',
                    'amount' => 1500.00,
                ],
            ],
            // Allocations: 1,500 + 3,000 = 4,500 = Cash 1,000 + Transfer 2,000 + Cheque 1,500
            'items' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 1500.00],
                ['customer_open_item_id' => $bill2->id, 'amount' => 3000.00],
            ],
        ]);

        $receipt = Document::where('document_type_id', DocumentType::where('code', 'RECEIPT')->value('id'))
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($receipt);
        $response->assertRedirect(route('payments.show', $receipt));
        $response->assertSessionHas('success');

        $this->assertSame(4500.00, (float) $receipt->total_amount);
        $this->assertSame($this->salesman->id, (int) $receipt->salesman_id);

        $lines = $receipt->paymentDocument->lines;
        $this->assertCount(3, $lines);
    }
}
