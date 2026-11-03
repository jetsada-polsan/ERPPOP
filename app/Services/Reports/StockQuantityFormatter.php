<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockQuantityFormatter
{
    /**
     * Stock is stored in the product base unit.  Present it with the largest
     * active selling pack first so warehouse reports remain countable.
     */
    public function format(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $unitOptions = DB::table('product_barcodes as pb')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'pb.unit_id')
            ->whereIn('pb.product_id', $rows->pluck('product_id')->filter()->unique())
            ->where('pb.is_active', true)
            ->where('pb.unit_factor', '>', 1)
            ->orderByDesc('pb.unit_factor')
            ->get(['pb.product_id', 'pb.unit_factor', 'pu.name as large_unit_name'])
            ->groupBy('product_id');

        return $rows->each(function ($row) use ($unitOptions): void {
            $quantity = (float) ($row->on_hand_qty ?? 0);
            $option = $unitOptions->get($row->product_id)?->first();
            $smallLabel = $row->unit_name ?: 'หน่วยย่อย';

            if (! $option) {
                $row->quantity_display = $this->number($quantity).' '.$smallLabel;

                return;
            }

            $factor = (float) $option->unit_factor;
            $largeQuantity = $quantity >= 0 ? floor($quantity / $factor) : ceil($quantity / $factor);
            $smallQuantity = $quantity - ($largeQuantity * $factor);
            $largeLabel = $option->large_unit_name ?: 'หน่วยหลัก';
            $row->quantity_display = $this->number($largeQuantity).' '.$largeLabel
                .' '.$this->number($smallQuantity).' '.$smallLabel;
        });
    }

    private function number(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
    }
}
