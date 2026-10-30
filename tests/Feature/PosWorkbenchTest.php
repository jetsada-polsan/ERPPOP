<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PosReceipt;
use App\Models\PosTerminal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function posUser(): User
    {
        $user = User::factory()->create([
            'username' => 'pos-workbench-user',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::create(['code' => 'POS_WORKBENCH', 'name' => 'POS Workbench']);
        $role->permissions()->attach(
            Permission::firstOrCreate(['code' => 'pos.use'], ['name' => 'pos.use'])->id
        );
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function userWithPermissions(array $permissions, ?int $branchId = null): User
    {
        $user = User::factory()->create([
            'username' => 'pos-workbench-'.uniqid(),
            'branch_id' => $branchId,
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $role = Role::create(['code' => 'POS_WORKBENCH_'.uniqid(), 'name' => 'POS Workbench']);
        $permissionIds = collect($permissions)->map(fn ($code) => Permission::firstOrCreate(
            ['code' => $code], ['name' => $code]
        )->id);
        $role->permissions()->attach($permissionIds->all());
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function receiptForBranch(Branch $branch, string $receiptNo): PosReceipt
    {
        $terminal = PosTerminal::create([
            'branch_id' => $branch->id,
            'code' => 'T-'.str_replace('-', '', $receiptNo),
            'name' => 'POS '.$branch->code,
        ]);

        return PosReceipt::create([
            'pos_terminal_id' => $terminal->id,
            'receipt_no' => $receiptNo,
            'receipt_date' => now('Asia/Bangkok'),
            'net_sales' => 100,
            'status' => 'completed',
        ]);
    }

    public function test_pos_workbench_promotes_python_pos_instead_of_tauri_release(): void
    {
        $response = $this->actingAs($this->posUser())->get(route('bplus.pos-workbench'));

        $response->assertOk()
            ->assertSee('PopCentral Python POS')
            ->assertSee('Python + PySide6')
            ->assertSee('Local SQLite')
            ->assertSee('ช่องทางดาวน์โหลดปลอดภัยด้วย HTTPS')
            ->assertSee('ผูกเครื่อง POS กับ ERP ก่อนติดตั้ง')
            ->assertSee('หน้า POS ไม่ต้องเลือก token')
            ->assertSee('ให้ผู้ดูแลระบบผูกเครื่อง')
            ->assertSee('ยังไม่มีไฟล์ติดตั้งที่ผ่านการ Build และ Publish')
            ->assertDontSee('ดาวน์โหลด/อัปเดต Python POS')
            ->assertDontSee('Vue + Tauri')
            ->assertDontSee('0.1.7')
            ->assertDontSee(route('pos.download'), false);
    }

    public function test_legacy_pos_download_url_redirects_to_python_pos(): void
    {
        $this->get(route('pos.download'))
            ->assertRedirect(route('python-pos.download'));
    }

    public function test_user_with_all_branch_permission_can_see_every_branch_by_default_and_filter_one(): void
    {
        $hq = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        $hqReceipt = $this->receiptForBranch($hq, 'WB-HQ-001');
        $branchReceipt = $this->receiptForBranch($branch, 'WB-B001-001');
        $user = $this->userWithPermissions(['pos.use', 'reports.all_branches'], $hq->id);

        $this->actingAs($user)->get(route('bplus.pos-workbench'))
            ->assertOk()
            ->assertViewHas('canSeeAllBranches', true)
            ->assertViewHas('selectedBranchId', null)
            ->assertViewHas('branches', fn ($branches) => $branches->contains('id', $hq->id)
                && $branches->contains('id', $branch->id))
            ->assertViewHas('receipts', fn ($receipts) => $receipts->contains('id', $hqReceipt->id)
                && $receipts->contains('id', $branchReceipt->id));

        $this->get(route('bplus.pos-workbench', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('selectedBranchId', $branch->id)
            ->assertViewHas('receipts', fn ($receipts) => $receipts->count() === 1
                && $receipts->sole()->is($branchReceipt));
    }

    public function test_branch_scoped_user_cannot_override_their_branch_in_pos_workbench(): void
    {
        $ownBranch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาตัวเอง', 'is_active' => true]);
        $otherBranch = Branch::create(['code' => 'B002', 'name_th' => 'อีกสาขา', 'is_active' => true]);
        $ownReceipt = $this->receiptForBranch($ownBranch, 'WB-OWN-001');
        $otherReceipt = $this->receiptForBranch($otherBranch, 'WB-OTHER-001');
        $user = $this->userWithPermissions(['pos.use'], $ownBranch->id);

        $this->actingAs($user)->get(route('bplus.pos-workbench', ['branch_id' => $otherBranch->id]))
            ->assertOk()
            ->assertViewHas('canSeeAllBranches', false)
            ->assertViewHas('selectedBranchId', $ownBranch->id)
            ->assertViewHas('branches', fn ($branches) => $branches->count() === 1
                && $branches->sole()->is($ownBranch))
            ->assertViewHas('receipts', fn ($receipts) => $receipts->count() === 1
                && $receipts->sole()->is($ownReceipt)
                && ! $receipts->contains('id', $otherReceipt->id));
    }

    public function test_user_without_branch_or_all_branch_permission_does_not_see_every_branch(): void
    {
        $branch = Branch::create(['code' => 'B001', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $this->receiptForBranch($branch, 'WB-UNASSIGNED-001');
        $user = $this->userWithPermissions(['pos.use']);

        $this->actingAs($user)->get(route('bplus.pos-workbench', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertSee('ยังไม่กำหนดสาขา')
            ->assertViewHas('canSeeAllBranches', false)
            ->assertViewHas('branches', fn ($branches) => $branches->isEmpty())
            ->assertViewHas('receipts', fn ($receipts) => $receipts->isEmpty());
    }
}
