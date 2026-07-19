<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportRun;
use App\Models\AppSetting;
use App\Models\BankStatement;
use App\Models\BranchExpense;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class MonthlyAccountingExportService
{
    public function create(string $period, ?int $branchId): AccountingExportRun
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Server ยังไม่ได้เปิด PHP Zip extension');
        }

        $from = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $scope = fn ($query, string $column) => $branchId ? $query->where($column, $branchId) : $query;

        $expenses = BranchExpense::with(['branch', 'expenseAccount', 'bankAccount', 'document'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('expense_date')->get();

        $sales = $this->salesVat($from, $to, $branchId);
        $purchases = $this->purchaseVat($from, $to, $branchId)->concat($expenses->where('vat_amount', '>', 0)->map(fn ($e) => [
            $e->expense_date->toDateString(), $e->tax_invoice_no, $e->supplier_name, $e->supplier_tax_id,
            $e->tax_branch, (float) $e->base_amount, (float) $e->vat_amount, (float) $e->total_amount, 'ค่าใช้จ่าย',
        ]));

        $statements = BankStatement::with(['bankAccount', 'reconciliation.checkedBy'])
            ->whereBetween('statement_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('branch_id', $branchId)))
            ->orderBy('statement_date')->get();

        $gl = DB::table('gl_journals as g')->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->leftJoin('documents as d', 'd.id', '=', 'g.document_id')
            ->whereBetween('g.entry_date', [$from->toDateString(), $to->toDateString()]);
        $scope($gl, 'd.branch_id');
        $glRows = $gl->orderBy('g.entry_date')->orderBy('g.id')->get(['g.entry_date', 'd.doc_number', 'a.code', 'a.name_th', 'g.debit', 'g.credit', 'g.remark']);

        $summary = [
            'sales_total' => round($sales->sum(fn ($r) => $r[7]), 2),
            'sales_vat' => round($sales->sum(fn ($r) => $r[6]), 2),
            'purchase_total' => round($purchases->sum(fn ($r) => $r[7]), 2),
            'purchase_vat' => round($purchases->sum(fn ($r) => $r[6]), 2),
            'expenses_total' => round($expenses->sum('total_amount'), 2),
            'withholding_tax' => round($expenses->sum('withholding_amount'), 2),
            'statement_count' => $statements->count(),
            'unreconciled_count' => $statements->filter(fn ($s) => $s->reconciliation?->status !== 'matched')->count(),
        ];

        $relative = 'accounting-exports/'.$period.'/POPSTAR-ACCOUNTING-'.$period.'-'.($branchId ?: 'ALL').'-'.now()->format('YmdHis').'.zip';
        Storage::disk('local')->makeDirectory(dirname($relative));
        $path = Storage::disk('local')->path($relative);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ไม่สามารถสร้างไฟล์ ZIP ได้');
        }

        $zip->addFromString('00_README.txt', $this->readme($period, $summary));
        $zip->addFromString('01_SUMMARY.csv', $this->csv(['รายการ', 'จำนวนเงิน'], collect([
            ['ยอดขายรวม', $summary['sales_total']], ['ภาษีขาย', $summary['sales_vat']], ['ยอดซื้อ/ค่าใช้จ่ายมี VAT', $summary['purchase_total']],
            ['ภาษีซื้อ', $summary['purchase_vat']], ['ค่าใช้จ่ายทั้งหมด', $summary['expenses_total']], ['ภาษีหัก ณ ที่จ่าย', $summary['withholding_tax']],
        ])));
        $zip->addFromString('02_BANK_RECONCILIATION.csv', $this->csv(['วันที่', 'ธนาคาร', 'เลขบัญชี', 'รายละเอียด', 'ยอด Statement', 'ยอดอ้างอิง', 'ผลต่าง', 'สถานะ', 'เลขอ้างอิง', 'ผู้ตรวจ'], $statements->map(fn ($s) => [
            $s->statement_date->toDateString(), $s->bankAccount->bank_name, $s->bankAccount->account_no, $s->description, $s->amount,
            $s->reconciliation?->expected_amount, $s->reconciliation?->difference_amount, $s->reconciliation?->status ?? 'pending',
            $s->reconciliation?->reference, $s->reconciliation?->checkedBy?->name,
        ])));
        $vatHeaders = ['วันที่', 'เลขที่เอกสาร', 'คู่ค้า', 'เลขผู้เสียภาษี', 'สาขาภาษี', 'มูลค่าก่อน VAT', 'VAT', 'รวม', 'ประเภท'];
        $zip->addFromString('03_SALES_VAT.csv', $this->csv($vatHeaders, $sales));
        $zip->addFromString('04_PURCHASE_VAT.csv', $this->csv($vatHeaders, $purchases));
        $zip->addFromString('05_EXPENSES.csv', $this->csv(['วันที่', 'สาขา', 'เลขใบสำคัญ', 'ผู้ขาย', 'เลขภาษี', 'เลขใบกำกับ', 'รายการ', 'บัญชีค่าใช้จ่าย', 'ก่อน VAT', 'VAT', 'WHT', 'รวม', 'วิธีจ่าย', 'อ้างอิง'], $expenses->map(fn ($e) => [
            $e->expense_date->toDateString(), $e->branch->code, $e->document->doc_number, $e->supplier_name, $e->supplier_tax_id,
            $e->tax_invoice_no, $e->description, $e->expenseAccount->code.' '.$e->expenseAccount->name_th, $e->base_amount,
            $e->vat_amount, $e->withholding_amount, $e->total_amount, $e->payment_method, $e->payment_reference,
        ])));
        $zip->addFromString('06_WITHHOLDING_TAX.csv', $this->csv(['วันที่', 'แบบ', 'ผู้ถูกหัก', 'เลขผู้เสียภาษี', 'ฐานภาษี', 'อัตรา', 'ภาษีหัก', 'เลขเอกสาร'], $expenses->where('withholding_amount', '>', 0)->map(fn ($e) => [
            $e->expense_date->toDateString(), $e->withholding_form, $e->supplier_name, $e->supplier_tax_id, $e->base_amount,
            $e->withholding_rate, $e->withholding_amount, $e->document->doc_number,
        ])));
        $zip->addFromString('07_GENERAL_LEDGER.csv', $this->csv(['วันที่', 'เลขเอกสาร', 'รหัสบัญชี', 'ชื่อบัญชี', 'เดบิต', 'เครดิต', 'คำอธิบาย'], $glRows->map(fn ($r) => (array) $r)));
        $zip->addFromString('manifest.json', json_encode(['company' => AppSetting::company('name_th'), 'period' => $period, 'branch_id' => $branchId, 'generated_at' => now()->toIso8601String(), 'summary' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($expenses as $expense) {
            $this->addEvidence($zip, $expense->evidence_path, 'evidence/expenses/'.$expense->document->doc_number);
        }
        foreach ($statements as $statement) {
            $this->addEvidence($zip, $statement->reconciliation?->slip_path, 'evidence/slips/'.$statement->id);
        }
        $zip->close();

        return AccountingExportRun::create([
            'period' => $period, 'branch_id' => $branchId, 'file_name' => $relative,
            'file_hash' => hash_file('sha256', $path), 'file_size' => filesize($path), 'summary' => $summary,
            'exported_by' => auth()->id(), 'exported_at' => now(),
        ]);
    }

    private function salesVat(Carbon $from, Carbon $to, ?int $branchId): Collection
    {
        $docs = DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE', 'SALE_RETURN'])->whereNull('d.cancelled_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pos_receipts as pr')->whereColumn('pr.document_id', 'd.id'))
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->get(['d.doc_date', 'd.doc_number', 'dt.code', 'c.name_th', 'c.tax_id', 'd.total_amount'])->map(function ($r) {
                $sign = $r->code === 'SALE_RETURN' ? -1 : 1;
                $total = $sign * (float) $r->total_amount;
                $base = round($total * 100 / (100 + $this->vatRateAt($r->doc_date)), 2);

                return [$r->doc_date, $r->doc_number, $r->name_th ?: 'ลูกค้าทั่วไป', $r->tax_id, null, $base, round($total - $base, 2), $total, $r->code];
            });
        $pos = DB::table('pos_receipts as r')->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')->where('r.status', 'completed')
            ->whereBetween('r.receipt_date', [$from, $to])->when($branchId, fn ($q) => $q->where('t.branch_id', $branchId))->get(['r.receipt_date', 'r.receipt_no', 'r.net_sales', 'r.vat_amount'])
            ->map(fn ($r) => [substr($r->receipt_date, 0, 10), $r->receipt_no, 'ขายปลีก POS', null, null, round((float) $r->net_sales - (float) $r->vat_amount, 2), (float) $r->vat_amount, (float) $r->net_sales, 'POS']);

        return $docs->concat($pos)->sortBy(fn ($r) => $r[0].$r[1])->values();
    }

    private function purchaseVat(Carbon $from, Carbon $to, ?int $branchId): Collection
    {
        return DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->where('dt.code', 'PURCHASE')->whereNull('d.cancelled_at')->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))->orderBy('d.doc_date')
            ->get(['d.doc_date', 'd.doc_number', 'd.reference', 's.name_th', 's.tax_id', 's.tax_branch', 'd.total_amount'])
            ->map(function ($r) {
                $total = (float) $r->total_amount;
                $base = round($total * 100 / (100 + $this->vatRateAt($r->doc_date)), 2);

                return [$r->doc_date, $r->reference ?: $r->doc_number, $r->name_th, $r->tax_id, $r->tax_branch, $base, round($total - $base, 2), $total, 'ซื้อสินค้า'];
            });
    }

    private function vatRateAt(string $date): float
    {
        return (float) (DB::table('vat_rates')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->value('rate_percent') ?? 7);
    }

    private function csv(array $headers, Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_values((array) $row));
        }
        rewind($stream);

        return stream_get_contents($stream);
    }

    private function addEvidence(ZipArchive $zip, ?string $path, string $target): void
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $zip->addFile(Storage::disk('local')->path($path), $target.($extension ? '.'.$extension : ''));
    }

    private function readme(string $period, array $summary): string
    {
        return "POPSTAR MONTHLY ACCOUNTING PACK {$period}\n\nชุดนี้ใช้ส่งให้สำนักงานบัญชีเพื่อตรวจสอบและนำเข้าโปรแกรมบัญชี ไม่ใช่ไฟล์ยื่นกรมสรรพากรโดยตรง\nสำนักงานบัญชีต้องตรวจเลขผู้เสียภาษี เลขใบกำกับ VAT/WHT และยอดกระทบธนาคารก่อนใช้ RD Prep หรือ New e-Filing\n\nภาษีขาย: {$summary['sales_vat']}\nภาษีซื้อ: {$summary['purchase_vat']}\nStatement ยังไม่ตรง/ยังไม่ตรวจ: {$summary['unreconciled_count']} รายการ\n";
    }
}
