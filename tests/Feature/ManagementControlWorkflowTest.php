<?php

namespace Tests\Feature;

use App\Http\Controllers\ManagementControlController;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Locks in the payroll (WHT entry -> approve -> pay -> payslip) and budget
 * (variance -> approve) workflows completed in ManagementControlController,
 * including the maker/checker guards.
 */
class ManagementControlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function controller(): ManagementControlController
    {
        return new ManagementControlController;
    }

    /** Create a user whose role carries exactly the given permission codes. */
    private function userWith(array $permissionCodes, string $username): User
    {
        $user = User::factory()->create(['username' => $username, 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'R_'.strtoupper($username), 'name' => $username]);
        $ids = collect($permissionCodes)->map(fn ($code) => Permission::firstOrCreate(['code' => $code], ['name' => $code])->id);
        $role->permissions()->sync($ids->all());
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function request(User $user, array $params = [], string $method = 'POST'): Request
    {
        $request = Request::create('/', $method, $params);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function employee(array $overrides = []): int
    {
        return DB::table('employees')->insertGetId(array_merge([
            'employee_code' => 'E'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => 'พนักงาน ทดสอบ', 'status' => 'Active',
            'monthly_salary' => 30000, 'social_security_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function abortCode(callable $fn): int|string
    {
        try {
            $fn();

            return 'NO-ABORT';
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ===== Payroll =====

    public function test_generate_payroll_calculates_salary_absence_and_social_security(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $this->actingAs($hr);
        $emp = $this->employee();
        DB::table('attendance_records')->insert([
            'employee_id' => $emp, 'work_date' => '2026-07-10', 'status' => 'absent',
            'overtime_hours' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));

        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();
        $this->assertSame('draft', $run->status);
        $this->assertSame($hr->id, $run->created_by);

        $item = DB::table('payroll_items')->where('payroll_run_id', $run->id)->where('employee_id', $emp)->first();
        $this->assertSame(30000.0, (float) $item->base_salary);
        $this->assertSame(1000.0, (float) $item->absence_deduction); // 30000/30 * 1 absent day
        $this->assertSame(750.0, (float) $item->social_security);    // capped at 750
        $this->assertSame(28250.0, (float) $item->net_amount);       // 30000 - 1000 - 750
    }

    public function test_withholding_tax_entry_recomputes_net_and_run_totals(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $this->actingAs($hr);
        $emp = $this->employee();
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();
        $item = DB::table('payroll_items')->where('payroll_run_id', $run->id)->where('employee_id', $emp)->first();
        $netBefore = (float) $item->net_amount;
        $deductionBefore = (float) $run->deduction_amount;

        $this->controller()->updatePayrollItems(
            $this->request($hr, ['items' => [['id' => $item->id, 'withholding_tax' => 500, 'other_deduction' => 250]]]),
            $run->id,
        );

        $item = DB::table('payroll_items')->where('id', $item->id)->first();
        $run = DB::table('payroll_runs')->where('id', $run->id)->first();
        $this->assertSame($netBefore - 750, (float) $item->net_amount);
        $this->assertSame(500.0, (float) $item->withholding_tax);
        $this->assertSame($deductionBefore + 750, (float) $run->deduction_amount);
    }

    public function test_creator_cannot_approve_own_payroll_run(): void
    {
        $maker = $this->userWith(['payroll.manage', 'payroll.approve'], 'maker');
        $this->actingAs($maker);
        $this->employee();
        $this->controller()->generatePayroll($this->request($maker, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();

        $code = $this->abortCode(fn () => $this->controller()->approvePayroll($this->request($maker), $run->id));
        $this->assertSame(403, $code);
        $this->assertSame('draft', DB::table('payroll_runs')->where('id', $run->id)->value('status'));
    }

    public function test_user_without_approve_permission_cannot_approve(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $this->actingAs($hr);
        $this->employee();
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();

        $this->assertSame(403, $this->abortCode(fn () => $this->controller()->approvePayroll($this->request($hr), $run->id)));
    }

    public function test_approval_locks_run_then_blocks_edits_and_allows_pay(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $gm = $this->userWith(['payroll.approve'], 'gm');
        $this->actingAs($hr);
        $emp = $this->employee();
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();
        $item = DB::table('payroll_items')->where('payroll_run_id', $run->id)->where('employee_id', $emp)->first();

        // cannot pay before approval
        $this->actingAs($gm);
        $this->assertSame(422, $this->abortCode(fn () => $this->controller()->markPayrollPaid($this->request($gm), $run->id)));

        // approve
        $this->controller()->approvePayroll($this->request($gm), $run->id);
        $run = DB::table('payroll_runs')->where('id', $run->id)->first();
        $this->assertSame('approved', $run->status);
        $this->assertSame($gm->id, $run->approved_by);

        // edits blocked after approval
        $this->actingAs($hr);
        $code = $this->abortCode(fn () => $this->controller()->updatePayrollItems(
            $this->request($hr, ['items' => [['id' => $item->id, 'withholding_tax' => 999]]]), $run->id,
        ));
        $this->assertSame(422, $code);

        // pay
        $this->actingAs($gm);
        $this->controller()->markPayrollPaid($this->request($gm), $run->id);
        $run = DB::table('payroll_runs')->where('id', $run->id)->first();
        $this->assertSame('paid', $run->status);
        $this->assertSame($gm->id, $run->paid_by);
    }

    public function test_approved_payroll_cannot_be_regenerated_back_to_draft(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'regen-hr');
        $gm = $this->userWith(['payroll.approve'], 'regen-gm');
        $this->actingAs($hr);
        $this->employee();
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();
        $itemIds = DB::table('payroll_items')->where('payroll_run_id', $run->id)->pluck('id')->all();

        $this->actingAs($gm);
        $this->controller()->approvePayroll($this->request($gm), $run->id);
        $this->actingAs($hr);
        $code = $this->abortCode(fn () => $this->controller()->generatePayroll(
            $this->request($hr, ['period' => '2026-07'])
        ));

        $this->assertSame(422, $code);
        $this->assertSame('approved', DB::table('payroll_runs')->where('id', $run->id)->value('status'));
        $this->assertSame($itemIds, DB::table('payroll_items')->where('payroll_run_id', $run->id)->pluck('id')->all());
    }

    public function test_payslip_renders_for_authorized_user(): void
    {
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $this->actingAs($hr);
        $emp = $this->employee(['full_name' => 'สมชาย เงินเดือน']);
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();
        $item = DB::table('payroll_items')->where('payroll_run_id', $run->id)->where('employee_id', $emp)->first();

        $html = $this->controller()->payslip($this->request($hr, [], 'GET'), $item->id)->render();
        $this->assertStringContainsString('สมชาย เงินเดือน', $html);
        $this->assertStringContainsString('สลิปเงินเดือน', $html);
    }

    public function test_show_payroll_run_forbidden_without_manage_permission(): void
    {
        $outsider = $this->userWith(['reports.view'], 'outsider');
        $hr = $this->userWith(['payroll.manage'], 'hr');
        $this->actingAs($hr);
        $this->employee();
        $this->controller()->generatePayroll($this->request($hr, ['period' => '2026-07']));
        $run = DB::table('payroll_runs')->where('period', '2026-07')->first();

        $this->actingAs($outsider);
        $this->assertSame(403, $this->abortCode(fn () => $this->controller()->showPayrollRun($this->request($outsider, [], 'GET'), $run->id)));
    }

    // ===== Budget =====

    private function budgetWithLine(User $creator, float $budgetAmount = 10000): array
    {
        $branch = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $account = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_EXPENSE)->firstOrFail();
        $cc = DB::table('cost_centers')->insertGetId(['code' => 'CC1', 'name' => 'ฝ่ายขาย', 'branch_id' => $branch->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $budget = DB::table('budgets')->insertGetId(['budget_no' => 'BG-2026-CC1', 'fiscal_year' => '2026', 'cost_center_id' => $cc, 'status' => 'draft', 'total_amount' => $budgetAmount, 'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('budget_lines')->insert(['budget_id' => $budget, 'month' => 7, 'account_id' => $account->id, 'budget_amount' => $budgetAmount]);

        return [$branch, $cc, $budget, $account->id];
    }

    private function recordExpense(int $branchId, int $costCenterId, int $accountId, float $amount, string $date): void
    {
        $typeId = DB::table('document_types')->where('code', 'EXPENSE')->value('id')
            ?: DB::table('document_types')->insertGetId(['code' => 'EXPENSE', 'name_th' => 'ค่าใช้จ่าย', 'affects_stock' => false, 'affects_ar' => false, 'affects_ap' => false, 'is_active' => true]);
        $docId = DB::table('documents')->insertGetId(['document_type_id' => $typeId, 'branch_id' => $branchId, 'doc_number' => 'EXP-'.uniqid(), 'doc_date' => $date, 'status' => 'active', 'total_items' => 1, 'total_amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_expenses')->insert([
            'document_id' => $docId, 'branch_id' => $branchId, 'cost_center_id' => $costCenterId,
            'expense_account_id' => $accountId, 'expense_date' => $date, 'supplier_name' => 'ผู้ขาย',
            'description' => 'ค่าใช้จ่ายทดสอบ', 'base_amount' => $amount, 'total_amount' => $amount,
            'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_budget_variance_compares_budget_against_actual_expense(): void
    {
        $acc = $this->userWith(['budget.manage'], 'acc');
        $this->actingAs($acc);
        [$branch, $cc, $budget, $accountId] = $this->budgetWithLine($acc, 10000);
        $this->recordExpense($branch->id, $cc, $accountId, 7500, '2026-07-15');
        // an expense outside the fiscal year must not count
        $this->recordExpense($branch->id, $cc, $accountId, 4000, '2025-07-15');

        $view = $this->controller()->showBudget($this->request($acc, [], 'GET'), $budget);
        $line = $view->getData()['lines']->firstWhere('month', 7);

        $this->assertSame(10000.0, (float) $line->budget_amount);
        $this->assertSame(7500.0, (float) $line->spent);
        $this->assertSame(2500.0, (float) $line->variance);
    }

    public function test_creator_cannot_approve_own_budget(): void
    {
        $maker = $this->userWith(['budget.manage', 'budget.approve'], 'bmaker');
        $this->actingAs($maker);
        [, , $budget] = $this->budgetWithLine($maker);

        $this->assertSame(403, $this->abortCode(fn () => $this->controller()->approveBudget($this->request($maker), $budget)));
        $this->assertSame('draft', DB::table('budgets')->where('id', $budget)->value('status'));
    }

    public function test_separate_approver_approves_budget(): void
    {
        $acc = $this->userWith(['budget.manage'], 'acc');
        $gm = $this->userWith(['budget.approve'], 'gm');
        $this->actingAs($acc);
        [, , $budget] = $this->budgetWithLine($acc);

        $this->actingAs($gm);
        $this->controller()->approveBudget($this->request($gm), $budget);

        $row = DB::table('budgets')->where('id', $budget)->first();
        $this->assertSame('approved', $row->status);
        $this->assertSame($gm->id, $row->approved_by);
    }

    public function test_approved_budget_lines_cannot_be_changed(): void
    {
        $acc = $this->userWith(['budget.manage'], 'locked-budget-acc');
        $gm = $this->userWith(['budget.approve'], 'locked-budget-gm');
        $this->actingAs($acc);
        [, $costCenterId, $budget, $accountId] = $this->budgetWithLine($acc, 10000);

        $this->actingAs($gm);
        $this->controller()->approveBudget($this->request($gm), $budget);
        $this->actingAs($acc);
        $code = $this->abortCode(fn () => $this->controller()->storeBudget($this->request($acc, [
            'fiscal_year' => '2026', 'cost_center_id' => $costCenterId, 'month' => 7,
            'account_id' => $accountId, 'budget_amount' => 99999,
        ])));

        $this->assertSame(422, $code);
        $this->assertSame(10000.0, (float) DB::table('budget_lines')->where('budget_id', $budget)->value('budget_amount'));
    }
}
