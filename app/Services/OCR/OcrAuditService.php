<?php

namespace App\Services\OCR;

use App\Models\AuditLog;
use App\Models\OcrDocument;
use App\Models\OcrReviewLog;

class OcrAuditService
{
    public function record(OcrDocument $document, string $action, array $old = [], array $new = [], ?string $note = null): void
    {
        OcrReviewLog::create([
            'ocr_document_id' => $document->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'old_value' => $old ?: null,
            'new_value' => $new ?: null,
            'note' => $note,
        ]);

        AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => $document->branch_id ?: auth()->user()?->branch_id,
            'action' => mb_substr('ocr_'.$action, 0, 50),
            'table_name' => 'ocr_documents',
            'record_id' => $document->id,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }
}
