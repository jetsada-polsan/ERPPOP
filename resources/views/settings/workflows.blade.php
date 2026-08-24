@extends('layout')
@section('title','ตั้งค่า Workflow - JET ERP')
@section('page-title','ตั้งค่า Workflow เอกสาร')
@section('page-subtitle','กำหนดเอกสารที่ใช้ Fast Lane หรือ Approval Lane และเรียงลำดับขั้นตอน')
@section('content')
<div class="container-fluid" style="max-width:1200px">
 @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
 @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
 <div class="alert alert-info">Fast Lane ทำงานได้ทันทีตามสิทธิ์ ส่วน Approval Lane ต้องผ่านขั้นตอนที่กำหนดก่อนจึงกระทบ Stock/บัญชี การเปลี่ยนค่าถูกบันทึก Audit Log</div>
 @foreach($definitions as $definition)<form method="post" action="{{ route('settings.workflows.update',$definition) }}" class="card mb-3 shadow-sm border-0">@csrf @method('PUT')<div class="card-body">
  <div class="d-flex justify-content-between align-items-center mb-3"><div><h4 class="h6 mb-1">{{ $definition->name_th }}</h4><code>{{ $definition->document_type_code }}</code></div><label class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($definition->is_active)> เปิดใช้งาน</label></div>
  <div class="row g-3"><div class="col-md-3"><label class="form-label">รูปแบบ</label><select name="mode" class="form-select"><option value="fast" @selected($definition->mode==='fast')>Fast Lane</option><option value="approval" @selected($definition->mode==='approval')>Approval Lane</option></select></div><div class="col-md-3"><label class="form-label">สิทธิ์อนุมัติ</label><input name="approval_permission" class="form-control" value="{{ $definition->approval_permission }}" placeholder="เช่น stock.manage"></div><div class="col-md-4"><label class="form-label">ลำดับขั้นตอน (บรรทัดละ 1 ขั้น)</label><textarea name="steps" rows="3" class="form-control">{{ implode("\n",$definition->steps??[]) }}</textarea></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">บันทึก</button></div></div>
 </div></form>@endforeach
</div>
@endsection
