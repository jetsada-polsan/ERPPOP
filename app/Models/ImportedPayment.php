<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch_id', 'receipt_id', 'legacy_psp_key', 'payment_code', 'payment_name', 'amount', 'change_amount', 'raw_data'])]
class ImportedPayment extends Model
{
    public $timestamps = false;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ImportedReceipt::class, 'receipt_id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'change_amount' => 'decimal:4',
            'raw_data' => 'array',
        ];
    }
}
