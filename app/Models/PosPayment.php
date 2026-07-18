<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['pos_receipt_id', 'method', 'amount', 'cash_received', 'change_amount', 'card_no', 'cheque_no'])]
class PosPayment extends Model
{
    public $timestamps = false;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PosReceipt::class, 'pos_receipt_id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'cash_received' => 'decimal:4',
            'change_amount' => 'decimal:4',
        ];
    }
}
