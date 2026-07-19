<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period', 'product_id', 'opening_qty', 'received_qty', 'issued_qty', 'ending_qty', 'average_cost', 'ending_value', 'closed_by', 'closed_at'])]
class InventoryCostClose extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'opening_qty' => 'decimal:4', 'received_qty' => 'decimal:4', 'issued_qty' => 'decimal:4',
            'ending_qty' => 'decimal:4', 'average_cost' => 'decimal:4', 'ending_value' => 'decimal:4',
            'closed_at' => 'datetime',
        ];
    }
}
