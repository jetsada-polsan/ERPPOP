<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['doc_number', 'branch_id', 'warehouse_location_id', 'status', 'count_mode', 'submitted_by', 'submitted_at', 'confirmed_by', 'confirmed_at', 'posted_document_id', 'note'])]
class StockCount extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function postedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'posted_document_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'counting';
    }

    protected function casts(): array
    {
        return ['submitted_at'=>'datetime', 'confirmed_at'=>'datetime'];
    }
}
