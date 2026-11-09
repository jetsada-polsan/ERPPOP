<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only signed dataset. Never reconstruct historical costs from product masters. */
class DailySalesReport
{
    public function source($from, $to, array $filters): Collection
    {
        $query = DB::table('sales_postings as s')->leftJoin('documents as d', 'd.id', '=', 's.document_id')
            ->whereBetween('s.sale_date', [substr((string) $from, 0, 10), substr((string) $to, 0, 10)])
            ->where(fn ($q) => $q->whereNull('d.id')->orWhere(fn ($q) => $q->where('d.status', 'active')->whereNull('d.cancelled_at')));
        if (! empty($filters['branch_id'])) {
            $query->where('s.branch_id', $filters['branch_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $query->where(fn ($q) => $q->where('s.sale_number', 'like', '%'.$filters['q'].'%')->orWhere('s.channel', 'like', '%'.$filters['q'].'%'));
        }
        $sales = $query->get(['s.*']);
        $notes = DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['SALE_RETURN', 'CREDIT_NOTE', 'DEBIT_NOTE'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [substr((string) $from, 0, 10), substr((string) $to, 0, 10)])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q->where('d.doc_number', 'like', '%'.$filters['q'].'%')->orWhere('dt.code', 'like', '%'.$filters['q'].'%')))
            ->get(['d.id as document_id', 'd.doc_date as sale_date', 'd.doc_number as sale_number', 'd.branch_id', 'd.salesman_id', 'dt.code as channel', 'd.total_amount as net_sales', 'd.vat_amount']);
        $rows = $sales->concat($notes);
        $costs = DB::table('stock_documents as sd')->join('stock_document_items as i', 'i.stock_document_id', '=', 'sd.id')
            ->whereIn('sd.document_id', $rows->pluck('document_id')->filter()->unique())
            ->get(['sd.document_id', 'i.cost_amount'])->groupBy('document_id');

        return $rows->map(function ($row) use ($costs) {
            $sign = in_array($row->channel, ['SALE_RETURN', 'CREDIT_NOTE'], true) ? -1 : 1;
            $row->net_sales = DecimalMath::multiply($row->net_sales, $sign, 2);
            $row->vat_amount = $row->vat_amount === null ? null : DecimalMath::multiply($row->vat_amount, $sign, 2);
            $row->revenue = $row->vat_amount === null ? null : DecimalMath::subtract($row->net_sales, $row->vat_amount, 2);
            $items = $costs->get($row->document_id);
            // Notes without stock movement change revenue only. Sales/returns need a cost snapshot.
            $financialNote = in_array($row->channel, ['CREDIT_NOTE', 'DEBIT_NOTE'], true);
            $row->cogs_amount = $items && ! $items->contains(fn ($i) => $i->cost_amount === null)
                ? DecimalMath::multiply(DecimalMath::sum($items->pluck('cost_amount')), $sign, 2)
                : ($financialNote ? '0.00' : null);
            $row->gross_profit = $row->cogs_amount === null || $row->revenue === null ? null : DecimalMath::subtract($row->revenue, $row->cogs_amount, 2);

            return $row;
        });
    }

    public function run($from, $to, array $filters): array
    {
        $source = $this->source($from, $to, $filters);
        if (isset($filters['channel'])) {
            $source = $source->where('channel', $filters['channel'])->values();
        }
        $rows = $source->groupBy(fn ($r) => $r->sale_date.'|'.$r->channel)->map(function ($group) {
            $first = $group->first();
            $missing = $group->whereNull('gross_profit')->count();

            return (object) [
                'sale_date' => $first->sale_date, 'channel' => $first->channel,
                'channel_name' => ['CASH_SALE' => 'ขายสดหลังบ้าน', 'CREDIT_SALE' => 'ขายเชื่อ'][$first->channel] ?? $first->channel,
                'bill_count' => $group->count(), 'net_sales' => DecimalMath::sum($group->pluck('net_sales'), 2),
                'amount' => DecimalMath::sum($group->pluck('net_sales'), 2),
                'vat_amount' => $group->contains(fn ($r) => $r->vat_amount === null) ? null : DecimalMath::sum($group->pluck('vat_amount'), 2),
                'cogs_amount' => $missing ? null : DecimalMath::sum($group->pluck('cogs_amount'), 2),
                'gross_profit' => $missing ? null : DecimalMath::sum($group->pluck('gross_profit'), 2),
                'missing_cost_count' => $missing,
            ];
        })->sortBy(fn ($r) => $r->sale_date.'|'.$r->channel)->values();
        $summary = ['net_sales' => DecimalMath::sum($source->pluck('net_sales'), 2), 'vat_amount' => DecimalMath::sum($source->pluck('vat_amount'), 2)];
        $total = $rows->count();
        $pagination = null;
        if ($filters['paginate'] ?? false) {
            $pagination = new LengthAwarePaginator($rows->forPage($filters['page'], $filters['per_page'])->values(), $total, $filters['per_page'], $filters['page'], ['path' => request()->url(), 'query' => request()->query()]);
            $rows = $pagination->getCollection();
        } else {
            $rows = $rows->take($filters['per_page']);
        }

        return compact('rows', 'summary', 'total', 'pagination');
    }
}
