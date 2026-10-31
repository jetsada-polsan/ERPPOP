<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\RoutePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItPosUatAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_manager_can_run_the_full_pos_flow(): void
    {
        $user = User::factory()->create(['username' => 'it_pos_uat']);
        $user->roles()->attach(Role::where('code', 'IT_MGR')->firstOrFail());

        foreach (['pos.use', 'pos.sell', 'pos.void', 'pos.cash.manage'] as $permission) {
            $this->assertTrue($user->hasPermission($permission), "IT_MGR should have {$permission} for POS UAT.");
        }

        $this->assertSame('pos.sell', RoutePermissions::resolve('pos.checkout'));
        $this->assertSame('pos.sell', RoutePermissions::resolve('pos.shift.open'));
        $this->assertSame('pos.sell', RoutePermissions::resolve('pos.shift.close'));
        $this->assertSame('pos.sell', RoutePermissions::resolve('pos.shift.cash-movement'));
        $this->assertTrue($user->hasPermission('pos.discount.override'));
        $this->assertTrue($user->hasPermission('pos.sell_below_cost'));
    }
}
