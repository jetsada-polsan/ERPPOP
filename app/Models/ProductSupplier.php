<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'supplier_id', 'supplier_sku', 'last_purchase_price',
    'minimum_order_qty', 'lead_time_days', 'is_primary', 'note',
])]
class ProductSupplier extends Model
{
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'last_purchase_price' => 'decimal:4', 'minimum_order_qty' => 'decimal:4'];
    }
}
