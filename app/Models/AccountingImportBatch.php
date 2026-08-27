<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'source_type', 'status', 'original_name', 'file_path', 'file_hash', 'suggested_amount', 'suggested_date', 'suggested_party', 'extracted_json', 'review_note', 'uploaded_by', 'reviewed_at'])]
class AccountingImportBatch extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return [
            'suggested_amount' => 'decimal:4',
            'suggested_date' => 'date',
            'extracted_json' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
