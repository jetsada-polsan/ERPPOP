<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_success_feedback_uses_a_fast_top_right_toast(): void
    {
        $html = $this->actingAs($this->viewer())
            ->withSession(['success' => 'บันทึกข้อมูลแล้ว'])
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("position: 'top-end'", $html);
        $this->assertStringContainsString('{ timer: 2200 }', $html);
        $this->assertStringContainsString('.erp-swal-toast.swal2-show', $html);
        $this->assertStringContainsString('animation-duration: 180ms', $html);
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

    public function test_dashboard_aggregates_all_branches_for_global_report_users(): void
    {
        [$headOffice, $branch] = $this->branches();
        $this->sale($headOffice, 'POS-HQ-01', 100);
        $this->sale($branch, 'POS-BR-01', 250);
        $viewer = $this->userWithPermissions('DASH_ALL_BRANCHES', ['dashboard.view', 'reports.view', 'reports.all_branches'], $headOffice);
        $this->assertTrue($viewer->hasPermission('reports.view'));
        $this->assertTrue($viewer->hasPermission('reports.all_branches'));

        $response = $this->actingAs($viewer)->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(350.0, (float) $response->viewData('summary')->total_sales);
        $this->assertCount(2, $response->viewData('byBranch'));
        $this->assertSame('รวมทุกสาขา', $response->viewData('scopeBranchName'));
    }

    public function test_multi_branch_manager_sees_only_assigned_branches_on_dashboard(): void
    {
        [$home, $assigned, $unassigned] = $this->branches();
        $this->sale($home, 'POS-HOME-01', 100);
        $this->sale($assigned, 'POS-ASSIGNED-01', 250);
        $this->sale($unassigned, 'POS-OTHER-01', 900);
        $viewer = $this->userWithPermissions('DASH_MULTI_BRANCH', ['dashboard.view', 'reports.view'], $home);
        foreach ([$home, $assigned] as $branch) {
            DB::table('user_branch_roles')->insert([
                'user_id' => $viewer->id,
                'branch_id' => $branch->id,
                'role_id' => $viewer->roles()->firstOrFail()->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($viewer)->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(350.0, (float) $response->viewData('summary')->total_sales);
        $this->assertCount(2, $response->viewData('byBranch'));
        $this->assertStringContainsString($home->name_th, $response->viewData('scopeBranchName'));
        $this->assertStringContainsString($assigned->name_th, $response->viewData('scopeBranchName'));
        $this->assertStringNotContainsString($unassigned->name_th, $response->viewData('scopeBranchName'));
    }

    public function test_dashboard_hides_business_figures_from_users_without_report_permission(): void
    {
        [$home] = $this->branches();
        $this->sale($home, 'POS-NO-REPORT-01', 100);
        $viewer = $this->userWithPermissions('DASH_NO_REPORT', ['dashboard.view'], $home);

        $response = $this->actingAs($viewer)->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(0.0, (float) $response->viewData('summary')->total_sales);
        $this->assertCount(0, $response->viewData('byBranch'));
        $this->assertSame('ไม่มีสาขาที่ได้รับสิทธิ์', $response->viewData('scopeBranchName'));
    }

    /** @return array<int, Branch> */
    private function branches(): array
    {
        return collect([['HQ', 'สำนักงานใหญ่'], ['B001', 'สาขาอำนาจ'], ['B002', 'สาขาอุบล']])
            ->map(fn (array $data) => Branch::create(['code' => $data[0], 'name_th' => $data[1], 'is_active' => true]))
            ->all();
    }

    private function sale(Branch $branch, string $receiptNo, float $amount): void
    {
        $terminal = PosTerminal::create([
            'branch_id' => $branch->id,
            'code' => 'T-'.$receiptNo,
            'name' => 'เครื่อง '.$receiptNo,
        ]);
        PosReceipt::create([
            'pos_terminal_id' => $terminal->id,
            'receipt_no' => $receiptNo,
            'receipt_date' => now(),
            'gross_sales' => $amount,
            'net_sales' => $amount,
            'status' => 'completed',
        ]);
    }

    /** @param array<int, string> $permissionCodes */
    private function userWithPermissions(string $roleCode, array $permissionCodes, Branch $home): User
    {
        $permissions = collect($permissionCodes)->map(fn (string $code) => Permission::firstOrCreate(
            ['code' => $code], ['name' => $code]
        ));
        $role = Role::create(['code' => $roleCode, 'name' => $roleCode]);
        $role->permissions()->attach($permissions->pluck('id'));
        $user = User::factory()->create(['branch_id' => $home->id, 'must_change_password' => false]);
        $user->roles()->attach($role->id);

        return $user->fresh();
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

    /** เมนูหมวดบนต้องสลับกลุ่มใน sidebar ได้โดยตรง ไม่ผูกกับช่องค้นหา */
    public function test_odoo_category_navigation_has_a_direct_group_switcher(): void
    {
        AppSetting::set('erp_layout', 'odoo');

        $html = $this->actingAs($this->viewer())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('function showGroup(target, link)', $html);
        $this->assertStringContainsString('showGroup(target, link);', $html);
        $this->assertStringContainsString('erp:select-section', $html);
        $this->assertStringContainsString('var navLinks = Array.prototype.slice.call(document.querySelectorAll(\'.odn-nav-link\'));', $html);
        $this->assertStringNotContainsString('if (!input) { return; }', $html);
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
