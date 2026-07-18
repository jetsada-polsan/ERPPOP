<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id', 'pos_terminal_id', 'cashier_id', 'shift_no', 'opened_at', 'closed_at',
    'opening_cash', 'cash_sales', 'transfer_sales', 'card_sales', 'cheque_sales',
    'expected_cash', 'counted_cash', 'cash_difference', 'receipt_count', 'status',
    'opening_note', 'closing_note',
])]
class PosShift extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'cashier_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PosReceipt::class);
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:4',
            'cash_sales' => 'decimal:4',
            'transfer_sales' => 'decimal:4',
            'card_sales' => 'decimal:4',
            'cheque_sales' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'cash_difference' => 'decimal:4',
        ];
    }
}
