<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['flash_sale_id', 'product_id', 'flash_price', 'max_qty_per_bill'])]
class FlashSaleItem extends Model
{
    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'flash_price' => 'decimal:4',
            'max_qty_per_bill' => 'decimal:4',
        ];
    }
}
