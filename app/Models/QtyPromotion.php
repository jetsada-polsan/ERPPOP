<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'name', 'promo_type', 'product_id', 'min_qty', 'free_product_id',
    'free_qty', 'discount_type', 'discount_value', 'branch_id',
    'starts_date', 'ends_date', 'note', 'is_active',
])]
class QtyPromotion extends Model
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function freeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'free_product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeRunningToday(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_date')->orWhere('starts_date', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_date')->orWhere('ends_date', '>=', $today));
    }

    // Human label like "ซื้อ 2 แถม 1" or "ซื้อ 2 ลด 50%"
    public function label(): string
    {
        $min = rtrim(rtrim(number_format((float) $this->min_qty, 2), '0'), '.');
        if ($this->promo_type === 'free_item') {
            $free = rtrim(rtrim(number_format((float) $this->free_qty, 2), '0'), '.');

            return "ซื้อ {$min} แถม {$free}";
        }

        $value = rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.');

        return 'ซื้อ ' . $min . ' ลด ' . $value . ($this->discount_type === 'percent' ? '%' : ' บาท');
    }

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'starts_date' => 'date',
            'ends_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
