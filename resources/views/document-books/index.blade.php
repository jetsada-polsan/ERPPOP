@extends('layout')
@section('title', 'สมุดเอกสาร - POPSTAR ERP')
@section('page-title', 'สมุดเอกสาร')
@section('page-subtitle', 'แยกเอกสารประเภทเดียวเป็นหลายเล่ม แต่ละเล่มมีเลขรันของตัวเอง (เช่น DS / DSN เหมือน BPlus)')
@section('content')

<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">เพิ่มเล่มเอกสารใหม่</h2>
    <form method="post" action="{{ route('document-books.store') }}" class="row g-3">
        @csrf
        <div class="col-md-3">
            <label class="form-label small text-muted">ประเภทเอกสาร</label>
            <select name="document_type_id" required class="form-select">
                @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name_th }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">รหัสเล่ม</label><input name="code" required class="form-control" placeholder="เช่น DSN"></div>
        <div class="col-md-2"><label class="form-label small text-muted">คำนำหน้าเลขที่</label><input name="prefix" required class="form-control" placeholder="เช่น DSN"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อเล่ม</label><input name="name" required class="form-control" placeholder="เช่น ใบขายเชื่อ เล่ม N"></div>
        <div class="col-md-1 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_default" value="1" class="form-check-input" id="bkDefault"><label class="form-check-label small" for="bkDefault">เล่มหลัก</label></div></div>
        <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">คำนำหน้าเลขที่จะประกอบเป็นเลขเอกสาร เช่น <code>DSN0001{{ now()->format('Ymd') }}001</code> — เอกสารเดิมไม่เปลี่ยน</p>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">เล่มเอกสารทั้งหมด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>ประเภทเอกสาร</th><th>รหัสเล่ม</th><th>ชื่อเล่ม</th><th>คำนำหน้า</th><th class="text-end">เอกสาร</th><th>เล่มหลัก</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
            @foreach($books as $book)
                <tr x-data="{ edit: false }">
                    <td>{{ $book->documentType->name_th }}</td>
                    <td class="fw-semibold">{{ $book->code }}</td>
                    <td>
                        <span x-show="!edit">{{ $book->name }}</span>
                        <form method="post" action="{{ route('document-books.update', $book) }}" x-show="edit" x-cloak class="d-flex gap-1 align-items-center">
                            @csrf @method('PUT')
                            <input name="name" value="{{ $book->name }}" class="form-control form-control-sm" style="width:200px">
                            <label class="small text-nowrap"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($book->is_default)> หลัก</label>
                            <label class="small text-nowrap"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($book->is_active)> ใช้งาน</label>
                            <button class="btn btn-sm btn-success">บันทึก</button>
                        </form>
                    </td>
                    <td><code>{{ $book->prefix }}</code></td>
                    <td class="text-end">{{ number_format($book->documents_count) }}</td>
                    <td>@if($book->is_default)<span class="badge text-bg-primary">เล่มหลัก</span>@endif</td>
                    <td><span class="badge {{ $book->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $book->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-light border" @click="edit = !edit"><span x-text="edit ? 'ยกเลิก' : 'แก้ไข'"></span></button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
