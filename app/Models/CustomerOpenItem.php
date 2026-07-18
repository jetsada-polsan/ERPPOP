<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id', 'document_id', 'salesman_id', 'gross_amount', 'vat_amount', 'discount_amount',
    'net_amount', 'paid_amount', 'balance_amount', 'gps_lat', 'gps_lng', 'due_date', 'status',
])]
class CustomerOpenItem extends Model
{
    const UPDATED_AT = null;

    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'balance_amount' => 'decimal:4',
            'due_date' => 'date',
        ];
    }
}
