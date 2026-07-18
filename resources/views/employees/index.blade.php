@extends('layout')
@section('title', 'แฟ้มพนักงาน - JET ERP')
@section('page-title', 'แฟ้มพนักงาน')
@section('page-subtitle', 'ข้อมูลบุคลากรจริง แยกจากบัญชีผู้ใช้และสิทธิ์เข้า ERP')

@section('content')
<div class="employee-summary mb-3">
    <div><span>พนักงานทั้งหมด</span><strong>{{ number_format($summary['total']) }}</strong></div>
    <div><span>ผูกสาขาแล้ว</span><strong>{{ number_format($summary['linked_branch']) }}</strong></div>
    <div><span>ยังไม่ผูกสาขา</span><strong>{{ number_format($summary['unassigned']) }}</strong></div>
    <div><span>มีบัญชีเข้า ERP</span><strong>{{ number_format($summary['linked_user']) }}</strong></div>
</div>

<form method="get" class="employee-filter mb-3">
    <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input name="q" value="{{ request('q') }}" class="form-control" placeholder="รหัส ชื่อ ชื่อเล่น หรือเบอร์โทร"></div>
    <select name="department" class="form-select"><option value="">ทุกแผนก</option>@foreach($departments as $item)<option value="{{ $item }}" @selected(request('department')===$item)>{{ $item }}</option>@endforeach</select>
    <select name="branch_id" class="form-select"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)request('branch_id')===(string)$branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select>
    <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>ค้นหา</button>
    <a href="{{ route('employees.index') }}" class="btn btn-light border">ล้าง</a>
</form>

<div class="content-card employee-card">
    <div class="table-responsive">
        <table class="table employee-table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>ชื่อเล่น</th><th>แผนก / ตำแหน่ง</th><th>สาขา</th><th>โทรศัพท์</th><th>เลขบัตร (ปกปิด)</th><th>บัญชี ERP</th></tr></thead>
            <tbody>
            @forelse($employees as $employee)
                <tr>
                    <td class="code">{{ $employee->employee_code }}</td>
                    <td><strong>{{ $employee->full_name }}</strong><small>{{ $employee->gender ?: '-' }} · {{ $employee->nationality ?: '-' }}</small></td>
                    <td>{{ $employee->nickname ?: '-' }}</td>
                    <td><strong>{{ $employee->department ?: 'ยังไม่ระบุแผนก' }}</strong><small>{{ $employee->position ?: 'ยังไม่ระบุตำแหน่ง' }}</small></td>
                    <td>@if($employee->branch)<span class="branch-ok">{{ $employee->branch->code }} · {{ $employee->branch->name_th }}</span>@else<span class="branch-wait">ยังไม่ผูกสาขา</span><small>{{ $employee->branch_text ?: $employee->source_section }}</small>@endif</td>
                    <td>{{ $employee->phone ?: '-' }}</td>
                    <td class="mono">{{ $employee->maskedNationalId() }}</td>
                    <td>@if($employee->user)<span class="user-linked"><i class="bi bi-check-circle-fill"></i> {{ $employee->user->username }}</span>@else<span class="text-muted">ยังไม่มีบัญชี</span>@endif</td>
                </tr>
            @empty<tr><td colspan="8" class="text-center text-muted py-5">ไม่พบข้อมูลพนักงาน</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top">{{ $employees->links() }}</div>
</div>
@endsection

@push('head')
<style>
.employee-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.employee-summary div{padding:12px 14px;border:1px solid #dbe5ed;border-radius:9px;background:#fff;box-shadow:0 3px 10px rgba(15,23,42,.04)}.employee-summary span{display:block;color:#64748b;font-size:10.5px}.employee-summary strong{display:block;margin-top:3px;color:#12344d;font-size:21px}.employee-filter{display:grid;grid-template-columns:minmax(260px,1fr) 210px 230px auto auto;gap:8px}.employee-card{overflow:hidden}.employee-table thead th{background:#eef5f9;color:#29465b}.employee-table td{font-size:11.5px}.employee-table td small{display:block;color:#7a8c9c;font-size:9.5px!important;margin-top:2px}.employee-table .code{color:#087fb9;font-weight:800}.mono{font-family:Consolas,monospace}.branch-ok,.user-linked{color:#047857;font-weight:700}.branch-wait{display:inline-block;padding:2px 5px;border-radius:4px;background:#fff7ed;color:#c2410c;font-size:9.5px;font-weight:700}@media(max-width:1000px){.employee-summary{grid-template-columns:1fr 1fr}.employee-filter{grid-template-columns:1fr 1fr}.employee-filter .input-group{grid-column:1/-1}}@media(max-width:600px){.employee-summary,.employee-filter{grid-template-columns:1fr}}
</style>
@endpush
