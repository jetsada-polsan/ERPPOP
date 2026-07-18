<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'doc_number', 'customer_id', 'branch_id', 'doc_date', 'due_date',
    'total_amount', 'status', 'note',
])]
class BillingNote extends Model
{
    public const STATUS_LABELS = [
        'open' => 'รอเก็บเงิน',
        'collected' => 'เก็บเงินแล้ว',
        'cancelled' => 'ยกเลิก',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingNoteItem::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }
}
