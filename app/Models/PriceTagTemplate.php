<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name', 'price_source', 'price_table_id', 'is_active'])]
class PriceTagTemplate extends Model
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_PRICE_TABLE = 'price_table';

    public const SOURCE_FLASH_SALE = 'flash_sale';

    public const SOURCE_NO_PRICE = 'no_price';

    public function priceTable(): BelongsTo
    {
        return $this->belongsTo(PriceTable::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
