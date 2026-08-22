<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ยอดเจ้าหนี้ค้างชำระรายใบ — คู่ขนานกับ CustomerOpenItem ของฝั่งลูกหนี้
 * AP aging ต้องอ่านจากที่นี่ ไม่ใช่จาก supplier_ledger ซึ่งเป็นสมุดเดินบัญชี
 */
class SupplierOpenItem extends Model
{
    protected $fillable = [
        'supplier_id', 'source_document_id', 'document_no', 'document_date', 'due_date',
        'original_amount', 'paid_amount', 'balance_amount', 'status', 'payment_terms', 'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'due_date' => 'date',
            'cleared_at' => 'datetime',
            'original_amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'balance_amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}
