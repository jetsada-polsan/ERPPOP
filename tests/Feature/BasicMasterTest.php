<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FleetVehicle;
use App\Models\Member;
use App\Models\MemberType;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductDepartment;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SalesArea;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasicMasterTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));

        return $user;
    }

    public function test_category_branch_and_warehouse_can_be_added_and_edited_without_changing_code(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->get(route('basic-master.index', 'branches'))->assertOk();
        $this->actingAs($user)->post(route('basic-master.store', 'product-categories'), ['code' => 'TST', 'name_th' => 'ทดสอบ'])->assertRedirect();
        $category = ProductCategory::where('code', 'TST')->sole();
        $this->actingAs($user)->put(route('basic-master.update', ['product-categories', $category->id]), ['code' => 'NEW', 'name_th' => 'แก้แล้ว'])->assertRedirect();
        $this->assertSame(['TST', 'แก้แล้ว'], [$category->fresh()->code, $category->fresh()->name_th]);

        $this->actingAs($user)->post(route('basic-master.store', 'branches'), ['code' => 'T99', 'name_th' => 'สาขาทดสอบ', 'negative_stock_policy' => 'block', 'is_active' => '1'])->assertRedirect();
        $branch = Branch::where('code', 'T99')->sole();
        $this->actingAs($user)->put(route('basic-master.update', ['branches', $branch->id]), ['name_th' => 'สาขาทดสอบ', 'negative_stock_policy' => 'allow', 'is_active' => '0'])->assertRedirect();
        $this->assertFalse($branch->fresh()->is_active);

        $this->actingAs($user)->post(route('basic-master.store', 'warehouses'), ['code' => 'WT', 'name' => 'คลังทดสอบ', 'branch_id' => $branch->id])->assertRedirect();
        $this->assertSame($branch->id, Warehouse::where('code', 'WT')->value('branch_id'));

        $this->assertSame(2, AuditLog::where('table_name', 'branches')->count());
    }

    public function test_used_branch_cannot_be_deleted(): void
    {
        $user = $this->manager();
        $branch = Branch::create(['code' => 'T98', 'name_th' => 'มีคลัง', 'is_active' => true]);
        Warehouse::create(['code' => 'W98', 'name' => 'คลัง', 'branch_id' => $branch->id]);

        $this->actingAs($user)->delete(route('master-data.destroy', ['branches', $branch->id]))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'ปิดใช้งานแทนการลบ'))
            ->assertSessionMissing('error');

        $this->assertNotNull($branch->fresh());
        $this->assertFalse($branch->fresh()->is_active);
        $this->assertTrue(AuditLog::where('action', 'master_deactivated')->where('table_name', 'branches')->where('record_id', $branch->id)->exists());

        $unused = Branch::create(['code' => 'T97', 'name_th' => 'ว่าง', 'is_active' => true]);
        $this->actingAs($user)->delete(route('master-data.destroy', ['branches', $unused->id]));
        $this->assertNull($unused->fresh());
    }

    public function test_all_constant_types_support_crud_and_safe_deactivate_when_referenced(): void
    {
        $user = $this->manager();

        foreach (array_keys(\App\Http\Controllers\BasicMasterController::TYPES) as $type) {
            $this->actingAs($user)->get(route('basic-master.index', $type))->assertOk();
        }

        // Create & Edit department, brand, unit, sales-area, member-type
        $this->actingAs($user)->post(route('basic-master.store', 'product-departments'), [
            'code' => 'DEP99',
            'name_th' => 'แผนกอาหารแช่แข็ง',
            'name_en' => 'Frozen',
        ])->assertRedirect();
        $department = ProductDepartment::where('code', 'DEP99')->sole();
        $this->assertTrue($department->is_active);

        $this->actingAs($user)->put(route('basic-master.update', ['product-departments', $department->id]), [
            'name_th' => 'แผนกอาหารแช่แข็ง (แก้ไข)',
            'name_en' => 'Frozen Updated',
            'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame('แผนกอาหารแช่แข็ง (แก้ไข)', $department->fresh()->name_th);

        $this->actingAs($user)->post(route('basic-master.store', 'product-brands'), [
            'code' => 'BRD99',
            'name_th' => 'ยี่ห้อทดสอบ',
        ])->assertRedirect();
        $brand = ProductBrand::where('code', 'BRD99')->sole();

        $this->actingAs($user)->post(route('basic-master.store', 'product-units'), [
            'code' => 'UNT99',
            'name' => 'แพ็คใหญ่',
            'qty_per_base_unit' => 12,
        ])->assertRedirect();
        $unit = ProductUnit::where('code', 'UNT99')->sole();

        $this->actingAs($user)->post(route('basic-master.store', 'sales-areas'), [
            'code' => 'SA99',
            'name' => 'สายเหนือ 99',
        ])->assertRedirect();
        $salesArea = SalesArea::where('code', 'SA99')->sole();

        $this->actingAs($user)->post(route('basic-master.store', 'member-types'), [
            'code' => 'MT99',
            'name' => 'VIP Gold',
            'point_rate' => 2.5,
        ])->assertRedirect();
        $memberType = MemberType::where('code', 'MT99')->sole();

        // Unused brand can be hard-deleted
        $unusedBrand = ProductBrand::create(['code' => 'BRD98', 'name_th' => 'ลบได้', 'is_active' => true]);
        $this->actingAs($user)->delete(route('master-data.destroy', ['product-brands', $unusedBrand->id]))->assertSessionHas('success');
        $this->assertNull($unusedBrand->fresh());

        // Reference department, brand, unit by a product; sales-area by customer; member-type by member
        $category = ProductCategory::create(['code' => 'CAT99', 'name_th' => 'หมวดใช้งาน', 'is_active' => true]);
        Product::create([
            'sku_code' => 'SKU-CONST-99',
            'name_th' => 'สินค้าผูกค่าคงที่',
            'product_category_id' => $category->id,
            'product_department_id' => $department->id,
            'product_brand_id' => $brand->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        Customer::create([
            'code' => 'CUST-SA99',
            'name_th' => 'ลูกค้าสายเหนือ',
            'sales_area_id' => $salesArea->id,
            'is_active' => true,
        ]);
        Member::create([
            'member_code' => 'MEM-MT99',
            'name' => 'สมาชิก VIP',
            'member_type_id' => $memberType->id,
            'is_active' => true,
        ]);

        // Deleting in-use constants must NOT hard-delete; instead deactivate (is_active = false)
        foreach ([
            ['product-categories', $category],
            ['product-departments', $department],
            ['product-brands', $brand],
            ['product-units', $unit],
            ['sales-areas', $salesArea],
            ['member-types', $memberType],
        ] as [$typeKey, $modelInstance]) {
            $this->actingAs($user)
                ->delete(route('master-data.destroy', [$typeKey, $modelInstance->id]))
                ->assertSessionHas('success');

            $fresh = $modelInstance->fresh();
            $this->assertNotNull($fresh, "{$typeKey} should not be hard-deleted when referenced");
            $this->assertFalse((bool) $fresh->is_active, "{$typeKey} should be deactivated when delete is attempted while in use");
        }
    }

    public function test_user_without_permission_cannot_open_master_pages(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);

        $this->actingAs($user)->get(route('basic-master.index', 'branches'))->assertForbidden();
    }

    public function test_vehicle_can_be_edited_and_vat_rate_closed(): void
    {
        $user = $this->manager();
        $vehicle = FleetVehicle::create(['code' => 'V1', 'registration' => 'กข-1', 'vehicle_type' => 'กระบะ', 'current_odometer' => 100, 'status' => 'active']);
        $this->actingAs($user)->put(route('fleet.vehicles.update', $vehicle), ['registration' => 'กข-2', 'vehicle_type' => 'กระบะ', 'status' => 'inactive', 'current_odometer' => 5])->assertRedirect();
        $this->assertSame(['กข-2', 'inactive', 100], [$vehicle->fresh()->registration, $vehicle->fresh()->status, $vehicle->fresh()->current_odometer]);

        $rate = VatRate::create(['rate_percent' => 7, 'effective_from' => '2026-01-01']);
        $this->actingAs($user)->put(route('bplus.vat-rates.close', $rate), ['effective_to' => '2025-12-31'])->assertSessionHasErrors('effective_to');
        $this->actingAs($user)->put(route('bplus.vat-rates.close', $rate), ['effective_to' => '2026-12-31', 'rate_percent' => 10])->assertRedirect();
        $this->assertSame(['2026-12-31', '7.00'], [$rate->fresh()->effective_to->toDateString(), $rate->fresh()->rate_percent]);

        $this->actingAs($user)->get(route('fleet.index'))->assertOk()->assertSee(route('fleet.vehicles.update', $vehicle));
        VatRate::create(['rate_percent' => 7, 'effective_from' => '2027-01-01']);
        $this->actingAs($user)->get(route('bplus.tax'))->assertOk()->assertSee('ปิดใช้');
    }
}
