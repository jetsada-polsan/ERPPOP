<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
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
use App\Services\Accounting\DocumentVoidService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\BookingService;
use App\Services\Sales\CreditSaleService;
use App\Services\Sales\CustomerPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidedDocumentPrintTest extends TestCase
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
        $this->branch = Branch::create(['code' => 'VP', 'name_th' => 'สาขาพิมพ์', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH-VP', 'name' => 'คลัง']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M-VP', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $this->location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product = Product::create([
            'sku_code' => 'VP-1', 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id, 'default_price' => 100,
            'average_cost' => 0, 'is_vat' => false, 'is_active' => true, 'negative_stock_policy' => 'block',
        ]);
        $this->supplier = Supplier::create(['code' => 'SUP-VP', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        $this->customer = Customer::create(['code' => 'C-VP', 'name_th' => 'ลูกค้าทดสอบ', 'branch_id' => $this->branch->id, 'is_active' => true]);

        $this->withoutMiddleware(ErpAuthorize::class);
        $this->actingAs(User::factory()->create(['username' => 'print_admin']));
    }

    public function test_normal_documents_can_be_printed(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(2);
        $receipt = $this->receipt($sale, 200);

        // 1. ใบเสร็จปกติ พิมพ์ได้ 200
        $this->get(route('payments.print', $receipt))->assertOk()->assertSee($receipt->doc_number);

        // 2. ใบกำกับภาษีปกติ พิมพ์ได้ 200
        $this->get(route('documents.tax-invoice', $sale))->assertOk()->assertSee($sale->doc_number);

        // 3. ใบส่งของปกติ พิมพ์ได้ 200
        $this->get(route('documents.delivery-note', $sale))->assertOk()->assertSee($sale->doc_number);
    }

    public function test_voided_receipt_cannot_be_printed(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(2);
        $receipt = $this->receipt($sale, 200);

        app(DocumentVoidService::class)->void($receipt, 'รับชำระผิดบิล', auth()->id());
        $this->assertSame('cancelled', $receipt->fresh()->status);

        $res = $this->get(route('payments.print', $receipt));
        $this->assertTrue(in_array($res->status(), [403, 410], true), 'Expected 403 or 410, got '.$res->status());
        $this->assertStringNotContainsString('RECEIPT', $res->getContent());
        $this->assertStringContainsString('ถูกยกเลิกแล้ว พิมพ์ไม่ได้', $res->getContent());
    }

    public function test_voided_credit_sale_cannot_print_tax_invoice_or_delivery_note(): void
    {
        $this->purchase(5);
        $sale = $this->creditSale(2);

        app(DocumentVoidService::class)->void($sale, 'คีย์ผิด', auth()->id());
        $this->assertSame('cancelled', $sale->fresh()->status);

        // ใบกำกับภาษี ต้องได้ 403 หรือ 410
        $resTax = $this->get(route('documents.tax-invoice', $sale));
        $this->assertTrue(in_array($resTax->status(), [403, 410], true), 'Expected 403 or 410, got '.$resTax->status());
        $this->assertStringContainsString('ถูกยกเลิกแล้ว พิมพ์ไม่ได้', $resTax->getContent());

        // ใบส่งของ ต้องได้ 403 หรือ 410
        $resDel = $this->get(route('documents.delivery-note', $sale));
        $this->assertTrue(in_array($resDel->status(), [403, 410], true), 'Expected 403 or 410, got '.$resDel->status());
        $this->assertStringContainsString('ถูกยกเลิกแล้ว พิมพ์ไม่ได้', $resDel->getContent());
    }

    public function test_cancelled_booking_delivery_note_cannot_be_printed(): void
    {
        $this->purchase(5);
        $booking = app(BookingService::class)->create([
            'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);

        app(BookingService::class)->cancel($booking->saleBooking, 'ลูกค้ายกเลิก', auth()->id());
        $this->assertSame('cancelled', $booking->fresh()->status);

        $res = $this->get(route('documents.delivery-note', $booking));
        $this->assertTrue(in_array($res->status(), [403, 410], true), 'Expected 403 or 410, got '.$res->status());
        $this->assertStringContainsString('ถูกยกเลิกแล้ว พิมพ์ไม่ได้', $res->getContent());
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
}
