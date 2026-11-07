@extends('layout')
@section('title', 'จัดการสายการขาย / สายส่ง')
@section('page-title', 'จัดการสายการขาย / สายส่ง')
@section('page-subtitle', 'กำหนดสายและสาขาสำหรับผู้ใช้และใบจอง')
@section('content')
<div class="content-card p-4 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="h5 mb-0">เพิ่มสายการขาย / สายส่ง</h2>
        <a href="{{ route('users.index') }}" class="btn btn-light border">กลับหน้าผู้ใช้</a>
    </div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form method="post" action="{{ route('sales-areas.store') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-2"><label class="form-label">รหัสสาย</label><input name="code" class="form-control" placeholder="รันอัตโนมัติ" value="{{ old('code') }}"></div>
        <div class="col-md-4"><label class="form-label">ชื่อสาย</label><input name="name" class="form-control" required value="{{ old('name') }}" placeholder="เช่น สาขาอำนาจเจริญ"></div>
        <div class="col-md-4"><label class="form-label">สาขา</label><select name="branch_id" class="form-select"><option value="">ไม่ระบุสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">เพิ่มสาย</button></div>
    </form>
    <p class="form-text mb-0">เว้นรหัสว่างเพื่อรัน B001, B002 ต่อจากรหัสล่าสุด รหัสสายผูกกับเล่มใบจอง โปรดตรวจเอกสารก่อนเปลี่ยนรหัสเดิม</p>
</div>
<div class="content-card p-4">
    <h2 class="h5 mb-3">สายที่มีอยู่</h2>
    @forelse($areas as $area)
    <form method="post" action="{{ route('sales-areas.update', $area) }}" class="row g-2 align-items-end border-bottom pb-3 mb-3">
        @csrf @method('PUT')
        <div class="col-md-2"><label class="form-label">รหัสสาย</label><input name="code" class="form-control" required value="{{ $area->code }}"></div>
        <div class="col-md-3"><label class="form-label">ชื่อสาย</label><input name="name" class="form-control" required value="{{ $area->name }}"></div>
        <div class="col-md-3"><label class="form-label">สาขา</label><select name="branch_id" class="form-select"><option value="">ไม่ระบุสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($area->branch_id == $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label">สถานะ</label><select name="is_active" class="form-select"><option value="1" @selected($area->is_active)>ใช้งาน</option><option value="0" @selected(!$area->is_active)>ปิดใช้งาน</option></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">บันทึก</button></div>
    </form>
    @empty <p class="text-muted">ยังไม่มีสายการขาย</p> @endforelse
</div>
@endsection
