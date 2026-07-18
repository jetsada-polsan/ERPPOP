<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en'])]
class ProductDepartment extends Model
{
    public $timestamps = false;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_department_id');
    }
}
