<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentReport
{
    /** One row per money line; allocation and reconciliation rows never multiply money. */
    public function lines($from, $to, array $filters): Collection
    {
        $pos = DB::table('pos_payments as line')->join('pos_receipts as r', 'r.id', '=', 'line.pos_receipt_id')
            ->join('pos_terminals as t', 't.id', '=', 'r.pos_terminal_id')
            ->where('r.status', 'completed')->where('r.is_legacy_import', false)
            ->whereBetween('r.receipt_date', [$from, $to])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('t.branch_id', $filters['branch_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where('r.receipt_no', 'like', '%'.$filters['q'].'%'))
            ->selectRaw("line.id, 'pos_payment' as source_type, line.method, line.amount,
                exists(select 1 from bank_reconciliations br where br.source_type = 'pos_payment' and br.source_id = line.id and br.status = 'matched') as matched")->get();
        $backOffice = DB::table('payment_lines as line')->join('payment_documents as pd', 'pd.id', '=', 'line.payment_document_id')
            ->join('documents as d', 'd.id', '=', 'pd.document_id')
            ->where('pd.party_type', 'customer')->where('pd.status', 'active')->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [substr((string) $from, 0, 10), substr((string) $to, 0, 10)])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('pd.branch_id', $filters['branch_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where('d.doc_number', 'like', '%'.$filters['q'].'%'))
            ->selectRaw("line.id, 'payment_line' as source_type, line.method, line.amount,
                exists(select 1 from bank_reconciliations br where br.source_type = 'payment_line' and br.source_id = line.id and br.status = 'matched') as matched")->get();

        return $pos->concat($backOffice);
    }

    public function rows($from, $to, array $filters): Collection
    {
        $lines = $this->lines($from, $to, $filters);
        $aggregate = fn ($rows, $bucket) => $rows->groupBy('method')->map(fn ($group, $method) => (object) [
            'bucket' => $bucket, 'method' => $method, 'line_count' => $group->count(), 'amount' => DecimalMath::sum($group->pluck('amount'), 2),
        ])->values();
        $waiting = $lines->filter(fn ($line) => in_array($line->method, ['transfer', 'qr', 'bank'], true) && ! $line->matched);

        return $aggregate($lines, 'รับแล้ว')->concat($aggregate($waiting, 'รอพิสูจน์'));
    }
}
