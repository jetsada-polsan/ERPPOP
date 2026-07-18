@extends('layout')
@section('title', 'สมาชิก - POPSTAR ERP')
@section('page-title', 'สมาชิก')
@section('page-subtitle', 'แฟ้มสมาชิก คะแนนสะสม ประเภทสมาชิก และสาขาที่ดูแล')
@section('content')
<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">เพิ่มสมาชิก</h2>
            <form method="post" action="{{ route('members.store') }}" class="row g-3">
                @csrf
                <div class="col-6"><label class="form-label small text-muted">รหัส</label><input name="member_code" required class="form-control"></div>
                <div class="col-6"><label class="form-label small text-muted">เบอร์โทร</label><input name="phone" class="form-control"></div>
                <div class="col-12"><label class="form-label small text-muted">ชื่อสมาชิก</label><input name="name" required class="form-control"></div>
                <div class="col-6">
                    <label class="form-label small text-muted">ประเภท</label>
                    <select name="member_type_id" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($memberTypes as $type)<option value="{{ $type->id }}">{{ $type->code }} - {{ $type->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted">สาขา</label>
                    <select name="branch_id" class="form-select">
                        <option value="">-- ทุกสาขา --</option>
                        @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6"><label class="form-label small text-muted">แต้ม</label><input type="number" step="0.0001" min="0" name="points" value="0" class="form-control"></div>
                <div class="col-6 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="memberActive"><label class="form-check-label" for="memberActive">ใช้งาน</label></div></div>
                <div class="col-12"><button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i> เพิ่มสมาชิก</button></div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-8">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">รายการสมาชิก</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>ชื่อ</th><th>ประเภท</th><th class="text-end">แต้ม</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    @forelse($members as $member)
                        <tr>
                            <form method="post" action="{{ route('members.update', $member) }}">
                                @csrf @method('PUT')
                                <td><input name="member_code" value="{{ $member->member_code }}" required class="form-control form-control-sm"></td>
                                <td><input name="name" value="{{ $member->name }}" required class="form-control form-control-sm"><input type="hidden" name="phone" value="{{ $member->phone }}"></td>
                                <td>
                                    <select name="member_type_id" class="form-select form-select-sm">
                                        <option value="">-- ไม่ระบุ --</option>
                                        @foreach($memberTypes as $type)<option value="{{ $type->id }}" @selected($member->member_type_id === $type->id)>{{ $type->name }}</option>@endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.0001" min="0" name="points" value="{{ $member->points }}" class="form-control form-control-sm text-end"></td>
                                <td><input type="hidden" name="branch_id" value="{{ $member->branch_id }}"><div class="form-check"><input type="checkbox" name="is_active" value="1" @checked($member->is_active) class="form-check-input"></div></td>
                                <td class="text-end"><button class="btn btn-sm btn-light border">บันทึก</button></td>
                            </form>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีสมาชิก</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $members->links() }}
        </div>
    </div>
</div>
@endsection
