<?php

namespace App\Services\OCR;

use App\Models\Branch;
use App\Models\Document;
use App\Models\OcrAttachment;
use App\Models\OcrDocument;
use App\Models\OcrExtractedLine;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OcrDocumentService
{
    public function __construct(
        private readonly OcrEngineInterface $engine,
        private readonly OcrParserService $parser,
        private readonly OcrMatchingService $matching,
        private readonly OcrAuditService $audit,
    ) {}

    public function createUpload(array $data): OcrDocument
    {
        return DB::transaction(function () use ($data): OcrDocument {
            $document = OcrDocument::create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'source_module' => 'purchase',
                'status' => 'uploaded',
                'created_by' => auth()->id(),
            ]);
            OcrAttachment::create([
                'ocr_document_id' => $document->id,
                'file_path' => $document->original_file_path,
                'file_name' => $document->original_file_name,
                'mime_type' => $document->file_mime_type,
            ]);
            $this->audit->record($document, 'upload', [], ['file_name' => $document->original_file_name]);

            return $document;
        });
    }

    public function process(OcrDocument $document): OcrDocument
    {
        if (! Storage::disk('local')->exists($document->original_file_path)) {
            $document->update(['status' => 'failed', 'error_message' => 'ไม่พบไฟล์ต้นฉบับใน storage']);
            $this->audit->record($document, 'process_failed', [], ['reason' => 'missing_file']);
            throw new RuntimeException('ไม่พบไฟล์ต้นฉบับสำหรับประมวลผล OCR');
        }

        try {
            $result = $this->engine->extract(
                Storage::disk('local')->path($document->original_file_path),
                $document->file_mime_type,
                $document->original_file_name,
            );
            $parsed = $this->parser->parse($result['raw_text']);

            return DB::transaction(function () use ($document, $result, $parsed): OcrDocument {
                $header = $parsed['header'];
                $document->update([
                    'status' => 'processing',
                    'ocr_engine' => $result['engine'],
                    'raw_text' => $result['raw_text'],
                    'confidence_score' => $result['confidence_score'],
                    'reference_no' => $header['reference_no'],
                    'document_date' => $header['document_date'],
                    'supplier_tax_id' => $header['supplier_tax_id'],
                    'total_amount' => $header['total_amount'],
                    'vat_amount' => $header['vat_amount'],
                    'net_amount' => $header['net_amount'],
                    'error_message' => null,
                ]);

                if (! $document->branch_id && $header['branch_name']) {
                    $branch = Branch::where('code', $header['branch_name'])
                        ->orWhere('name_th', $header['branch_name'])->first();
                    if ($branch) {
                        $document->update(['branch_id' => $branch->id]);
                    }
                }

                $document->lines()->delete();
                foreach ($parsed['lines'] as $line) {
                    OcrExtractedLine::create(['ocr_document_id' => $document->id, ...$line]);
                }

                $supplierName = $header['supplier_name'];
                $this->matching->match($document->fresh(['supplier']), $supplierName, $header['supplier_tax_id']);
                $document->load('lines');
                $allMatched = $document->lines->isNotEmpty()
                    && $document->lines->every(fn (OcrExtractedLine $line) => $line->match_status === 'matched');
                $document->update(['status' => $allMatched ? 'matched' : 'needs_review']);
                $this->audit->record($document, 'process', [], [
                    'engine' => $result['engine'],
                    'line_count' => $document->lines->count(),
                    'status' => $document->status,
                ]);

                return $document->fresh();
            });
        } catch (\Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $this->audit->record($document, 'process_failed', [], ['reason' => $e->getMessage()]);
            throw new RuntimeException('ประมวลผล OCR ไม่สำเร็จ: '.$e->getMessage(), previous: $e);
        }
    }

    public function review(OcrDocument $document, array $data): OcrDocument
    {
        $old = $document->only(['supplier_id', 'branch_id', 'reference_no', 'document_date', 'total_amount', 'vat_amount', 'net_amount']);
        DB::transaction(function () use ($document, $data, $old): void {
            $document->update([
                'supplier_id' => $data['supplier_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'document_date' => $data['document_date'] ?? null,
                'total_amount' => $data['total_amount'] ?? null,
                'vat_amount' => $data['vat_amount'] ?? null,
                'net_amount' => $data['net_amount'] ?? null,
                'status' => 'needs_review',
                'reviewed_by' => auth()->id(),
                'error_message' => null,
            ]);

            foreach ($data['lines'] ?? [] as $lineId => $lineData) {
                $line = $document->lines()->whereKey($lineId)->first();
                if (! $line) {
                    continue;
                }
                $productId = ! empty($lineData['matched_product_id']) ? (int) $lineData['matched_product_id'] : null;
                $product = $productId ? Product::find($productId) : null;
                $line->update([
                    'extracted_product_code' => $lineData['extracted_product_code'] ?? null,
                    'extracted_barcode' => $lineData['extracted_barcode'] ?? null,
                    'extracted_product_name' => $lineData['extracted_product_name'] ?? null,
                    'extracted_qty' => $lineData['extracted_qty'] ?? null,
                    'extracted_unit' => $lineData['extracted_unit'] ?? null,
                    'extracted_unit_price' => $lineData['extracted_unit_price'] ?? null,
                    'extracted_discount' => $lineData['extracted_discount'] ?? null,
                    'extracted_line_total' => $lineData['extracted_line_total'] ?? null,
                    'matched_product_id' => $product?->id,
                    'matched_unit_id' => $product?->base_unit_id,
                    'match_status' => $product ? 'matched' : 'unmatched',
                    'review_note' => $lineData['review_note'] ?? null,
                ]);
            }
            $this->audit->record($document, 'review', $old, $document->fresh()->only(['supplier_id', 'branch_id', 'reference_no', 'document_date', 'total_amount', 'vat_amount', 'net_amount']));
        });

        return $document->fresh(['lines', 'supplier', 'branch']);
    }

    public function approve(OcrDocument $document): OcrDocument
    {
        $document->load('lines');
        $errors = [];
        if (! $document->supplier_id) {
            $errors[] = 'ต้องเลือกซัพพลายเออร์';
        }
        if (! $document->branch_id) {
            $errors[] = 'ต้องเลือกสาขาหรือคลังปลายทาง';
        }
        if (! $document->document_date) {
            $errors[] = 'ต้องระบุวันที่เอกสาร';
        }
        if ($document->lines->isEmpty()) {
            $errors[] = 'ต้องมีรายการสินค้าอย่างน้อย 1 รายการ';
        }
        foreach ($document->lines as $line) {
            if ($line->match_status !== 'matched' || ! $line->matched_product_id) {
                $errors[] = "รายการที่ {$line->line_no} ยังจับคู่สินค้าไม่ได้";
            }
            if ((float) $line->extracted_qty <= 0) {
                $errors[] = "รายการที่ {$line->line_no} ต้องมีจำนวนมากกว่า 0";
            }
            if ($line->extracted_unit_price === null || (float) $line->extracted_unit_price < 0) {
                $errors[] = "รายการที่ {$line->line_no} ต้องมีราคาต่อหน่วย";
            }
        }
        if ($errors) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $oldStatus = $document->status;
        $document->update(['status' => 'approved', 'approved_by' => auth()->id()]);
        $this->audit->record($document, 'approve', ['status' => $oldStatus], ['status' => 'approved']);

        return $document->fresh(['lines', 'supplier', 'branch']);
    }

    public function reject(OcrDocument $document, ?string $note = null): OcrDocument
    {
        $oldStatus = $document->status;
        $document->update(['status' => 'rejected', 'error_message' => $note]);
        $this->audit->record($document, 'reject', ['status' => $oldStatus], ['status' => 'rejected'], $note);

        return $document->fresh();
    }

    public function duplicateWarning(OcrDocument $document): bool
    {
        if (! $document->supplier_id || ! $document->reference_no) {
            return false;
        }

        return OcrDocument::where('id', '<>', $document->id)
            ->where('supplier_id', $document->supplier_id)
            ->where('reference_no', $document->reference_no)
            ->whereIn('status', ['matched', 'needs_review', 'approved', 'posted'])
            ->exists()
            || Document::where('supplier_id', $document->supplier_id)
                ->where('reference', $document->reference_no)->exists();
    }

    public function totalMismatchWarning(OcrDocument $document): bool
    {
        if ($document->total_amount === null || ! $document->relationLoaded('lines')) {
            $document->load('lines');
        }
        if ($document->total_amount === null || $document->lines->isEmpty()) {
            return false;
        }

        $lineTotal = $document->lines->sum(function (OcrExtractedLine $line): float {
            if ($line->extracted_line_total !== null) {
                return (float) $line->extracted_line_total;
            }

            return (float) $line->extracted_qty * (float) $line->extracted_unit_price;
        });

        return abs((float) $document->total_amount - $lineTotal) > max(1, abs((float) $document->total_amount) * 0.01);
    }
}
