<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashBook;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleBooking;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Accounting\CashBookPostingService;
use App\Services\Accounting\CashTransferService;
use App\Services\Sales\BookingDeliveryService;
use App\Services\Sales\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ปิดช่องว่าง P0 สองจุดสุดท้าย:
 *  - ใบจองเคยตั้ง delivery_status เป็น pending แล้วไม่มีอะไรเปลี่ยนอีกเลย
 *    รายงานครบกำหนดส่งจึงเห็นทุกใบค้างตลอดกาลแม้ส่งของไปแล้ว
 *  - เงินสดที่เอาไปฝากธนาคารหายจากสมุดเงินสดโดยไม่มีร่องรอย
 */
class BookingDeliveryAndCashTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_full_delivery_clears_it_from_the_outstanding_list(): void
    {
        [$booking, $user] = $this->deliveryBooking('BD1');
        $this->actingAs($user);
        $service = app(BookingDeliveryService::class);

        $this->assertSame(1, $service->outstanding()->count());

        $service->record($booking, SaleBooking::DELIVERY_DELIVERED);

        $this->assertSame(SaleBooking::DELIVERY_DELIVERED, $booking->fresh()->delivery_status);
        $this->assertNotNull($booking->fresh()->delivered_at);
        $this->assertSame(0, $service->outstanding()->count(), 'ส่งครบแล้วต้องหายจากรายการค้างส่ง');
    }

    public function test_a_partial_delivery_stays_outstanding_and_records_no_delivery_time(): void
    {
        [$booking, $user] = $this->deliveryBooking('BD2');
        $this->actingAs($user);
        $service = app(BookingDeliveryService::class);

        $service->record($booking, SaleBooking::DELIVERY_PARTIAL);

        $this->assertSame(SaleBooking::DELIVERY_PARTIAL, $booking->fresh()->delivery_status);
        $this->assertNull($booking->fresh()->delivered_at, 'ส่งบางส่วนยังไม่ถือว่าจบ');
        $this->assertSame(1, $service->outstanding()->count());
    }

    public function test_a_delivery_cannot_be_recorded_twice(): void
    {
        [$booking, $user] = $this->deliveryBooking('BD3');
        $this->actingAs($user);
        $service = app(BookingDeliveryService::class);
        $service->record($booking, SaleBooking::DELIVERY_DELIVERED);
        $first = $booking->fresh()->delivered_at;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('บันทึกส่งครบแล้ว');
        $service->record($booking->fresh(), SaleBooking::DELIVERY_DELIVERED);

        $this->assertEquals($first, $booking->fresh()->delivered_at);
    }

    public function test_a_pickup_booking_has_no_delivery_to_record(): void
    {
        [$booking, $user] = $this->deliveryBooking('BD4', delivery: false);
        $this->actingAs($user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('รับเองที่สาขา');
        app(BookingDeliveryService::class)->record($booking, SaleBooking::DELIVERY_DELIVERED);
    }

    public function test_recording_a_delivery_is_written_to_the_audit_log(): void
    {
        [$booking, $user] = $this->deliveryBooking('BD5');
        $this->actingAs($user);

        app(BookingDeliveryService::class)->record($booking, SaleBooking::DELIVERY_DELIVERED, 'ส่งโดยรถบริษัท');

        $audit = AuditLog::where('table_name', 'sale_bookings')->sole();
        $this->assertSame('booking_delivery_delivered', $audit->action);
        $this->assertSame('pending', $audit->old_values['delivery_status']);
        $this->assertSame('ส่งโดยรถบริษัท', $audit->new_values['note']);
    }

    public function test_depositing_cash_moves_it_out_of_the_drawer_and_into_the_bank(): void
    {
        [$branch, $bank, $user] = $this->bankMasters('CT1');
        $this->actingAs($user);

        $document = app(CashTransferService::class)->create(CashTransferService::DEPOSIT, [
            'branch_id' => $branch->id,
            'bank_account_id' => $bank->id,
            'amount' => 5000,
            'transfer_date' => '2026-08-23',
        ]);

        $entry = CashBook::where('source_type', CashBookPostingService::SOURCE_BANK_TRANSFER)->sole();
        $this->assertSame(5000.0, (float) $entry->cash_out);
        $this->assertSame(0.0, (float) $entry->cash_in);
        $this->assertSame($document->id, (int) $entry->source_id);

        $this->assertGl($document, ChartOfAccount::ROLE_BANK, debit: 5000.0);
        $this->assertGl($document, ChartOfAccount::ROLE_CASH, credit: 5000.0);
    }

    public function test_withdrawing_cash_moves_it_the_other_way(): void
    {
        [$branch, $bank, $user] = $this->bankMasters('CT2');
        $this->actingAs($user);

        $document = app(CashTransferService::class)->create(CashTransferService::WITHDRAWAL, [
            'branch_id' => $branch->id,
            'bank_account_id' => $bank->id,
            'amount' => 2000,
            'transfer_date' => '2026-08-23',
        ]);

        $entry = CashBook::where('source_type', CashBookPostingService::SOURCE_BANK_TRANSFER)->sole();
        $this->assertSame(2000.0, (float) $entry->cash_in);
        $this->assertGl($document, ChartOfAccount::ROLE_CASH, debit: 2000.0);
        $this->assertGl($document, ChartOfAccount::ROLE_BANK, credit: 2000.0);
    }

    public function test_a_transfer_of_zero_or_less_is_refused(): void
    {
        [$branch, $bank, $user] = $this->bankMasters('CT3');
        $this->actingAs($user);

        $this->expectException(RuntimeException::class);
        app(CashTransferService::class)->create(CashTransferService::DEPOSIT, [
            'branch_id' => $branch->id, 'bank_account_id' => $bank->id,
            'amount' => 0, 'transfer_date' => '2026-08-23',
        ]);
    }

    private function assertGl($document, string $role, float $debit = 0, float $credit = 0): void
    {
        $accountId = ChartOfAccount::where('default_role', $role)->value('id');
        $lines = GlJournal::where('document_id', $document->id)->where('account_id', $accountId)->get();
        $this->assertNotEmpty($lines, "ไม่พบรายการ GL ของ {$role}");
        $this->assertSame($debit, round((float) $lines->sum('debit'), 2));
        $this->assertSame($credit, round((float) $lines->sum('credit'), 2));
    }

    private function bankMasters(string $suffix): array
    {
        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $bank = BankAccount::create([
            'branch_id' => $branch->id, 'bank_name' => 'ธนาคารทดสอบ',
            'account_no' => '123-4-5678'.$suffix, 'account_name' => 'บริษัททดสอบ', 'is_active' => true,
        ]);
        $user = User::factory()->create(['username' => 'cash_'.strtolower($suffix), 'branch_id' => $branch->id]);

        return [$branch, $bank, $user];
    }

    private function deliveryBooking(string $suffix, bool $delivery = true): array
    {
        DocumentType::firstOrCreate(['code' => DocumentType::BOOKING], ['name_th' => 'ใบจอง']);
        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH'.$suffix, 'name' => 'คลัง']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M'.$suffix, 'name' => 'หลัก']);
        $branch->update(['default_warehouse_location_id' => $location->id]);
        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix, 'name_th' => 'สินค้าทดสอบ', 'base_unit_id' => $unit->id,
            'default_price' => 100, 'is_active' => true,
        ]);
        $customer = Customer::create(['code' => 'CUS'.$suffix, 'name_th' => 'ลูกค้า', 'branch_id' => $branch->id, 'is_active' => true]);
        $user = User::factory()->create(['username' => 'bk_'.strtolower($suffix), 'branch_id' => $branch->id]);

        $this->actingAs($user);
        $document = app(BookingService::class)->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'fulfillment_type' => $delivery ? SaleBooking::FULFILLMENT_DELIVERY : SaleBooking::FULFILLMENT_PICKUP,
            'delivery_due_at' => $delivery ? '2026-08-30 15:00:00' : null,
            'items' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100]],
        ]);

        return [SaleBooking::where('document_id', $document->id)->sole(), $user];
    }
}
