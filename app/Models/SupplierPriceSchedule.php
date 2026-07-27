<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'supplier_id', 'unit_id', 'minimum_qty', 'unit_price', 'vat_mode',
    'effective_from', 'effective_to', 'is_active', 'note',
])]
class SupplierPriceSchedule extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    public function scopeEffective(Builder $query, string $date, mixed $quantity = 1): Builder
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $dates) => $dates->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where('minimum_qty', '<=', $quantity);
    }

    protected function casts(): array
    {
        return [
            'minimum_qty' => 'decimal:8',
            'unit_price' => 'decimal:8',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
