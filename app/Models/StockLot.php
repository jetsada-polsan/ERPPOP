<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'warehouse_location_id', 'source_document_id', 'lot_number',
    'received_date', 'expiry_date', 'initial_qty', 'remaining_qty', 'unit_cost',
])]
class StockLot extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'expiry_date' => 'date',
            'initial_qty' => 'decimal:4',
            'remaining_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function warehouseLocation(): BelongsTo { return $this->belongsTo(WarehouseLocation::class); }
    public function sourceDocument(): BelongsTo { return $this->belongsTo(Document::class, 'source_document_id'); }
    public function movements(): HasMany { return $this->hasMany(StockMovement::class); }
}
