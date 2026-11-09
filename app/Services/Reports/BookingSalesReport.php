<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BookingSalesReport
{
    public function rows($from, $to, array $filters): Collection
    {
        $bookings = DB::table('sale_bookings as sb')->join('documents as d', 'd.id', '=', 'sb.document_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->whereNull('d.cancelled_at')->whereNotIn('d.status', ['cancelled', 'void', 'rejected'])
            ->where('sb.status', '!=', 'cancelled')
            ->whereBetween('d.doc_date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->orderByDesc('d.doc_date')->orderByDesc('d.id')
            ->get(['d.id', 'd.doc_number as booking_no', 'd.doc_date', 'd.total_amount as booking_amount', 'sb.status', 'sb.confirmed_document_id', 'c.name_th as customer_name', 'sm.name as salesman_name']);
        $sales = DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->leftJoin('document_books as b', 'b.id', '=', 'd.document_book_id')
            ->where('dt.code', 'CREDIT_SALE')->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->where(fn ($q) => $q->whereIn('d.source_document_id', $bookings->pluck('id'))->orWhereIn('d.id', $bookings->pluck('confirmed_document_id')->filter()))
            ->get(['d.id', 'd.source_document_id', 'd.doc_number', 'd.total_amount', 'd.vat_amount', 'b.code as book_code', 'b.no_vat']);

        return $bookings->map(function ($row) use ($sales) {
            $linked = $row->status === 'converted_to_sale'
                ? $sales->filter(fn ($d) => $d->source_document_id == $row->id || ($d->source_document_id === null && $d->id == $row->confirmed_document_id))->unique('id') : collect();
            $a = $linked->where('book_code', 'DS');
            $b = $linked->where('book_code', 'DSN');
            $row->sale_doc_no = $linked->pluck('doc_number')->implode(', ');
            $row->ds_amount = DecimalMath::sum($a->pluck('total_amount'), 2);
            $row->dsn_amount = DecimalMath::sum($b->pluck('total_amount'), 2);
            $row->unclassified_amount = DecimalMath::sum($linked->whereNotIn('book_code', ['DS', 'DSN'])->pluck('total_amount'), 2);
            $row->vat_amount = DecimalMath::sum($linked->pluck('vat_amount'), 2);
            $row->total_amount = DecimalMath::sum($linked->pluck('total_amount'), 2);
            $row->variance = DecimalMath::subtract($row->booking_amount, $row->total_amount, 2);
            unset($row->id, $row->confirmed_document_id);

            return $row;
        })->filter(function ($row) use ($filters) {
            $term = mb_strtolower($filters['q'] ?? '');

            return $term === '' || str_contains(mb_strtolower(implode(' ', [$row->booking_no, $row->sale_doc_no, $row->customer_name, $row->salesman_name])), $term);
        })->values();
    }
}
