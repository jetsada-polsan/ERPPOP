<?php

namespace App\Services\OCR;

use App\Models\OcrDocument;
use App\Services\Purchasing\PurchaseService;
use RuntimeException;

class GoodsReceiptDraftService
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly OcrAuditService $audit,
    ) {}

    public function post(OcrDocument $document)
    {
        $document->load('lines');
        if ($document->status !== 'approved') {
            throw new RuntimeException('ต้อง Approve OCR Draft ก่อนจึงจะโพสต์รับสินค้าได้');
        }
        if ($document->posted_document_id) {
            return $document->postedDocument;
        }

        $purchase = $this->purchases->create([
            'supplier_id' => $document->supplier_id,
            'branch_id' => $document->branch_id,
            'is_credit' => true,
            'prices_include_vat' => true,
            'claim_input_vat' => ((float) $document->vat_amount) > 0,
            'doc_date' => $document->document_date?->toDateString(),
            'reference' => $document->reference_no,
            'created_by' => auth()->id(),
            'remark' => 'รับสินค้าจาก OCR Draft '.$document->uuid,
            'items' => $document->lines->map(fn ($line) => [
                'product_id' => $line->matched_product_id,
                'qty' => (float) $line->extracted_qty,
                'unit_price' => (float) $line->extracted_unit_price,
            ])->all(),
        ]);
        $document->update(['status' => 'posted', 'posted_document_id' => $purchase->id]);
        $this->audit->record($document, 'post', [], ['posted_document_id' => $purchase->id]);

        return $purchase;
    }
}
