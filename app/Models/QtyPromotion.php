<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'name', 'promo_type', 'product_id', 'min_qty', 'min_amount', 'free_product_id',
    'free_qty', 'discount_type', 'discount_value', 'max_discount', 'bundle_price', 'branch_id',
    'starts_date', 'ends_date', 'starts_at', 'ends_at', 'note', 'is_active',
])]
class QtyPromotion extends Model
{
    /** โปรต่อสินค้า (ดูจำนวนที่ซื้อ) กับโปรต่อบิล (ดูยอดซื้อทั้งบิล) */
    public const BILL_TYPES = ['bill_discount', 'bill_free_item'];

    public function isBillLevel(): bool
    {
        return in_array($this->promo_type, self::BILL_TYPES, true);
    }

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
        return $this->scopeNotEnded($query)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()));
    }

    /** รวมโปรที่ยังไม่เริ่ม ให้เครื่อง POS เก็บไว้ใช้ตอนออฟไลน์เมื่อถึงเวลา */
    public function scopeNotEnded(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** เก็บวันที่ไว้คู่กับเวลา POS รุ่นเก่าและรายงานเดิมยังอ่านวันที่ */
    protected static function booted(): void
    {
        static::saving(function (self $promotion) {
            if ($promotion->starts_at) {
                $promotion->starts_date = $promotion->starts_at->toDateString();
            } elseif ($promotion->starts_date) {
                $promotion->starts_at = $promotion->starts_date->copy()->startOfDay();
            }
            if ($promotion->ends_at) {
                $promotion->ends_date = $promotion->ends_at->toDateString();
            } elseif ($promotion->ends_date) {
                $promotion->ends_at = $promotion->ends_date->copy()->endOfDay();
            }
        });
    }

    // Human label like "ซื้อ 2 แถม 1" or "ซื้อ 2 ลด 50%"
    public function label(): string
    {
        $min = rtrim(rtrim(number_format((float) $this->min_qty, 2), '0'), '.');
        $amount = number_format((float) $this->min_amount);
        if ($this->promo_type === 'bill_free_item') {
            $free = rtrim(rtrim(number_format((float) $this->free_qty, 2), '0'), '.');

            return "ยอดครบ {$amount} บาท แถม {$free}";
        }
        if ($this->promo_type === 'bill_discount') {
            $value = rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.');

            return "ยอดครบ {$amount} บาท ลด {$value}".($this->discount_type === 'percent' ? '%' : ' บาท');
        }
        if ($this->promo_type === 'free_item') {
            $free = rtrim(rtrim(number_format((float) $this->free_qty, 2), '0'), '.');

            return "ซื้อ {$min} แถม {$free}";
        }

        $value = rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.');

        if ($this->promo_type === 'bundle_price') {
            $value = rtrim(rtrim(number_format((float) $this->bundle_price, 2), '0'), '.');

            return "ซื้อ {$min} ชิ้น {$value} บาท";
        }

        return 'ซื้อ '.$min.' ลด '.$value.($this->discount_type === 'percent' ? '%' : ' บาท');
    }

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:8',
            'free_qty' => 'decimal:8',
            'discount_value' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'bundle_price' => 'decimal:4',
            'starts_date' => 'date',
            'ends_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
