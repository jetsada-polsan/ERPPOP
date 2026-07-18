@extends('layout')
@section('title', 'ราคานาทีทอง - POPSTAR ERP')
@section('page-title', 'ราคานาทีทอง')
@section('page-subtitle', 'ตั้งแคมเปญลดราคาพิเศษชั่วคราวสำหรับสินค้าที่เลือกไว้')
@section('content')
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">สร้างแคมเปญนาทีทอง</h2>
    <form method="post" action="{{ route('flash-sales.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control" value="{{ old('code') }}"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อกลุ่มนาทีทอง</label><input name="name" required class="form-control" placeholder="เช่น นาทีทองวาริน / นาทีทองปลาดุก" value="{{ old('name') }}"></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" class="form-select">
                <option value="">-- ทุกสาขา --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="flashActive"><label class="form-check-label" for="flashActive">ใช้งาน</label></div></div>

        <div class="col-md-2"><label class="form-label small text-muted">วันที่เริ่ม</label><input type="date" name="starts_date" required class="form-control" value="{{ old('starts_date', now()->toDateString()) }}"></div>
        <div class="col-md-2"><label class="form-label small text-muted">วันที่สิ้นสุด</label><input type="date" name="ends_date" class="form-control" value="{{ old('ends_date') }}"></div>
        <div class="col-md-2"><label class="form-label small text-muted">เวลาเริ่ม</label><input type="time" name="start_time" class="form-control" value="{{ old('start_time') }}"></div>
        <div class="col-md-2"><label class="form-label small text-muted">เวลาสิ้นสุด</label><input type="time" name="end_time" class="form-control" value="{{ old('end_time') }}"></div>

        <div class="col-md-8">
            <label class="form-label small text-muted d-block">วันที่ใช้แคมเปญ (ไม่เลือก = ทุกวัน)</label>
            @foreach(['0'=>'อาทิตย์','1'=>'จันทร์','2'=>'อังคาร','3'=>'พุธ','4'=>'พฤหัส','5'=>'ศุกร์','6'=>'เสาร์'] as $val => $label)
                <div class="form-check form-check-inline">
                    <input type="checkbox" name="days_of_week[]" value="{{ $val }}" class="form-check-input" id="dow{{ $val }}" @checked(in_array($val, old('days_of_week', []), false))>
                    <label class="form-check-label small" for="dow{{ $val }}">{{ $label }}</label>
                </div>
            @endforeach
        </div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">สร้างแคมเปญ</button></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ / เงื่อนไขเพิ่มเติม" value="{{ old('note') }}"></div>
    </form>
</div>
<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการแคมเปญนาทีทอง</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>กลุ่มนาทีทอง</th><th>สาขา</th><th>ช่วงวันที่</th><th>ช่วงเวลา</th><th>สินค้า</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
            @forelse($flashSales as $flashSale)
                <tr>
                    <td class="fw-semibold">{{ $flashSale->code }}</td>
                    <td>{{ $flashSale->name }}</td>
                    <td>{{ $flashSale->branch?->name_th ?? 'ทุกสาขา' }}</td>
                    <td>{{ $flashSale->starts_date?->thaiDate() }} - {{ $flashSale->ends_date?->thaiDate() ?? 'ไม่มีกำหนด' }}</td>
                    <td>{{ $flashSale->start_time?->format('H:i') ?? '00:00' }} - {{ $flashSale->end_time?->format('H:i') ?? '23:59' }}</td>
                    <td>{{ $flashSale->items_count }} รายการ</td>
                    <td><span class="badge {{ $flashSale->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $flashSale->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('flash-sales.show', $flashSale) }}" class="btn btn-sm btn-light border">จัดการสินค้า</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีแคมเปญนาทีทอง</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $flashSales->links() }}
</div>
@endsection
