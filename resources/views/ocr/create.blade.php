@extends('layout')

@section('title', 'อัปโหลด OCR - PopCentral')
@section('page-title', 'อัปโหลดเอกสาร OCR')
@section('page-subtitle', 'เอกสารจะถูกสร้างเป็น Draft และยังไม่กระทบสต็อกหรือบัญชี')

@section('content')
<div class="content-card p-4" style="max-width:780px">
    @if($errors->any())<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('ocr.documents.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">ประเภทเอกสาร</label><select name="document_type" class="form-select" required><option value="supplier_delivery_note">ใบส่งของ Supplier</option><option value="tax_invoice">ใบกำกับภาษี Supplier</option><option value="goods_receipt">ใบรับสินค้า</option></select></div>
            <div class="col-md-6"><label class="form-label">สาขา / คลังปลายทาง</label><select name="branch_id" class="form-select" required><option value="">เลือกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label">ซัพพลายเออร์ <span class="text-muted">(ถ้าทราบ)</span></label><select name="supplier_id" class="form-select"><option value="">ให้ระบบลองจับคู่จากเอกสาร</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name_th }}</option>@endforeach</select></div>
            <div class="col-12"><label class="form-label">ไฟล์ PDF หรือรูปภาพ</label><input class="form-control" type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.txt,.csv" required><div class="form-text">รองรับ PDF, JPG, PNG และไฟล์ TXT/CSV สำหรับทดสอบ Mock OCR ขนาดไม่เกิน 15 MB</div></div>
            <div class="col-12 d-flex justify-content-between align-items-center pt-2"><a href="{{ route('ocr.documents.index') }}" class="btn btn-light border">ยกเลิก</a><button class="btn btn-primary"><i class="bi bi-file-earmark-arrow-up me-1"></i>สร้าง OCR Draft</button></div>
        </div>
    </form>
</div>
@endsection
