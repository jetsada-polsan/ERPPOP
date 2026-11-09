<?php

namespace App\Services\Reports;

use App\Support\DecimalMath;
use Brick\Math\RoundingMode;
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
            ->where('pu.qty_per_base_unit', '>', 1)
            ->orderByDesc('pu.qty_per_base_unit')->orderBy('pb.id')
            ->get(['pb.product_id', 'pu.qty_per_base_unit as unit_factor', 'pu.name as large_unit_name'])
            ->groupBy('product_id');

        return $rows->each(function ($row) use ($unitOptions): void {
            $quantity = DecimalMath::of($row->on_hand_qty ?? 0);
            $option = $unitOptions->get($row->product_id)?->first();
            $smallLabel = $row->unit_name ?: 'หน่วยย่อย';

            if (! $option) {
                $row->quantity_display = $this->number($quantity).' '.$smallLabel;

                return;
            }

            $factor = DecimalMath::of($option->unit_factor);
            $largeQuantity = $quantity->dividedBy($factor, 0, RoundingMode::Down);
            $smallQuantity = $quantity->minus($largeQuantity->multipliedBy($factor));
            $largeLabel = trim(preg_replace('/\s*[x*×]\s*\d+(?:\.\d+)?$/u', '', (string) $option->large_unit_name)) ?: 'หน่วยหลัก';
            $row->quantity_display = $this->number($largeQuantity).' '.$largeLabel
                .' '.$this->number($smallQuantity).' '.$smallLabel;
        });
    }

    private function number($quantity): string
    {
        return rtrim(rtrim(DecimalMath::round($quantity, 8), '0'), '.');
    }
}
