<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period', 'branch_id', 'form_type', 'status', 'taxable_amount', 'tax_amount', 'document_count', 'file_name', 'file_hash', 'prepared_by', 'prepared_at', 'reviewed_by', 'reviewed_at', 'submission_reference', 'submitted_at', 'note'])]
class TaxFilingRun extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return [
            'taxable_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'prepared_at' => 'datetime', 'reviewed_at' => 'datetime', 'submitted_at' => 'datetime',
        ];
    }
}
