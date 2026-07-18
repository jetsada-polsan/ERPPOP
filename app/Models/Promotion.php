<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'name', 'promotion_type', 'starts_at', 'ends_at', 'min_qty', 'min_amount',
    'discount_amount', 'discount_percent', 'product_id', 'note', 'is_active',
])]
class Promotion extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'min_qty' => 'decimal:4',
            'min_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
