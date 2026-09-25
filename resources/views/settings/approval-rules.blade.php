@extends('layout')
@section('title', 'ตารางอนุมัติวงเงิน')
@section('page-title', 'ตารางอนุมัติวงเงิน')
@section('content')
<form method="post" action="{{ route('settings.approval-rules.store') }}" class="row g-3 mb-4">@csrf
<div class="col-md-3"><label class="form-label">เอกสาร</label><select name="document_type" class="form-select"><option value="SALE_RETURN">ใบรับคืน</option><option value="SUPPLIER_CREDIT_NOTE">ใบลดหนี้ผู้ขาย</option><option value="SUPPLIER_DEBIT_NOTE">ใบเพิ่มหนี้ผู้ขาย</option><option value="PURCHASE_ORDER">ใบขอซื้อ</option></select></div>
<div class="col-md-3"><label class="form-label">สาขา</label><select name="branch_id" class="form-select"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name_th }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">วงเงินเริ่มต้น</label><input name="min_amount" type="number" min="0" step="0.01" value="0" required class="form-control"></div>
<div class="col-md-3"><label class="form-label">วงเงินสูงสุด</label><input name="max_amount" type="number" min="0" step="0.01" class="form-control"></div>
<div class="col-md-9"><label class="form-label">สิทธิ์ผู้อนุมัติ</label><select name="permission" class="form-select">@foreach($permissions as $permission)<option value="{{ $permission->code }}">{{ $permission->code }}</option>@endforeach</select></div>
<div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่มกติกา</button></div></form>
<div class="table-responsive"><table class="table"><thead><tr><th>เอกสาร</th><th>สาขา</th><th>วงเงิน</th><th>สิทธิ์</th><th>ใช้งาน</th></tr></thead><tbody>@foreach($rules as $rule)<tr><td>{{ $rule->document_type }}</td><td>{{ $branches->firstWhere('id', $rule->branch_id)?->name_th ?? 'ทุกสาขา' }}</td><td>{{ number_format($rule->min_amount,2) }} - {{ $rule->max_amount === null ? 'ไม่จำกัด' : number_format($rule->max_amount,2) }}</td><td>{{ $rule->permission }}</td><td><form method="post" action="{{ route('settings.approval-rules.toggle', $rule->id) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($rule->is_active) aria-label="ใช้งานกติกา" onchange="this.form.submit()"></form></td></tr>@endforeach</tbody></table></div>
@endsection
