<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'finished_product_id', 'output_qty', 'note', 'is_active'])]
class ProductionRecipe extends Model
{
    public function finishedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'finished_product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionRecipeItem::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    protected function casts(): array
    {
        return [
            'output_qty' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
