<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pos_receipt_id', 'seq', 'product_id', 'qty', 'unit_price',
    'discount_amount', 'vat_amount', 'net_amount',
])]
class PosReceiptItem extends Model
{
    public $timestamps = false;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'pos_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
        ];
    }
}
