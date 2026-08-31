@extends('layout')

@section('title', 'ตรวจ OCR Draft - PopCentral')
@section('page-title', 'ตรวจ OCR Draft')
@section('page-subtitle', $document->original_file_name.' · '.$document->status)

@push('head')
<style>
    .ocr-review{display:grid;grid-template-columns:minmax(320px,.85fr) minmax(520px,1.5fr);gap:14px}.ocr-box{background:#fff;border:1px solid var(--erp-border);border-radius:8px;padding:15px}.ocr-doc-preview{min-height:620px;background:#f4f7fa;border:1px solid var(--erp-border);display:grid;place-items:center;overflow:auto}.ocr-doc-preview img{max-width:100%;height:auto}.ocr-doc-preview iframe{width:100%;height:620px;border:0}.ocr-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.ocr-line-wrap{overflow:auto}.ocr-line-table{width:100%;min-width:980px;border-collapse:collapse}.ocr-line-table th{font-size:10px;background:var(--erp-surface-2);color:var(--erp-muted);padding:8px;text-align:left;white-space:nowrap}.ocr-line-table td{padding:7px;border-top:1px solid var(--erp-border);vertical-align:top}.ocr-line-table input,.ocr-line-table select{font-size:11px;min-width:80px}.ocr-line-table .product-select{min-width:240px}.ocr-warn{background:#fff8e6;border:1px solid #f3d27a;color:#855d00;border-radius:6px;padding:10px 12px;font-size:12px}.ocr-status{display:inline-block;padding:4px 8px;border-radius:5px;background:#eef1f4;font-size:11px;font-weight:800}.ocr-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}@media(max-width:1050px){.ocr-review{grid-template-columns:1fr}.ocr-meta{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.ocr-meta{grid-template-columns:1fr}}
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><a href="{{ route('ocr.documents.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>กลับรายการ OCR</a><span class="ocr-status">{{ $document->status }}</span></div>
@if(session('success'))<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>@endif
<div class="ocr-review">
    <section class="ocr-box">
        <div class="d-flex justify-content-between align-items-center mb-2"><strong>เอกสารต้นฉบับ</strong><a href="{{ route('ocr.documents.file',$document) }}" target="_blank" class="btn btn-sm btn-light border"><i class="bi bi-box-arrow-up-right"></i></a></div>
        <div class="ocr-doc-preview">
            @if(str_starts_with($document->file_mime_type,'image/'))<img src="{{ route('ocr.documents.file',$document) }}" alt="เอกสารต้นฉบับ">
            @elseif($document->file_mime_type === 'application/pdf')<iframe src="{{ route('ocr.documents.file',$document) }}" title="เอกสาร PDF"></iframe>
            @else<div class="text-muted text-center p-4"><i class="bi bi-file-text fs-1 d-block mb-2"></i>ไฟล์ทดสอบข้อความ<br><small>{{ $document->original_file_name }}</small></div>@endif
        </div>
        <div class="small text-muted mt-2">Engine: {{ $document->ocr_engine ?: 'ยังไม่เริ่ม' }} · Confidence: {{ $document->confidence_score !== null ? number_format((float)$document->confidence_score*100,1).'%' : '-' }}</div>
        @if($document->raw_text)<details class="mt-3"><summary class="small text-muted">ข้อความที่อ่านได้</summary><pre class="small bg-light p-2 mt-2" style="white-space:pre-wrap;max-height:220px;overflow:auto">{{ $document->raw_text }}</pre></details>@endif
    </section>
    <section class="ocr-box">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-3"><div><h2 class="h5 fw-bold mb-1">ข้อมูล Draft</h2><div class="small text-muted">ตรวจและแก้ไขได้ ก่อน Approve</div></div>
            @if(in_array($document->status,['uploaded','failed']))<form method="post" action="{{ route('ocr.documents.process',$document) }}">@csrf<button class="btn btn-primary"><i class="bi bi-cpu me-1"></i>เริ่ม OCR</button></form>@endif
        </div>
        @if($duplicateWarning)<div class="ocr-warn mb-3"><i class="bi bi-exclamation-triangle me-1"></i>พบเอกสารซัพพลายเออร์และเลขที่อ้างอิงซ้ำ กรุณาตรวจสอบก่อนอนุมัติ</div>@endif
        @if($totalMismatchWarning)<div class="ocr-warn mb-3"><i class="bi bi-calculator me-1"></i>ยอดรวมในหัวเอกสารต่างจากผลรวมรายการมากกว่า 1% กรุณาตรวจสอบราคา ส่วนลด และ VAT</div>@endif
        <form method="post" action="{{ route('ocr.documents.review',$document) }}">
            @csrf
            <div class="ocr-meta mb-3">
                <div><label class="form-label small">ซัพพลายเออร์</label><select name="supplier_id" class="form-select form-select-sm"><option value="">เลือกซัพพลายเออร์</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($document->supplier_id===$supplier->id)>{{ $supplier->code }} · {{ $supplier->name_th }}</option>@endforeach</select></div>
                <div><label class="form-label small">สาขา / คลัง</label><select name="branch_id" class="form-select form-select-sm"><option value="">เลือกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($document->branch_id===$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
                <div><label class="form-label small">เลขที่เอกสาร</label><input name="reference_no" class="form-control form-control-sm" value="{{ $document->reference_no }}"></div>
                <div><label class="form-label small">วันที่เอกสาร</label><input type="date" name="document_date" class="form-control form-control-sm" value="{{ $document->document_date?->toDateString() }}"></div>
                <div><label class="form-label small">ยอดรวม</label><input type="number" step="0.01" min="0" name="total_amount" class="form-control form-control-sm" value="{{ $document->total_amount }}"></div>
                <div><label class="form-label small">VAT</label><input type="number" step="0.01" min="0" name="vat_amount" class="form-control form-control-sm" value="{{ $document->vat_amount }}"></div>
                <div><label class="form-label small">ยอดก่อน VAT</label><input type="number" step="0.01" min="0" name="net_amount" class="form-control form-control-sm" value="{{ $document->net_amount }}"></div>
            </div>
            <div class="ocr-line-wrap mb-3"><table class="ocr-line-table"><thead><tr><th>#</th><th>สินค้า</th><th>รหัส</th><th>Barcode</th><th>จำนวน</th><th>หน่วย</th><th>ราคา/หน่วย</th><th>ส่วนลด</th><th>รวม</th><th>จับคู่</th></tr></thead><tbody>
            @forelse($document->lines as $line)
            <tr>
                <td>{{ $line->line_no }}</td>
                <td><div class="small fw-semibold">{{ $line->extracted_product_name ?: '-' }}</div><input name="lines[{{ $line->id }}][extracted_product_name]" class="form-control form-control-sm" value="{{ $line->extracted_product_name }}"></td>
                <td><input name="lines[{{ $line->id }}][extracted_product_code]" class="form-control form-control-sm" value="{{ $line->extracted_product_code }}"></td>
                <td><input name="lines[{{ $line->id }}][extracted_barcode]" class="form-control form-control-sm" value="{{ $line->extracted_barcode }}"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][extracted_qty]" class="form-control form-control-sm" value="{{ $line->extracted_qty }}"></td>
                <td><input name="lines[{{ $line->id }}][extracted_unit]" class="form-control form-control-sm" value="{{ $line->extracted_unit }}"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][extracted_unit_price]" class="form-control form-control-sm" value="{{ $line->extracted_unit_price }}"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][extracted_discount]" class="form-control form-control-sm" value="{{ $line->extracted_discount }}"></td>
                <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][extracted_line_total]" class="form-control form-control-sm" value="{{ $line->extracted_line_total }}"></td>
                <td><select name="lines[{{ $line->id }}][matched_product_id]" class="form-select form-select-sm product-select"><option value="">เลือกสินค้า</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($line->matched_product_id===$product->id)>{{ $product->sku_code }} · {{ $product->name_th }}</option>@endforeach</select><div class="small mt-1 text-{{ $line->match_status==='matched'?'success':'danger' }}">{{ $line->match_status }}</div></td>
            </tr>
            @empty<tr><td colspan="10" class="text-center text-muted py-4">ยังไม่พบรายการจาก OCR กรุณาแก้ไข/เพิ่มด้วยข้อมูลจริงก่อน Approve</td></tr>@endforelse
            </tbody></table></div>
            @if($document->lines->isNotEmpty())<div class="small text-muted mb-3"><i class="bi bi-box-seam me-1"></i>ผลกระทบเมื่อโพสต์: รับสินค้า {{ number_format((float)$document->lines->sum('extracted_qty'),4) }} หน่วย · มูลค่าตามรายการ {{ number_format((float)$document->lines->sum('extracted_line_total'),2) }} บาท · ยังไม่ตัดสต็อก</div>@endif
            <div class="ocr-actions"><button class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>บันทึก Draft</button></div>
        </form>
        <hr>
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap"><div><strong>ขั้นตอนถัดไป</strong><div class="small text-muted">Approve จะยังไม่กระทบสต็อก ต้องกดโพสต์อีกครั้ง</div></div><div class="ocr-actions">
            @if(!in_array($document->status,['posted','rejected']))<form method="post" action="{{ route('ocr.documents.approve',$document) }}">@csrf<button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Approve Draft</button></form><form method="post" action="{{ route('ocr.documents.reject',$document) }}">@csrf<button class="btn btn-outline-danger" onclick="return confirm('ตีกลับเอกสารนี้?')"><i class="bi bi-x-circle me-1"></i>Reject</button></form>@endif
            @if($document->status==='approved')<form method="post" action="{{ route('ocr.documents.post',$document) }}">@csrf<button class="btn btn-primary" onclick="return confirm('ยืนยันโพสต์รับสินค้าเข้าสต็อก?')"><i class="bi bi-box-arrow-in-down me-1"></i>โพสต์รับสินค้า</button></form>@endif
            @if($document->status==='posted')<a class="btn btn-light border" href="{{ route('purchases.show',$document->postedDocument) }}">เปิดใบซื้อที่โพสต์แล้ว</a>@endif
        </div></div>
    </section>
</div>
@endsection
