<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'batch_id', 'legacy_psh_key', 'pos_code', 'receipt_no', 'receipt_date', 'receipt_time',
    'cashier_code', 'member_code', 'gross_amount', 'discount_amount', 'vat_amount',
    'net_amount', 'item_count', 'raw_data', 'status', 'posted_pos_receipt_id',
])]
class ImportedReceipt extends Model
{
    const UPDATED_AT = null;

    // status: pending/valid/error/posted
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_ERROR = 'error';

    public const STATUS_POSTED = 'posted';

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportedReceiptItem::class, 'receipt_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ImportedPayment::class, 'receipt_id');
    }

    public function postedPosReceipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'posted_pos_receipt_id');
    }

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'gross_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'raw_data' => 'array',
        ];
    }
}
