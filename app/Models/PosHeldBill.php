<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hold_no', 'branch_id', 'pos_terminal_id', 'customer_id', 'total_amount', 'status', 'note'])]
class PosHeldBill extends Model
{
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function terminal(): BelongsTo { return $this->belongsTo(PosTerminal::class, 'pos_terminal_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:4'];
    }
}
