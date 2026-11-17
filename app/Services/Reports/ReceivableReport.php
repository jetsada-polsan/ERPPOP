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
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'd.sales_area_id')
            ->whereIn('oi.status', ['open', 'partial'])
            ->where('d.status', 'active')->whereNull('d.cancelled_at')
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('d.branch_id', $filters['branch_id']))
            ->when(! empty($filters['sales_area_id']), fn ($q) => $q->where('d.sales_area_id', $filters['sales_area_id']))
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q->where('c.name_th', 'like', '%'.$filters['q'].'%')->orWhere('c.code', 'like', '%'.$filters['q'].'%')->orWhere('d.doc_number', 'like', '%'.$filters['q'].'%')))
            ->get(['oi.due_date', 'oi.balance_amount', 'sa.code as area_code', 'sa.name as area_name']);
        $areas = $items->map(fn ($item) => trim(($item->area_code ?? '').' '.($item->area_name ?? '')) ?: 'ไม่ระบุสายขาย')->unique()->values();
        if ($areas->isEmpty()) {
            $areas = collect(['ไม่ระบุสายขาย']);
        }
        $buckets = [];
        foreach ($items as $item) {
            $days = $item->due_date ? (int) Carbon::parse($item->due_date)->startOfDay()->diffInDays(today(), false) : 0;
            $bucket = $days <= 0 ? 0 : ($days <= 30 ? 1 : ($days <= 60 ? 2 : ($days <= 90 ? 3 : 4)));
            $areaName = trim(($item->area_code ?? '').' '.($item->area_name ?? '')) ?: 'ไม่ระบุสายขาย';
            $buckets[$areaName][$bucket][] = $item->balance_amount;
        }

        return $areas->flatMap(fn ($area) => collect(['ยังไม่ถึงกำหนด', '1-30 วัน', '31-60 วัน', '61-90 วัน', 'เกิน 90 วัน'])
            ->values()->map(fn ($label, $index) => (object) [
                'bucket' => $label, 'sales_area_name' => $area, 'amount' => DecimalMath::sum($buckets[$area][$index] ?? [], 2),
            ]))->values();
    }
}
