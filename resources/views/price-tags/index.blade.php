@extends('layout')
@section('title', 'ป้ายราคา - POPSTAR ERP')
@section('page-title', 'ป้ายราคา')
@section('page-subtitle', 'กำหนดรูปแบบป้ายราคาและพิมพ์ป้ายสำหรับสินค้า')
@section('content')
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('price-tags.print') }}" class="btn btn-primary">
        <i class="bi bi-printer-fill me-1"></i> พิมพ์ป้ายราคา
    </a>
</div>

<div class="content-card p-4 mb-3" x-data="{ source: 'default' }">
    <h2 class="h5 fw-bold mb-3">เพิ่มรูปแบบป้ายราคา</h2>
    <form method="post" action="{{ route('price-tags.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัสป้ายราคา</label><input name="code" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อป้ายราคา</label><input name="name" required class="form-control" placeholder="เช่น ป้ายขายสดหน้าร้าน"></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">แหล่งราคา</label>
            <select name="price_source" x-model="source" class="form-select">
                <option value="default">ราคาขายทั่วไป</option>
                <option value="price_table">ตามตารางราคา</option>
                <option value="flash_sale">ราคานาทีทอง (ถ้ามีแคมเปญ)</option>
                <option value="no_price">ไม่แสดงราคา</option>
            </select>
        </div>
        <div class="col-md-3" x-show="source === 'price_table'" x-cloak>
            <label class="form-label small text-muted">ตารางราคา</label>
            <select name="price_table_id" class="form-select">
                <option value="">-- เลือกตารางราคา --</option>
                @foreach($priceTables as $pt)
                    <option value="{{ $pt->id }}">{{ $pt->code }} - {{ $pt->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-1 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="tagActive"><label class="form-check-label" for="tagActive">ใช้</label></div></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
    </form>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการรูปแบบป้ายราคา</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อป้ายราคา</th><th>แหล่งราคา</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($templates as $tpl)
                <tr>
                    <td class="fw-semibold">{{ $tpl->code }}</td>
                    <td>{{ $tpl->name }}</td>
                    <td>
                        @switch($tpl->price_source)
                            @case('price_table') ตารางราคา: {{ $tpl->priceTable?->name ?? '-' }} @break
                            @case('flash_sale') ราคานาทีทอง @break
                            @case('no_price') ไม่แสดงราคา @break
                            @default ราคาขายทั่วไป
                        @endswitch
                    </td>
                    <td><span class="badge {{ $tpl->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $tpl->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีรูปแบบป้ายราคา</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $templates->links() }}
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
