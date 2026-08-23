<?php

namespace App\Console\Commands;

use App\Models\ChartOfAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * กระทบยอดหลังยิงงานพร้อมกัน — พิสูจน์ว่าสต๊อก ต้นทุน บัญชี และรายงานตรงกัน
 *
 * ยิงพร้อมกันแล้วไม่ error ยังไม่พอ ต้องพิสูจน์ด้วยว่าไม่มีอะไรถูกนับซ้ำหรือหายไป
 * ทุกเกณฑ์ต้องขึ้น "ผ่าน" ถ้ามีตัวไหนไม่ผ่าน exit code จะไม่เป็นศูนย์
 */
class UatReconcile extends Command
{
    protected $signature = 'uat:reconcile {--branch= : จำกัดเฉพาะสาขาทดสอบ}';

    protected $description = 'UAT reconciliation: stock, COGS, GL and sales_postings must agree';

    public function handle(): int
    {
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $checks = [];

        // 1. เอกสารขายทุกใบต้องมี GL และ debit ต้องเท่ากับ credit
        $unbalanced = DB::table('gl_journals')
            ->selectRaw('document_id, sum(debit) as d, sum(credit) as c')
            ->groupBy('document_id')
            ->havingRaw('round(sum(debit)::numeric, 2) <> round(sum(credit)::numeric, 2)')
            ->count();
        $checks[] = ['GL ดุลทุกเอกสาร', $unbalanced === 0, $unbalanced.' เอกสารไม่ดุล'];

        $salesWithoutGl = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('d.status', 'active')
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gl_journals as g')->whereColumn('g.document_id', 'd.id'))
            ->count();
        $checks[] = ['เอกสารขายลง GL ครบ', $salesWithoutGl === 0, $salesWithoutGl.' ใบไม่มี GL'];

        // 2. เลขที่เอกสารห้ามซ้ำ
        $duplicateNumbers = DB::table('documents')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('branch_id, doc_number')
            ->groupBy('branch_id', 'doc_number')
            ->havingRaw('count(*) > 1')
            ->count();
        $checks[] = ['เลขที่เอกสารไม่ซ้ำ', $duplicateNumbers === 0, $duplicateNumbers.' เลขซ้ำ'];

        // 3. หนึ่งเอกสารขาย = หนึ่งแถวใน sales_postings ห้ามนับซ้ำ
        $postingDuplicates = DB::table('sales_postings')
            ->whereNotNull('document_id')
            ->selectRaw('document_id')
            ->groupBy('document_id')
            ->havingRaw('count(*) > 1')
            ->count();
        $checks[] = ['sales_postings ไม่นับซ้ำ', $postingDuplicates === 0, $postingDuplicates.' เอกสารซ้ำ'];

        // 4. ต้นทุนขายใน GL ต้องเท่ากับต้นทุนที่บันทึกบนบรรทัดเอกสาร
        $cogsAccount = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_COGS)->value('id');
        $glCogs = (float) DB::table('gl_journals')->where('account_id', $cogsAccount)->sum('debit');
        $lineCogs = (float) DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->sum('sdi.cost_amount');
        $checks[] = [
            'ต้นทุนขาย GL = ต้นทุนบนบรรทัด',
            abs($glCogs - $lineCogs) < 0.01,
            sprintf('GL %s vs บรรทัด %s (ต่าง %s)', number_format($glCogs, 2), number_format($lineCogs, 2), number_format($glCogs - $lineCogs, 2)),
        ];

        // 5. ยอดขายใน sales_postings ต้องเท่ากับรายได้ + ภาษีขายใน GL
        $revenue = $this->ledgerCredit(ChartOfAccount::ROLE_SALES_REVENUE);
        $vat = $this->ledgerCredit(ChartOfAccount::ROLE_VAT_OUTPUT);
        $posted = (float) DB::table('sales_postings')->sum('net_sales');
        $checks[] = [
            'sales_postings = รายได้ + VAT',
            abs($posted - $revenue - $vat) < 0.01,
            sprintf('ขาย %s vs รายได้ %s + VAT %s (ต่าง %s)',
                number_format($posted, 2), number_format($revenue, 2), number_format($vat, 2),
                number_format($posted - $revenue - $vat, 2)),
        ];

        // 6. สต๊อกที่ขายไปต้องเท่ากับที่หายจากยอดคงเหลือ
        $soldQty = (float) DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->join('documents as d', 'd.id', '=', 'sd.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->sum('sdi.qty');
        $movementQty = (float) DB::table('stock_movements as m')
            ->join('documents as d', 'd.id', '=', 'm.document_id')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->sum('m.qty');
        $checks[] = [
            'สต๊อกที่ตัด = บรรทัดที่ขาย',
            abs($soldQty - $movementQty) < 0.0001,
            sprintf('ขาย %s vs เคลื่อนไหว %s', $soldQty, $movementQty),
        ];

        $rows = array_map(fn (array $check) => [$check[0], $check[1] ? 'ผ่าน' : 'ไม่ผ่าน', $check[2]], $checks);
        $this->table(['เกณฑ์', 'ผล', 'รายละเอียด'], $rows);

        $failed = count(array_filter($checks, fn (array $check) => ! $check[1]));
        $this->line($failed === 0 ? '<info>กระทบยอดผ่านทุกเกณฑ์</info>' : "<error>ไม่ผ่าน {$failed} เกณฑ์</error>");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function ledgerCredit(string $role): float
    {
        return (float) DB::table('gl_journals')
            ->where('account_id', ChartOfAccount::where('default_role', $role)->value('id'))
            ->sum('credit');
    }
}
