<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าจอผู้บริหาร — ต้องเปิดได้ ตัวเลขต้องไม่หลุดข้ามสาขา และต้องรอดเมื่อยังไม่มีข้อมูล
 */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_and_survives_an_empty_system(): void
    {
        $response = $this->actingAs($this->executive())->get('/executive');

        $response->assertOk();
        // ระบบที่เพิ่งล้างข้อมูลต้องขึ้นศูนย์ ไม่ใช่พัง
        $this->assertSame(0.0, $response->viewData('today')['sales']);
        $this->assertCount(14, $response->viewData('trend'), 'เส้นแนวโน้มต้องมีครบ 14 วันเสมอ แม้ไม่มียอด');
    }

    public function test_the_json_feed_returns_the_same_shape_for_polling(): void
    {
        $response = $this->actingAs($this->executive())->getJson('/executive/data');

        $response->assertOk()->assertJsonStructure([
            'today' => ['sales', 'profit', 'margin', 'bills', 'average_bill'],
            'compare' => ['sales', 'bills'],
            'trend', 'branches', 'channels', 'topProducts', 'attention', 'refreshed_at',
        ]);
    }

    public function test_yesterday_with_no_sales_reports_no_comparison_rather_than_a_fake_jump(): void
    {
        $response = $this->actingAs($this->executive())->getJson('/executive/data');

        // เมื่อวานยอดศูนย์ เทียบเป็นเปอร์เซ็นต์ไม่ได้ ต้องบอกว่าเทียบไม่ได้ ไม่ใช่ขึ้น 100%
        $this->assertNull($response->json('compare.sales'));
    }

    public function test_a_user_without_cross_branch_rights_is_pinned_to_their_own_branch(): void
    {
        $home = Branch::create(['code' => 'BX1', 'name_th' => 'สาขาของฉัน', 'is_active' => true]);
        $other = Branch::create(['code' => 'BX2', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $user = $this->executive(['reports.view'], $home->id);

        // ขอดูสาขาอื่นตรง ๆ ก็ต้องไม่ได้
        $response = $this->actingAs($user)->get('/executive?branch_id='.$other->id);

        $response->assertOk();
        $this->assertSame('สาขาของฉัน', $response->viewData('branchName'));
    }

    /** @param  array<int, string>  $codes */
    private function executive(array $codes = ['reports.view', 'reports.all_branches'], ?int $branchId = null): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'exec-'.++$sequence, 'is_active' => true,
            'must_change_password' => false, 'branch_id' => $branchId,
        ]);
        $role = Role::create(['code' => 'EXEC_'.$sequence, 'name' => 'exec '.$sequence]);
        foreach ($codes as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }
}
