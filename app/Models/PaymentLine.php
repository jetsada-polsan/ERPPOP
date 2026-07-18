<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_document_id', 'seq', 'method', 'amount', 'card_no', 'cheque_no',
    'cheque_due_date', 'bank_account_id',
])]
class PaymentLine extends Model
{
    public $timestamps = false;

    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'cheque_due_date' => 'date',
        ];
    }
}
