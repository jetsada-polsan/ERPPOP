<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['production_batch_id', 'seq', 'weight_qty', 'unit_price', 'total_price', 'barcode', 'printed_at'])]
class ProductionBatchPackage extends Model
{
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    protected function casts(): array
    {
        return [
            'weight_qty' => 'decimal:4', 'unit_price' => 'decimal:4',
            'total_price' => 'decimal:4', 'printed_at' => 'datetime',
        ];
    }
}
