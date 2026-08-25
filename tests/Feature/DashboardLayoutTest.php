<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * แผงควบคุมมีสองหน้าตา (Classic กับ Odoo) แต่ต้องมาจาก controller ตัวเดียว
 *
 * สิ่งที่คุมไว้: การสลับ layout เปลี่ยนแค่ view ที่ render ไม่เปลี่ยนสิทธิ์
 * และไม่เปลี่ยนชุดข้อมูลที่ส่งเข้า view — ถ้ามีใครเผลอแยก controller
 * หรือแยก query ของสองหน้านี้ออกจากกัน เทสต์นี้จะจับได้
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        static $sequence = 0;
        $user = User::factory()->create([
            'username' => 'dash-'.++$sequence, 'is_active' => true, 'must_change_password' => false,
        ]);
        $role = Role::create(['code' => 'DASH_'.$sequence, 'name' => 'dash '.$sequence]);
        $role->permissions()->attach(
            Permission::firstOrCreate(['code' => 'dashboard.view'], ['name' => 'dashboard.view'])->id
        );
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_classic_layout_renders_the_classic_dashboard(): void
    {
        AppSetting::set('erp_layout', 'classic');

        $this->actingAs($this->viewer())->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_odoo_layout_renders_the_odoo_dashboard(): void
    {
        AppSetting::set('erp_layout', 'odoo');

        $this->actingAs($this->viewer())->get(route('dashboard'))
            ->assertOk()
            ->assertViewIs('dashboard-odoo');
    }

    /**
     * ข้อกำหนดจากเจ้าของงาน: "Classic และ Odoo ต้องใช้ข้อมูลชุดเดียวกัน"
     * ตัวเลขที่ทั้งสองหน้าใช้ร่วมกันต้องออกมาเท่ากันเป๊ะ
     */
    public function test_both_layouts_receive_the_same_figures(): void
    {
        $viewer = $this->viewer();

        AppSetting::set('erp_layout', 'classic');
        $classic = $this->actingAs($viewer)->get(route('dashboard'))->viewData('summary');

        AppSetting::set('erp_layout', 'odoo');
        $odoo = $this->actingAs($viewer)->get(route('dashboard'))->viewData('summary');

        $this->assertEquals($classic->total_sales, $odoo->total_sales);
        $this->assertEquals($classic->receipt_count, $odoo->receipt_count);
        $this->assertEquals($classic->gross_profit, $odoo->gross_profit);
    }

    /**
     * แผงควบคุมเป็นหน้าแรกหลังล็อกอิน ตั้งใจให้ผู้ใช้ที่ล็อกอินแล้วเข้าได้ทุกคน
     * (ไม่มีอยู่ใน RoutePermissions::MAP) สิ่งที่ต้องคุมคือการสลับ layout
     * ต้องไม่ทำให้ผู้ที่ยังไม่ล็อกอินหลุดเข้ามาได้
     */
    public function test_guests_are_sent_to_login_in_both_layouts(): void
    {
        foreach (['classic', 'odoo'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            $this->get(route('dashboard'))->assertRedirect(route('login'));
        }
    }

    /** และผู้ใช้ที่ล็อกอินแล้วต้องเข้าได้เท่ากันทั้งสอง layout */
    public function test_signed_in_users_reach_the_dashboard_in_both_layouts(): void
    {
        $viewer = $this->viewer();

        foreach (['classic', 'odoo'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            $this->actingAs($viewer)->get(route('dashboard'))->assertOk();
        }
    }
}
