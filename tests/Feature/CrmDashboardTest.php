<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CrmActivity;
use App\Models\CrmOpportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_search_is_bounded_active_and_branch_scoped(): void
    {
        $branch = Branch::create(['code' => 'SEARCH', 'name_th' => 'สาขาค้นหา']);
        $other = Branch::create(['code' => 'OTHER', 'name_th' => 'อื่น']);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        foreach (range(1, 25) as $i) {
            Customer::create(['code' => 'FIND-'.$i, 'name_th' => 'ลูกค้า '.$i, 'branch_id' => $branch->id, 'is_active' => true]);
        }
        Customer::create(['code' => 'FIND-HIDDEN', 'name_th' => 'อื่น', 'branch_id' => $other->id, 'is_active' => true]);
        Customer::create(['code' => 'FIND-OFF', 'name_th' => 'ปิด', 'branch_id' => $branch->id, 'is_active' => false]);
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user);
        $this->getJson(route('crm.search-customers', ['q' => 'FIND']))->assertOk()->assertJsonCount(20)
            ->assertJsonMissing(['code' => 'FIND-HIDDEN'])->assertJsonMissing(['code' => 'FIND-OFF']);
        $this->getJson(route('crm.search-customers', ['q' => 'FIND-25']))->assertOk()->assertJsonPath('0.code', 'FIND-25');
        $this->getJson(route('crm.search-customers', ['q' => 'F']))->assertOk()->assertExactJson([]);
        $this->get(route('crm.pipeline'))->assertOk()->assertDontSee('FIND-25')->assertSee('pipelineCustomerSearch()');
    }

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

    public function test_branch_user_can_create_and_complete_a_customer_follow_up(): void
    {
        $branch = Branch::create(['code' => 'CRM4', 'name_th' => 'สาขางานติดตาม', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'crm_activity_user', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'CRM-ACT', 'name_th' => 'ลูกค้างานติดตาม', 'branch_id' => $branch->id, 'is_active' => true]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->post(route('crm.activities.store'), [
            'customer_id' => $customer->id,
            'activity_type' => 'call',
            'subject' => 'โทรติดตามใบเสนอราคา',
            'due_at' => now()->addDay()->format('Y-m-d H:i'),
        ]);

        $response->assertRedirect(route('crm.index'));
        $activity = CrmActivity::sole();
        $this->assertSame($user->id, $activity->assigned_to);
        $this->assertDatabaseHas('crm_activities', ['id' => $activity->id, 'status' => 'pending']);

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->patch(route('crm.activities.complete', $activity))
            ->assertRedirect(route('crm.index'));
        $this->assertDatabaseHas('crm_activities', ['id' => $activity->id, 'status' => 'completed']);
    }

    public function test_customer_360_shows_and_can_complete_customer_activity(): void
    {
        $branch = Branch::create(['code' => 'CRM5', 'name_th' => 'สาขา Customer 360', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'crm_360_user', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'CRM-360', 'name_th' => 'ลูกค้า Customer 360', 'branch_id' => $branch->id, 'is_active' => true]);
        $activity = CrmActivity::create([
            'customer_id' => $customer->id, 'branch_id' => $branch->id, 'assigned_to' => $user->id,
            'created_by' => $user->id, 'activity_type' => 'call', 'subject' => 'โทรติดตามงาน 360', 'status' => 'pending',
        ]);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get(route('customers.show', $customer));

        $response->assertOk()->assertSee('โทรติดตามงาน 360')->assertSee('เพิ่มงานติดตาม');
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)
            ->patch(route('crm.activities.complete', $activity))->assertRedirect(route('crm.index'));
        $this->assertDatabaseHas('crm_activities', ['id' => $activity->id, 'status' => 'completed']);
    }

    public function test_pipeline_is_branch_aware_and_can_create_and_move_an_opportunity(): void
    {
        $branch = Branch::create(['code' => 'CRM6', 'name_th' => 'สาขา Pipeline', 'is_active' => true]);
        $otherBranch = Branch::create(['code' => 'CRM7', 'name_th' => 'สาขา Pipeline อื่น', 'is_active' => true]);
        $user = User::factory()->create(['username' => 'crm_pipeline_user', 'branch_id' => $branch->id]);
        $customer = Customer::create(['code' => 'CRM-PIPE', 'name_th' => 'ลูกค้า Pipeline', 'branch_id' => $branch->id, 'is_active' => true]);
        $otherCustomer = Customer::create(['code' => 'CRM-HIDDEN', 'name_th' => 'ลูกค้า Pipeline อื่น', 'branch_id' => $otherBranch->id, 'is_active' => true]);
        CrmOpportunity::create(['customer_id' => $otherCustomer->id, 'branch_id' => $otherBranch->id, 'title' => 'ไม่ควรเห็นงานนี้', 'stage' => 'new']);

        $response = $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->get(route('crm.pipeline'));
        $response->assertOk()->assertSee('Pipeline งานขาย')->assertDontSee('ไม่ควรเห็นงานนี้');

        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->post(route('crm.opportunities.store'), [
            'customer_id' => $customer->id, 'title' => 'ขยายออเดอร์สาขาใหม่', 'stage' => 'qualified', 'expected_amount' => '12500.00',
        ])->assertRedirect(route('crm.pipeline'));
        $opportunity = CrmOpportunity::where('title', 'ขยายออเดอร์สาขาใหม่')->sole();
        $this->withoutMiddleware(ErpAuthorize::class)->actingAs($user)->patch(route('crm.opportunities.stage', $opportunity), [
            'stage' => 'won',
        ])->assertRedirect(route('crm.pipeline'));
        $this->assertDatabaseHas('crm_opportunities', ['id' => $opportunity->id, 'stage' => 'won']);
    }
}
