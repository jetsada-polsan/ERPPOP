<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_document_id', 'customer_open_item_id', 'allocated_amount',
    'discount_amount', 'wht_amount',
])]
class PaymentAllocation extends Model
{
    public $timestamps = false;

    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class);
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(CustomerOpenItem::class, 'customer_open_item_id');
    }

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'wht_amount' => 'decimal:4',
        ];
    }
}
