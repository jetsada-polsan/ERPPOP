<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'document_id', 'production_recipe_id', 'output_product_id', 'input_weight_qty',
    'output_weight_qty', 'loss_weight_qty', 'yield_percent', 'total_input_cost',
    'output_unit_cost', 'scale_plu', 'prepared_by',
    'selling_unit_price', 'net_selling_unit_price', 'estimated_profit_per_unit', 'estimated_margin_percent',
])]
class ProductionBatch extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProductionBatchPackage::class)->orderBy('seq');
    }

    protected function casts(): array
    {
        return [
            'input_weight_qty' => 'decimal:4', 'output_weight_qty' => 'decimal:4',
            'loss_weight_qty' => 'decimal:4', 'yield_percent' => 'decimal:4',
            'total_input_cost' => 'decimal:4', 'output_unit_cost' => 'decimal:4',
            'selling_unit_price' => 'decimal:4', 'net_selling_unit_price' => 'decimal:4',
            'estimated_profit_per_unit' => 'decimal:4', 'estimated_margin_percent' => 'decimal:4',
        ];
    }
}
