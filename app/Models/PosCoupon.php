<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pos_receipt_id', 'coupon_code', 'amount'])]
class PosCoupon extends Model
{
    public $timestamps = false;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'pos_receipt_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
