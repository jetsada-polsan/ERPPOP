<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['document_id', 'to_warehouse_location_id', 'total_qty', 'total_items', 'refer_reference', 'refer_person'])]
class StockDocument extends Model
{
    const UPDATED_AT = null;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function toWarehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_warehouse_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockDocumentItem::class);
    }

    protected function casts(): array
    {
        return ['total_qty' => 'decimal:4'];
    }
}
