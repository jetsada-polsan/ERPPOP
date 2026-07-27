<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'hold_no', 'branch_id', 'pos_terminal_id', 'pos_shift_id', 'cashier_id', 'held_by',
    'customer_id', 'total_amount', 'status', 'note', 'payload', 'held_at', 'resumed_at',
])]
class PosHeldBill extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'cashier_id');
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:8',
            'payload' => 'array',
            'held_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }
}
