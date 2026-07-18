<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_th', 'name_en', 'affects_stock', 'affects_ar', 'affects_ap', 'is_active'])]
class DocumentType extends Model
{
    public $timestamps = false;

    public const BOOKING = 'BOOKING';

    public const CREDIT_SALE = 'CREDIT_SALE';

    public const CASH_SALE = 'CASH_SALE';

    protected function casts(): array
    {
        return [
            'affects_stock' => 'boolean',
            'affects_ar' => 'boolean',
            'affects_ap' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
