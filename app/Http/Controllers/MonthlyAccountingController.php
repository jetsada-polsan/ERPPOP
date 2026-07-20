<?php

namespace App\Http\Controllers;

use App\Models\AccountingExportRun;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Services\Accounting\BranchExpenseService;
use App\Services\Accounting\MonthlyAccountingExportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MonthlyAccountingController extends Controller
{
    public function index(Request $request): View
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')) ? $request->query('period') : now()->format('Y-m');
        $branchId = $request->integer('branch_id') ?: null;
        $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $expenses = BranchExpense::with(['branch', 'expenseAccount', 'document'])->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderByDesc('expense_date')->paginate(20)->withQueryString();
        $statements = BankStatement::with(['bankAccount', 'reconciliation.checkedBy'])->whereBetween('statement_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('branch_id', $branchId)))
            ->orderByDesc('statement_date')->limit(100)->get();

        return view('monthly-accounting.index', [
            'period' => $period, 'branchId' => $branchId, 'expenses' => $expenses, 'statements' => $statements,
            'branches' => Branch::orderBy('code')->get(), 'bankAccounts' => BankAccount::orderBy('bank_name')->get(),
            'expenseAccounts' => ChartOfAccount::where('account_type', 'expense')->orderBy('code')->get(),
            'costCenters' => DB::table('cost_centers')->where('is_active', true)->orderBy('code')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name_th')->get(['id', 'name_th', 'tax_id', 'tax_branch']),
            'exportRuns' => AccountingExportRun::where('period', $period)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderByDesc('exported_at')->limit(10)->get(),
            'stats' => [
                'expense_total' => (float) BranchExpense::whereBetween('expense_date', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('total_amount'),
                'withholding_total' => (float) BranchExpense::whereBetween('expense_date', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('withholding_amount'),
                'statement_count' => $statements->count(),
                'unreconciled_count' => $statements->filter(fn ($s) => $s->reconciliation?->status !== 'matched')->count(),
            ],
        ]);
    }

    public function storeExpense(Request $request, BranchExpenseService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'], 'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'], 'expense_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'expense_date' => ['required', 'date'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_name' => ['required', 'string', 'max:200'], 'supplier_tax_id' => ['nullable', 'digits:13'], 'tax_branch' => ['nullable', 'string', 'max:20'],
            'tax_invoice_no' => ['nullable', 'string', 'max:80'], 'tax_invoice_date' => ['nullable', 'date'], 'description' => ['required', 'string', 'max:2000'],
            'base_amount' => ['required', 'numeric', 'min:0.01'], 'vat_amount' => ['nullable', 'numeric', 'min:0'], 'withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'withholding_form' => ['nullable', 'in:PND3,PND53'], 'payment_method' => ['required', 'in:cash,transfer'],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id', 'required_if:payment_method,transfer'],
            'payment_reference' => ['nullable', 'string', 'max:100'], 'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:15360'],
        ]);
        $data['vat_amount'] = (float) ($data['vat_amount'] ?? 0);
        $data['withholding_rate'] = (float) ($data['withholding_rate'] ?? 0);
        $data['withholding_amount'] = round((float) $data['base_amount'] * $data['withholding_rate'] / 100, 2);
        if ($data['withholding_amount'] > 0 && empty($data['withholding_form'])) {
            throw ValidationException::withMessages(['withholding_form' => 'กรุณาเลือก ภ.ง.ด.3 หรือ ภ.ง.ด.53']);
        }
        if ($request->hasFile('evidence')) {
            $data['evidence_path'] = $request->file('evidence')->store('accounting/expenses/'.$request->input('expense_date'), 'local');
        }

        try {
            $service->create($data);
        } catch (\Throwable $exception) {
            if (! empty($data['evidence_path'])) {
                Storage::disk('local')->delete($data['evidence_path']);
            }
            throw ValidationException::withMessages(['expense' => $exception->getMessage()]);
        }

        return back()->with('success', 'บันทึกค่าใช้จ่ายและลง GL แล้ว');
    }

    public function importStatement(Request $request): RedirectResponse
    {
        $data = $request->validate(['bank_account_id' => ['required', 'exists:bank_accounts,id'], 'statement_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $handle = fopen($request->file('statement_file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            throw ValidationException::withMessages(['statement_file' => 'ไฟล์ไม่มีหัวตาราง']);
        }
        $header = array_map(fn ($v) => strtolower(trim((string) $v, "\xEF\xBB\xBF \t\n\r\0\x0B")), $header);
        $aliases = ['date' => ['date', 'statement_date', 'วันที่'], 'description' => ['description', 'รายละเอียด', 'รายการ'], 'amount' => ['amount', 'จำนวนเงิน', 'ยอดเงิน'], 'balance' => ['balance', 'คงเหลือ', 'ยอดคงเหลือ']];
        $index = [];
        foreach ($aliases as $key => $names) {
            foreach ($names as $name) {
                $hit = array_search($name, $header, true);
                if ($hit !== false) {
                    $index[$key] = $hit;
                    break;
                }
            }
        }
        if (! isset($index['date'], $index['amount'])) {
            throw ValidationException::withMessages(['statement_file' => 'ต้องมีคอลัมน์ date/วันที่ และ amount/จำนวนเงิน']);
        }

        $count = DB::transaction(function () use ($handle, $index, $data) {
            $count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                try {
                    $date = $this->statementDate((string) $row[$index['date']]);
                } catch (\Throwable) {
                    continue;
                }
                $amount = (float) str_replace([',', ' '], '', $row[$index['amount']]);
                $description = trim((string) ($row[$index['description']] ?? ''));
                BankStatement::firstOrCreate([
                    'bank_account_id' => $data['bank_account_id'], 'statement_date' => $date, 'description' => $description, 'amount' => $amount,
                ], ['balance' => isset($index['balance']) ? (float) str_replace(',', '', $row[$index['balance']]) : null, 'reconciled' => false]);
                $count++;
            }

            return $count;
        });
        fclose($handle);

        return back()->with('success', "นำเข้า Statement {$count} แถวแล้ว");
    }

    public function reconcile(Request $request, BankStatement $bankStatement): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'], 'match_type' => ['required', 'in:pos_transfer,expense,payment,other'],
            'reference' => ['nullable', 'string', 'max:120'], 'expected_amount' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:2000'],
            'slip' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:15360'],
        ]);
        $difference = round(abs((float) $bankStatement->amount) - (float) $data['expected_amount'], 2);
        $data += ['difference_amount' => $difference, 'status' => abs($difference) <= 0.01 ? 'matched' : 'mismatch', 'checked_by' => auth()->id(), 'checked_at' => now()];
        $old = $bankStatement->reconciliation;
        if ($request->hasFile('slip')) {
            if ($old?->slip_path) {
                Storage::disk('local')->delete($old->slip_path);
            }
            $data['slip_path'] = $request->file('slip')->store('accounting/slips/'.$bankStatement->statement_date->format('Y-m'), 'local');
        }
        BankReconciliation::updateOrCreate(['bank_statement_id' => $bankStatement->id], $data);
        $bankStatement->update(['reconciled' => abs($difference) <= 0.01]);

        return back()->with('success', abs($difference) <= 0.01 ? 'ยอด Statement ตรงกับหลักฐานแล้ว' : 'บันทึกแล้ว แต่ยอดยังมีผลต่าง '.number_format($difference, 2).' บาท');
    }

    public function autoReconcile(Request $request): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'branch_id' => ['nullable', 'exists:branches,id']]);
        $from = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $statements = BankStatement::with('bankAccount')->whereBetween('statement_date', [$from, $to])
            ->where('reconciled', false)->when(! empty($data['branch_id']), fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('branch_id', $data['branch_id'])))->get();
        $matched = 0;
        foreach ($statements as $statement) {
            $amount = (float) $statement->amount;
            $match = $amount < 0
                ? $this->expenseMatch($statement, abs($amount))
                : $this->incomeMatch($statement, $amount);
            if (! $match) {
                continue;
            }
            BankReconciliation::updateOrCreate(['bank_statement_id' => $statement->id], [
                'branch_id' => $match['branch_id'], 'match_type' => $match['match_type'], 'reference' => $match['reference'],
                'expected_amount' => abs($amount), 'difference_amount' => 0, 'status' => 'matched',
                'source_type' => $match['source_type'], 'source_id' => $match['source_id'], 'match_confidence' => $match['confidence'],
                'note' => 'จับคู่อัตโนมัติจากวันที่ บัญชีธนาคาร ยอด และแหล่งอ้างอิงที่ยังไม่ถูกใช้', 'checked_by' => auth()->id(), 'checked_at' => now(),
            ]);
            $statement->update(['reconciled' => true]);
            $matched++;
        }

        return back()->with('success', "จับคู่ Statement อัตโนมัติ {$matched} รายการ รายการที่ไม่ชัดเจนยังคงรอตรวจด้วยคน");
    }

    private function expenseMatch(BankStatement $statement, float $amount): ?array
    {
        $expense = BranchExpense::where('bank_account_id', $statement->bank_account_id)
            ->whereDate('expense_date', $statement->statement_date)
            ->whereRaw('abs(total_amount - withholding_amount - ?) <= 0.01', [$amount])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_reconciliations as br')
                ->where('br.source_type', 'branch_expense')->whereColumn('br.source_id', 'branch_expenses.id'))
            ->first();

        return $expense ? [
            'branch_id' => $expense->branch_id, 'match_type' => 'expense', 'reference' => $expense->document?->doc_number,
            'source_type' => 'branch_expense', 'source_id' => $expense->id, 'confidence' => 100,
        ] : null;
    }

    private function incomeMatch(BankStatement $statement, float $amount): ?array
    {
        $payment = DB::table('payment_lines as pl')->join('payment_documents as pd', 'pd.id', '=', 'pl.payment_document_id')
            ->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->where('pl.bank_account_id', $statement->bank_account_id)->whereDate('d.doc_date', $statement->statement_date)
            ->whereIn('pl.method', ['transfer', 'qr', 'bank'])->whereRaw('abs(pl.amount - ?) <= 0.01', [$amount])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_reconciliations as br')->where('br.source_type', 'payment_line')->whereColumn('br.source_id', 'pl.id'))
            ->first(['pl.id', 'pd.branch_id', 'd.doc_number']);
        if ($payment) {
            return ['branch_id' => $payment->branch_id, 'match_type' => 'payment', 'reference' => $payment->doc_number, 'source_type' => 'payment_line', 'source_id' => $payment->id, 'confidence' => 100];
        }

        $pos = DB::table('pos_payments as pp')->join('pos_receipts as pr', 'pr.id', '=', 'pp.pos_receipt_id')
            ->join('pos_terminals as pt', 'pt.id', '=', 'pr.pos_terminal_id')
            ->whereDate('pr.receipt_date', $statement->statement_date)->whereIn('pp.method', ['transfer', 'qr', 'bank'])
            ->whereRaw('abs(pp.amount - ?) <= 0.01', [$amount])->where('pr.status', 'completed')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_reconciliations as br')->where('br.source_type', 'pos_payment')->whereColumn('br.source_id', 'pp.id'))
            ->first(['pp.id', 'pt.branch_id', 'pr.receipt_no']);

        return $pos ? ['branch_id' => $pos->branch_id, 'match_type' => 'pos_transfer', 'reference' => $pos->receipt_no, 'source_type' => 'pos_payment', 'source_id' => $pos->id, 'confidence' => 100] : null;
    }

    public function export(Request $request, MonthlyAccountingExportService $service): BinaryFileResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'branch_id' => ['nullable', 'exists:branches,id']]);
        $from = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $hasUnreconciled = BankStatement::whereBetween('statement_date', [$from, $to])
            ->when(! empty($data['branch_id']), fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('branch_id', $data['branch_id'])))
            ->whereDoesntHave('reconciliation', fn ($q) => $q->where('status', 'matched'))
            ->exists();
        if ($hasUnreconciled) {
            throw ValidationException::withMessages(['export' => 'ยังมี Statement ที่ไม่ผ่านการตรวจเทียบหลักฐาน ไม่สามารถส่งออกได้']);
        }
        $run = $service->create($data['period'], isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        return response()->download(Storage::disk('local')->path($run->file_name), basename($run->file_name));
    }

    public function downloadRun(AccountingExportRun $run): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($run->file_name), 404);

        return response()->download(Storage::disk('local')->path($run->file_name), basename($run->file_name));
    }

    private function statementDate(string $value): string
    {
        $value = trim($value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return Carbon::parse($value)->toDateString();
    }
}
