<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\CashBook;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\SupplierOpenItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\DocumentVoidService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CashSaleService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use App\Services\Sales\SaleReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * ข้อ 11: ยกเลิกเอกสารหลังบ้าน ต้องใส่เหตุผล กลับสต๊อก + GL + ลูกหนี้/เจ้าหนี้ และเก็บ audit
 * ห้ามยกเลิกเมื่อมีเอกสารปลายทาง ของถูกใช้ไปแล้ว หรืองวดปิด
 */
class DocumentVoidTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private WarehouseLocation $location;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['PURCHASE', 'in'], ['CREDIT_SALE', 'out'], ['CASH_SALE', 'out'], ['BOOKING', 'none'], ['RECEIPT', 'none'], ['SALE_RETURN', 'in']] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $this->branch = Branch::create(['code' => 'VD', 'name_th' => 'สาขายกเลิก', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH-VD', 'name' => 'คลัง']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M-VD', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $this->location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product = Product::create([
            'sku_code' => 'VD-1', 'name_th' => 'สินค้า', 'base_unit_id' => $unit->id, 'default_price' => 100,
            'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $this->supplier = Supplier::create(['code' => 'SUP-VD', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        $this->customer = Customer::create(['code' => 'C-VD', 'name_th' => 'ลูกค้า', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->actingAs(User::factory()->create(['username' => 'void_admin']));
    }

    public function test_voiding_a_credit_sale_restores_stock_closes_the_debt_and_nets_gl_to_zero(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(2);
        $this->assertSame(3.0, $this->onHand());

        app(DocumentVoidService::class)->void($sale, 'คีย์ลูกค้าผิด', auth()->id());

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame(5.0, $this->onHand());
        $item = CustomerOpenItem::where('document_id', $sale->id)->sole();
        $this->assertSame('cancelled', $item->status);
        $this->assertSame(0.0, (float) $item->balance_amount);
        $this->assertGlNetsToZero('document_id', $sale->id);
        $this->assertTrue(DB::table('audit_logs')->where('table_name', 'documents')->where('record_id', $sale->id)->where('action', 'void')->exists());
    }

    public function test_a_paid_credit_sale_cannot_be_voided_until_the_receipt_is(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(1);
        $receipt = $this->receipt($sale, 100);
        $service = app(DocumentVoidService::class);

        $this->assertContains('มีการรับชำระใบนี้แล้ว ยกเลิกใบเสร็จก่อน', $service->blockers($sale));

        $service->void($receipt, 'รับเงินซ้ำ', auth()->id());
        $item = CustomerOpenItem::where('document_id', $sale->id)->sole();
        $this->assertSame(100.0, (float) $item->balance_amount);
        $this->assertSame(CustomerOpenItem::STATUS_OPEN, $item->status);
        $this->assertGlNetsToZero('payment_document_id', DB::table('payment_documents')->where('document_id', $receipt->id)->value('id'));

        $service->void($sale->fresh(), 'ลูกค้ายกเลิก', auth()->id());
        $this->assertSame('cancelled', $sale->fresh()->status);
    }

    public function test_voiding_a_cash_sale_also_reverses_the_cash_book(): void
    {
        $this->purchase(5);
        $sale = app(CashSaleService::class)->create([
            'branch_id' => $this->branch->id,
            'items' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);

        app(DocumentVoidService::class)->void($sale, 'ทอนผิดบิล', auth()->id());

        $this->assertSame(5.0, $this->onHand());
        $this->assertSame(0.0, round((float) CashBook::sum('cash_in') - (float) CashBook::sum('cash_out'), 2));
        $this->assertGlNetsToZero('document_id', $sale->id);
    }

    public function test_voiding_a_purchase_removes_the_received_stock_and_the_payable(): void
    {
        $purchase = $this->purchase(5);

        app(DocumentVoidService::class)->void($purchase, 'ผู้ขายส่งผิด', auth()->id());

        $this->assertSame(0.0, $this->onHand());
        $this->assertSame('cancelled', SupplierOpenItem::where('source_document_id', $purchase->id)->value('status'));
        $this->assertGlNetsToZero('document_id', $purchase->id);
    }

    public function test_a_purchase_whose_goods_were_sold_cannot_be_voided(): void
    {
        $purchase = $this->purchase(5);
        $this->creditSale(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ถูกขายหรือโอนไปแล้ว');
        app(DocumentVoidService::class)->void($purchase, 'ลอง', auth()->id());
    }

    public function test_voiding_an_approved_return_takes_the_goods_back_out_and_restores_the_debt(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(2);
        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs($this->userWith('void_maker', ['sales.manage']));
        $this->post(route('sale-returns.store'), [
            'branch_id' => $this->branch->id, 'source_doc_number' => $sale->doc_number,
            'items' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100, 'return_disposition' => 'available']],
        ])->assertRedirect();
        $return = Document::where('source_document_id', $sale->id)->sole();
        $approver = $this->userWith('void_approver', ['finance.note.approve']);
        app(SaleReturnService::class)->approve($return, $approver->id);
        $this->assertSame(4.0, $this->onHand());
        $this->assertSame(100.0, (float) CustomerOpenItem::where('document_id', $sale->id)->value('balance_amount'));

        app(DocumentVoidService::class)->void($return->fresh(), 'รับคืนผิดใบ', $approver->id);

        $this->assertSame(3.0, $this->onHand());
        $this->assertSame(200.0, (float) CustomerOpenItem::where('document_id', $sale->id)->value('balance_amount'));
        $this->assertGlNetsToZero('document_id', $return->id);
        // ใบรับคืนถูกยกเลิกแล้ว ใบขายต้นทางยกเลิกต่อได้
        $this->assertSame([], app(DocumentVoidService::class)->blockers($sale->fresh()));
    }

    public function test_a_document_in_a_closed_period_cannot_be_voided(): void
    {
        $purchase = $this->purchase(5);
        AccountingPeriod::create([
            'name' => 'งวดปิด', 'starts_on' => now()->startOfMonth(), 'ends_on' => now()->endOfMonth(),
            'status' => 'closed', 'closed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(DocumentVoidService::class)->void($purchase, 'ลอง', auth()->id());
    }

    public function test_a_reason_is_required_and_the_route_needs_the_void_permission(): void
    {
        $purchase = $this->purchase(5);

        try {
            app(DocumentVoidService::class)->void($purchase, '  ', auth()->id());
            $this->fail('ต้องบังคับเหตุผล');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('เหตุผล', $e->getMessage());
        }

        $this->withoutMiddleware(ErpAuthorize::class);
        $this->post(route('documents.void', $purchase), ['reason' => 'ลอง'])->assertForbidden();

        $role = Role::firstOrCreate(['code' => 'VOID_TEST'], ['name' => 'ทดสอบยกเลิก']);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'documents.void'], ['name' => 'ยกเลิกเอกสาร'])->id]);
        auth()->user()->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs(auth()->user()->fresh());

        $this->post(route('documents.void', $purchase), ['reason' => 'ผู้ขายส่งผิด'])->assertRedirect();
        $this->assertSame('cancelled', $purchase->fresh()->status);
    }

    private function userWith(string $username, array $permissions): User
    {
        $role = Role::create(['code' => 'VR_'.$username, 'name' => $username]);
        $role->permissions()->sync(collect($permissions)->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id)->all());
        $user = User::factory()->create(['username' => $username]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function purchase(float $qty): Document
    {
        return app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'is_credit' => true,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 50]],
        ]);
    }

    private function creditSale(float $qty): Document
    {
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 100]],
        ]);

        return app(CreditSaleService::class)->convertBookingToCreditSale($booking->saleBooking);
    }

    private function receipt(Document $sale, float $amount): Document
    {
        return app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'method' => 'cash',
            'allocations' => [['customer_open_item_id' => CustomerOpenItem::where('document_id', $sale->id)->value('id'), 'amount' => $amount]],
        ]);
    }

    private function onHand(): float
    {
        return (float) StockBalance::where('product_id', $this->product->id)->where('warehouse_location_id', $this->location->id)->value('on_hand_qty');
    }

    private function assertGlNetsToZero(string $column, int $id): void
    {
        $lines = DB::table('gl_journals')->where($column, $id);
        $this->assertGreaterThan(0, (clone $lines)->where('remark', 'like', 'VOID REVERSAL:%')->count(), 'ต้องมีรายการกลับ GL');
        $byAccount = (clone $lines)->selectRaw('account_id, sum(debit) - sum(credit) as net')->groupBy('account_id')->pluck('net');
        foreach ($byAccount as $net) {
            $this->assertEqualsWithDelta(0, (float) $net, 0.001);
        }
    }
}
