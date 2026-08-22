<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleBooking;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Sales\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ใบจองแบบส่งของต้องรู้ "กำหนดส่ง" ตั้งแต่ต้น ไม่งั้นรายงานเกินกำหนดส่งไม่มีอะไรให้เทียบ
 * และห้ามใช้ due_date ของลูกหนี้แทน เพราะนั่นคือกำหนดชำระเงิน
 */
class BookingDeliveryDueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_delivery_booking_without_a_due_date_is_refused(): void
    {
        [$branch, $customer, $product] = $this->masters('DD1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ใบจองแบบส่งของต้องระบุวันและเวลาที่ต้องส่ง');

        app(BookingService::class)->create($this->payload($branch, $customer, $product) + [
            'fulfillment_type' => SaleBooking::FULFILLMENT_DELIVERY,
        ]);
    }

    public function test_a_delivery_booking_records_the_due_date_and_starts_pending(): void
    {
        [$branch, $customer, $product] = $this->masters('DD2');

        $document = app(BookingService::class)->create($this->payload($branch, $customer, $product) + [
            'fulfillment_type' => SaleBooking::FULFILLMENT_DELIVERY,
            'delivery_due_at' => '2026-08-30 15:00:00',
        ]);

        $booking = SaleBooking::where('document_id', $document->id)->sole();
        $this->assertTrue($booking->isDelivery());
        $this->assertSame('2026-08-30 15:00:00', $booking->delivery_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(SaleBooking::DELIVERY_PENDING, $booking->delivery_status);
        $this->assertNull($booking->delivered_at);
    }

    public function test_a_pickup_booking_needs_no_due_date(): void
    {
        [$branch, $customer, $product] = $this->masters('DD3');

        $document = app(BookingService::class)->create($this->payload($branch, $customer, $product));

        $booking = SaleBooking::where('document_id', $document->id)->sole();
        $this->assertSame(SaleBooking::FULFILLMENT_PICKUP, $booking->fulfillment_type);
        $this->assertNull($booking->delivery_due_at);
    }

    public function test_the_form_rejects_a_delivery_booking_with_no_due_date(): void
    {
        [$branch, $customer, $product] = $this->masters('DD4');
        $user = User::factory()->create(['username' => 'booking_due_uat', 'branch_id' => $branch->id]);

        $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'fulfillment_type' => 'delivery',
                'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
            ])
            ->assertSessionHasErrors('delivery_due_at');

        $this->assertSame(0, SaleBooking::count());
    }

    /** @return array{0: array<string, mixed>} */
    private function payload(Branch $branch, Customer $customer, Product $product): array
    {
        return [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]],
        ];
    }

    private function masters(string $suffix): array
    {
        DocumentType::firstOrCreate(['code' => DocumentType::BOOKING], ['name_th' => 'ใบจอง', 'stock_effect' => 'none']);
        $branch = Branch::create(['code' => 'BR'.$suffix, 'name_th' => 'สาขาทดสอบ '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง '.$suffix]);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN'.$suffix, 'name' => 'พื้นที่หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $customer = Customer::create(['code' => 'CUS'.$suffix, 'name_th' => 'ลูกค้าทดสอบ', 'branch_id' => $branch->id, 'is_active' => true]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'is_active' => true,
        ]);

        return [$branch->fresh(), $customer, $product];
    }
}
