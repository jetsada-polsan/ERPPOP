<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class StockBalanceReport
{
    public function run(array $filters): array
    {
        // A product with no movement still has a zero balance at a real location.
        // Keep the product catalogue on the left; never create stock rows to report zeros.
        $query = DB::table('products as p')
            ->crossJoin('warehouse_locations as wl')
            ->join('warehouses as w', 'w.id', '=', 'wl.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', DB::raw(
                'coalesce((select min(owner.id) from branches owner where owner.default_warehouse_location_id = wl.id and owner.is_active = true), w.branch_id)'
            ))
            ->leftJoin('product_units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('stock_balances as sb', fn (JoinClause $join) => $join
                ->on('sb.product_id', '=', 'p.id')->on('sb.warehouse_location_id', '=', 'wl.id'));

        if (! empty($filters['branch_id'])) {
            $query->where('b.id', $filters['branch_id']);
        }

        if ($filters['q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%';
            $query->where(function ($search) use ($term) {
                foreach (['p.sku_code', 'p.name_th', 'w.name', 'wl.name'] as $column) {
                    $search->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$term]);
                }
                // EXISTS avoids duplicating a balance when several barcodes match.
                $search->orWhereExists(fn ($barcodes) => $barcodes->selectRaw('1')
                    ->from('product_barcodes as pb')->whereColumn('pb.product_id', 'p.id')
                    ->whereRaw("LOWER(pb.barcode) LIKE ? ESCAPE '!'", [$term]));
            });
        }

        $query->orderBy('p.sku_code')->orderBy('p.id')->orderBy('wl.id')
            ->selectRaw("p.id as product_id, p.sku_code, p.name_th, coalesce(u.name, '-') as unit_name,
                concat(coalesce(b.name_th, w.name), ' / ', coalesce(wl.name, wl.code)) as location_name,
                coalesce(sb.on_hand_qty, 0) as on_hand_qty, coalesce(sb.reserved_qty, 0) as reserved_qty");

        $pagination = ($filters['paginate'] ?? false)
            ? $query->paginate($filters['per_page'], ['*'], 'page', $filters['page'])->withQueryString()
            : null;
        $rows = $pagination ? $pagination->getCollection() : $query->limit($filters['per_page'])->get();
        $barcodes = DB::table('product_barcodes')->whereIn('product_id', $rows->pluck('product_id'))
            ->orderBy('id')->get(['product_id', 'barcode'])->groupBy('product_id');
        foreach ($rows as $row) {
            $row->barcodes = ($barcodes->get($row->product_id) ?? collect())->pluck('barcode')->unique()->implode(', ');
        }

        return [
            'title' => 'สินค้าคงเหลือ',
            'columns' => [
                ['label' => 'รหัส', 'key' => 'sku_code'],
                ['label' => 'บาร์โค้ด', 'key' => 'barcodes'],
                ['label' => 'สินค้า', 'key' => 'name_th'],
                ['label' => 'หน่วย', 'key' => 'unit_name'],
                ['label' => 'คลัง/ที่เก็บ', 'key' => 'location_name'],
                ['label' => 'คงเหลือ', 'key' => 'on_hand_qty', 'type' => 'quantity', 'class' => 'text-end'],
                ['label' => 'จอง', 'key' => 'reserved_qty', 'type' => 'quantity', 'class' => 'text-end'],
            ],
            'rows' => $rows,
            'total' => $pagination?->total() ?? $rows->count(),
            'pagination' => $pagination,
        ];
    }
}
