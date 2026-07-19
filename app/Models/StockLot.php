<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'warehouse_location_id', 'source_document_id', 'source_lot_id', 'lot_number',
    'received_date', 'manufacture_date', 'expiry_date', 'initial_qty', 'remaining_qty', 'unit_cost',
    'quality_status', 'quality_reason', 'quality_updated_by', 'quality_updated_at',
])]
class StockLot extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
            'initial_qty' => 'decimal:4',
            'remaining_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'quality_updated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_lot_id');
    }

    public function qualityChecks(): HasMany
    {
        return $this->hasMany(StockLotQualityCheck::class);
    }

    public function recallCases(): HasMany
    {
        return $this->hasMany(RecallCase::class);
    }

    public function qualityUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quality_updated_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
