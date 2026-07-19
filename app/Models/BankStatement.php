<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['bank_account_id', 'statement_date', 'description', 'amount', 'balance', 'reconciled'])]
class BankStatement extends Model
{
    public $timestamps = false;

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function reconciliation(): HasOne
    {
        return $this->hasOne(BankReconciliation::class);
    }

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'reconciled' => 'boolean',
        ];
    }
}
