<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SaleBooking;
use App\Models\SalesArea;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSalesAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_uses_logged_in_user_without_claiming_the_customer(): void
    {
        [$branch, $customer, $product] = $this->masters('BP');
        $route = SalesArea::with('documentBook')
            ->where('code', 'B11')
            ->firstOrFail();
        $user = User::factory()->create([
            'username' => 'booking_bplus_route_uat',
            'branch_id' => $branch->id,
            'sales_area_id' => $route->id,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_area_id' => $route->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 3,
                    'unit_price' => 90,
                ]],
            ]);

        $response->assertRedirect();
        $this->assertNotNull($route->documentBook);
        $this->assertDatabaseHas('documents', [
            'branch_id' => $branch->id,
            'sales_area_id' => $route->id,
            'sales_user_id' => $user->id,
            'document_book_id' => $route->document_book_id,
            // ข้อ 16: ใบจองใช้รูปแบบ BK + สาขา + วันที่ + ลำดับ เหมือนเอกสารอื่น (เดิม B01)
            'doc_number' => 'BK'.$branch->code.now()->format('Ymd').'001',
            'total_amount' => 270,
        ]);
        // ผู้รับผิดชอบใบจองไม่ผูก user กับลูกค้าอีกต่อไป: ลูกค้ายังไม่มีเจ้าของ
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'sales_user_id' => null,
        ]);
    }

    public function test_booking_uses_the_customer_owner_and_route(): void
    {
        [$branch, $customer, $product] = $this->masters('A');
        $area = SalesArea::create([
            'code' => 'ROUTE-NORTH',
            'name' => 'สายขนส่งโซนเหนือ',
            'area_type' => 'route',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'username' => 'booking_area_uat',
            'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
        ]);
        $customer->update(['sales_user_id' => $user->id, 'sales_area_id' => $area->id]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_area_id' => $area->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 2,
                    'unit_price' => 125,
                ]],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
            'sales_user_id' => $user->id,
            'total_amount' => 250,
        ]);
        $this->assertDatabaseHas('sale_bookings', [
            'sales_area_id' => $area->id,
            'sales_user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_booking_can_use_the_legacy_unassigned_sales_area_choice(): void
    {
        [$branch, $customer, $product] = $this->masters('NOAREA');
        $area = SalesArea::create([
            'code' => 'ROUTE-DEFAULT', 'name' => 'สายตั้งต้นของผู้ใช้',
            'area_type' => 'route', 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $user = User::factory()->create([
            'username' => 'booking_no_area_uat', 'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
        ]);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_area_id' => null,
                'sales_user_id' => $user->id,
                'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100]],
            ])->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sales_area_id' => null,
        ]);
    }

    public function test_booking_form_keeps_new_number_series_and_top_right_validation_toasts(): void
    {
        $user = User::factory()->create(['username' => 'booking_form_toast_uat']);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('BK + สาขา + วันที่ + ลำดับ (ออกให้อัตโนมัติ)')
            ->assertSee("window.erpToast('warning', title", false)
            ->assertSee("position: 'top-end'", false)
            ->assertSee('salesAreaFromParty: true', false);
    }

    public function test_booking_ignores_customer_ownership_and_lets_the_creator_book_for_themselves(): void
    {
        // ใบจองไม่ผูก user กับลูกค้าทีละรายอีกต่อไป: ลูกค้ามีเจ้าของเป็นคนอื่นก็ไม่ควรกันคนที่กำลังสร้างใบจองออก
        [$branch, $customer, $product] = $this->masters('OWN');
        $area = SalesArea::create([
            'code' => 'ROUTE-OWN',
            'name' => 'สายเจ้าของลูกค้า',
            'area_type' => 'route',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $owner = User::factory()->create(['username' => 'customer_owner', 'sales_area_id' => $area->id]);
        $other = User::factory()->create(['username' => 'other_sales_user', 'branch_id' => $branch->id]);
        $customer->update(['sales_user_id' => $owner->id, 'sales_area_id' => $area->id]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($other)
            ->from(route('bookings.index'))
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 100,
                ]],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'branch_id' => $branch->id,
            'sales_user_id' => $other->id,
        ]);
        // เจ้าของลูกค้าเดิมต้องไม่ถูกเปลี่ยนโดยการสร้างใบจอง
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'sales_user_id' => $owner->id,
        ]);
    }

    public function test_booking_rejects_assigning_another_user_without_sales_assign_permission(): void
    {
        // User ที่ไม่มีสิทธิ์ sales.assign มอบหมายใบจองให้คนอื่นไม่ได้ (ไม่เกี่ยวกับเจ้าของลูกค้าอีกต่อไป)
        [$branch, $customer, $product] = $this->masters('ASSIGN');
        $creator = User::factory()->create(['username' => 'assign_creator', 'branch_id' => $branch->id]);
        $target = User::factory()->create(['username' => 'assign_target', 'branch_id' => $branch->id]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($creator)
            ->from(route('bookings.index'))
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_user_id' => $target->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 100,
                ]],
            ]);

        $response->assertRedirect(route('bookings.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_booking_rejects_a_route_linked_to_another_branch(): void
    {
        [$branch, $customer, $product] = $this->masters('A');
        [$otherBranch] = $this->masters('B');
        $area = SalesArea::create([
            'code' => 'ROUTE-OTHER',
            'name' => 'สายของสาขาอื่น',
            'area_type' => 'route',
            'branch_id' => $otherBranch->id,
            'is_active' => true,
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs(User::factory()->create(['username' => 'booking_area_guard_uat']))
            ->from(route('bookings.index'))
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_area_id' => $area->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'unit_price' => 100,
                ]],
            ]);

        $response->assertRedirect(route('bookings.index'))
            ->assertSessionHas('error');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_booking_shows_and_enforces_available_stock_after_reservations(): void
    {
        [$branch, $customer, $product] = $this->masters('STOCK');
        StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $branch->default_warehouse_location_id)
            ->update(['on_hand_qty' => 10, 'reserved_qty' => 4]);
        $user = User::factory()->create([
            'username' => 'booking_stock_uat',
            'branch_id' => $branch->id,
        ]);
        // หน้าจองต้องมีสิทธิ์ sales.manage อยู่แล้ว (ดู RoutePermissions: 'bookings.' => 'sales.manage')
        // จึงต้องให้สิทธิ์นี้กับผู้ใช้ทดสอบด้วย ไม่งั้น search.products จะตอบ 403 (ผูกสิทธิ์เพิ่มใน 9587d7c)
        $role = Role::create(['code' => 'BOOKING_STOCK_UAT', 'name' => 'booking stock uat']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'sales.manage'], ['name' => 'sales.manage'])->id);
        $user->roles()->attach($role->id);
        $user = $user->fresh();

        $stockResponse = $this->withoutMiddleware(ErpAuthorize::class)
            ->actingAs($user)
            ->getJson(route('search.products', ['q' => $product->sku_code, 'branch_id' => $branch->id]));

        $stockResponse->assertOk();
        $stock = $stockResponse->json()[0];
        $this->assertSame(10.0, (float) $stock['on_hand_qty']);
        $this->assertSame(4.0, (float) $stock['reserved_qty']);
        $this->assertSame(6.0, (float) $stock['available_qty']);

        $response = $this->from(route('bookings.index'))
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'items' => [[
                    'product_id' => $product->id,
                    'qty' => 7,
                    'unit_price' => 100,
                ]],
            ]);

        $response->assertRedirect(route('bookings.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'reserved_qty' => 4,
        ]);
    }

    public function test_pending_booking_edit_keeps_new_number_and_moves_reservations_to_the_new_lines(): void
    {
        [$branch, $customer, $product] = $this->masters('EDIT');
        $otherCustomer = Customer::create([
            'code' => 'CUS-EDIT-2', 'name_th' => 'ลูกค้าทดสอบแก้ไข 2',
            'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $otherProduct = Product::create([
            'sku_code' => 'SKU-EDIT-2', 'name_th' => 'สินค้าแก้ไข 2',
            'base_unit_id' => $product->base_unit_id, 'default_price' => 0, 'is_active' => true,
        ]);
        StockBalance::create([
            'product_id' => $otherProduct->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'on_hand_qty' => 20,
            'reserved_qty' => 0,
        ]);
        $area = SalesArea::create([
            'code' => 'EDIT-ROUTE', 'name' => 'สายแก้ไข', 'area_type' => 'route',
            'branch_id' => $branch->id, 'is_active' => true,
        ]);
        $user = User::factory()->create(['username' => 'booking_edit_uat', 'branch_id' => $branch->id]);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_user_id' => $user->id,
                'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 10]],
            ])->assertRedirect();

        $booking = SaleBooking::with('document')->latest('id')->firstOrFail();
        $originalNumber = $booking->document->doc_number;
        $this->get(route('bookings.edit', $booking))
            ->assertOk()
            ->assertSee($originalNumber)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('SKUEDIT');

        $this->put(route('bookings.update', $booking), [
            'customer_id' => $otherCustomer->id,
            'branch_id' => $branch->id,
            'sales_area_id' => $area->id,
            'sales_user_id' => $user->id,
            'items' => [['product_id' => $otherProduct->id, 'qty' => 6, 'unit_price' => 20]],
        ])->assertRedirect(route('bookings.show', $booking));

        $booking->refresh();
        $booking->load('document');
        $this->assertSame($originalNumber, $booking->document->doc_number);
        $this->assertSame($otherCustomer->id, $booking->document->customer_id);
        $this->assertSame($area->id, $booking->document->sales_area_id);
        $this->assertSame('120.00000000', (string) $booking->document->total_amount);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'reserved_qty' => 0,
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $otherProduct->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'reserved_qty' => 6,
        ]);
        $this->assertDatabaseHas('stock_document_items', [
            'stock_document_id' => $booking->document->stockDocument->id,
            'product_id' => $otherProduct->id,
            'qty' => 6,
            'unit_price' => 20,
        ]);

        $this->post(route('bookings.store'), [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'sales_user_id' => $user->id,
            'items' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10]],
        ])->assertRedirect();
        $this->assertDatabaseHas('documents', [
            'branch_id' => $branch->id,
            'doc_number' => 'BK'.$branch->code.now()->format('Ymd').'002',
        ]);
    }

    public function test_booking_edit_that_exceeds_available_stock_keeps_the_existing_booking_and_reservation(): void
    {
        [$branch, $customer, $product] = $this->masters('EDITSTOCK');
        $user = User::factory()->create(['username' => 'booking_edit_stock_uat', 'branch_id' => $branch->id]);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->post(route('bookings.store'), [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'sales_user_id' => $user->id,
                'items' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 10]],
            ])->assertRedirect();

        $booking = SaleBooking::with('document.stockDocument.items')->latest('id')->firstOrFail();
        StockBalance::where('product_id', $product->id)
            ->where('warehouse_location_id', $branch->default_warehouse_location_id)
            ->update(['on_hand_qty' => 5]);

        $this->from(route('bookings.edit', $booking))->put(route('bookings.update', $booking), [
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'sales_user_id' => $user->id,
            'items' => [['product_id' => $product->id, 'qty' => 6, 'unit_price' => 10]],
        ])->assertRedirect(route('bookings.edit', $booking))->assertSessionHas('error');

        $this->assertSame('3.00000000', (string) $booking->fresh()->document->stockDocument->items->sole()->qty);
        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'warehouse_location_id' => $branch->default_warehouse_location_id,
            'reserved_qty' => 3,
        ]);
    }

    /** @return array{Branch, Customer, Product} */
    private function masters(string $suffix): array
    {
        DocumentType::firstOrCreate(
            ['code' => DocumentType::BOOKING],
            ['name_th' => 'ใบจอง', 'stock_effect' => 'none'],
        );
        $branch = Branch::create([
            'code' => 'BR'.$suffix,
            'name_th' => 'สาขาทดสอบ '.$suffix,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'code' => 'WH'.$suffix,
            'name' => 'คลัง '.$suffix,
        ]);
        $location = WarehouseLocation::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'MAIN'.$suffix,
            'name' => 'พื้นที่หลัก '.$suffix,
        ]);
        $branch->update(['default_warehouse_location_id' => $location->id]);

        $customer = Customer::create([
            'code' => 'CUS'.$suffix,
            'name_th' => 'ลูกค้าทดสอบ '.$suffix,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $unit = ProductUnit::firstOrCreate(
            ['code' => 'EA'],
            ['name' => 'ชิ้น', 'qty_per_base_unit' => 1],
        );
        $product = Product::create([
            'sku_code' => 'SKU'.$suffix,
            'name_th' => 'สินค้าทดสอบ '.$suffix,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        StockBalance::create([
            'product_id' => $product->id,
            'warehouse_location_id' => $location->id,
            'on_hand_qty' => 100,
            'reserved_qty' => 0,
        ]);

        return [$branch->fresh(), $customer, $product];
    }
}
