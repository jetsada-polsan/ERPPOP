<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'price_table_id',
    'unit_id',
    'new_price',
    'old_price',
    'effective_at',
    'status',
    'pos_price_schedule_id',
    'batch_ref',
    'note',
    'created_by',
    'applied_at',
    'error',
])]
class ScheduledPriceChange extends Model
{
    protected function casts(): array
    {
        return [
            'new_price' => 'decimal:4',
            'old_price' => 'decimal:4',
            'effective_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTable(): BelongsTo
    {
        return $this->belongsTo(PriceTable::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    public function posPriceSchedule(): BelongsTo
    {
        return $this->belongsTo(PosPriceSchedule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeDue(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query->where('status', 'pending')
            ->where('effective_at', '<=', $at ?? now());
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', 'applied');
    }
}
