<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ข้อ 13: ยังไม่มีกะต้องเห็นปุ่มเปิดกะชัด ๆ ถ้าเปิดไม่ได้ต้องบอกเหตุผล ไม่ใช่ปุ่มที่ซ่อนอยู่
 */
class PosShiftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_without_a_shift_sees_an_open_shift_button(): void
    {
        $this->open($this->cashier(withProfile: true))
            ->assertSee('data-testid="shift-needed"', false)
            ->assertSee('ยังไม่เปิดกะ')
            ->assertSee('openShiftModal()', false)
            ->assertViewHas('shiftBlocker', null);
    }

    public function test_a_cashier_not_linked_to_a_till_profile_is_told_why(): void
    {
        $this->open($this->cashier(withProfile: false))
            ->assertSee('ยังไม่ได้ผูกกับแคชเชียร์')
            ->assertViewHas('shiftBlocker', fn ($reason) => str_contains($reason, 'ผูกกับแคชเชียร์'));
    }

    public function test_a_viewer_is_told_they_cannot_sell(): void
    {
        $this->open(User::factory()->create(['username' => 'pos_viewer']))
            ->assertSee('ไม่มีสิทธิ์ขาย (pos.sell)');
    }

    private function open(User $user)
    {
        $this->withoutVite();

        return $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get(route('pos.index'))->assertOk();
    }

    private function cashier(bool $withProfile): User
    {
        $branch = Branch::create(['code' => 'SV', 'name_th' => 'สาขากะ', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-SV', 'name' => 'คลัง']);
        $branch->update(['default_warehouse_location_id' => WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'M', 'name' => 'หลัก'])->id]);
        $user = User::factory()->create(['username' => 'shift_'.($withProfile ? 'ok' : 'nolink'), 'branch_id' => $branch->id]);
        $role = Role::firstOrCreate(['code' => 'SV_CASHIER'], ['name' => 'แคชเชียร์']);
        $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $user->roles()->attach($role->id);
        if ($withProfile) {
            Salesman::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'code' => 'SV1', 'name' => 'แคชเชียร์', 'is_active' => true]);
        }

        return $user->fresh();
    }
}
