<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'doc_number', 'branch_id', 'supplier_id', 'doc_date', 'need_by_date',
    'status', 'total_amount', 'is_credit', 'requested_by', 'approved_by',
    'approved_at', 'received_document_id', 'note',
])]
class PurchaseOrder extends Model
{
    public const STATUS_LABELS = [
        'requested' => 'ขอซื้อ (รออนุมัติ)',
        'approved' => 'อนุมัติแล้ว',
        'ordered' => 'สั่งซื้อแล้ว',
        'partially_received' => 'รับของบางส่วน',
        'received' => 'รับของแล้ว',
        'cancelled' => 'ยกเลิก',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receivedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'received_document_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'need_by_date' => 'date',
            'approved_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_credit' => 'boolean',
        ];
    }
}
