<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ocr_document_id', 'ocr_extracted_line_id', 'match_type', 'candidate_id', 'candidate_name', 'score', 'selected'])]
class OcrMatchResult extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OcrExtractedLine::class, 'ocr_extracted_line_id');
    }

    protected function casts(): array
    {
        return ['score' => 'decimal:4', 'selected' => 'boolean'];
    }
}
