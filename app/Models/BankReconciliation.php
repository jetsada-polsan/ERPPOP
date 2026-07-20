<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_statement_id', 'branch_id', 'match_type', 'reference', 'expected_amount', 'difference_amount', 'status', 'slip_path', 'note', 'checked_by', 'checked_at', 'source_type', 'source_id', 'match_confidence'])]
class BankReconciliation extends Model
{
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:4', 'difference_amount' => 'decimal:4', 'checked_at' => 'datetime'];
    }
}
