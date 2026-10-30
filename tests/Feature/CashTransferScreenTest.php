<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashBook;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\TransportJob;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Sales\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าจอฝาก/ถอนเงินสด และหน้าบันทึกการส่งของ — สอง flow ที่มี service แล้วแต่ยังไม่มีทางให้คนใช้
 */
class CashTransferScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_depositing_cash_from_the_screen_reaches_the_cash_book(): void
    {
        [$user, $branch, $bank] = $this->financeUser('CTS1');

        $this->actingAs($user)->post(route('cash-transfers.store'), [
            'type' => 'CASH_DEPOSIT',
            'branch_id' => $branch->id,
            'bank_account_id' => $bank->id,
            'amount' => 7500,
            'transfer_date' => '2026-08-23',
            'reference' => 'SLIP-001',
        ])->assertRedirect(route('cash-transfers.index'));

        $entry = CashBook::sole();
        $this->assertSame(7500.0, (float) $entry->cash_out);
        $this->assertSame(1, Document::count());
    }

    public function test_a_user_cannot_record_cash_for_another_branch(): void
    {
        [$user, $branch, $bank] = $this->financeUser('CTS2');
        $other = Branch::create(['code' => 'CTS2-OTHER', 'name_th' => 'สาขาอื่น', 'is_active' => true]);

        $this->actingAs($user)->post(route('cash-transfers.store'), [
            'type' => 'CASH_WITHDRAWAL',
            'branch_id' => $other->id,          // พยายามบันทึกให้สาขาอื่น
            'bank_account_id' => $bank->id,
            'amount' => 1000,
            'transfer_date' => '2026-08-23',
        ])->assertRedirect();

        // ต้องถูกบังคับกลับมาเป็นสาขาของตัวเอง
        $this->assertSame($branch->id, (int) Document::sole()->branch_id);
    }

    public function test_the_screen_refuses_a_zero_amount(): void
    {
        [$user, $branch, $bank] = $this->financeUser('CTS3');

        $this->actingAs($user)->post(route('cash-transfers.store'), [
            'type' => 'CASH_DEPOSIT', 'branch_id' => $branch->id, 'bank_account_id' => $bank->id,
            'amount' => 0, 'transfer_date' => '2026-08-23',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Document::count());
    }

    public function test_the_booking_page_guides_delivery_bookings_to_loading_and_transport(): void
    {
        [$user] = $this->financeUser('CTS4', extra: ['sales.manage']);
        $delivery = $this->booking('CTS4D', $user, delivery: true);
        $pickup = $this->booking('CTS4P', $user, delivery: false);

        $this->actingAs($user)->get(route('bookings.show', $delivery))
            ->assertOk()->assertSee('เปิดใบขึ้นของ')->assertSee('ไปบอร์ดขนส่ง')->assertSee('กำหนดส่ง')
            ->assertDontSee('name="delivery_status"', false);

        $this->actingAs($user)->get(route('bookings.show', $pickup))
            ->assertOk()->assertSee('รับเองที่สาขา')->assertDontSee('เปิดใบขึ้นของ');
    }

    public function test_completing_a_delivery_on_the_fleet_board_updates_the_booking(): void
    {
        [$user] = $this->financeUser('CTS5', extra: ['sales.manage', 'transport.manage']);
        $booking = $this->booking('CTS5D', $user, delivery: true);

        $this->actingAs($user)->get(route('fleet.load-sheet', $booking))->assertOk();
        $job = TransportJob::where('booking_id', $booking->id)->sole();
        $line = $job->loadItems()->sole();
        $this->post(route('fleet.load-sheet.save', $booking), [
            'items' => [$line->id => ['loaded_qty' => $line->planned_qty, 'availability' => 'available']],
        ])->assertRedirect(route('fleet.load-sheet', $booking));
        $this->post(route('fleet.board.update', $booking), ['status' => 'loaded'])->assertRedirect();
        $this->post(route('fleet.board.update', $booking), ['status' => 'in_transit'])->assertRedirect();
        $this->post(route('fleet.board.update', $booking), ['status' => 'delivered'])->assertRedirect();

        $this->assertSame('delivered', $booking->fresh()->delivery_status);
        $this->assertNotNull($booking->fresh()->delivered_at);
        $this->assertSame('delivered', $job->fresh()->status);
    }

    private function booking(string $suffix, User $user, bool $delivery): SaleBooking
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
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'on_hand_qty' => 100,
            'reserved_qty' => 0,
        ]);
        $customer = Customer::create(['code' => 'CUS'.$suffix, 'name_th' => 'ลูกค้า', 'branch_id' => $branch->id, 'is_active' => true]);

        $this->actingAs($user);
        $document = app(BookingService::class)->create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'fulfillment_type' => $delivery ? 'delivery' : 'pickup',
            'delivery_due_at' => $delivery ? '2026-08-30 15:00:00' : null,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
        ]);

        return SaleBooking::where('document_id', $document->id)->sole();
    }

    private function financeUser(string $suffix, array $extra = []): array
    {
        $branch = Branch::create(['code' => $suffix, 'name_th' => 'สาขา '.$suffix, 'is_active' => true]);
        $bank = BankAccount::create([
            'branch_id' => $branch->id, 'bank_name' => 'ธนาคารทดสอบ',
            'account_no' => 'ACC-'.$suffix, 'account_name' => 'บัญชีทดสอบ',
        ]);
        $user = User::factory()->create([
            'username' => 'ct_'.strtolower($suffix), 'branch_id' => $branch->id,
            'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'CT_'.strtoupper($suffix), 'name' => 'Cash transfer test']);
        foreach (array_merge(['finance.manage'], $extra) as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return [$user, $branch, $bank];
    }
}
