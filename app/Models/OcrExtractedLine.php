<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ocr_document_id', 'line_no', 'raw_text', 'extracted_product_code', 'extracted_barcode', 'extracted_product_name',
    'extracted_qty', 'extracted_unit', 'extracted_unit_price', 'extracted_discount', 'extracted_line_total',
    'confidence_score', 'matched_product_id', 'matched_unit_id', 'match_status', 'review_note',
])]
class OcrExtractedLine extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(OcrDocument::class, 'ocr_document_id');
    }

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'matched_product_id');
    }

    public function matchedUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'matched_unit_id');
    }

    protected function casts(): array
    {
        return [
            'extracted_qty' => 'decimal:8',
            'extracted_unit_price' => 'decimal:8',
            'extracted_discount' => 'decimal:8',
            'extracted_line_total' => 'decimal:8',
            'confidence_score' => 'decimal:4',
        ];
    }
}
