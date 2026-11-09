<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceivableReport
{
    /** Current open balances only: allocation history is not sufficient to reconstruct an arbitrary as-of. */
    public function aging(array $filters): Collection
    {
        $items = DB::table('customer_open_items as oi')->join('documents as d', 'd.id', '=', 'oi.document_id')
            ->join('customers as c', 'c.id', '=', 'oi.customer_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q->where('c.name_th', 'like', '%'.$filters['q'].'%')->orWhere('c.code', 'like', '%'.$filters['q'].'%')->orWhere('d.doc_number', 'like', '%'.$filters['q'].'%')))
            ->get(['oi.due_date', 'oi.balance_amount']);
        $buckets = array_fill(0, 5, []);
        foreach ($items as $item) {
            $days = $item->due_date ? (int) Carbon::parse($item->due_date)->startOfDay()->diffInDays(today(), false) : 0;
            $bucket = $days <= 0 ? 0 : ($days <= 30 ? 1 : ($days <= 60 ? 2 : ($days <= 90 ? 3 : 4)));
            $buckets[$bucket][] = $item->balance_amount;
        }

        return collect(['ยังไม่ถึงกำหนด', '1-30 วัน', '31-60 วัน', '61-90 วัน', 'เกิน 90 วัน'])->map(fn ($label, $index) => (object) ['bucket' => $label, 'amount' => DecimalMath::sum($buckets[$index], 2)]);
    }
}
