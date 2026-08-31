<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'document_type', 'source_module', 'original_file_path', 'original_file_name', 'file_mime_type',
    'original_file_sha256', 'status', 'ocr_engine', 'raw_text', 'confidence_score', 'supplier_id', 'supplier_tax_id',
    'branch_id', 'reference_no', 'document_date', 'total_amount', 'vat_amount', 'net_amount', 'error_message',
    'created_by', 'reviewed_by', 'approved_by', 'posted_document_id',
])]
class OcrDocument extends Model
{
    public function lines(): HasMany
    {
        return $this->hasMany(OcrExtractedLine::class)->orderBy('line_no');
    }

    public function matchResults(): HasMany
    {
        return $this->hasMany(OcrMatchResult::class);
    }

    public function reviewLogs(): HasMany
    {
        return $this->hasMany(OcrReviewLog::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OcrAttachment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'posted_document_id');
    }

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'confidence_score' => 'decimal:4',
            'total_amount' => 'decimal:8',
            'vat_amount' => 'decimal:8',
            'net_amount' => 'decimal:8',
        ];
    }
}
