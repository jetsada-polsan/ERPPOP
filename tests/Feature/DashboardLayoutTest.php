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
     * เปลือกของสอง layout ต้องไม่ปนกัน — Odoo ใช้แถบบน + เมนูแบบกลุ่ม
     * ส่วน Classic ใช้ rail ไอคอนเหมือนเดิม ถ้า markup รั่วข้ามกันเมื่อไร
     * หน้าจอจะเพี้ยนแบบที่มองไม่เห็นจาก unit test อื่น
     */
    public function test_each_layout_renders_only_its_own_shell(): void
    {
        $viewer = $this->viewer();

        AppSetting::set('erp_layout', 'odoo');
        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSee('class="odn-topbar', false)
            ->assertSee('class="odn-item', false)
            ->assertSee('id="odn-q"', false)
            ->assertDontSee('class="fa-rail-btn', false);

        AppSetting::set('erp_layout', 'classic');
        $this->actingAs($viewer)->get(route('dashboard'))
            ->assertSee('class="fa-rail-btn', false)
            ->assertDontSee('class="odn-topbar', false)
            ->assertDontSee('class="odn-item', false);
    }

    /**
     * แผงปรับหน้าจอต้องมีทั้งสอง layout — จอแต่ละเครื่องไม่เท่ากัน
     * ค่าที่ปรับเก็บใน localStorage ของเครื่องนั้น ไม่แตะฐานข้อมูล
     * และไม่ทับค่ากลางของบริษัท ตรงนี้เทสต์ได้แค่ว่า markup ออกมาครบ
     */
    public function test_display_preferences_panel_is_available_in_both_layouts(): void
    {
        $viewer = $this->viewer();

        foreach (['classic', 'odoo'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            $this->actingAs($viewer)->get(route('dashboard'))
                ->assertSee('id="erp-display-btn"', false)
                ->assertSee('data-pref="uiScale"', false)
                ->assertSee('data-pref="menuScale"', false)
                ->assertSee('id="edp-font"', false)
                ->assertSee('id="edp-theme"', false);
        }
    }

    /** ค่ากลางของบริษัทต้องยังเป็นค่าตั้งต้นที่ส่งมากับหน้า */
    public function test_company_theme_still_drives_the_default(): void
    {
        AppSetting::set('erp_theme', 'emerald');
        AppSetting::set('erp_layout', 'odoo');

        $this->actingAs($this->viewer())->get(route('dashboard'))
            ->assertSee('data-theme="emerald"', false);
    }

    /**
     * เมนูทั้งสอง layout เดินจาก $menuSections ชุดเดียวกัน ซึ่งถูกกรองด้วย
     * สิทธิ์มาก่อนแล้ว จำนวนลิงก์เมนูที่ออกมาจึงต้องเท่ากันเป๊ะ
     * ถ้าใครเพิ่มเมนูให้ layout เดียวในอนาคต เทสต์นี้จะจับได้
     */
    public function test_both_shells_list_the_same_number_of_menu_entries(): void
    {
        $viewer = $this->viewer();

        AppSetting::set('erp_layout', 'odoo');
        $odoo = $this->actingAs($viewer)->get(route('dashboard'))->getContent();

        AppSetting::set('erp_layout', 'classic');
        $classic = $this->actingAs($viewer)->get(route('dashboard'))->getContent();

        /* นับด้วย 'odn-item t-' ไม่ใช่ 'odn-item' เฉย ๆ เพราะกล่องไอคอนข้างในใช้
           คลาส odn-item-ico ซึ่งขึ้นต้นเหมือนกัน จะถูกนับซ้ำเป็นเมนูอีกตัว */
        $this->assertSame(
            substr_count($classic, 'class="fa-subnav-link'),
            substr_count($odoo, 'class="odn-item t-'),
            'จำนวนเมนูของสอง layout ไม่เท่ากัน'
        );
    }

    /** เมนูที่ผู้ใช้ไม่มีสิทธิ์ ต้องไม่โผล่ในทั้งสอง layout เท่า ๆ กัน */
    public function test_neither_shell_shows_menu_entries_the_user_cannot_open(): void
    {
        $viewer = $this->viewer();

        foreach (['odoo', 'classic'] as $layout) {
            AppSetting::set('erp_layout', $layout);
            $this->actingAs($viewer)->get(route('dashboard'))
                ->assertDontSee('ผังบัญชี / บันทึกบัญชี');
        }
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
