<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'branch_id', 'unit_id', 'price', 'effective_from', 'effective_to',
    'status', 'note', 'created_by', 'published_by', 'published_at',
])]
class PosPriceSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function unit(): BelongsTo { return $this->belongsTo(ProductUnit::class); }

    /** Schedules are server-approved before they are allowed into a POS catalog. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
