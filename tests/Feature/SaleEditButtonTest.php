<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
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

class SaleEditButtonTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private WarehouseLocation $location;
    private Product $product;
    private Customer $customer;
    private User $manager;
    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ใบซื้อ', 'stock_effect' => 'in']);
        DocumentType::firstOrCreate(['code' => 'CASH_SALE'], ['name_th' => 'ใบขายสด', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'CREDIT_SALE'], ['name_th' => 'ใบขายเชื่อ', 'stock_effect' => 'out']);
        DocumentType::firstOrCreate(['code' => 'BOOKING'], ['name_th' => 'ใบจอง', 'stock_effect' => 'none']);
        DocumentType::firstOrCreate(['code' => 'RECEIPT'], ['name_th' => 'ใบเสร็จรับเงิน', 'stock_effect' => 'none']);

        $this->branch = Branch::create(['code' => 'B-EDIT', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $this->branch->id, 'code' => 'WH-EDIT', 'name' => 'คลัง']);
        $this->location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'LOC-EDIT', 'name' => 'หลัก']);
        $this->branch->update(['default_warehouse_location_id' => $this->location->id]);

        $unit = ProductUnit::firstOrCreate(['code' => 'EA'], ['name' => 'ชิ้น', 'qty_per_base_unit' => 1]);
        $this->product = Product::create([
            'sku_code' => 'SKU-EDIT-01',
            'name_th' => 'สินค้าทดสอบปุ่มแก้',
            'base_unit_id' => $unit->id,
            'default_price' => 100,
            'average_cost' => 50,
            'is_vat' => false,
            'is_active' => true,
            'negative_stock_policy' => 'block',
        ]);
        $supplier = Supplier::create(['code' => 'SUP-EDIT', 'name_th' => 'ผู้จำหน่าย', 'is_active' => true]);

        app(PurchaseService::class)->create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'is_credit' => true,
            'prices_include_vat' => false,
            'items' => [['product_id' => $this->product->id, 'qty' => 50, 'unit_price' => 50]],
        ]);

        $this->customer = Customer::create([
            'code' => 'CUS-EDIT',
            'name_th' => 'ลูกค้าทดสอบปุ่มแก้',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        // Clerk (sales.manage only, no documents.void)
        $this->clerk = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $clerkRole = Role::firstOrCreate(['code' => 'SALES_CLERK'], ['name' => 'พนักงานขาย']);
        $clerkRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการการขาย'])->id,
        ]);
        $this->clerk->roles()->attach($clerkRole);

        // Manager (sales.manage + documents.void)
        $this->manager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $mgrRole = Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผู้จัดการสาขา']);
        $mgrRole->permissions()->syncWithoutDetaching([
            Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'จัดการการขาย'])->id,
            Permission::firstOrCreate(['code' => 'documents.void'], ['name' => 'ยกเลิกเอกสาร'])->id,
        ]);
        $this->manager->roles()->attach($mgrRole);
    }

    private function createBookingAndSale(float $qty = 5, float $unitPrice = 100): array
    {
        $this->actingAs($this->clerk);
        $doc = app(BookingService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => $unitPrice]],
        ]);
        $booking = SaleBooking::where('document_id', $doc->id)->sole();
        $sale = app(CreditSaleService::class)->convertBookingToCreditSale($booking);

        return [$booking->fresh(), $sale->fresh()];
    }

    /**
     * 1. ผู้จัดการเห็นปุ่มและ modal ส่งไป bookings.revert-confirm
     */
    public function test_manager_sees_edit_sale_button_and_revert_modal(): void
    {
        [$booking, $sale] = $this->createBookingAndSale();

        $this->actingAs($this->manager);
        $response = $this->get(route('sales.show', $sale));

        $response->assertOk();
        // ต้องเห็นปุ่มแก้ไขใบขาย
        $response->assertSee('แก้ไขใบขาย');
        // ต้องมี modal ส่งไปที่ bookings.revert-confirm
        $response->assertSee(route('bookings.revert-confirm', $booking), false);
        // ต้องมี hidden field return=edit
        $response->assertSee('name="return" value="edit"', false);
        // ต้องมีคำอธิบายขั้นตอนใน modal
        $response->assertSee('ใบขายเลขนี้จะถูกยกเลิก (เก็บประวัติไว้ในระบบ)');
        $response->assertSee('สต็อกจะถูกคืนกลับไปกันไว้ในใบจอง');
        $response->assertSee('ยอดลูกหนี้ของใบนี้จะถูกปิด');
        $response->assertSee('ระบบจะพาไปหน้าแก้ไขใบจอง เมื่อกด Confirm ใหม่จะได้ใบขายเลขใหม่');
    }

    /**
     * 2. คนขายไม่มีสิทธิ์: เห็นปุ่มแบบอธิบาย และ POST ตรงไป bookings.revert-confirm ได้ 403
     */
    public function test_salesperson_sees_explanation_and_cannot_revert_directly(): void
    {
        [$booking, $sale] = $this->createBookingAndSale();

        $this->actingAs($this->clerk);
        $response = $this->get(route('sales.show', $sale));

        $response->assertOk();
        // เห็นปุ่มแก้ไขใบขาย
        $response->assertSee('แก้ไขใบขาย');
        // เห็น modal คำอธิบายสำหรับคนขาย
        $response->assertSee('การแก้ใบขายที่ออกแล้วต้องให้ผู้จัดการยกเลิกใบเดิมก่อน');
        $response->assertSee('แจ้งผู้จัดการพร้อมเลขใบ');
        // ต้องไม่มี form submit ไปที่ bookings.revert-confirm สำหรับคนขาย
        $response->assertDontSee(route('bookings.revert-confirm', $booking), false);

        // POST ตรงไปที่ bookings.revert-confirm ต้องถูกปฏิเสธ 403 Forbidden
        $postResponse = $this->post(route('bookings.revert-confirm', $booking), [
            'reason' => 'พยายามถอยเอง',
            'return' => 'edit',
        ]);
        $postResponse->assertForbidden();
    }

    /**
     * 3. return=edit สำเร็จ -> redirect ไป bookings.edit; ไม่ส่ง -> redirect เดิม (bookings.show)
     */
    public function test_revert_with_return_edit_redirects_to_bookings_edit_and_without_redirects_to_show(): void
    {
        // กรณีมี return=edit
        [$booking1, $sale1] = $this->createBookingAndSale(3, 100);

        $this->actingAs($this->manager);
        $response1 = $this->post(route('bookings.revert-confirm', $booking1), [
            'reason' => 'ลูกค้าขอเปลี่ยนรายการสินค้า',
            'return' => 'edit',
        ]);

        $response1->assertRedirect(route('bookings.edit', $booking1));
        $response1->assertSessionHas('success', 'ยกเลิกใบขายเดิมแล้ว แก้ไขรายการแล้วกด Confirm ใหม่');
        $this->assertSame('cancelled', $sale1->fresh()->status);
        $this->assertSame(SaleBooking::STATUS_PENDING, $booking1->fresh()->status);

        // กรณีไม่ส่ง return=edit (พฤติกรรมเดิม)
        [$booking2, $sale2] = $this->createBookingAndSale(2, 100);

        $this->actingAs($this->manager);
        $response2 = $this->post(route('bookings.revert-confirm', $booking2), [
            'reason' => 'ถอยแบบเดิม',
        ]);

        $response2->assertRedirect(route('bookings.show', $booking2));
        $response2->assertSessionHas('success', 'ถอย Confirm แล้ว ยกเลิกใบขาย คืนสต๊อก ปิดลูกหนี้ ใบจองกลับมากันของไว้');
        $this->assertSame('cancelled', $sale2->fresh()->status);
    }

    /**
     * 4. ใบที่รับชำระแล้ว -> ข้อความ error จาก service แสดงในหน้า และใบขายยังเป็น active
     */
    public function test_paid_sale_cannot_be_reverted_and_shows_error(): void
    {
        [$booking, $sale] = $this->createBookingAndSale(5, 100);

        // บันทึกรับชำระเงินเต็มจำนวน
        $openItem = CustomerOpenItem::where('document_id', $sale->id)->sole();
        app(CustomerPaymentService::class)->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'method' => 'cash',
            'allocations' => [['customer_open_item_id' => $openItem->id, 'amount' => $sale->total_amount]],
        ]);

        $this->actingAs($this->manager);
        $response = $this->from(route('sales.show', $sale))
            ->post(route('bookings.revert-confirm', $booking), [
                'reason' => 'พยายามแก้บิลที่จ่ายเงินแล้ว',
                'return' => 'edit',
            ]);

        // ต้อง redirect กลับมาที่หน้าเดิม (sales.show) พร้อมข้อความ error
        $response->assertRedirect(route('sales.show', $sale));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('รับชำระ', session('error'));

        // ใบขายต้องยังคงเป็น active ไม่ถูกยกเลิก
        $this->assertSame('active', $sale->fresh()->status);
    }

    /**
     * 5. ใบที่ยกเลิกแล้ว (status != active) -> ไม่มีปุ่มแก้ไข แต่ถ้ามีใบขายใหม่ออกแทน ให้ลิงก์ "ใบขายใหม่: {เลขใบ}"
     */
    public function test_voided_sale_does_not_show_edit_button_and_shows_new_sale_link(): void
    {
        [$booking, $sale] = $this->createBookingAndSale(5, 100);

        // ยกเลิกใบเดิม
        $this->actingAs($this->manager);
        $this->post(route('bookings.revert-confirm', $booking), [
            'reason' => 'แก้ไขรายการเพื่อออกบิลใหม่',
            'return' => 'edit',
        ]);

        $this->assertSame('cancelled', $sale->fresh()->status);

        // เปิดดูใบขายเดิมที่ถูกยกเลิกไปแล้ว -> ต้องไม่มีปุ่ม "แก้ไขใบขาย"
        $response1 = $this->get(route('sales.show', $sale));
        $response1->assertOk();
        $response1->assertDontSee('id="btn-edit-sale"', false);

        // ทำการ Confirm ใบจองใหม่อีกครั้ง -> จะได้ใบขายใหม่
        $newSale = app(CreditSaleService::class)->convertBookingToCreditSale($booking->fresh());
        $this->assertNotSame($sale->id, $newSale->id);

        // เปิดดูใบขายเดิมอีกครั้ง -> ต้องเห็นลิงก์ไปยัง "ใบขายใหม่: {เลขใบ}"
        $response2 = $this->get(route('sales.show', $sale));
        $response2->assertOk();
        $response2->assertSee("ใบขายใหม่: {$newSale->doc_number}");
        $response2->assertSee(route('sales.show', $newSale), false);
    }

    /**
     * 6. ในหน้าใบจอง (bookings/show) ปุ่ม "ถอย Confirm" เปลี่ยนข้อความปุ่มเป็น "แก้ไขใบขาย (ถอย Confirm)"
     */
    public function test_bookings_show_displays_updated_button_text(): void
    {
        [$booking, $sale] = $this->createBookingAndSale();

        $this->actingAs($this->manager);
        $response = $this->get(route('bookings.show', $booking));

        $response->assertOk();
        $response->assertSee('แก้ไขใบขาย (ถอย Confirm)');
    }
}
