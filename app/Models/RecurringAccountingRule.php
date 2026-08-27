<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'rule_type', 'name', 'party_name', 'base_amount', 'vat_amount', 'frequency', 'next_run_date', 'last_run_at', 'is_active', 'payload', 'created_by'])]
class RecurringAccountingRule extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function advanceNextRun(): void
    {
        $next = $this->next_run_date;
        $this->forceFill([
            'last_run_at' => $next,
            'next_run_date' => match ($this->frequency) {
                'weekly' => $next->copy()->addWeek(),
                'quarterly' => $next->copy()->addQuarterNoOverflow(),
                'yearly' => $next->copy()->addYearNoOverflow(),
                default => $next->copy()->addMonthNoOverflow(),
            },
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'next_run_date' => 'date',
            'last_run_at' => 'date',
            'is_active' => 'boolean',
            'payload' => 'array',
        ];
    }
}
