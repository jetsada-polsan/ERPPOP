<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SaleBooking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Purchasing\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ใบเสนอราคาเคยมีแค่ปุ่มพิมพ์/ยกเลิก: เพิ่มสถานะลูกค้าตอบรับ แปลงเป็นใบจองหรือใบขาย
 * โดยคัดลอกรายการและอ้างอิงเลขใบเสนอราคา และเลือก/สร้างลูกค้าตอนแปลงได้
 */
class QuotationConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_acceptance_is_recorded_without_converting(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $quotation = $this->quotation($branch, $product, customer: $customer);
        $this->signIn();

        $this->post(route('quotations.accept', $quotation))->assertRedirect();

        $this->assertSame('accepted', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->converted_booking_id);
        $this->get(route('quotations.show', $quotation))->assertOk()
            ->assertSee('ลูกค้าตอบรับแล้ว')
            ->assertSee('แปลงเป็นใบจอง / ใบขาย');
    }

    public function test_converts_to_a_booking_copying_lines_and_quoting_the_source_number(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $quotation = $this->quotation($branch, $product, customer: $customer);
        $this->signIn();

        $this->post(route('quotations.convert', $quotation), ['target' => 'booking'])->assertRedirect();

        $quotation->refresh();
        $booking = SaleBooking::findOrFail($quotation->converted_booking_id);
        $document = $booking->document()->with('stockDocument.items')->first();
        $this->assertSame('accepted', $quotation->status);
        $this->assertStringContainsString($quotation->doc_number, (string) $document->remark);
        $this->assertSame(2.0, (float) $document->stockDocument->items->sole()->qty);
        $this->assertSame(150.0, (float) $document->stockDocument->items->sole()->unit_price);
    }

    public function test_converts_straight_to_a_credit_sale_through_the_booking(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $quotation = $this->quotation($branch, $product, customer: $customer);
        $this->signIn();

        $response = $this->post(route('quotations.convert', $quotation), ['target' => 'sale']);

        $sale = Document::whereHas('documentType', fn ($q) => $q->where('code', 'CREDIT_SALE'))->sole();
        $response->assertRedirect(route('sales.show', $sale));
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame(300.0, (float) $sale->total_amount);
        $this->assertSame(SaleBooking::findOrFail($quotation->fresh()->converted_booking_id)->status, 'converted_to_sale');
    }

    public function test_typed_in_customer_must_be_resolved_before_converting(): void
    {
        [$branch, $product] = $this->masters();
        $quotation = $this->quotation($branch, $product, customerName: 'ร้านป้าแดง');
        $this->signIn();

        $this->post(route('quotations.convert', $quotation), ['target' => 'booking'])
            ->assertRedirect()
            ->assertSessionHasErrors(['convert' => 'ใบเสนอราคานี้ยังไม่ได้ผูกลูกค้าในทะเบียน — เลือกลูกค้าที่มีอยู่ หรือสร้างลูกค้าใหม่ก่อนแปลง']);

        $this->assertNull($quotation->fresh()->converted_booking_id);
    }

    public function test_typed_in_customer_can_be_created_while_converting(): void
    {
        [$branch, $product] = $this->masters();
        $quotation = $this->quotation($branch, $product, customerName: 'ร้านป้าแดง');
        $this->signIn();

        $this->post(route('quotations.convert', $quotation), [
            'target' => 'booking',
            'new_customer_code' => 'C-PADANG',
            'new_customer_name' => 'ร้านป้าแดง',
            'new_customer_tax_id' => '1234567890123',
        ])->assertRedirect();

        $customer = Customer::where('code', 'C-PADANG')->sole();
        $this->assertSame('ร้านป้าแดง', $customer->name_th);
        $this->assertSame($branch->id, $customer->branch_id);
        $this->assertSame($customer->id, $quotation->fresh()->customer_id);
        $this->assertNotNull($quotation->fresh()->converted_booking_id);
    }

    public function test_typed_in_customer_can_be_matched_to_an_existing_one(): void
    {
        [$branch, $product, $existing] = $this->masters();
        $existing->update(['name_th' => 'ร้านป้าแดง สาขาตลาด']);
        $quotation = $this->quotation($branch, $product, customerName: 'ร้านป้าแดง');
        $this->signIn();

        // หน้าแสดงลูกค้าที่ชื่อใกล้เคียงให้เลือก
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('ร้านป้าแดง สาขาตลาด');

        $this->post(route('quotations.convert', $quotation), ['target' => 'booking', 'customer_id' => $existing->id])
            ->assertRedirect();

        $this->assertSame($existing->id, $quotation->fresh()->customer_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_expired_quotations_cannot_be_converted(): void
    {
        [$branch, $product, $customer] = $this->masters();
        $quotation = $this->quotation($branch, $product, customer: $customer);
        $quotation->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->signIn();

        $this->post(route('quotations.convert', $quotation), ['target' => 'booking'])->assertRedirect();

        $this->assertSame('expired', $quotation->fresh()->status);
        $this->assertNull($quotation->fresh()->converted_booking_id);
    }

    private function quotation(Branch $branch, Product $product, ?Customer $customer = null, ?string $customerName = null): Quotation
    {
        $quotation = Quotation::create([
            'doc_number' => 'QT-TEST-'.uniqid(), 'customer_id' => $customer?->id, 'customer_name' => $customerName,
            'branch_id' => $branch->id, 'doc_date' => now()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(), 'total_amount' => 300, 'status' => 'open',
        ]);
        QuotationItem::create(['quotation_id' => $quotation->id, 'product_id' => $product->id, 'qty' => 2, 'unit_price' => 150]);

        return $quotation;
    }

    /** @return array{0: Branch, 1: Product, 2: Customer} */
    private function masters(): array
    {
        foreach ([['PURCHASE', 'in'], ['CREDIT_SALE', 'out'], ['BOOKING', 'none']] as [$code, $effect]) {
            DocumentType::firstOrCreate(['code' => $code], ['name_th' => $code, 'stock_effect' => $effect]);
        }
        $branch = Branch::create(['code' => 'QT', 'name_th' => 'สาขาใบเสนอราคา', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-QT', 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M-QT', 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'QT-1', 'name_th' => 'สินค้าเสนอราคา', 'base_unit_id' => $unit->id,
            'default_price' => 150, 'average_cost' => 0, 'is_vat' => false, 'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP-QT', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);
        app(PurchaseService::class)->create([
            'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'is_credit' => true,
            'items' => [['product_id' => $product->id, 'qty' => 10, 'unit_price' => 80]],
        ]);
        $customer = Customer::create(['code' => 'C-QT', 'name_th' => 'ลูกค้าเครดิต', 'branch_id' => $branch->id, 'is_active' => true]);

        return [$branch->fresh(), $product, $customer];
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['username' => 'quotation_tester']));
        $this->withoutMiddleware(ErpAuthorize::class);
    }
}
