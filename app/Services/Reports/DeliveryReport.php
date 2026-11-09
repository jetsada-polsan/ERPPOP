<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryReport
{
    /** One booking per row. Load items/payment lines are deliberately not joined. */
    public function rows($from, $to, array $filters): Collection
    {
        return DB::table('sale_bookings as sb')->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('transport_jobs as j', 'j.booking_id', '=', 'sb.id')
            ->where('sb.fulfillment_type', 'delivery')->where('sb.status', '!=', 'cancelled')
            ->whereNotIn('d.status', ['cancelled', 'void', 'rejected'])->whereNull('d.cancelled_at')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q->where('d.doc_number', 'like', '%'.$filters['q'].'%')->orWhere('c.name_th', 'like', '%'.$filters['q'].'%')))
            ->orderBy('d.doc_date')->orderBy('d.id')
            ->get(['d.doc_number', 'd.doc_date', 'c.name_th as customer_name', 'sb.delivery_due_at', 'sb.delivery_status', 'j.status as transport_status', 'j.loaded_at', 'j.delivered_at', 'j.paid_amount'])
            ->map(function ($row) {
                $reference = $row->delivered_at ? Carbon::parse($row->delivered_at) : now();
                $row->late_days = $row->delivery_due_at && $reference->gt(Carbon::parse($row->delivery_due_at))
                    ? (int) Carbon::parse($row->delivery_due_at)->diffInDays($reference) : 0;

                return $row;
            });
    }
}
