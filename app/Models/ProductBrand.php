<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en'])]
class ProductBrand extends Model
{
    public $timestamps = false;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_brand_id');
    }
}
