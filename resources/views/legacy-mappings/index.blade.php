@extends('layout')

@section('title', 'Mapping Bplus → ERP - PopCentral')
@section('page-title', 'Mapping Bplus → ERP')
@section('page-subtitle', 'ทะเบียนตรวจสอบตารางเก่ากับโครงสร้าง ERP ใหม่ โดยไม่แก้ฐานข้อมูลเดิม')

@section('content')
<div class="container-fluid py-3">
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">ตารางทั้งหมด</small><h2 class="mb-0">{{ number_format($summary['total']) }}</h2></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm border-start border-4 border-success"><div class="card-body"><small class="text-muted">Map แล้ว</small><h2 class="mb-0 text-success">{{ number_format($summary['mapped']) }}</h2></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm border-start border-4 border-warning"><div class="card-body"><small class="text-muted">รอตรวจ</small><h2 class="mb-0 text-warning">{{ number_format($summary['needs_review']) }}</h2></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm border-start border-4 border-secondary"><div class="card-body"><small class="text-muted">ไม่เอาเข้า ERP</small><h2 class="mb-0 text-secondary">{{ number_format($summary['excluded']) }}</h2></div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body border-bottom">
            <form class="row g-2" method="get">
                <div class="col-md-4"><input class="form-control" name="q" value="{{ $filters['search'] }}" placeholder="ค้นหาตารางเก่าหรือตารางใหม่"></div>
                <div class="col-md-3"><select class="form-select" name="status"><option value="">ทุกสถานะ</option><option value="mapped" @selected($filters['status'] === 'mapped')>Map แล้ว</option><option value="needs_review" @selected($filters['status'] === 'needs_review')>รอตรวจ</option><option value="excluded" @selected($filters['status'] === 'excluded')>ไม่เอาเข้า ERP</option></select></div>
                <div class="col-md-3"><select class="form-select" name="module"><option value="">ทุกโมดูล</option>@foreach($modules as $module)<option value="{{ $module }}" @selected($filters['module'] === $module)>{{ $module }}</option>@endforeach</select></div>
                <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>ค้นหา</button></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>ตาราง Bplus</th><th>ตาราง ERP</th><th>โมดูล</th><th>ประเภท</th><th>คอลัมน์เดิม</th><th>คอลัมน์ใหม่</th><th>สถานะ</th></tr></thead>
                <tbody>
                @forelse($mappings as $mapping)
                    <tr><td><code>{{ $mapping->legacy_table }}</code><div class="small text-muted">{{ $mapping->legacy_schema }}</div></td><td><code>{{ $mapping->target_table ?: 'ไม่สร้างใน ERP' }}</code></td><td>{{ $mapping->module }}</td><td>{{ $mapping->mapping_type }}</td><td>{{ $mapping->legacy_column_count }}</td><td>{{ $mapping->target_column_count }}</td><td><span class="badge text-bg-{{ $mapping->status === 'mapped' ? 'success' : ($mapping->status === 'excluded' ? 'secondary' : 'warning') }}">{{ $mapping->status === 'mapped' ? 'Map แล้ว' : ($mapping->status === 'excluded' ? 'ไม่เอาเข้า ERP' : 'รอตรวจ') }}</span></td></tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบข้อมูลตามเงื่อนไข</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">{{ $mappings->links() }}</div>
    </div>
</div>
@endsection
