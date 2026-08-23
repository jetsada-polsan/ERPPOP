<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Permission;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ทะเบียนรายงาน: ผู้บริหารเปิด/ปิดรายงานได้เอง และสิทธิ์ ดู / export / ข้ามสาขา
 * เป็นคนละใบกัน ตาม CLAUDE_LEGACY_REBUILD_BRIEF.md หัวข้อ "สถาปัตยกรรมรายงานใหม่"
 */
class ReportGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_a_report_hides_it_from_the_menu_without_deleting_it(): void
    {
        $admin = $this->userWith(['settings.manage']);
        $definition = ReportDefinition::where('code', 'sales.daily_sales')->sole();

        $this->actingAs($admin)->get('/reports')->assertOk()->assertSee('ยอดขายรายวัน');

        $this->actingAs($admin)
            ->post('/settings/reports', ['report_id' => $definition->id, 'enabled' => 0])
            ->assertRedirect();

        $this->assertFalse($definition->fresh()->enabled);
        // definition ต้องยังอยู่ ไม่ใช่ถูกลบทิ้ง
        $this->assertDatabaseHas('report_definitions', ['code' => 'sales.daily_sales']);

        $catalog = $this->actingAs($admin)->get('/reports')->viewData('catalog');
        $this->assertArrayNotHasKey('daily_sales', $catalog['sales']['reports'] ?? []);
    }

    public function test_a_planned_report_cannot_be_switched_on_before_uat(): void
    {
        $admin = $this->userWith(['settings.manage']);
        // สร้างรายงานที่ยังไม่มีหน้าจอขึ้นมาเอง แทนที่จะพึ่งว่ารายงานตัวใดตัวหนึ่งจะยัง planned ตลอดไป
        $planned = ReportDefinition::create([
            'code' => 'test.not_built_yet',
            'category' => 'test',
            'category_title' => 'ทดสอบ',
            'name' => 'รายงานที่ยังไม่มีหน้าจอ',
            'view_permission' => 'reports.view',
            'status' => 'planned',
            'enabled' => false,
            'sort_order' => 999,
        ]);

        $this->actingAs($admin)
            ->post('/settings/reports', ['report_id' => $planned->id, 'enabled' => 1])
            ->assertSessionHasErrors('report');

        $this->assertFalse($planned->fresh()->enabled);
    }

    public function test_switching_a_report_writes_an_audit_log(): void
    {
        $admin = $this->userWith(['settings.manage']);
        $definition = ReportDefinition::where('code', 'sales.daily_sales')->sole();

        $this->actingAs($admin)->post('/settings/reports', ['report_id' => $definition->id, 'enabled' => 0]);

        $audit = AuditLog::where('table_name', 'report_definitions')->sole();
        $this->assertSame('report_disabled', $audit->action);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('sales.daily_sales', $audit->new_values['code']);
    }

    public function test_a_user_without_the_all_branches_permission_cannot_see_another_branch(): void
    {
        [$own, $other] = $this->twoBranchesWithCreditSales();
        $user = $this->userWith(['sales.manage'], $own);

        // ขอ 'all' มาก็ต้องไม่หลุด — สิทธิ์เป็นตัวตัดสิน ไม่ใช่พารามิเตอร์
        $response = $this->actingAs($user)->get('/reports?category=sales&report=credit_sales&from=2026-08-01&to=2026-08-31&branch_id=all');
        $response->assertOk();
        $response->assertViewHas('canSeeAllBranches', false);

        $numbers = collect($response->viewData('result')['rows'])->pluck('doc_number')->all();
        $this->assertContains('CR-OWN-1', $numbers);
        $this->assertNotContains('CR-OTHER-1', $numbers);
    }

    public function test_the_all_branches_permission_opens_every_branch(): void
    {
        [$own, $other] = $this->twoBranchesWithCreditSales();
        $user = $this->userWith(['sales.manage', 'reports.all_branches'], $own);

        $rows = collect($this->actingAs($user)
            ->get('/reports?category=sales&report=credit_sales&from=2026-08-01&to=2026-08-31&branch_id=all')
            ->viewData('result')['rows'])->pluck('doc_number')->all();

        $this->assertContains('CR-OWN-1', $rows);
        $this->assertContains('CR-OTHER-1', $rows);
    }

    public function test_export_buttons_appear_only_with_the_export_permission(): void
    {
        $branch = Branch::create(['code' => 'EXP', 'name_th' => 'สาขา export', 'is_active' => true]);

        $this->actingAs($this->userWith(['sales.manage'], $branch))
            ->get('/reports')->assertOk()->assertViewHas('canExport', false)->assertDontSee('ดาวน์โหลด Excel');

        $this->actingAs($this->userWith(['sales.manage', 'reports.export'], $branch))
            ->get('/reports')->assertOk()->assertViewHas('canExport', true)->assertSee('ดาวน์โหลด Excel');
    }

    public function test_a_user_with_no_branch_and_no_cross_branch_right_sees_no_real_data(): void
    {
        $this->twoBranchesWithCreditSales();
        $user = $this->userWith(['sales.manage']);   // ไม่ผูกสาขา และไม่มี reports.all_branches

        $response = $this->actingAs($user)->get('/reports?category=sales&report=credit_sales&from=2026-08-01&to=2026-08-31&branch_id=all');

        $response->assertOk();
        $response->assertViewHas('branchLocked', true);
        $response->assertSee('ยังไม่ได้สังกัดสาขา');
        $this->assertEmpty($response->viewData('result')['rows']);
    }

    /** นโยบายสิทธิ์ที่เจ้าของตัดสินใจ 2026-08-23 — migration ต้อง seed ให้ตรงนี้ */
    public function test_the_seeded_roles_match_the_agreed_access_policy(): void
    {
        $expected = [
            'GM' => ['reports.export' => true, 'reports.all_branches' => true],
            'IT_MGR' => ['reports.export' => true, 'reports.all_branches' => true],
            'ACC_MGR' => ['reports.export' => true, 'reports.all_branches' => true],
            'EXECUTIVE' => ['reports.export' => true, 'reports.all_branches' => true],
            // พนักงานบัญชีส่งออกได้ แต่ดูเฉพาะสาขาตัวเอง
            'ACC' => ['reports.export' => true, 'reports.all_branches' => false],
            'BRANCH_MGR' => ['reports.export' => true, 'reports.all_branches' => false],
            // หน้าร้านและพนักงานส่งของไม่มีสิทธิ์รายงานเลย
            'CASHIER' => ['reports.view' => false, 'reports.export' => false, 'reports.all_branches' => false],
            'DELIVERY' => ['reports.view' => false, 'reports.export' => false, 'reports.all_branches' => false],
            // การตลาดดูรายงานได้ แต่ไม่แตะการเงินและไม่ข้ามสาขา
            'MARKETING' => ['reports.view' => true, 'finance.manage' => false, 'reports.all_branches' => false],
        ];

        foreach ($expected as $roleCode => $permissions) {
            $role = Role::where('code', $roleCode)->first();
            $this->assertNotNull($role, "ไม่พบ role {$roleCode}");
            $held = $role->permissions()->pluck('code')->all();
            foreach ($permissions as $code => $shouldHave) {
                $this->assertSame(
                    $shouldHave,
                    in_array($code, $held, true),
                    "{$roleCode} ".($shouldHave ? 'ต้องมี' : 'ต้องไม่มี')." {$code}"
                );
            }
        }
    }

    private function twoBranchesWithCreditSales(): array
    {
        $own = Branch::create(['code' => 'OWN', 'name_th' => 'สาขาตัวเอง', 'is_active' => true]);
        $other = Branch::create(['code' => 'OTH', 'name_th' => 'สาขาอื่น', 'is_active' => true]);
        $type = DocumentType::create(['code' => 'CREDIT_SALE', 'name_th' => 'ขายเชื่อ']);
        foreach ([[$own, 'CR-OWN-1'], [$other, 'CR-OTHER-1']] as [$branch, $number]) {
            Document::create([
                'document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => $number,
                'doc_date' => '2026-08-10', 'status' => 'active', 'total_items' => 1, 'total_amount' => 500,
            ]);
        }

        return [$own, $other];
    }

    private function userWith(array $permissionCodes, ?Branch $branch = null): User
    {
        $user = User::factory()->create([
            'username' => 'rg_'.uniqid(),
            'is_active' => true,
            'must_change_password' => false,
            'branch_id' => $branch?->id,
        ]);
        $role = Role::create(['code' => 'RG_'.strtoupper(uniqid()), 'name' => 'Report governance test']);
        // reports.view คือประตูของหน้ารายงาน (RoutePermissions) ส่วนสิทธิ์รายรายงานเป็นชั้นที่สอง
        foreach (array_unique([...$permissionCodes, 'reports.view']) as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
