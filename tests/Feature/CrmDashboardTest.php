<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_dashboard_shows_customer_360_summary(): void
    {
        $branch = Branch::create(['code' => 'CRM1', 'name_th' => 'สาขา CRM', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'crm_summary_user', 'branch_id' => $branch->id]);
        Customer::create(['code' => 'CRM-001', 'name_th' => 'ลูกค้า CRM หนึ่ง', 'branch_id' => $branch->id, 'is_active' => true]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get(route('crm.index'));

        $response->assertOk()
            ->assertSee('ลูกค้าสัมพันธ์')
            ->assertSee('ลูกค้า CRM หนึ่ง')
            ->assertSee('Customer 360');
    }

    public function test_branch_user_cannot_see_customers_from_another_branch(): void
    {
        $ownBranch = Branch::create(['code' => 'CRM2', 'name_th' => 'สาขาของฉัน', 'is_active' => true]);
        $otherBranch = Branch::create(['code' => 'CRM3', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'crm_branch_user', 'branch_id' => $ownBranch->id]);
        Customer::create(['code' => 'CRM-OWN', 'name_th' => 'ลูกค้าสาขาฉัน', 'branch_id' => $ownBranch->id, 'is_active' => true]);
        Customer::create(['code' => 'CRM-OTHER', 'name_th' => 'ลูกค้าสาขาอื่น', 'branch_id' => $otherBranch->id, 'is_active' => true]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get(route('crm.index'));

        $response->assertOk()->assertSee('ลูกค้าสาขาฉัน')->assertDontSee('ลูกค้าสาขาอื่น');
    }
}
