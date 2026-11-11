<?php

namespace App\Services\Sales;

use App\Models\PosPriceSchedule;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PosPriceScheduleService
{
    /**
     * Return one approved override per product/unit. A branch-specific row wins
     * over a company row, then the most recently effective row wins. Publishing
     * must reject overlapping rows; this ordering is a defensive last line.
     *
     * @param array<int, int> $productIds
     * @return Collection<string, PosPriceSchedule>
     */
    public function active(array $productIds, int $branchId, CarbonInterface $at): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return PosPriceSchedule::published()
            ->whereIn('product_id', $productIds)
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderByRaw('CASE WHEN branch_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn (PosPriceSchedule $row) => $row->product_id.':'.($row->unit_id ?? 'base'));
    }

    /** Rows a POS may need while offline: live plus future published schedules. */
    public function catalog(array $productIds, int $branchId, CarbonInterface $from): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return PosPriceSchedule::published()
            ->whereIn('product_id', $productIds)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * Publish a POS price schedule override.
     *
     * @param Product|int $product
     * @param array<string, mixed> $data
     */
    public function publish(Product|int $product, array $data, ?int $userId = null): PosPriceSchedule
    {
        $productId = $product instanceof \App\Models\Product ? $product->id : (int) $product;

        return PosPriceSchedule::create([
            'product_id' => $productId,
            'branch_id' => $data['branch_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'price' => $data['price'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'note' => $data['note'] ?? null,
            'status' => 'published',
            'created_by' => $userId,
            'published_by' => $userId,
            'published_at' => now(),
        ]);
    }

    /**
     * Cancel an existing POS price schedule so it stops overriding prices.
     */
    public function cancel(PosPriceSchedule $schedule): void
    {
        $schedule->update(['status' => 'cancelled']);
    }
}
