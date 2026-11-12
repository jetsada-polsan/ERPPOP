<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
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
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use App\Support\ArReceiptAllocationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArReceiptPartialTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Customer $customer;

    private BankAccount $bankAccount;

    private Salesman $salesman;

    private ChartOfAccount $cashCoa;

    private ChartOfAccount $arCoa;

    private ChartOfAccount $bankCoa;

    private ChartOfAccount $roundingCoa;

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

        $this->customer = Customer::create([
            'code' => 'CUST-01',
            'name_th' => 'ลูกค้ารายการทดสอบ BPlus',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $this->salesman = Salesman::create([
            'branch_id' => $this->branch->id,
            'code' => 'SM01',
            'name' => 'สมชาย ขยันเก็บ',
            'is_active' => true,
        ]);

        // COA Setup
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
            ['name_th' => 'เงินฝากธนาคาร', 'account_type' => 'asset']
        );
        $this->roundingCoa = ChartOfAccount::firstOrCreate(
            ['code' => '5090'],
            ['name_th' => 'กำไรขาดทุนจากเศษสตางค์', 'account_type' => 'expense']
        );

        $this->bankAccount = BankAccount::create([
            'branch_id' => $this->branch->id,
            'bank_name' => 'กสิกรไทย',
            'account_no' => '123-4-56789-0',
            'chart_of_account_id' => $this->bankCoa->id,
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'username' => 'finance_tester',
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $role = Role::firstOrCreate(['code' => 'ACC_MGR'], ['name' => 'ผู้จัดการฝ่ายบัญชี']);
        $this->user->roles()->attach($role);
        $this->actingAs($this->user);
    }

    private function createCreditSaleBill(Customer $customer, float $amount, string $docDate = '2026-10-01', string $sku = 'ITEM'): CustomerOpenItem
    {
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => $sku.'-'.uniqid(),
            'name_th' => 'สินค้าทดสอบ',
            'base_unit_id' => $unit->id,
            'default_price' => $amount,
            'average_cost' => round($amount * 0.7, 4),
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        $supplier = Supplier::firstOrCreate(['code' => 'SUP-01'], ['name_th' => 'ผู้จำหน่ายหลัก', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'doc_date' => $docDate,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => round($amount * 0.7, 4)]],
        ]);

        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'doc_date' => $docDate,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $amount]],
        ]);

        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
        $sale->update(['doc_date' => $docDate]);

        $openItem = CustomerOpenItem::where('document_id', $sale->id)->sole();
        $openItem->update(['doc_date' => $docDate]);

        return $openItem->fresh();
    }

    /**
     * 1. เคสจริงจาก BPlus:
     *    บิล 1 (เก่าสุด): 12,697.90 (วันที่ 2026-10-01)
     *    บิล 2 (ใหม่กว่า): 2,665.00 (วันที่ 2026-10-15)
     *    รวมยอดหนี้: 15,362.90
     *    ลูกค้าจ่าย: 3,363.00 บาท
     *    ผลลัพธ์:
     *    - บิล 1 โดนตัด 3,363.00 คงเหลือ 9,334.90 สถานะ partial
     *    - บิล 2 โดนตัด 0.00 คงเหลือ 2,665.00 สถานะ open
     *    - ยอดหนี้คงค้างรวมของลูกค้า = 11,999.90 บาทพอดีถึงสตางค์
     *    - ยอดรวมใบเสร็จ = 3,363.00
     */
    public function test_real_bplus_case_partial_payment_cuts_oldest_bill_first_and_calculates_next_payment_exact_to_satang(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customer, 12697.90, '2026-10-01', 'ITEM1');
        $bill2 = $this->createCreditSaleBill($this->customer, 2665.00, '2026-10-15', 'ITEM2');

        // ตรวจสอบตัวช่วยกระจายยอด (ArReceiptAllocationHelper)
        $bills = [
            ['id' => $bill1->id, 'doc_date' => '2026-10-01', 'doc_number' => $bill1->document->doc_number, 'balance' => 12697.90],
            ['id' => $bill2->id, 'doc_date' => '2026-10-15', 'doc_number' => $bill2->document->doc_number, 'balance' => 2665.00],
        ];
        $distribution = ArReceiptAllocationHelper::distribute($bills, 3363.00);

        $this->assertSame(15362.90, $distribution['total_debt']);
        $this->assertSame(3363.00, $distribution['total_allocated']);
        $this->assertSame(11999.90, $distribution['next_payment_remaining']);

        // บันทึกผ่าน Controller POST
        $response = $this->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'salesman_id' => $this->salesman->id,
            'doc_date' => '2026-10-20',
            'cash_amount' => 3363.00,
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 3363.00],
                ['customer_open_item_id' => $bill2->id, 'amount' => 0.00],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // ตรวจสอบบิล 1
        $bill1Fresh = $bill1->fresh();
        $this->assertSame(CustomerOpenItem::STATUS_PARTIAL, $bill1Fresh->status);
        $this->assertSame(9334.90, (float) $bill1Fresh->balance_amount);
        $this->assertSame(3363.00, (float) $bill1Fresh->paid_amount);

        // ตรวจสอบบิล 2
        $bill2Fresh = $bill2->fresh();
        $this->assertSame(CustomerOpenItem::STATUS_OPEN, $bill2Fresh->status);
        $this->assertSame(2665.00, (float) $bill2Fresh->balance_amount);
        $this->assertSame(0.00, (float) $bill2Fresh->paid_amount);

        // ยอดหนี้รวมคงค้างลูกค้ารายนี้ = 11,999.90 บาทพอดี
        $totalCustomerRemaining = round((float) CustomerOpenItem::where('customer_id', $this->customer->id)
            ->whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
            ->sum('balance_amount'), 2);
        $this->assertSame(11999.90, $totalCustomerRemaining);

        // ตรวจสอบใบเสร็จรับเงิน
        $receipt = PaymentDocument::where('customer_id', $this->customer->id)->sole();
        $this->assertSame(3363.00, (float) $receipt->document->total_amount);
        $this->assertSame(3363.00, (float) $receipt->lines->sum('amount'));
        $this->assertCount(1, $receipt->allocations);
        $this->assertSame(3363.00, (float) $receipt->allocations->first()->allocated_amount);
    }

    /**
     * 2. จ่ายพอดี 2 บิล: 12,697.90 + 2,665.00 = 15,362.90
     *    - ทั้งสองบิลปิดยอดเป็น paid
     *    - ยอดคงเหลือเป็น 0.00 ทั้งคู่
     *    - หนี้คงค้างลูกค้ารวม = 0.00
     */
    public function test_exact_full_payment_of_both_bills_clears_customer_debt_completely(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customer, 12697.90, '2026-10-01', 'FULL1');
        $bill2 = $this->createCreditSaleBill($this->customer, 2665.00, '2026-10-15', 'FULL2');

        $response = $this->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'salesman_id' => $this->salesman->id,
            'doc_date' => '2026-10-20',
            'cash_amount' => 15362.90,
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 12697.90],
                ['customer_open_item_id' => $bill2->id, 'amount' => 2665.00],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertSame(CustomerOpenItem::STATUS_PAID, $bill1->fresh()->status);
        $this->assertSame(0.00, (float) $bill1->fresh()->balance_amount);

        $this->assertSame(CustomerOpenItem::STATUS_PAID, $bill2->fresh()->status);
        $this->assertSame(0.00, (float) $bill2->fresh()->balance_amount);

        $openDebt = (float) CustomerOpenItem::where('customer_id', $this->customer->id)
            ->whereIn('status', [CustomerOpenItem::STATUS_OPEN, CustomerOpenItem::STATUS_PARTIAL])
            ->sum('balance_amount');
        $this->assertSame(0.00, $openDebt);
    }

    /**
     * 3. จ่ายเกินยอดหนี้ที่เลือก (เช่น หนี้ 15,362.90 จ่าย 16,000.00):
     *    - Helper โยน InvalidArgumentException
     *    - Controller ปฏิเสธ และบิลไม่ถูกแก้ไข
     */
    public function test_payment_exceeding_total_selected_debt_is_rejected(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customer, 12697.90, '2026-10-01', 'OVER1');
        $bill2 = $this->createCreditSaleBill($this->customer, 2665.00, '2026-10-15', 'OVER2');

        $bills = [
            ['id' => $bill1->id, 'balance' => 12697.90],
            ['id' => $bill2->id, 'balance' => 2665.00],
        ];

        // 1. Helper ปฏิเสธ
        $this->expectException(\InvalidArgumentException::class);
        ArReceiptAllocationHelper::distribute($bills, 16000.00);
    }

    /**
     * 3b. Controller ปฏิเสธถ้ายอดจัดสรรหรือยอดจ่ายเกินยอดค้าง
     */
    public function test_controller_rejects_overpaid_bill(): void
    {
        $bill1 = $this->createCreditSaleBill($this->customer, 12697.90, '2026-10-01', 'OVERB1');

        $response = $this->from(route('ar-receipts.create'))->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cash_amount' => 13000.00,
            'allocations' => [
                ['customer_open_item_id' => $bill1->id, 'amount' => 13000.00],
            ],
        ]);

        $response->assertRedirect(route('ar-receipts.create'));
        $response->assertSessionHas('error');

        $this->assertSame(12697.90, (float) $bill1->fresh()->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_OPEN, $bill1->fresh()->status);
        $this->assertSame(0, PaymentDocument::count());
    }

    /**
     * 4. เศษขาด 1.00 บาท (เช่น บิล 2,040.00 ลูกค้าจ่าย 2,039.00 ระบุ rounding_amount = -1.00):
     *    - บิล 2,040.00 ถูกตัดเต็ม ปิดหนี้เป็น paid
     *    - PaymentDocument เก็บ rounding_amount = -1.00
     *    - GL บันทึก Dr เงินสด 2,039.00, Dr เศษสตางค์ 1.00, Cr ลูกหนี้การค้า 2,040.00
     *    - เดบิต = เครดิต = 2,040.00 บาทพอดีถึงสตางค์
     */
    public function test_shortfall_rounding_of_one_baht_settles_bill_in_full_and_posts_balanced_gl(): void
    {
        $bill = $this->createCreditSaleBill($this->customer, 2040.00, '2026-10-05', 'ROUND-SHORT');

        $response = $this->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'salesman_id' => $this->salesman->id,
            'doc_date' => '2026-10-20',
            'cash_amount' => 2039.00,
            'rounding_amount' => -1.00,
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 2040.00],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // บิลต้องปิดเต็มจำนวน
        $this->assertSame(CustomerOpenItem::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame(0.00, (float) $bill->fresh()->balance_amount);
        $this->assertSame(2040.00, (float) $bill->fresh()->paid_amount);

        // PaymentDocument
        $payDoc = PaymentDocument::where('customer_id', $this->customer->id)->sole();
        $this->assertSame(2039.00, (float) $payDoc->lines->sum('amount'));
        $this->assertSame(-1.00, (float) $payDoc->rounding_amount);

        // ตรวจสอบสมุดรายวันทั่วไป (GL)
        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        $totalDr = round((float) $journals->sum('debit'), 2);
        $totalCr = round((float) $journals->sum('credit'), 2);

        $this->assertSame(2040.00, $totalDr, 'ยอดเดบิตรวมต้องเท่ากับ 2,040.00');
        $this->assertSame(2040.00, $totalCr, 'ยอดเครดิตรวมต้องเท่ากับ 2,040.00');

        // Dr เงินสด 2,039.00
        $cashEntry = $journals->firstWhere('account_id', $this->cashCoa->id);
        $this->assertNotNull($cashEntry);
        $this->assertSame(2039.00, (float) $cashEntry->debit);

        // Dr เศษสตางค์ 1.00
        $roundingEntry = $journals->firstWhere('account_id', $this->roundingCoa->id);
        $this->assertNotNull($roundingEntry, 'ต้องมีบันทึกบัญชีเศษสตางค์');
        $this->assertSame(1.00, (float) $roundingEntry->debit);
        $this->assertSame(0.00, (float) $roundingEntry->credit);

        // Cr ลูกหนี้การค้า 2,040.00
        $arCredit = round((float) $journals->where('account_id', $this->arCoa->id)->sum('credit'), 2);
        $this->assertSame(2040.00, $arCredit);
    }

    /**
     * 5. เศษเกิน 1.00 บาท (เช่น บิล 2,040.00 ลูกค้าจ่าย 2,041.00 ระบุ rounding_amount = 1.00):
     *    - บิล 2,040.00 ถูกตัดเต็ม ปิดหนี้เป็น paid
     *    - GL บันทึก Dr เงินสด 2,041.00, Cr ลูกหนี้การค้า 2,040.00, Cr เศษสตางค์ 1.00
     *    - เดบิต = เครดิต = 2,041.00 บาทพอดีถึงสตางค์
     */
    public function test_excess_rounding_of_one_baht_settles_bill_and_posts_balanced_gl(): void
    {
        $bill = $this->createCreditSaleBill($this->customer, 2040.00, '2026-10-05', 'ROUND-EXCESS');

        $response = $this->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'salesman_id' => $this->salesman->id,
            'doc_date' => '2026-10-20',
            'cash_amount' => 2041.00,
            'rounding_amount' => 1.00,
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 2040.00],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // บิลต้องปิดเต็มจำนวน
        $this->assertSame(CustomerOpenItem::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame(0.00, (float) $bill->fresh()->balance_amount);

        // PaymentDocument
        $payDoc = PaymentDocument::where('customer_id', $this->customer->id)->sole();
        $this->assertSame(2041.00, (float) $payDoc->lines->sum('amount'));
        $this->assertSame(1.00, (float) $payDoc->rounding_amount);

        // GL
        $journals = GlJournal::where('payment_document_id', $payDoc->id)->get();
        $totalDr = round((float) $journals->sum('debit'), 2);
        $totalCr = round((float) $journals->sum('credit'), 2);

        $this->assertSame(2042.00, $totalDr);
        $this->assertSame(2042.00, $totalCr);

        // Dr เงินสด 2,041.00
        $cashEntry = $journals->firstWhere('account_id', $this->cashCoa->id);
        $this->assertSame(2041.00, (float) $cashEntry->debit);

        // Net Cr ลูกหนี้การค้า 2,040.00 (Cr 2,041.00 - Dr 1.00)
        $arCredit = round((float) $journals->where('account_id', $this->arCoa->id)->sum('credit'), 2);
        $arDebit = round((float) $journals->where('account_id', $this->arCoa->id)->sum('debit'), 2);
        $this->assertSame(2040.00, round($arCredit - $arDebit, 2));

        // Cr เศษสตางค์ 1.00
        $roundingEntry = $journals->firstWhere('account_id', $this->roundingCoa->id);
        $this->assertNotNull($roundingEntry);
        $this->assertSame(0.00, (float) $roundingEntry->debit);
        $this->assertSame(1.00, (float) $roundingEntry->credit);
    }

    /**
     * 6. ปฏิเสธเศษขาด/เกินที่เกิน ±1.00 บาท (เช่น -1.01 หรือ +1.01):
     *    - Controller validation ปฏิเสธ
     *    - ข้อมูลไม่ถูกบันทึก
     */
    public function test_rounding_amount_exceeding_limit_is_rejected(): void
    {
        $bill = $this->createCreditSaleBill($this->customer, 2040.00, '2026-10-05', 'ROUND-BAD');

        $response = $this->from(route('ar-receipts.create'))->post(route('ar-receipts.store'), [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'cash_amount' => 2038.99,
            'rounding_amount' => -1.01,
            'allocations' => [
                ['customer_open_item_id' => $bill->id, 'amount' => 2040.00],
            ],
        ]);

        $response->assertSessionHasErrors(['rounding_amount']);
        $this->assertSame(0, PaymentDocument::count());
        $this->assertSame(2040.00, (float) $bill->fresh()->balance_amount);
    }

    /**
     * 7. ปฏิเสธการบันทึกในงวดบัญชีที่ปิดแล้ว (Closed Period):
     *    - ต้องถูกบล็อกด้วย AccountingPeriodGuard
     */
    public function test_receipt_into_closed_accounting_period_is_rejected(): void
    {
        $bill = $this->createCreditSaleBill($this->customer, 2040.00, '2026-09-10', 'CLOSED-P');

        AccountingPeriod::create([
            'name' => 'งวดกันยายน 2569',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        try {
            app(CustomerPaymentService::class)->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $this->customer->id,
                'doc_date' => '2026-09-15',
                'payments' => [['method' => 'cash', 'amount' => 2040.00]],
                'allocations' => [['customer_open_item_id' => $bill->id, 'amount' => 2040.00]],
            ]);
            $this->fail('ต้องถูกปฏิเสธเมื่อบันทึกลงงวดบัญชีที่ปิดแล้ว');
        } catch (ValidationException) {
            // Success: blocked by AccountingPeriodGuard
        }

        $this->assertSame(0, PaymentDocument::count());
        $this->assertSame(2040.00, (float) $bill->fresh()->balance_amount);
    }

    /**
     * 8. open-items API ส่งคืน doc_date_raw ในรูปแบบ YYYY-MM-DD
     */
    public function test_customer_open_items_endpoint_returns_doc_date_raw(): void
    {
        $bill = $this->createCreditSaleBill($this->customer, 5000.00, '2026-10-01', 'API-ITEM');

        $response = $this->getJson(route('ar-receipts.open-items', $this->customer));

        $response->assertOk();
        $data = $response->json();
        $this->assertNotEmpty($data);
        $this->assertSame('2026-10-01', $data[0]['doc_date_raw']);
        $this->assertSame(5000.00, (float) $data[0]['balance']);
    }
}
