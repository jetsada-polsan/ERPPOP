<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCashierCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_which_cashiers_can_sign_in_without_revealing_pins(): void
    {
        Role::firstOrCreate(['code' => 'CASHIER'], ['name' => 'แคชเชียร์'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        Role::firstOrCreate(['code' => 'BRANCH_MGR'], ['name' => 'ผจก'])->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['code' => 'pos.sell'], ['name' => 'ขาย'])->id]);
        $branch = Branch::create(['code' => 'B905', 'name_th' => 'ทดสอบ', 'is_active' => true]);
        User::factory()->create(['username' => 'b905mgr', 'branch_id' => $branch->id]);
        $this->artisan('erp:setup-branch-pos-staff', ['branch' => 'B905', '--user' => 'b905mgr', '--cashier' => ['b905', 'b905-2'], '--manager' => 'm905'])->assertSuccessful();
        Salesman::where('code', 'B905')->sole()->setPin('482165', false);

        $this->artisan('erp:pos-cashier-check', ['branch' => 'B905'])
            ->expectsOutputToContain('แคชเชียร์สาขา B905')
            ->doesntExpectOutputToContain('482165')
            ->assertSuccessful();
    }
}
