<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ocr_document_id', 'user_id', 'action', 'old_value', 'new_value', 'note'])]
class OcrReviewLog extends Model
{
    public $timestamps = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['old_value' => 'array', 'new_value' => 'array', 'created_at' => 'datetime'];
    }
}
