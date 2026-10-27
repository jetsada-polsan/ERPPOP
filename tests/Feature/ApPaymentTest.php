<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PaymentAllocation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierOpenItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Support\ErpMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * จ่ายชำระเจ้าหนี้: เลือกใบซื้อค้างจ่ายได้หลายใบ เงินสด/โอน/เช็ค หัก ณ ที่จ่ายได้
 * และลง GL Dr เจ้าหนี้การค้า / Cr เงินสดหรือธนาคาร.
 */
class ApPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pays_only_the_selected_purchases_and_posts_ap_against_cash(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $first = $this->creditPurchase($branch, $supplier, $product, 300);
        $second = $this->creditPurchase($branch, $supplier, $product, 500);
        $third = $this->creditPurchase($branch, $supplier, $product, 700);
        $this->signIn();

        $response = $this->post(route('ap-payments.store'), [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'method' => 'cash',
            'items' => [
                ['supplier_open_item_id' => $this->openItem($first)->id, 'amount' => 300],
                ['supplier_open_item_id' => $this->openItem($third)->id, 'amount' => 700],
            ],
        ]);

        $payment = Document::whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_VOUCHER'))->sole();
        $response->assertRedirect(route('payments.show', $payment));
        $this->assertSame(1000.0, (float) $payment->total_amount);

        // ใบที่ไม่ได้เลือก (ใบกลาง) ต้องยังค้างเต็มจำนวน แม้จะเก่ากว่าใบที่สาม
        $this->assertSame('cleared', $this->openItem($first)->status);
        $this->assertSame(500.0, (float) $this->openItem($second)->balance_amount);
        $this->assertSame('cleared', $this->openItem($third)->status);

        // จำได้ว่าเงินก้อนนี้ตัดใบไหนเท่าไร
        $allocations = PaymentAllocation::where('payment_document_id', $payment->paymentDocument->id)
            ->pluck('allocated_amount', 'supplier_open_item_id')->map(fn ($v) => (float) $v)->all();
        $this->assertEqualsCanonicalizing(
            [$this->openItem($first)->id => 300.0, $this->openItem($third)->id => 700.0],
            $allocations,
        );

        $this->assertSame(1000.0, $this->paymentSide($payment, ChartOfAccount::ROLE_AP, 'debit'));
        $this->assertSame(1000.0, $this->paymentSide($payment, ChartOfAccount::ROLE_CASH, 'credit'));
    }

    public function test_a_partial_payment_leaves_the_purchase_partly_open(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $purchase = $this->creditPurchase($branch, $supplier, $product, 1000);
        $this->signIn();

        $this->post(route('ap-payments.store'), [
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'method' => 'transfer',
            'items' => [['supplier_open_item_id' => $this->openItem($purchase)->id, 'amount' => 400]],
        ])->assertRedirect();

        $item = $this->openItem($purchase);
        $this->assertSame('partial', $item->status);
        $this->assertSame(600.0, (float) $item->balance_amount);
        // โอนเงิน -> ออกจากเงินฝากธนาคาร ไม่ใช่เงินสด
        $payment = Document::whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_VOUCHER'))->sole();
        $this->assertSame(400.0, $this->paymentSide($payment, ChartOfAccount::ROLE_BANK, 'credit'));
    }

    public function test_withholding_tax_reduces_the_cash_paid_but_clears_the_full_payable(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $supplier->update(['tax_id' => '0105551234567']);
        $purchase = $this->creditPurchase($branch, $supplier, $product, 1000);
        $this->signIn();

        $this->post(route('ap-payments.store'), [
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'method' => 'cheque',
            'cheque_no' => 'CHQ-0001', 'cheque_due_date' => '2026-11-30', 'cheque_bank' => 'กสิกรไทย',
            'items' => [['supplier_open_item_id' => $this->openItem($purchase)->id, 'amount' => 1000]],
            'withholding_base' => 1000, 'withholding_rate' => 3,
            'withholding_form' => 'PND53', 'withholding_income_type' => 'ค่าบริการ',
        ])->assertRedirect();

        $payment = Document::whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_VOUCHER'))->sole();
        $this->assertSame('cleared', $this->openItem($purchase)->status);
        $this->assertSame(1000.0, $this->paymentSide($payment, ChartOfAccount::ROLE_AP, 'debit'));
        $this->assertSame(970.0, $this->paymentSide($payment, ChartOfAccount::ROLE_BANK, 'credit'));
        $this->assertSame(30.0, $this->paymentSide($payment, ChartOfAccount::ROLE_WHT_PAYABLE, 'credit'));
        $this->assertDatabaseHas('cheques', ['cheque_no' => 'CHQ-0001', 'direction' => 'out', 'amount' => 970]);
        $this->assertSame(30.0, (float) PaymentAllocation::where('payment_document_id', $payment->paymentDocument->id)->sum('wht_amount'));
    }

    public function test_paying_more_than_is_owed_is_refused_and_nothing_is_posted(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $purchase = $this->creditPurchase($branch, $supplier, $product, 300);
        $this->signIn();

        $this->from(route('ap-payments.create', ['supplier_id' => $supplier->id]))
            ->post(route('ap-payments.store'), [
                'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'method' => 'cash',
                'items' => [['supplier_open_item_id' => $this->openItem($purchase)->id, 'amount' => 999]],
            ])->assertRedirect(route('ap-payments.create', ['supplier_id' => $supplier->id]));

        $this->assertStringContainsString('ค้างจ่าย 300.00', (string) session('error'));
        $this->assertSame(0, Document::whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_VOUCHER'))->count());
        $this->assertSame(300.0, (float) $this->openItem($purchase)->balance_amount);
    }

    public function test_another_suppliers_purchase_cannot_be_paid_on_this_voucher(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $other = Supplier::create(['code' => 'SUP-OTHER', 'name_th' => 'ผู้จำหน่ายอื่น', 'is_active' => true]);
        $theirs = $this->creditPurchase($branch, $other, $product, 200);
        $this->creditPurchase($branch, $supplier, $product, 500);
        $this->signIn();

        $this->post(route('ap-payments.store'), [
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'method' => 'cash',
            'items' => [['supplier_open_item_id' => $this->openItem($theirs)->id, 'amount' => 200]],
        ])->assertRedirect();

        $this->assertStringContainsString('ไม่ใช่ของผู้จำหน่ายรายนี้', (string) session('error'));
        $this->assertSame(200.0, (float) $this->openItem($theirs)->balance_amount);
    }

    public function test_purchase_page_links_to_the_payment_with_that_purchase_selected(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $purchase = $this->creditPurchase($branch, $supplier, $product, 450);
        $item = $this->openItem($purchase);
        $this->signIn();

        $link = route('ap-payments.create', ['supplier_id' => $supplier->id, 'open_item_id' => $item->id]);
        $this->get(route('purchases.show', $purchase))->assertOk()
            ->assertSee('จ่ายชำระ')
            ->assertSee('ค้างจ่าย 450.00')
            ->assertSee($link);

        $html = $this->get($link)->assertOk()->getContent();
        $this->assertStringContainsString($purchase->doc_number, $html);
        $this->assertStringContainsString("const preselected = {$item->id};", $html);
    }

    public function test_index_lists_suppliers_with_money_owed_and_the_menu_reaches_it(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $this->creditPurchase($branch, $supplier, $product, 300);
        $this->creditPurchase($branch, $supplier, $product, 200);
        $this->signIn();

        $this->get(route('ap-payments.index'))->assertOk()
            ->assertSee($supplier->name_th)
            ->assertSee('500.00');

        $finance = collect(ErpMenu::all())->firstWhere('label', 'การเงิน / บัญชี');
        $this->assertContains('ap-payments.index', collect($finance['items'])->pluck('route')->all());
    }

    public function test_paying_from_the_supplier_page_still_works_and_now_records_allocations(): void
    {
        [$branch, $supplier, $product] = $this->masters();
        $old = $this->creditPurchase($branch, $supplier, $product, 300);
        $this->creditPurchase($branch, $supplier, $product, 500);
        $this->signIn();

        $this->post(route('suppliers.payments.store', $supplier), [
            'branch_id' => $branch->id, 'method' => 'cash', 'amount' => 300,
        ])->assertRedirect();

        $this->assertSame('cleared', $this->openItem($old)->status);
        $this->assertDatabaseHas('payment_allocations', [
            'supplier_open_item_id' => $this->openItem($old)->id,
            'allocated_amount' => 300,
        ]);
    }

    private function creditPurchase(Branch $branch, Supplier $supplier, Product $product, float $amount): Document
    {
        return app(PurchaseService::class)->create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'due_date' => '2026-12-31',
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => $amount]],
        ]);
    }

    private function openItem(Document $purchase): SupplierOpenItem
    {
        return SupplierOpenItem::where('source_document_id', $purchase->id)->sole();
    }

    private function paymentSide(Document $payment, string $role, string $side): float
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');

        return (float) DB::table('gl_journals')
            ->where('payment_document_id', $payment->paymentDocument->id)
            ->where('account_id', $accountId)
            ->sum($side);
    }

    /** @return array{0: Branch, 1: Supplier, 2: Product} */
    private function masters(): array
    {
        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'PAYMENT_VOUCHER'], ['name_th' => 'ใบสำคัญจ่าย']);
        $branch = Branch::create(['code' => 'AP', 'name_th' => 'สาขาทดสอบจ่ายเจ้าหนี้', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-AP', 'name' => 'คลังทดสอบ']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN-AP', 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $supplier = Supplier::create(['code' => 'SUP-AP', 'name_th' => 'ผู้จำหน่ายค้างจ่าย', 'is_active' => true]);
        $unit = ProductUnit::create(['code' => 'EA-AP', 'name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'AP-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'allow',
        ]);

        return [$branch->fresh(), $supplier, $product];
    }

    private function signIn(): void
    {
        $user = User::factory()->create(['username' => 'ap_payer']);
        $role = Role::create(['code' => 'AP_PAYER', 'name' => 'AP payer']);
        $role->permissions()->sync(collect(['finance.manage', 'purchasing.manage'])
            ->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
        $user->roles()->attach($role->id);

        $this->actingAs($user->fresh());
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
