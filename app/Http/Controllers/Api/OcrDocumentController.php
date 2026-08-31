<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OcrDocument;
use App\Services\OCR\GoodsReceiptDraftService;
use App\Services\OCR\OcrDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OcrDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOcr($request);

        return response()->json(OcrDocument::with(['supplier', 'branch'])->latest()->paginate(30));
    }

    public function show(Request $request, OcrDocument $document): JsonResponse
    {
        $this->authorizeOcr($request);
        $document->load(['lines.matchedProduct', 'matchResults', 'attachments', 'supplier', 'branch', 'postedDocument']);

        return response()->json($document);
    }

    public function store(Request $request, OcrDocumentService $service): JsonResponse
    {
        $this->authorizeOcr($request);
        $data = $request->validate([
            'document_type' => ['required', 'in:supplier_delivery_note,tax_invoice,goods_receipt'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'document_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,txt,csv', 'max:15360'],
        ]);
        $file = $request->file('document_file');
        $hash = hash_file('sha256', $file->getRealPath());
        if (OcrDocument::where('original_file_sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['document_file' => 'ไฟล์นี้เคยถูกอัปโหลดแล้ว']);
        }
        $path = $file->store('ocr/documents/'.now()->format('Y-m'), 'local');
        $document = $service->createUpload([
            'document_type' => $data['document_type'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'branch_id' => $data['branch_id'],
            'original_file_path' => $path,
            'original_file_name' => $file->getClientOriginalName(),
            'file_mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'original_file_sha256' => $hash,
        ]);

        return response()->json($document->fresh(), 201);
    }

    public function process(Request $request, OcrDocument $document, OcrDocumentService $service): JsonResponse
    {
        $this->authorizeOcr($request);

        return response()->json($service->process($document));
    }

    public function review(Request $request, OcrDocument $document, OcrDocumentService $service): JsonResponse
    {
        $this->authorizeOcr($request);

        return response()->json($service->review($document, $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'net_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array'],
            'lines.*.matched_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.extracted_product_code' => ['nullable', 'string', 'max:100'],
            'lines.*.extracted_barcode' => ['nullable', 'string', 'max:80'],
            'lines.*.extracted_product_name' => ['nullable', 'string', 'max:250'],
            'lines.*.extracted_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.extracted_unit' => ['nullable', 'string', 'max:100'],
            'lines.*.extracted_unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.extracted_discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.extracted_line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.review_note' => ['nullable', 'string', 'max:1000'],
        ])));
    }

    public function approve(Request $request, OcrDocument $document, OcrDocumentService $service): JsonResponse
    {
        $this->authorizeOcr($request);

        return response()->json($service->approve($document));
    }

    public function reject(Request $request, OcrDocument $document, OcrDocumentService $service): JsonResponse
    {
        $this->authorizeOcr($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json($service->reject($document, $data['note'] ?? null));
    }

    public function postToGoodsReceipt(Request $request, OcrDocument $document, GoodsReceiptDraftService $service): JsonResponse
    {
        $this->authorizeOcr($request);

        return response()->json($service->post($document));
    }

    public function file(Request $request, OcrDocument $document)
    {
        $this->authorizeOcr($request);
        abort_unless(Storage::disk('local')->exists($document->original_file_path), 404);

        return response()->file(Storage::disk('local')->path($document->original_file_path), [
            'Content-Type' => $document->file_mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_file_name).'"',
        ]);
    }

    private function authorizeOcr(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('purchasing.manage'), 403, 'ไม่มีสิทธิ์จัดการ OCR รับสินค้า');
    }
}
