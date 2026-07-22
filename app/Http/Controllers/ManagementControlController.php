<?php

namespace App\Http\Controllers;

use App\Models\PurchasePlan;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ManagementControlController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canBudget = $user?->hasPermission('budget.manage');
        $canPayroll = $user?->hasPermission('payroll.manage');
        $canEcommerce = $user?->hasPermission('ecommerce.sync');
        $canMonitor = $user?->hasPermission('monitoring.manage');
        $canPurchase = $user?->hasPermission('purchasing.manage');
        $period = $request->query('period', now()->format('Y-m'));
        [$from, $to] = $this->period($period);
        $profit = DB::table('branches as b')->get(['b.id', 'b.code', 'b.name_th'])->map(function ($branch) use ($from, $to) {
            $sales = (float) DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                ->where('d.branch_id', $branch->id)->where('d.status', 'active')->whereBetween('d.doc_date', [$from, $to])
                ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
                ->selectRaw("coalesce(sum(case when dt.code='SALE_RETURN' then -d.total_amount else d.total_amount end),0) as amount")->value('amount');
            $cogs = (float) DB::table('stock_document_items as i')->join('stock_documents as s', 's.id', '=', 'i.stock_document_id')
                ->join('documents as d', 'd.id', '=', 's.document_id')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                ->where('d.branch_id', $branch->id)->where('d.status', 'active')->whereBetween('d.doc_date', [$from, $to])
                ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])
                ->selectRaw("coalesce(sum(case when dt.code='SALE_RETURN' then -i.cost_amount else i.cost_amount end),0) as amount")->value('amount');
            $expenses = (float) DB::table('branch_expenses')->where('branch_id', $branch->id)
                ->whereBetween('expense_date', [$from, $to])->sum('total_amount');
            $branch->sales = $sales;
            $branch->cogs = $cogs;
            $branch->expenses = $expenses;
            $branch->net_profit = $sales - $cogs - $expenses;

            return $branch;
        });

        return view('management-controls.index', [
            'period' => $period, 'profit' => $profit,
            'costCenters' => $canBudget ? DB::table('cost_centers')->orderBy('code')->get() : collect(),
            'budgets' => $canBudget ? DB::table('budgets as b')->join('cost_centers as c', 'c.id', '=', 'b.cost_center_id')
                ->where('b.fiscal_year', substr($period, 0, 4))->orderByDesc('b.id')->get(['b.*', 'c.name as cost_center_name']) : collect(),
            'employees' => $canPayroll ? DB::table('employees')->whereRaw('lower(status) = ?', ['active'])->orderBy('full_name')->get() : collect(),
            'attendance' => $canPayroll ? DB::table('attendance_records as a')->join('employees as e', 'e.id', '=', 'a.employee_id')
                ->whereBetween('a.work_date', [$from, $to])->orderByDesc('a.work_date')->limit(100)->get(['a.*', 'e.employee_code', 'e.full_name']) : collect(),
            'payrollRuns' => $canPayroll ? DB::table('payroll_runs')->orderByDesc('period')->limit(12)->get() : collect(),
            'channels' => $canEcommerce ? DB::table('ecommerce_channels')->where('is_active', true)->orderBy('platform')->get() : collect(),
            'orders' => $canEcommerce ? DB::table('ecommerce_orders as o')->join('ecommerce_channels as c', 'c.id', '=', 'o.ecommerce_channel_id')
                ->orderByDesc('o.ordered_at')->limit(100)->get(['o.*', 'c.name as channel_name']) : collect(),
            'purchasePlans' => $canPurchase ? PurchasePlan::with(['product', 'supplier'])->orderByDesc('id')->limit(100)->get() : collect(),
            'monitorEvents' => $canMonitor ? DB::table('monitor_events')->orderByDesc('detected_at')->limit(100)->get() : collect(),
            'accounts' => $canBudget ? DB::table('chart_of_accounts')->orderBy('code')->get(['id', 'code', 'name_th']) : collect(),
        ]);
    }

    public function storeCostCenter(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30', 'unique:cost_centers,code'], 'name' => ['required', 'string', 'max:150'], 'branch_id' => ['nullable', 'exists:branches,id']]);
        DB::table('cost_centers')->insert($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'เพิ่ม Cost Center แล้ว');
    }

    public function storeBudget(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'digits:4'], 'cost_center_id' => ['required', 'exists:cost_centers,id'],
            'month' => ['required', 'integer', 'between:1,12'], 'account_id' => ['required', 'exists:chart_of_accounts,id'],
            'budget_amount' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($data): void {
            $budget = DB::table('budgets')->where('fiscal_year', $data['fiscal_year'])
                ->where('cost_center_id', $data['cost_center_id'])->lockForUpdate()->first();
            if (! $budget) {
                $id = DB::table('budgets')->insertGetId([
                    'budget_no' => 'BG-'.$data['fiscal_year'].'-'.$data['cost_center_id'], 'fiscal_year' => $data['fiscal_year'],
                    'cost_center_id' => $data['cost_center_id'], 'status' => 'draft', 'created_by' => auth()->id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                abort_unless($budget->status === 'draft', 422, 'งบประมาณที่อนุมัติแล้วแก้ไขไม่ได้');
                $id = $budget->id;
            }
            DB::table('budget_lines')->updateOrInsert(
                ['budget_id' => $id, 'month' => $data['month'], 'account_id' => $data['account_id']],
                ['budget_amount' => $data['budget_amount'], 'note' => $data['note'] ?? null],
            );
            DB::table('budgets')->where('id', $id)->update([
                'total_amount' => DB::table('budget_lines')->where('budget_id', $id)->sum('budget_amount'), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'บันทึกงบประมาณแล้ว');
    }

    public function generatePurchasePlan(): RedirectResponse
    {
        $products = DB::table('products as p')->leftJoin('stock_balances as s', 's.product_id', '=', 'p.id')
            ->where('p.is_active', true)->groupBy('p.id', 'p.maximum_stock', 'p.minimum_stock')
            ->havingRaw('coalesce(sum(s.on_hand_qty),0) < coalesce(p.minimum_stock,0)')
            ->selectRaw('p.id, p.maximum_stock, p.minimum_stock, coalesce(sum(s.on_hand_qty),0) as on_hand')->get();
        foreach ($products as $product) {
            $supplier = DB::table('product_suppliers')->where('product_id', $product->id)->orderByDesc('is_primary')->first();
            PurchasePlan::updateOrCreate(['plan_no' => 'AUTO-'.now()->format('Ymd').'-'.$product->id], [
                'product_id' => $product->id,
                'supplier_id' => $supplier?->supplier_id,
                'suggested_qty' => max(0, (float) ($product->maximum_stock ?: $product->minimum_stock) - (float) $product->on_hand),
                'target_stock_qty' => $product->maximum_stock ?: $product->minimum_stock,
                'status' => 'suggested', 'note' => 'สร้างจาก Min/Max และยอดคงเหลืออัตโนมัติ',
            ]);
        }

        return back()->with('success', 'สร้างแผนซื้ออัตโนมัติ '.$products->count().' รายการ');
    }

    public function storeAttendance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'work_date' => ['required', 'date'],
            'clock_in' => ['nullable', 'date'], 'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'status' => ['required', 'in:present,late,leave,absent,holiday'], 'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        DB::table('attendance_records')->updateOrInsert(
            ['employee_id' => $data['employee_id'], 'work_date' => $data['work_date']],
            $data + ['recorded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()],
        );

        return back()->with('success', 'บันทึกเวลาเข้างานแล้ว');
    }

    public function generatePayroll(Request $request): RedirectResponse
    {
        $period = $request->validate(['period' => ['required', 'date_format:Y-m']])['period'];
        [$from, $to] = $this->period($period);
        DB::transaction(function () use ($period, $from, $to): void {
            $run = DB::table('payroll_runs')->where('period', $period)->lockForUpdate()->first();
            if ($run) {
                abort_unless($run->status === 'draft', 422, 'Payroll ที่อนุมัติหรือจ่ายแล้วคำนวณใหม่ไม่ได้');
                DB::table('payroll_runs')->where('id', $run->id)->update([
                    'created_by' => auth()->id(), 'updated_at' => now(),
                ]);
            } else {
                $runId = DB::table('payroll_runs')->insertGetId([
                    'period' => $period, 'status' => 'draft', 'created_by' => auth()->id(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $run = DB::table('payroll_runs')->where('id', $runId)->first();
            }
            DB::table('payroll_items')->where('payroll_run_id', $run->id)->delete();
            foreach (DB::table('employees')->whereRaw('lower(status) = ?', ['active'])->get() as $employee) {
                $salary = (float) ($employee->monthly_salary ?: $employee->wage_amount ?: 0);
                $absent = DB::table('attendance_records')->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->where('status', 'absent')->count();
                $ot = (float) DB::table('attendance_records')->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->sum('overtime_hours');
                $absence = round($salary / 30 * $absent, 2);
                $overtime = round($salary / 30 / 8 * 1.5 * $ot, 2);
                $social = $employee->social_security_enabled ? min(750, round($salary * 0.05, 2)) : 0;
                DB::table('payroll_items')->insert([
                    'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'base_salary' => $salary,
                    'overtime_amount' => $overtime, 'absence_deduction' => $absence, 'social_security' => $social,
                    'withholding_tax' => 0, 'other_deduction' => 0, 'net_amount' => $salary + $overtime - $absence - $social,
                    'calculation_detail' => json_encode(['absent_days' => $absent, 'overtime_hours' => $ot]),
                ]);
            }
            $totals = DB::table('payroll_items')->where('payroll_run_id', $run->id)
                ->selectRaw('sum(base_salary+overtime_amount) gross, sum(absence_deduction+social_security+withholding_tax+other_deduction) deduction, sum(net_amount) net')->first();
            DB::table('payroll_runs')->where('id', $run->id)->update(['gross_amount' => $totals->gross, 'deduction_amount' => $totals->deduction, 'net_amount' => $totals->net]);
        });

        return back()->with('success', 'คำนวณ Payroll '.$period.' แล้ว (ภาษีต้องตรวจและกรอกก่อนอนุมัติ)');
    }

    public function importEcommerceOrder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ecommerce_channel_id' => ['required', 'exists:ecommerce_channels,id'], 'external_order_id' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', 'max:30'], 'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:40'], 'total_amount' => ['required', 'numeric', 'min:0'],
            'ordered_at' => ['nullable', 'date'], 'payload' => ['nullable', 'json'], 'items' => ['nullable', 'json'],
        ]);
        DB::transaction(function () use ($data): void {
            DB::table('ecommerce_orders')->updateOrInsert(
                ['ecommerce_channel_id' => $data['ecommerce_channel_id'], 'external_order_id' => $data['external_order_id']], [
                    'status' => $data['status'], 'customer_name' => $data['customer_name'] ?? null, 'customer_phone' => $data['customer_phone'] ?? null,
                    'total_amount' => $data['total_amount'], 'ordered_at' => $data['ordered_at'] ?? now(),
                    'raw_payload' => $data['payload'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            $orderId = DB::table('ecommerce_orders')->where('ecommerce_channel_id', $data['ecommerce_channel_id'])
                ->where('external_order_id', $data['external_order_id'])->value('id');
            if (! empty($data['items'])) {
                $items = json_decode($data['items'], true, flags: JSON_THROW_ON_ERROR);
                DB::table('ecommerce_order_items')->where('ecommerce_order_id', $orderId)->delete();
                foreach ($items as $item) {
                    $sku = trim((string) ($item['sku'] ?? ''));
                    $qty = (float) ($item['qty'] ?? 0);
                    $price = (float) ($item['unit_price'] ?? 0);
                    if ($sku === '' || $qty <= 0) {
                        continue;
                    }
                    DB::table('ecommerce_order_items')->insert([
                        'ecommerce_order_id' => $orderId, 'external_sku' => $sku,
                        'product_id' => DB::table('products')->where('sku_code', $sku)->value('id'),
                        'qty' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 4),
                    ]);
                }
            }
            DB::table('ecommerce_sync_logs')->insert([
                'ecommerce_channel_id' => $data['ecommerce_channel_id'], 'direction' => 'in',
                'entity_type' => 'order', 'status' => 'success', 'record_count' => 1, 'message' => $data['external_order_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'นำเข้าคำสั่งซื้อออนไลน์แล้ว');
    }

    // ===== Payroll approve/pay workflow =====

    public function showPayrollRun(Request $request, int $run): View
    {
        abort_unless($request->user()?->hasPermission('payroll.manage'), 403);
        $payrollRun = DB::table('payroll_runs')->where('id', $run)->first();
        abort_if($payrollRun === null, 404);
        $items = DB::table('payroll_items as pi')->join('employees as e', 'e.id', '=', 'pi.employee_id')
            ->where('pi.payroll_run_id', $run)->orderBy('e.full_name')
            ->get(['pi.*', 'e.employee_code', 'e.full_name', 'e.social_security_enabled']);

        return view('management-controls.payroll-show', [
            'run' => $payrollRun,
            'items' => $items,
            'canApprove' => $request->user()?->hasPermission('payroll.approve'),
        ]);
    }

    // แก้ภาษีหัก ณ ที่จ่าย และรายการหักอื่นรายคน แล้วคำนวณสุทธิใหม่ (เฉพาะ draft)
    public function updatePayrollItems(Request $request, int $run): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.manage'), 403);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.withholding_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.other_deduction' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $run): void {
            $payrollRun = DB::table('payroll_runs')->where('id', $run)->lockForUpdate()->first();
            abort_if($payrollRun === null, 404);
            abort_unless($payrollRun->status === 'draft', 422, 'อนุมัติแล้วแก้ไขไม่ได้');

            foreach ($data['items'] as $row) {
                $item = DB::table('payroll_items')->where('id', $row['id'])->where('payroll_run_id', $run)->first();
                if (! $item) {
                    continue;
                }
                $wht = round((float) ($row['withholding_tax'] ?? 0), 2);
                $other = round((float) ($row['other_deduction'] ?? 0), 2);
                $net = round((float) $item->base_salary + (float) $item->overtime_amount
                    - (float) $item->absence_deduction - (float) $item->social_security - $wht - $other, 2);
                DB::table('payroll_items')->where('id', $item->id)->update([
                    'withholding_tax' => $wht, 'other_deduction' => $other, 'net_amount' => $net,
                ]);
            }
            $totals = DB::table('payroll_items')->where('payroll_run_id', $run)
                ->selectRaw('sum(base_salary+overtime_amount) gross, sum(absence_deduction+social_security+withholding_tax+other_deduction) deduction, sum(net_amount) net')->first();
            DB::table('payroll_runs')->where('id', $run)->update([
                'gross_amount' => $totals->gross, 'deduction_amount' => $totals->deduction,
                'net_amount' => $totals->net, 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'บันทึกภาษีและรายการหักแล้ว คำนวณยอดสุทธิใหม่เรียบร้อย');
    }

    public function approvePayroll(Request $request, int $run): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.approve'), 403);
        $payrollRun = DB::transaction(function () use ($request, $run) {
            $row = DB::table('payroll_runs')->where('id', $run)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless($row->status === 'draft', 422, 'สถานะไม่ถูกต้อง (ต้องเป็น draft)');
            abort_if($row->created_by === $request->user()->id, 403, 'ผู้จัดทำไม่สามารถอนุมัติงวดของตนเอง');
            abort_if(DB::table('payroll_items')->where('payroll_run_id', $run)->doesntExist(), 422, 'ไม่มีรายการพนักงานในงวดนี้');

            DB::table('payroll_runs')->where('id', $run)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('approve', 'payroll_runs', $run, ['status' => 'draft'], ['status' => 'approved']);

            return $row;
        });

        return back()->with('success', 'อนุมัติ Payroll งวด '.$payrollRun->period.' แล้ว');
    }

    public function markPayrollPaid(Request $request, int $run): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.approve'), 403);
        $payrollRun = DB::transaction(function () use ($request, $run) {
            $row = DB::table('payroll_runs')->where('id', $run)->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless($row->status === 'approved', 422, 'ต้องอนุมัติก่อนจ่าย');

            DB::table('payroll_runs')->where('id', $run)->update([
                'status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('pay', 'payroll_runs', $run, ['status' => 'approved'], ['status' => 'paid']);

            return $row;
        });

        return back()->with('success', 'บันทึกจ่ายเงินเดือนงวด '.$payrollRun->period.' แล้ว');
    }

    // สลิปเงินเดือนพิมพ์ A4 รายคน
    public function payslip(Request $request, int $item): View
    {
        abort_unless($request->user()?->hasPermission('payroll.manage'), 403);
        $row = DB::table('payroll_items as pi')->join('payroll_runs as r', 'r.id', '=', 'pi.payroll_run_id')
            ->join('employees as e', 'e.id', '=', 'pi.employee_id')
            ->where('pi.id', $item)
            ->first(['pi.*', 'r.period', 'r.status as run_status', 'e.employee_code', 'e.full_name', 'e.position', 'e.department']);
        abort_if($row === null, 404);

        return view('management-controls.payslip', ['slip' => $row]);
    }

    // ===== Budget approve + variance =====

    public function showBudget(Request $request, int $budget): View
    {
        abort_unless($request->user()?->hasPermission('budget.manage'), 403);
        $row = DB::table('budgets as b')->join('cost_centers as c', 'c.id', '=', 'b.cost_center_id')
            ->where('b.id', $budget)->first(['b.*', 'c.name as cost_center_name', 'c.branch_id as cost_center_branch_id']);
        abort_if($row === null, 404);

        // เทียบงบ vs จ่ายจริง: budget_lines ต่อ (เดือน+บัญชี) เทียบ branch_expenses ของ cost center เดียวกัน
        $lines = DB::table('budget_lines as bl')->join('chart_of_accounts as a', 'a.id', '=', 'bl.account_id')
            ->where('bl.budget_id', $budget)->orderBy('bl.month')->orderBy('a.code')
            ->get(['bl.*', 'a.code as account_code', 'a.name_th as account_name']);

        // จับคู่ค่าใช้จ่ายจริง (branch_expenses) กับบรรทัดงบตาม เดือน+บัญชี — จัดกลุ่มใน PHP
        // เพื่อไม่ผูกกับ SQL เฉพาะฐานข้อมูล (เช่น extract() ของ Postgres)
        $actuals = [];
        DB::table('branch_expenses')
            ->where('cost_center_id', $row->cost_center_id)
            ->whereBetween('expense_date', [$row->fiscal_year.'-01-01', $row->fiscal_year.'-12-31'])
            ->get(['expense_date', 'expense_account_id', 'total_amount'])
            ->each(function ($r) use (&$actuals) {
                $key = Carbon::parse($r->expense_date)->month.'-'.$r->expense_account_id;
                $actuals[$key] = ($actuals[$key] ?? 0) + (float) $r->total_amount;
            });

        $lines = $lines->map(function ($line) use ($actuals) {
            $spent = (float) ($actuals[$line->month.'-'.$line->account_id] ?? 0);
            $line->spent = $spent;
            $line->variance = (float) $line->budget_amount - $spent;

            return $line;
        });

        return view('management-controls.budget-show', [
            'budget' => $row,
            'lines' => $lines,
            'canApprove' => $request->user()?->hasPermission('budget.approve'),
        ]);
    }

    public function approveBudget(Request $request, int $budget): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('budget.approve'), 403);
        $row = DB::transaction(function () use ($request, $budget) {
            $locked = DB::table('budgets')->where('id', $budget)->lockForUpdate()->first();
            abort_if($locked === null, 404);
            abort_unless($locked->status === 'draft', 422, 'สถานะไม่ถูกต้อง (ต้องเป็น draft)');
            abort_if($locked->created_by === $request->user()->id, 403, 'ผู้จัดทำไม่สามารถอนุมัติงบของตนเอง');

            DB::table('budgets')->where('id', $budget)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('approve', 'budgets', $budget, ['status' => 'draft'], ['status' => 'approved']);

            return $locked;
        });

        return back()->with('success', 'อนุมัติงบประมาณ '.$row->budget_no.' แล้ว');
    }

    private function audit(string $action, string $table, int $recordId, array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(), 'action' => $action, 'table_name' => $table, 'record_id' => $recordId,
            'old_values' => json_encode($old), 'new_values' => json_encode($new),
            'created_at' => now(),
        ]);
    }

    private function period(string $period): array
    {
        $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()];
    }
}
