<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Purchasing\SupplierPaymentService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ข้อ 12: รับ/จ่ายด้วยโอนหรือเช็คเลือกบัญชีธนาคารได้ และ GL ลงบัญชีที่เลือก
 * ไม่เลือกยังลงบัญชีของสาขาเหมือนเดิม เงินสดไม่สนบัญชีที่เลือก
 */
class PaymentBankAccountChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_transfer_posts_to_the_chosen_bank_account(): void
    {
        [$branch, $customer, $item] = $this->creditSale();
        $chosen = $this->bank($branch, '1020-09', 'เงินฝาก - บัญชีที่เลือก');

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'method' => 'transfer', 'bank_account_id' => $chosen->id,
            'allocations' => [['customer_open_item_id' => $item->id, 'amount' => 100]],
        ]);

        $paymentId = DB::table('payment_documents')->where('document_id', $receipt->id)->value('id');
        $this->assertSame(100.0, (float) DB::table('gl_journals')->where('payment_document_id', $paymentId)->where('account_id', $chosen->chart_of_account_id)->sum('debit'));
        $this->assertSame($chosen->id, (int) DB::table('payment_lines')->where('payment_document_id', $paymentId)->value('bank_account_id'));
    }

    public function test_cash_ignores_a_chosen_bank_account(): void
    {
        [$branch, $customer, $item] = $this->creditSale();
        $chosen = $this->bank($branch, '1020-09', 'เงินฝาก - บัญชีที่เลือก');

        $receipt = app(CustomerPaymentService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'method' => 'cash', 'bank_account_id' => $chosen->id,
            'allocations' => [['customer_open_item_id' => $item->id, 'amount' => 100]],
        ]);

        $paymentId = DB::table('payment_documents')->where('document_id', $receipt->id)->value('id');
        $this->assertSame(0.0, (float) DB::table('gl_journals')->where('payment_document_id', $paymentId)->where('account_id', $chosen->chart_of_account_id)->sum('debit'));
        $this->assertNull(DB::table('payment_lines')->where('payment_document_id', $paymentId)->value('bank_account_id'));
    }

    public function test_a_supplier_transfer_posts_to_the_chosen_bank_account(): void
    {
        [$branch] = $this->creditSale();
        $chosen = $this->bank($branch, '1020-08', 'เงินฝาก - จ่ายผู้ขาย');
        $supplier = Supplier::where('code', 'SUP-BK')->sole();

        $payment = app(SupplierPaymentService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'method' => 'transfer', 'bank_account_id' => $chosen->id,
            'amount' => 100,
        ]);

        $paymentId = DB::table('payment_documents')->where('document_id', $payment->id)->value('id');
        $this->assertSame(100.0, (float) DB::table('gl_journals')->where('payment_document_id', $paymentId)->where('account_id', $chosen->chart_of_account_id)->sum('credit'));
    }

    private function bank(Branch $branch, string $code, string $name): BankAccount
    {
        $gl = ChartOfAccount::create(['code' => $code, 'name_th' => $name, 'account_type' => 'asset']);

        return BankAccount::create(['branch_id' => $branch->id, 'bank_name' => 'ธนาคารทดสอบ', 'account_no' => $code, 'chart_of_account_id' => $gl->id]);
    }

    /** @return array{0: Branch, 1: Customer, 2: CustomerOpenItem} */
    private function creditSale(): array
    {
        foreach ([['PURCHASE', 'in'], ['CREDIT_SALE', 'out'], ['BOOKING', 'none'], ['RECEIPT', 'none'], ['PAYMENT_VOUCHER', 'none']] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $this->actingAs(User::factory()->create(['username' => 'bank_choice']));
        $branch = Branch::create(['code' => 'BK', 'name_th' => 'สาขาธนาคาร', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-BK', 'name' => 'คลัง']);
        $branch->update(['default_warehouse_location_id' => WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'หลัก'])->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create(['sku_code' => 'BK-1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP-BK', 'name_th' => 'ผู้ขาย', 'is_active' => true]);
        app(PurchaseService::class)->create(['branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 50]]]);
        $customer = Customer::create(['code' => 'C-BK', 'name_th' => 'ลูกค้า', 'branch_id' => $branch->id, 'is_active' => true]);
        $booking = app(BookingService::class)->create(['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]]]);
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);

        return [$branch->fresh(), $customer, CustomerOpenItem::where('document_id', $sale->id)->sole()];
    }
}
