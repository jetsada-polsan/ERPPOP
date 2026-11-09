<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafeDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_customer_is_deleted_but_used_one_is_kept(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(Role::where('code', 'GM')->value('id'));
        $unused = Customer::create(['code' => 'DEL1', 'name_th' => 'x', 'is_active' => true]);
        $used = Customer::create(['code' => 'DEL2', 'name_th' => 'y', 'is_active' => true]);
        DB::table('customer_contacts')->insert(['customer_id' => $used->id, 'name' => 'c']);

        $this->actingAs($user)->delete(route('master-data.destroy', ['customers', $unused->id]))->assertSessionHas('success');
        $this->actingAs($user)->delete(route('master-data.destroy', ['customers', $used->id]))->assertSessionHas('success');

        $this->assertNull(Customer::find($unused->id));
        $this->assertNotNull(Customer::find($used->id));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'master_deleted')->where('record_id', $unused->id)->exists());
    }
}
