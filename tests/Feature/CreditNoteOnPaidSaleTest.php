<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditDebitNoteService;
use App\Services\Sales\CreditSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * ใบลดหนี้เคยอ้างได้เฉพาะใบขายเชื่อที่ยังค้าง: อ้างใบที่ชำระแล้วได้ด้วย
 * ส่วนที่เกินยอดค้างเป็นเครดิตลูกค้า หรือคืนเงินสด
 */
class CreditNoteOnPaidSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fully_paid_sale_can_be_credited_as_customer_credit(): void
    {
        [$customer, $item, $branch] = $this->creditSale(200, paid: 200);

        $note = $this->createAndApprove($customer, $item, $branch, 80, 'credit');

        $this->assertSame('active', $note->status);
        $this->assertSame(0.0, (float) $item->fresh()->balance_amount);
        $this->assertSame(120.0, (float) $item->fresh()->net_amount);
        // ลูกหนี้ติดยอดเครดิต 80 ไว้หักใบถัดไป
        $this->assertSame(80.0, $this->gl($note, ChartOfAccount::ROLE_AR, 'credit'));
        $this->assertSame(0.0, $this->gl($note, ChartOfAccount::ROLE_CASH, 'credit'));
    }

    public function test_a_fully_paid_sale_can_be_credited_as_a_cash_refund(): void
    {
        [$customer, $item, $branch] = $this->creditSale(200, paid: 200);

        $note = $this->createAndApprove($customer, $item, $branch, 80, 'refund');

        $this->assertSame(0.0, $this->gl($note, ChartOfAccount::ROLE_AR, 'credit'));
        $this->assertSame(80.0, $this->gl($note, ChartOfAccount::ROLE_CASH, 'credit'));
        $this->assertSame(0.0, (float) DB::table('customer_ledger')->where('document_id', $note->id)->value('amount'));
    }

    public function test_a_partly_paid_sale_reduces_what_is_owed_first_then_refunds_the_rest(): void
    {
        [$customer, $item, $branch] = $this->creditSale(200, paid: 150);

        $note = $this->createAndApprove($customer, $item, $branch, 120, 'refund');

        $this->assertSame(0.0, (float) $item->fresh()->balance_amount);
        $this->assertSame(50.0, $this->gl($note, ChartOfAccount::ROLE_AR, 'credit'));
        $this->assertSame(70.0, $this->gl($note, ChartOfAccount::ROLE_CASH, 'credit'));
        $this->assertSame(120.0, $this->gl($note, ChartOfAccount::ROLE_SALES_RETURN, 'debit') + $this->gl($note, ChartOfAccount::ROLE_VAT_OUTPUT, 'debit'));
    }

    public function test_cannot_credit_more_than_the_sale_is_worth_including_pending_notes(): void
    {
        [$customer, $item, $branch] = $this->creditSale(200, paid: 200);
        $this->signIn('maker');
        $service = app(CreditDebitNoteService::class);
        $service->create($this->data($customer, $item, $branch, 150, 'credit'));

        try {
            $service->create($this->data($customer, $item, $branch, 60, 'credit'));
            $this->fail('ควรปฏิเสธเพราะลดได้อีกแค่ 50');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ลดได้อีก 50.00', $e->getMessage());
        }
    }

    public function test_the_picker_lists_paid_sales_with_what_can_still_be_credited(): void
    {
        [$customer, $item] = $this->creditSale(200, paid: 200);
        $this->signIn('viewer');

        $this->getJson(url("credit-debit-notes/customers/{$customer->id}/open-items"))
            ->assertOk()
            ->assertJsonPath('0.id', $item->id)
            ->assertJsonPath('0.is_paid', true)
            ->assertJsonPath('0.creditable_amount', 200);
    }

    private function createAndApprove(Customer $customer, CustomerOpenItem $item, Branch $branch, float $amount, string $settlement): Document
    {
        $this->signIn('maker');
        $note = app(CreditDebitNoteService::class)->create($this->data($customer, $item, $branch, $amount, $settlement));
        $this->signIn('approver');

        return app(CreditDebitNoteService::class)->approveCredit($note);
    }

    private function data(Customer $customer, CustomerOpenItem $item, Branch $branch, float $amount, string $settlement): array
    {
        return [
            'type' => 'credit', 'customer_id' => $customer->id, 'branch_id' => $branch->id,
            'amount' => $amount, 'reason' => 'ลดราคาหลังขาย', 'open_item_id' => $item->id, 'settlement' => $settlement,
        ];
    }

    private function gl(Document $note, string $role, string $side): float
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');

        return round((float) DB::table('gl_journals')->where('document_id', $note->id)->where('account_id', $accountId)->sum($side), 2);
    }

    /** @return array{0: Customer, 1: CustomerOpenItem, 2: Branch} */
    private function creditSale(float $amount, float $paid): array
    {
        foreach ([['PURCHASE', 'in'], ['CREDIT_SALE', 'out'], ['BOOKING', 'none'], ['CREDIT_NOTE', 'none'], ['DEBIT_NOTE', 'none']] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $branch = Branch::create(['code' => 'CN', 'name_th' => 'สาขาลดหนี้', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-CN', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M-CN', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'CN-1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'default_price' => $amount,
            'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP-CN', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 50]],
        ]);
        $customer = Customer::create(['code' => 'C-CN', 'name_th' => 'ลูกค้าเครดิต', 'branch_id' => $branch->id, 'is_active' => true]);
        $booking = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $amount]],
        ]);
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
        $item = CustomerOpenItem::where('document_id', $sale->id)->sole();
        // จำลองว่าลูกค้าชำระมาแล้ว
        $balance = round((float) $item->balance_amount - $paid, 2);
        $item->update(['balance_amount' => $balance, 'status' => $balance <= 0.01 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL]);

        return [$customer, $item->fresh(), $branch->fresh()];
    }

    private function signIn(string $name): void
    {
        $this->actingAs(User::firstOrCreate(['username' => 'cn_'.$name], [
            'name' => $name, 'password' => bcrypt('x'), 'is_active' => true,
        ]));
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
