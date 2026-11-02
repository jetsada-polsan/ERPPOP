<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FleetVehicle;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
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

        $this->actingAs($user)->delete(route('master-data.destroy', ['branches', $branch->id]));

        $this->assertNotNull($branch->fresh());

        $unused = Branch::create(['code' => 'T97', 'name_th' => 'ว่าง', 'is_active' => true]);
        $this->actingAs($user)->delete(route('master-data.destroy', ['branches', $unused->id]));
        $this->assertNull($unused->fresh());
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
