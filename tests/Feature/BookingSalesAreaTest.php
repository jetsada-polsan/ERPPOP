<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesArea;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSalesAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_uses_logged_in_user_and_claims_unassigned_customer(): void
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
            'doc_number' => 'B11'.$branch->code.now()->format('Ymd').'001',
            'total_amount' => 270,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'sales_user_id' => $user->id,
            'sales_area_id' => $route->id,
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

    public function test_booking_rejects_a_customer_owned_by_another_user(): void
    {
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

        return [$branch->fresh(), $customer, $product];
    }
}
