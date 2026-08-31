<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_id', 'supplier_product_code', 'supplier_product_name', 'product_id', 'unit_id', 'conversion_rate', 'last_used_at',
])]
class SupplierProductMapping extends Model
{
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    protected function casts(): array
    {
        return ['conversion_rate' => 'decimal:8', 'last_used_at' => 'datetime'];
    }
}
