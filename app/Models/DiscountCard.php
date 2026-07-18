<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'card_code', 'name', 'member_id', 'discount_type', 'discount_value',
    'min_amount', 'max_discount_amount', 'starts_date', 'ends_date',
    'usage_limit', 'used_count', 'is_active', 'note',
])]
class DiscountCard extends Model
{
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isValidAt(Carbon $now): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_date && $now->toDateString() < $this->starts_date->toDateString()) {
            return false;
        }

        if ($this->ends_date && $now->toDateString() > $this->ends_date->toDateString()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    // Returns the baht discount for a given bill subtotal, or null if the
    // card doesn't apply (inactive/expired/below minimum bill amount).
    public function computeDiscount(float $subtotal): ?float
    {
        if (! $this->isValidAt(now())) {
            return null;
        }

        if ($this->min_amount && $subtotal < (float) $this->min_amount) {
            return null;
        }

        $discount = $this->discount_type === 'percent'
            ? $subtotal * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        if ($this->max_discount_amount) {
            $discount = min($discount, (float) $this->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'min_amount' => 'decimal:4',
            'max_discount_amount' => 'decimal:4',
            'starts_date' => 'date',
            'ends_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
