<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'doc_no', 'doc_date', 'production_recipe_id', 'finished_product_id', 'branch_id',
    'warehouse_location_id', 'planned_qty', 'produced_qty', 'status', 'note',
])]
class ProductionOrder extends Model
{
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'planned_qty' => 'decimal:4',
            'produced_qty' => 'decimal:4',
        ];
    }
}
