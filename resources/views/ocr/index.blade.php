@extends('layout')

@section('title', 'OCR รับสินค้า - PopCentral')
@section('page-title', 'OCR รับสินค้า')
@section('page-subtitle', 'อ่านเอกสารซื้อเป็น Draft ให้คนตรวจ ก่อนรับเข้าสต็อกจริง')

@push('head')
<style>
    .ocr-shell{display:grid;gap:14px}.ocr-panel{background:#fff;border:1px solid var(--erp-border);border-radius:8px}.ocr-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px}.ocr-kpis{display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid var(--erp-border);border-bottom:1px solid var(--erp-border)}.ocr-kpi{padding:14px;border-right:1px solid var(--erp-border)}.ocr-kpi:last-child{border:0}.ocr-kpi span{display:block;color:var(--erp-muted);font-size:11px}.ocr-kpi strong{font-size:22px;color:var(--erp-primary-dark)}.ocr-filter{display:flex;gap:8px;align-items:end;padding:12px;flex-wrap:wrap}.ocr-table{width:100%;border-collapse:collapse}.ocr-table th{background:var(--erp-surface-2);font-size:11px;color:var(--erp-muted);padding:10px;text-align:left}.ocr-table td{padding:10px;border-top:1px solid var(--erp-border);font-size:12px;color:var(--erp-text)}.ocr-pill{display:inline-block;padding:4px 8px;border-radius:5px;font-size:10px;font-weight:800;background:#eef1f4;color:#52677d}.ocr-pill.good{background:#e7f5ee;color:#168a65}.ocr-pill.warn{background:#fff4dc;color:#9b6400}.ocr-pill.bad{background:#fdebec;color:#bd2634}@media(max-width:800px){.ocr-kpis{grid-template-columns:repeat(2,1fr)}.ocr-kpi:nth-child(2n){border-right:0}}
</style>
@endpush

@section('content')
<div class="ocr-shell">
    @if(session('success'))<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>@endif
    <section class="ocr-panel">
        <div class="ocr-head">
            <div><h2 class="h5 fw-bold mb-1">เอกสาร OCR</h2><div class="small text-muted">ไฟล์ต้นฉบับจะไม่ถูกลบ และทุกขั้นตอนมีประวัติการตรวจสอบ</div></div>
            <a class="btn btn-primary" href="{{ route('ocr.documents.create') }}"><i class="bi bi-upload me-1"></i>อัปโหลดเอกสาร</a>
        </div>
        <div class="ocr-kpis">
            @foreach(['uploaded'=>'อัปโหลดแล้ว','processing'=>'กำลังอ่าน','needs_review'=>'รอตรวจ','approved'=>'อนุมัติแล้ว','posted'=>'โพสต์แล้ว'] as $key=>$label)
                <a class="ocr-kpi text-decoration-none" href="{{ route('ocr.documents.index', ['status'=>$key]) }}"><span>{{ $label }}</span><strong>{{ number_format((int)($statusCounts[$key] ?? 0)) }}</strong></a>
            @endforeach
        </div>
        <form class="ocr-filter" method="get">
            <div><label class="form-label small text-muted mb-1">สถานะ</label><select name="status" class="form-select form-select-sm"><option value="">ทุกสถานะ</option>@foreach(['uploaded'=>'อัปโหลดแล้ว','processing'=>'กำลังอ่าน','needs_review'=>'รอตรวจ','matched'=>'จับคู่แล้ว','approved'=>'อนุมัติแล้ว','rejected'=>'ตีกลับ','posted'=>'โพสต์แล้ว','failed'=>'ผิดพลาด'] as $key=>$label)<option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>@endforeach</select></div>
            <button class="btn btn-sm btn-outline-primary">กรอง</button>
            @if($status)<a class="btn btn-sm btn-light border" href="{{ route('ocr.documents.index') }}">ล้าง</a>@endif
        </form>
        <div class="table-responsive"><table class="ocr-table"><thead><tr><th>ไฟล์ / ประเภท</th><th>ซัพพลายเออร์</th><th>สาขา</th><th>เลขที่เอกสาร</th><th>สถานะ</th><th>วันที่สร้าง</th><th></th></tr></thead><tbody>
            @forelse($documents as $document)
            <tr><td><strong>{{ $document->original_file_name }}</strong><div class="text-muted">{{ $document->document_type }} · {{ $document->ocr_engine ?: 'ยังไม่ประมวลผล' }}</div></td><td>{{ $document->supplier?->name_th ?: 'ยังไม่ระบุ' }}</td><td>{{ $document->branch?->code ?: '-' }}</td><td>{{ $document->reference_no ?: '-' }}</td><td><span class="ocr-pill {{ in_array($document->status,['approved','posted'])?'good':(in_array($document->status,['needs_review','matched','uploaded'])?'warn':($document->status==='failed'?'bad':'')) }}">{{ $document->status }}</span></td><td>{{ $document->created_at->thaiDate(true) }}</td><td class="text-end"><a class="btn btn-sm btn-light border" href="{{ route('ocr.documents.show',$document) }}">เปิดตรวจ</a></td></tr>
            @empty<tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีเอกสาร OCR</td></tr>@endforelse
        </tbody></table></div>
        <div class="p-3">{{ $documents->links() }}</div>
    </section>
</div>
@endsection
