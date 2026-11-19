<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MonthEndReceivables
{
    /** Replay dated BPlus evidence against invoice gross amounts, including invoices now paid. */
    public function report(object $area, Carbon $end): array
    {
        $date = $end->toDateString();
        $activeAtClose = static fn ($q) => $q->where(fn ($s) => $s->where('status', 'active')->whereNull('cancelled_at'))
            ->orWhere('cancelled_at', '>=', $end->copy()->addDay()->startOfDay());
        $datedDocs = DB::table('documents')->where('doc_date', '<=', $date)->where($activeAtClose);
        $items = DB::table('customer_open_items as oi')->joinSub(clone $datedDocs, 'd', 'd.id', '=', 'oi.document_id')
            ->where('d.branch_id', $area->branch_id)->where('d.sales_area_id', $area->id)->where('d.source_system', 'bplus')
            ->select('oi.id', 'oi.due_date', 'oi.gross_amount', 'd.doc_number', 'd.customer_id', 'd.id as invoice_id')
            ->selectSub(DB::table('payment_allocations as a')->join('payment_documents as p', 'p.id', '=', 'a.payment_document_id')
                ->joinSub(clone $datedDocs, 'receipt', 'receipt.id', '=', 'p.document_id')
                ->whereColumn('a.customer_open_item_id', 'oi.id')
                ->selectRaw('coalesce(sum(a.allocated_amount + a.discount_amount + a.wht_amount),0)'), 'paid')
            ->selectSub(DB::query()->fromSub(clone $datedDocs, 'n')->join('document_types as t', 't.id', '=', 'n.document_type_id')
                ->where('t.code', 'CREDIT_NOTE')->whereColumn('n.customer_id', 'd.customer_id')
                ->whereColumn('n.branch_id', 'd.branch_id')->whereColumn('n.sales_area_id', 'd.sales_area_id')
                ->where(fn ($q) => $q->whereColumn('n.source_document_id', 'd.id')->orWhere(fn ($ref) => $ref->whereNull('n.source_document_id')->whereColumn('n.reference', 'd.doc_number')))
                ->selectRaw('coalesce(sum(n.total_amount),0)'), 'credited')
            ->orderBy('d.doc_number')->get();
        $rows = collect();
        foreach ($items as $item) {
            $balance = DecimalMath::subtract(DecimalMath::subtract($item->gross_amount, $item->credited, 2), $item->paid, 2);
            if (DecimalMath::compare($balance, 0) <= 0) {
                continue;
            }
            $days = $item->due_date ? (int) Carbon::parse($item->due_date)->diffInDays($end->copy()->startOfDay(), false) : 0;
            $bucket = match (true) {
                $days <= 0 => 'ยังไม่ถึงกำหนด', $days <= 30 => '1–30 วัน', $days <= 60 => '31–60 วัน', $days <= 90 => '61–90 วัน', default => 'เกิน 90 วัน'
            };
            $rows->push(['doc_number' => $item->doc_number, 'due_date' => $item->due_date, 'bucket' => $bucket, 'amount' => $balance]);
        }

        return ['title' => 'อายุหนี้ ณ '.$date, 'rows' => $rows, 'columns' => [
            ['key' => 'doc_number', 'label' => 'เลขที่เอกสาร'], ['key' => 'due_date', 'label' => 'วันครบกำหนด'],
            ['key' => 'bucket', 'label' => 'ช่วงอายุหนี้'], ['key' => 'amount', 'label' => 'ยอดคงค้าง', 'type' => 'money'],
        ]];
    }
}
