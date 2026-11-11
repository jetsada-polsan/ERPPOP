<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidTestDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Product $product;
    private Customer $customer;
    private User $admin;
    private Document $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['PURCHASE', 'ใบซื้อ', 'in'], ['CASH_SALE', 'ใบขายสด', 'out'], ['CREDIT_SALE', 'ใบขายเชื่อ', 'out'],
            ['BOOKING', 'ใบจอง', 'none'], ['RECEIPT', 'ใบเสร็จรับเงิน', 'none'], ['STOCK_ADJUSTMENT', 'ใบปรับสต๊อก', 'none']] as [$code, $name, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $name, 'stock_effect' => $effect]);
        }
        $this->branch = Branch::create(['code' => 'B-VT', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH-VT', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-VT', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product = Product::create(['sku_code' => 'SKU-VT', 'name_th' => 'สินค้าทดสอบล้างข้อมูล', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'average_cost' => 50, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block']);
        $supplier = Supplier::create(['code' => 'SUP-VT', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        $this->purchase = app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id, 'is_credit' => true, 'prices_include_vat' => false,
            'items' => [['product_id' => $this->product->id, 'qty' => 50, 'unit_price' => 50]],
        ]);
        $this->customer = Customer::create(['code' => 'CUS-VT', 'name_th' => 'ลูกค้าทดสอบ', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'must_change_password' => false]);
    }

    /** @return array{SaleBooking, Document} */
    private function bookingAndSale(float $qty = 5): array
    {
        $this->actingAs($this->admin);
        $doc = app(BookingService::class)->create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 100]],
        ]);
        $booking = SaleBooking::where('document_id', $doc->id)->sole();

        return [$booking->fresh(), app(CreditSaleService::class)->convertBookingToCreditSale($booking)->fresh()];
    }

    private function onHand(): float
    {
        return (float) StockBalance::where('product_id', $this->product->id)->sum('on_hand_qty');
    }

    private function execute(array $extra = []): int
    {
        $today = now()->toDateString();

        return $this->artisan('erp:void-test-documents', ['--from' => $today, '--to' => $today] + $extra)->run();
    }

    public function test_preview_changes_nothing(): void
    {
        [$booking, $sale] = $this->bookingAndSale();
        $this->assertSame(45.0, $this->onHand());

        $this->assertSame(0, $this->execute());

        $this->assertSame('active', $sale->fresh()->status);
        $this->assertSame('active', $this->purchase->fresh()->status);
        $this->assertSame(45.0, $this->onHand());
        $this->assertSame(0, \DB::table('audit_logs')->where('action', 'void')->count());
    }

    public function test_apply_cancels_receipts_sales_bookings_and_purchases_and_restores_stock(): void
    {
        [$bookingA, $saleA] = $this->bookingAndSale(5);
        [$bookingB, $saleB] = $this->bookingAndSale(3);
        $openItem = CustomerOpenItem::where('document_id', $saleB->id)->sole();
        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'method' => 'cash',
            'allocations' => [['customer_open_item_id' => $openItem->id, 'amount' => $saleB->total_amount]],
        ]);
        $adjustment = Document::create(['document_type_id' => DocumentType::where('code', 'STOCK_ADJUSTMENT')->value('id'),
            'branch_id' => $this->branch->id, 'doc_number' => 'AJ-VT-1', 'doc_date' => now()->toDateString(), 'status' => 'active', 'total_amount' => 0]);

        $this->assertSame(0, $this->execute(['--apply' => true, '--user' => $this->admin->id]));

        foreach ([$saleA, $saleB, $this->purchase] as $doc) {
            $this->assertSame('cancelled', $doc->fresh()->status, $doc->doc_number);
        }
        $receiptDoc = Document::whereHas('documentType', fn ($q) => $q->where('code', 'RECEIPT'))->sole();
        $this->assertSame('cancelled', $receiptDoc->status, 'ใบเสร็จต้องถูกยกเลิกก่อนใบขายที่มันชำระ');
        $this->assertSame(SaleBooking::STATUS_CANCELLED, $bookingA->fresh()->status);
        $this->assertSame(SaleBooking::STATUS_CANCELLED, $bookingB->fresh()->status);
        $this->assertSame('active', $adjustment->fresh()->status, 'ใบปรับสต๊อกต้องไม่ถูกแตะ');
        $this->assertSame(0.0, $this->onHand(), 'ยกเลิกใบซื้อแล้วสต๊อกที่รับเข้าต้องกลับเป็น 0');
        $this->assertSame(0.0, (float) StockBalance::where('product_id', $this->product->id)->sum('reserved_qty'));
        $this->assertGreaterThan(0, \DB::table('audit_logs')->where('action', 'void')->where('user_id', $this->admin->id)->count());
    }

    public function test_documents_outside_the_date_range_are_left_alone(): void
    {
        [$booking, $sale] = $this->bookingAndSale();
        $old = now()->subDays(10);
        Document::whereKey([$sale->id, $booking->document_id, $this->purchase->id])->update(['created_at' => $old]);

        $this->assertSame(0, $this->execute(['--apply' => true, '--user' => $this->admin->id]));

        $this->assertSame('active', $sale->fresh()->status);
        $this->assertSame('active', $this->purchase->fresh()->status);
    }

    public function test_apply_without_user_or_range_is_refused(): void
    {
        $this->artisan('erp:void-test-documents', ['--from' => '2026-11-01', '--to' => '2026-11-11', '--apply' => true])->assertExitCode(2);
        $this->artisan('erp:void-test-documents', ['--apply' => true, '--user' => 1])->assertExitCode(2);
    }
}
