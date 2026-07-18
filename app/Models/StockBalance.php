<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'warehouse_location_id', 'on_hand_qty', 'reserved_qty'])]
class StockBalance extends Model
{
    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'decimal:4',
            'reserved_qty' => 'decimal:4',
        ];
    }
}
