<?php

namespace App\Services\Sales;

use App\Models\Document;
use App\Support\DecimalMath;

/** Both pending and posted credits reserve the original sale's value. Payments do not. */
final class SaleCreditLimit
{
    public function remaining(Document $sale, ?int $exceptId = null): string
    {
        $used = Document::query()
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', ['CREDIT_NOTE', 'SALE_RETURN']))
            ->whereNotIn('status', ['rejected', 'cancelled', 'void'])
            ->where(fn ($q) => $q->where('source_document_id', $sale->id)
                ->orWhere(fn ($q) => $q->whereNull('source_document_id')->where('reference', $sale->doc_number)))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('total_amount');
        $remaining = DecimalMath::subtract($sale->total_amount, DecimalMath::sum($used));
        return DecimalMath::compare($remaining, 0) > 0 ? $remaining : '0.00000000';
    }
}
