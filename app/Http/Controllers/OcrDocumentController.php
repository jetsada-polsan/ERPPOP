<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\OcrDocument;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\OCR\GoodsReceiptDraftService;
use App\Services\OCR\OcrDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OcrDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $documents = OcrDocument::with(['supplier', 'branch'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('ocr.index', [
            'documents' => $documents,
            'status' => $status,
            'statusCounts' => OcrDocument::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function create(): View
    {
        return view('ocr.create', [
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
        ]);
    }

    public function store(Request $request, OcrDocumentService $service): RedirectResponse
    {
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

        return redirect()->route('ocr.documents.show', $document)->with('success', 'อัปโหลดเอกสารแล้ว กดเริ่ม OCR เพื่อสร้าง Draft');
    }

    public function show(OcrDocument $document, OcrDocumentService $service): View
    {
        $document->load(['lines.matchedProduct', 'matchResults', 'reviewLogs.user', 'attachments', 'supplier', 'branch', 'postedDocument']);

        return view('ocr.show', [
            'document' => $document,
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('code')->limit(500)->get(['id', 'code', 'name_th']),
            'products' => Product::with('baseUnit')->where('is_active', true)->orderBy('name_th')->limit(1000)->get(['id', 'sku_code', 'name_th', 'base_unit_id']),
            'duplicateWarning' => $service->duplicateWarning($document),
            'totalMismatchWarning' => $service->totalMismatchWarning($document),
        ]);
    }

    public function process(OcrDocument $document, OcrDocumentService $service): RedirectResponse
    {
        try {
            $service->process($document);
        } catch (RuntimeException $e) {
            return back()->withErrors(['ocr' => $e->getMessage()]);
        }

        return back()->with('success', 'ประมวลผล OCR แล้ว กรุณาตรวจสอบข้อมูลก่อน Approve');
    }

    public function review(Request $request, OcrDocument $document, OcrDocumentService $service): RedirectResponse
    {
        $data = $request->validate([
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
        ]);
        $service->review($document, $data);

        return back()->with('success', 'บันทึกผลตรวจ Draft แล้ว');
    }

    public function approve(OcrDocument $document, OcrDocumentService $service): RedirectResponse
    {
        try {
            $service->approve($document);
        } catch (RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('success', 'Approve Draft แล้ว ตรวจผลกระทบอีกครั้งก่อนโพสต์รับสินค้า');
    }

    public function reject(Request $request, OcrDocument $document, OcrDocumentService $service): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $service->reject($document, $data['note'] ?? null);

        return redirect()->route('ocr.documents.index')->with('success', 'ตีกลับเอกสาร OCR แล้ว');
    }

    public function postToGoodsReceipt(OcrDocument $document, GoodsReceiptDraftService $service): RedirectResponse
    {
        try {
            $purchase = $service->post($document);
        } catch (RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'โพสต์เป็นใบซื้อและรับสินค้าเข้าคลังแล้ว');
    }

    public function file(OcrDocument $document): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($document->original_file_path), 404);

        return response()->file(Storage::disk('local')->path($document->original_file_path), [
            'Content-Type' => $document->file_mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_file_name).'"',
        ]);
    }
}
