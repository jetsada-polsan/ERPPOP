<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'doc_number', 'customer_id', 'customer_name', 'branch_id', 'salesman_id',
    'doc_date', 'valid_until', 'total_amount', 'status', 'converted_booking_id', 'note',
])]
class Quotation extends Model
{
    public const STATUS_LABELS = [
        'open' => 'รอลูกค้าตอบรับ',
        'accepted' => 'ตอบรับแล้ว',
        'expired' => 'หมดอายุ',
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

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function customerLabel(): string
    {
        return $this->customer?->name_th ?? $this->customer_name ?? 'ลูกค้าทั่วไป';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'valid_until' => 'date',
            'total_amount' => 'decimal:2',
        ];
    }
}
