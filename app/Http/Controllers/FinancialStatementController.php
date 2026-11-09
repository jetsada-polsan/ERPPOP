<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Services\Reports\ReportScope;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * งบการเงินจาก gl_journals: งบทดลอง (Trial Balance), งบกำไรขาดทุน (P&L),
 * งบแสดงฐานะการเงิน (Balance Sheet). อ่านยอด debit/credit ตามช่วงวันที่แล้ว
 * จัดกลุ่มตาม account_type.
 */
class FinancialStatementController extends Controller
{
    private const SHEETS = [
        'trial_balance' => 'งบทดลอง',
        'income_statement' => 'งบกำไรขาดทุน',
        'balance_sheet' => 'งบแสดงฐานะการเงิน',
    ];

    public function index(Request $request): View
    {
        $sheet = $request->query('sheet', 'trial_balance');
        if (! isset(self::SHEETS[$sheet])) {
            $sheet = 'trial_balance';
        }

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $branchId = app(ReportScope::class)->branchId($request->input('branch_id'));
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->startOfYear();

        // ยอด debit/credit ต่อบัญชี ในช่วงวันที่ (Balance Sheet ใช้ยอดสะสมถึง $to)
        $balanceSheet = $sheet === 'balance_sheet';
        $movements = app(ReportScope::class)->applyGl(DB::table('gl_journals'), 'gl_journals', $request->input('branch_id'))
            ->selectRaw('account_id, SUM(debit) AS total_debit, SUM(credit) AS total_credit')
            ->when(! $balanceSheet, fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->when($balanceSheet, fn ($q) => $q->where('entry_date', '<=', $to->toDateString()))
            ->groupBy('account_id')
            ->get()->keyBy('account_id');

        $accounts = ChartOfAccount::orderBy('code')->get()->map(function ($acc) use ($movements) {
            $m = $movements->get($acc->id);
            $debit = DecimalMath::round($m->total_debit ?? 0, 2);
            $credit = DecimalMath::round($m->total_credit ?? 0, 2);
            // ยอดคงเหลือตามธรรมชาติของบัญชี: asset/expense = debit-credit, อื่น = credit-debit
            $natural = in_array($acc->account_type, ['asset', 'expense'], true) ? DecimalMath::subtract($debit, $credit, 2) : DecimalMath::subtract($credit, $debit, 2);
            $acc->total_debit = $debit;
            $acc->total_credit = $credit;
            $acc->balance = $natural;

            return $acc;
        });

        $data = match ($sheet) {
            'income_statement' => $this->incomeStatement($accounts),
            'balance_sheet' => $this->balanceSheet($accounts, $from, $to),
            default => $this->trialBalance($accounts),
        };

        return view('financial-statements.index', array_merge($data, [
            'sheets' => self::SHEETS,
            'branchId' => $branchId,
            'branches' => app(ReportScope::class)->apply(\App\Models\Branch::query(), 'id')->orderBy('code')->get(),
            'allBranches' => auth()->user()?->canSeeAllReportBranches() ?? true,
            'sheet' => $sheet,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));
    }

    private function trialBalance($accounts): array
    {
        $rows = $accounts->filter(fn ($a) => abs($a->total_debit) > 0.001 || abs($a->total_credit) > 0.001)->values();

        return [
            'rows' => $rows,
            'totalDebit' => DecimalMath::sum($rows->pluck('total_debit'), 2),
            'totalCredit' => DecimalMath::sum($rows->pluck('total_credit'), 2),
        ];
    }

    private function incomeStatement($accounts): array
    {
        $revenue = $accounts->where('account_type', 'revenue');
        $expense = $accounts->where('account_type', 'expense');
        $totalRevenue = DecimalMath::sum($revenue->pluck('balance'), 2);
        $totalExpense = DecimalMath::sum($expense->pluck('balance'), 2);

        return [
            'revenue' => $revenue->filter(fn ($a) => abs($a->balance) > 0.001)->values(),
            'expense' => $expense->filter(fn ($a) => abs($a->balance) > 0.001)->values(),
            'totalRevenue' => $totalRevenue,
            'totalExpense' => $totalExpense,
            'netProfit' => DecimalMath::subtract($totalRevenue, $totalExpense, 2),
        ];
    }

    private function balanceSheet($accounts, Carbon $from, Carbon $to): array
    {
        // กำไร(ขาดทุน)สุทธิสะสมถึงวันที่ = รายได้สะสม - ค่าใช้จ่ายสะสม โอนเข้าส่วนของเจ้าของ
        $netProfit = DecimalMath::subtract(DecimalMath::sum($accounts->where('account_type', 'revenue')->pluck('balance')), DecimalMath::sum($accounts->where('account_type', 'expense')->pluck('balance')), 2);

        $assets = $accounts->where('account_type', 'asset')->filter(fn ($a) => abs($a->balance) > 0.001)->values();
        $liabilities = $accounts->where('account_type', 'liability')->filter(fn ($a) => abs($a->balance) > 0.001)->values();
        $equity = $accounts->where('account_type', 'equity')->filter(fn ($a) => abs($a->balance) > 0.001)->values();

        $totalAssets = DecimalMath::sum($assets->pluck('balance'), 2);
        $totalLiabilities = DecimalMath::sum($liabilities->pluck('balance'), 2);
        $totalEquity = DecimalMath::add(DecimalMath::sum($equity->pluck('balance')), $netProfit, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'netProfit' => $netProfit,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'balanced' => DecimalMath::compare(DecimalMath::absoluteDifference($totalAssets, DecimalMath::add($totalLiabilities, $totalEquity)), '0.01') <= 0,
        ];
    }
}
