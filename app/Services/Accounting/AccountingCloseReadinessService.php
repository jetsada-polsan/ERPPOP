<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\BankStatement;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingCloseReadinessService
{
    /** @return array<int, array{code:string,label:string,status:string,detail:string}> */
    public function checks(AccountingPeriod $period): array
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();
        $branchId = $period->branch_id;

        $gl = DB::table('gl_journals as g')
            ->leftJoin('documents as d', 'd.id', '=', 'g.document_id')
            ->leftJoin('payment_documents as pd', 'pd.id', '=', 'g.payment_document_id')
            ->whereBetween('g.entry_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where(fn ($b) => $b->where('d.branch_id', $branchId)->orWhere('pd.branch_id', $branchId)));
        $debit = (float) (clone $gl)->sum('g.debit');
        $credit = (float) (clone $gl)->sum('g.credit');
        $difference = round($debit - $credit, 2);

        $postingCodes = ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN', 'PURCHASE', 'EXPENSE', 'CREDIT_NOTE', 'DEBIT_NOTE'];
        $unposted = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereBetween('d.doc_date', [$from, $to])
            ->whereIn('dt.code', $postingCodes)
            ->where('d.status', '!=', 'cancelled')
            ->where('d.total_amount', '>', 0)
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gl_journals as g')->whereColumn('g.document_id', 'd.id'))
            ->count();
        $unpostedPayments = DB::table('payment_documents as pd')->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->whereBetween('d.doc_date', [$from, $to])->where('pd.status', 'active')
            ->when($branchId, fn ($q) => $q->where('pd.branch_id', $branchId))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payment_lines as pl')->whereColumn('pl.payment_document_id', 'pd.id')->where('pl.amount', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('gl_journals as g')->whereColumn('g.payment_document_id', 'pd.id'))
            ->count();
        $unposted += $unpostedPayments;

        $requiredRoles = [ChartOfAccount::ROLE_CASH, ChartOfAccount::ROLE_BANK, ChartOfAccount::ROLE_AR, ChartOfAccount::ROLE_AP, ChartOfAccount::ROLE_INVENTORY, ChartOfAccount::ROLE_VAT_INPUT, ChartOfAccount::ROLE_VAT_OUTPUT, ChartOfAccount::ROLE_SALES_REVENUE, ChartOfAccount::ROLE_SALES_RETURN, ChartOfAccount::ROLE_COGS, ChartOfAccount::ROLE_EXPENSE, ChartOfAccount::ROLE_WHT_PAYABLE];
        $configuredRoles = ChartOfAccount::whereIn('default_role', $requiredRoles)->pluck('default_role')->all();
        $missingRoles = array_values(array_diff($requiredRoles, $configuredRoles));

        $unreconciled = BankStatement::query()->whereBetween('statement_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('branch_id', $branchId)))
            ->whereDoesntHave('reconciliation', fn ($q) => $q->where('status', 'matched'))->count();

        $taxExceptions = DB::table('branch_expenses')->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where(fn ($q) => $q->where(fn ($v) => $v->where('vat_amount', '>', 0)->whereNull('tax_invoice_no'))
                ->orWhere(fn ($w) => $w->where('withholding_amount', '>', 0)->whereNull('withholding_form')))
            ->count();

        $latestBackup = collect(glob(storage_path('app/backups/erp-db-*.gz')) ?: [])->sortByDesc(fn ($file) => filemtime($file))->first();
        $backupAge = $latestBackup ? (time() - filemtime($latestBackup)) / 3600 : null;

        return [
            ['code' => 'gl_balance', 'label' => 'เดบิตเท่ากับเครดิต', 'status' => abs($difference) <= 0.01 ? 'pass' : 'block', 'detail' => 'เดบิต '.number_format($debit, 2).' เครดิต '.number_format($credit, 2).' ผลต่าง '.number_format($difference, 2)],
            ['code' => 'document_posting', 'label' => 'เอกสารลง GL ครบ', 'status' => $unposted === 0 ? 'pass' : 'block', 'detail' => $unposted === 0 ? 'ไม่พบเอกสารตกหล่น' : "มีเอกสารยังไม่ลง GL {$unposted} รายการ"],
            ['code' => 'account_mapping', 'label' => 'ผังบัญชีอัตโนมัติครบ', 'status' => $missingRoles === [] ? 'pass' : 'block', 'detail' => $missingRoles === [] ? 'ผูก default role ครบ' : 'ยังขาด '.implode(', ', $missingRoles)],
            ['code' => 'bank_reconciliation', 'label' => 'กระทบยอดธนาคารครบ', 'status' => $unreconciled === 0 ? 'pass' : 'block', 'detail' => $unreconciled === 0 ? 'Statement ผ่านการตรวจทั้งหมด' : "Statement ค้างตรวจ {$unreconciled} รายการ"],
            ['code' => 'tax_documents', 'label' => 'หลักฐานภาษีครบ', 'status' => $taxExceptions === 0 ? 'pass' : 'block', 'detail' => $taxExceptions === 0 ? 'ไม่พบรายการภาษีขาดข้อมูลบังคับ' : "พบรายการภาษีขาดข้อมูล {$taxExceptions} รายการ"],
            ['code' => 'backup', 'label' => 'Backup ล่าสุด', 'status' => $backupAge !== null && $backupAge <= 26 ? 'pass' : 'block', 'detail' => $backupAge === null ? 'ยังไม่มี Backup' : number_format($backupAge, 1).' ชั่วโมงที่แล้ว'],
        ];
    }

    public function assertReady(AccountingPeriod $period): void
    {
        $blocked = collect($this->checks($period))->where('status', 'block');
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'close' => 'ยังปิดงวดไม่ได้: '.$blocked->pluck('detail')->implode(' | '),
            ]);
        }
    }
}
