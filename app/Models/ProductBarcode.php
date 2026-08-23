<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'barcode', 'barcode_type', 'type_note', 'unit_id', 'unit_factor', 'price', 'is_active'])]
class ProductBarcode extends Model
{
    public $timestamps = false;

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
        return [
            'unit_factor' => 'decimal:8',
            'price' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }
}
