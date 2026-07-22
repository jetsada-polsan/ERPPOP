@extends('layout')
@section('title', 'แฟ้มพนักงาน - JET ERP')
@section('page-title', 'แฟ้มพนักงาน')
@section('page-subtitle', 'ข้อมูลบุคลากรจริง แยกจากบัญชีผู้ใช้และสิทธิ์เข้า ERP')

@section('content')
<div x-data="{ addOpen: {{ $errors->any() ? 'true' : 'false' }} }" x-cloak>
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

<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-success rounded-pill px-4" @click="addOpen = true"><i class="bi bi-person-plus-fill me-1"></i>เพิ่มพนักงาน</button>
</div>

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

{{-- โมดัลเพิ่มพนักงานใหม่ --}}
<div class="booking-modal-backdrop" x-show="addOpen" x-transition.opacity @keydown.escape.window="addOpen = false" @click.self="addOpen = false">
    <div class="booking-modal" style="width:min(820px,100%)" @click.outside="addOpen = false"
        x-data="{ deptChoice: @js(old('department', '')) }">
        <div class="modal-header border-0 px-4 pt-4 pb-2">
            <div>
                <h3 class="h5 fw-bold mb-1">เพิ่มพนักงานใหม่</h3>
                <div class="text-muted small">รหัสพนักงาน (EMP####) รันเลขให้อัตโนมัติ ไม่ต้องกรอกเอง</div>
            </div>
            <button type="button" class="btn btn-light rounded-circle" @click="addOpen = false"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="post" action="{{ route('employees.store') }}">
            @csrf
            <div class="modal-body px-4 pb-2 row g-3">
                <div class="col-md-5">
                    <label class="form-label small text-muted">ชื่อ-นามสกุล *</label>
                    <input name="full_name" required class="form-control" value="{{ old('full_name') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">ชื่อเล่น</label>
                    <input name="nickname" class="form-control" value="{{ old('nickname') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">เพศ</label>
                    <select name="gender" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        <option value="ชาย" @selected(old('gender')==='ชาย')>ชาย</option>
                        <option value="หญิง" @selected(old('gender')==='หญิง')>หญิง</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">สัญชาติ</label>
                    <input name="nationality" class="form-control" value="{{ old('nationality', 'ไทย') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">โทรศัพท์</label>
                    <input name="phone" class="form-control" value="{{ old('phone') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">โทรศัพท์สำรอง</label>
                    <input name="alt_phone" class="form-control" value="{{ old('alt_phone') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">เลขบัตรประชาชน</label>
                    <input name="national_id" class="form-control" value="{{ old('national_id') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">วันเกิด</label>
                    <input type="date" name="birth_date_raw" class="form-control" value="{{ old('birth_date_raw') }}">
                </div>

                <div class="col-12">
                    <label class="form-label small text-muted">ที่อยู่</label>
                    <textarea name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">สาขา</label>
                    <select name="branch_id" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id')===(string) $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">แผนก</label>
                    <select name="department" class="form-select" x-model="deptChoice">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($departments as $item)
                            <option value="{{ $item }}" @selected(old('department') === $item)>{{ $item }}</option>
                        @endforeach
                        <option value="__other__" @selected(old('department') === '__other__')>-- แผนกใหม่ (พิมพ์เอง) --</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">ตำแหน่ง</label>
                    <input name="position" class="form-control" value="{{ old('position') }}">
                </div>
                <div class="col-md-4" x-show="deptChoice === '__other__'" x-cloak>
                    <label class="form-label small text-muted">ชื่อแผนกใหม่</label>
                    <input name="department_other" class="form-control" value="{{ old('department_other') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">ประเภทการจ้าง</label>
                    <input name="employment_type" class="form-control" placeholder="เช่น รายเดือน/รายวัน" value="{{ old('employment_type') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">รูปแบบค่าจ้าง</label>
                    <input name="wage_type" class="form-control" placeholder="เช่น รายเดือน/รายวัน" value="{{ old('wage_type') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">ค่าจ้าง/อัตรา</label>
                    <input type="number" step="0.01" min="0" name="wage_amount" class="form-control text-end" value="{{ old('wage_amount') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">เงินเดือน (สำหรับ Payroll)</label>
                    <input type="number" step="0.01" min="0" name="monthly_salary" class="form-control text-end" value="{{ old('monthly_salary', 0) }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">วันเริ่มงาน</label>
                    <input type="date" name="start_date_raw" class="form-control" value="{{ old('start_date_raw') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">สถานะ</label>
                    <select name="status" required class="form-select">
                        <option value="Active" @selected(old('status', 'Active')==='Active')>Active (ทำงานอยู่)</option>
                        <option value="Inactive" @selected(old('status')==='Inactive')>Inactive (ไม่ทำงานแล้ว)</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <label class="form-check">
                        <input type="hidden" name="social_security_enabled" value="0">
                        <input type="checkbox" name="social_security_enabled" value="1" class="form-check-input" @checked(old('social_security_enabled', true))>
                        เข้าประกันสังคม
                    </label>
                </div>

                <div class="col-12">
                    <label class="form-label small text-muted">หมายเหตุ</label>
                    <input name="remark" class="form-control" value="{{ old('remark') }}">
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light border px-4" @click="addOpen = false">ยกเลิก</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check2-circle me-1"></i>บันทึกพนักงานใหม่</button>
            </div>
        </form>
    </div>
</div>
</div>
@endsection

@push('head')
<style>
.booking-modal-backdrop { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 24px; }
.booking-modal { max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
.employee-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.employee-summary div{padding:12px 14px;border:1px solid #dbe5ed;border-radius:9px;background:#fff;box-shadow:0 3px 10px rgba(15,23,42,.04)}.employee-summary span{display:block;color:#64748b;font-size:10.5px}.employee-summary strong{display:block;margin-top:3px;color:#12344d;font-size:21px}.employee-filter{display:grid;grid-template-columns:minmax(260px,1fr) 210px 230px auto auto;gap:8px}.employee-card{overflow:hidden}.employee-table thead th{background:#eef5f9;color:#29465b}.employee-table td{font-size:11.5px}.employee-table td small{display:block;color:#7a8c9c;font-size:9.5px!important;margin-top:2px}.employee-table .code{color:#087fb9;font-weight:800}.mono{font-family:Consolas,monospace}.branch-ok,.user-linked{color:#047857;font-weight:700}.branch-wait{display:inline-block;padding:2px 5px;border-radius:4px;background:#fff7ed;color:#c2410c;font-size:9.5px;font-weight:700}@media(max-width:1000px){.employee-summary{grid-template-columns:1fr 1fr}.employee-filter{grid-template-columns:1fr 1fr}.employee-filter .input-group{grid-column:1/-1}}@media(max-width:600px){.employee-summary,.employee-filter{grid-template-columns:1fr}}
</style>
@endpush
