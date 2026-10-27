<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en'])]
class ProductCategory extends Model
{
    public $timestamps = false;

    public function scopeForPos(Builder $query): Builder
    {
        return $query->where('name_th', '!=', 'สินค้ายกเลิก');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
