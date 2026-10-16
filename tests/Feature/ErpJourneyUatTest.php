<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UAT ทั้งวงจรผ่าน route จริงเหมือนผู้ใช้กด (ข้อมูล UAT- จาก tools/staging/seed_uat_data.php)
 *
 * 1) ซื้อเชื่อ → รับของเข้าสต๊อก → จ่ายเจ้าหนี้ → GL สมดุล
 * 2) ใบจอง → แปลงเป็นขายเชื่อ → วางบิล → รับชำระ → นำเข้า Statement → กระทบยอด
 */
class ErpJourneyUatTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        ob_start();
        require base_path('tools/staging/seed_uat_data.php');
        ob_end_clean();
        $this->branchId = (int) DB::table('branches')->where('code', 'UAT')->value('id');
        $this->locationId = (int) DB::table('warehouse_locations')->where('code', 'UAT-MAIN')->value('id');
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs(User::factory()->create([
            'username' => 'uat-journey', 'branch_id' => $this->branchId, 'is_active' => true, 'must_change_password' => false,
        ]));
    }

    public function test_purchase_receive_pay_supplier_and_gl_balances(): void
    {
        $supplierId = (int) DB::table('suppliers')->where('code', 'UAT-SUP-001')->value('id');
        $productId = (int) DB::table('products')->where('sku_code', 'UAT-SKU-001')->value('id');
        $onHandBefore = $this->onHand($productId);

        $this->post(route('purchases.store'), [
            'supplier_id' => $supplierId, 'branch_id' => $this->branchId, 'is_credit' => true,
            'items' => [['product_id' => $productId, 'qty' => 20, 'unit_price' => 40,
                'lot_number' => 'UAT-PO-LOT', 'expiry_date' => now()->addMonths(6)->toDateString()]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertEqualsWithDelta($onHandBefore + 20, $this->onHand($productId), 0.0001, 'รับของแล้วสต๊อกต้องเพิ่ม 20');
        $payable = $this->payable($supplierId);
        $this->assertEqualsWithDelta(800, $payable, 0.01, 'ซื้อเชื่อ 20 × 40 ต้องเป็นหนี้ผู้จำหน่าย 800');

        $this->post(route('suppliers.payments.store', $supplierId), [
            'branch_id' => $this->branchId, 'method' => 'transfer', 'amount' => 800,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payable = $this->payable($supplierId);
        $this->assertEqualsWithDelta(0, $payable, 0.01, 'จ่ายครบแล้วหนี้ต้องเป็นศูนย์');
        $this->assertGlBalanced();
        $this->assertReconciles();
    }

    public function test_credit_sale_billing_receipt_and_bank_reconciliation(): void
    {
        $customerId = (int) DB::table('customers')->where('code', 'UAT-CUS-001')->value('id');
        DB::table('customers')->where('id', $customerId)->update(['credit_limit' => 100000]);
        $productId = (int) DB::table('products')->where('sku_code', 'UAT-SKU-001')->value('id'); // ไม่มี VAT
        $onHandBefore = $this->onHand($productId);

        $this->post(route('bookings.store'), [
            'customer_id' => $customerId, 'branch_id' => $this->branchId, 'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $productId, 'qty' => 10, 'unit_price' => 60]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $bookingId = (int) DB::table('sale_bookings')->latest('id')->value('id');
        $this->assertEqualsWithDelta($onHandBefore, $this->onHand($productId), 0.0001, 'ใบจองต้องไม่ตัดสต๊อก');

        $this->post(route('bookings.convert', $bookingId))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsWithDelta($onHandBefore - 10, $this->onHand($productId), 0.0001, 'ขายเชื่อต้องตัดสต๊อก');

        $openItem = DB::table('customer_open_items')->where('customer_id', $customerId)->first();
        $this->assertNotNull($openItem, 'ขายเชื่อต้องเปิดลูกหนี้');
        $this->assertEqualsWithDelta(600, (float) $openItem->balance_amount, 0.01);

        $this->post(route('billing-notes.store'), [
            'customer_id' => $customerId, 'branch_id' => $this->branchId, 'open_item_id' => [$openItem->id],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('billing_notes', 1);

        $this->post(route('customers.payments.store', $customerId), [
            'branch_id' => $this->branchId, 'method' => 'transfer',
            'open_item_id' => [$openItem->id], 'amount' => [600],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsWithDelta(0, (float) DB::table('customer_open_items')->where('id', $openItem->id)->value('balance_amount'), 0.01, 'รับเงินครบแล้วลูกหนี้ต้องเป็นศูนย์');

        $bankId = (int) DB::table('bank_accounts')->where('account_no', 'UAT-000-1')->value('id');
        $csv = "date,description,amount,balance\n".now()->toDateString().",UAT-CUS-001 โอนชำระ,600,600\n";
        $this->post(route('monthly-accounting.statements.import'), [
            'bank_account_id' => $bankId,
            'statement_file' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $statementId = (int) DB::table('bank_statements')->value('id');
        $this->assertGreaterThan(0, $statementId, 'นำเข้า Statement แล้วต้องมีแถว');

        $this->post(route('monthly-accounting.statements.reconcile', $statementId), [
            'branch_id' => $this->branchId, 'match_type' => 'payment', 'expected_amount' => 600, 'reference' => 'UAT-CUS-001',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'ยอด Statement ตรงกับหลักฐานแล้ว');
        $this->assertDatabaseCount('bank_reconciliations', 1);

        $this->assertGlBalanced();
        $this->assertReconciles();
    }

    private function payable(int $supplierId): float
    {
        return (float) DB::table('supplier_ledger')->where('supplier_id', $supplierId)->orderByDesc('id')->value('balance_after');
    }

    private function onHand(int $productId): float
    {
        return (float) DB::table('stock_balances')->where('product_id', $productId)
            ->where('warehouse_location_id', $this->locationId)->value('on_hand_qty');
    }

    private function assertGlBalanced(): void
    {
        $debit = (float) DB::table('gl_journals')->sum('debit');
        $credit = (float) DB::table('gl_journals')->sum('credit');
        $this->assertGreaterThan(0, $debit, 'ต้องมี GL เกิดขึ้นจริง');
        $this->assertEqualsWithDelta($debit, $credit, 0.01, 'GL เดบิตต้องเท่าเครดิต');
    }

    private function assertReconciles(): void
    {
        $code = Artisan::call('uat:reconcile', ['--branch' => $this->branchId]);
        $this->assertSame(0, $code, "uat:reconcile ไม่ผ่าน:\n".Artisan::output());
    }
}
