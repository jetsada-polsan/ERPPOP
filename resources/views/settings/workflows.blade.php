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
  <div class="row g-3"><div class="col-md-3"><label class="form-label">รูปแบบ</label><select name="mode" class="form-select"><option value="fast" @selected($definition->mode==='fast')>Fast Lane</option><option value="approval" @selected($definition->mode==='approval')>Approval Lane</option></select></div><div class="col-md-3"><label class="form-label">สิทธิ์อนุมัติ</label><select name="approval_permission" class="form-select"><option value="">ไม่กำหนด</option>@foreach($roles as $role) @foreach($role->permissions->where('code','like','%.approve%')->merge($role->permissions->whereIn('code',['stock.manage']))->unique('code') as $permission)<option value="{{ $permission->code }}" @selected($definition->approval_permission===$permission->code)>{{ $permission->code }} · {{ $role->name }}</option>@endforeach @endforeach</select></div><div class="col-md-3"><label class="form-label">ตำแหน่งผู้อนุมัติ</label><div class="border rounded p-2" style="max-height:120px;overflow:auto">@forelse($positions as $position)<label class="d-block small"><input type="checkbox" name="approver_positions[]" value="{{ $position }}" @checked(in_array($position,$definition->approver_positions??[],true))> {{ $position }}</label>@empty<span class="small text-muted">ยังไม่มีตำแหน่งในแฟ้มพนักงาน</span>@endforelse</div></div><div class="col-md-3"><label class="form-label">ลำดับขั้นตอน</label><textarea name="steps" rows="3" class="form-control workflow-steps-input">{{ implode("\n",$definition->steps??[]) }}</textarea><small class="text-muted">ลากตำแหน่งไปฝั่งขวา แล้วลากขึ้นลงเพื่อเรียงลำดับ</small></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">บันทึก</button></div></div>
  <div class="workflow-builder mt-3" data-steps='@json($definition->steps ?? [])'>
   <div class="workflow-pool"><strong>ตำแหน่งที่ใช้ได้</strong><small>ลากไปฝั่งขวา</small><div class="workflow-pool-items">@foreach($positions as $position)<button type="button" class="workflow-role" draggable="true" data-role="{{ $position }}">{{ $position }} <i class="bi bi-arrow-right"></i></button>@endforeach</div></div>
   <div class="workflow-target"><strong>ลำดับการอนุมัติ</strong><small>ขั้นตอนบนสุดทำก่อน</small><div class="workflow-step-list" data-empty="ลากตำแหน่งมาวางที่นี่"></div></div>
  </div>
 </div></form>@endforeach
</div>
@endsection

@push('head')
<style>
.workflow-builder{display:grid;grid-template-columns:1fr 1fr;gap:16px;background:#f7fbfe;border:1px solid #dbe7ef;border-radius:8px;padding:14px}
.workflow-pool,.workflow-target{background:#fff;border:1px solid #dbe7ef;border-radius:7px;padding:12px;min-height:150px}
.workflow-pool>small,.workflow-target>small{display:block;color:#627481;font-size:12px;margin:3px 0 10px}
.workflow-pool-items{display:flex;flex-wrap:wrap;gap:7px}
.workflow-role{border:1px solid #c3d4e2;background:#eef4f9;color:#1d3b52;border-radius:5px;padding:7px 9px;font:inherit;font-size:13px;cursor:grab}
.workflow-role:hover{border-color:#1585c0;color:#0f4c75}
.workflow-step-list{min-height:95px;border:1px dashed #9bb5c8;border-radius:6px;padding:7px}
.workflow-step-list:empty:before{content:attr(data-empty);display:block;text-align:center;color:#7b8d99;padding:25px 8px}
.workflow-step{display:flex;align-items:center;gap:8px;background:#eef4f9;border:1px solid #c3d4e2;border-radius:5px;padding:8px;margin-bottom:7px;cursor:grab}
.workflow-step .handle{color:#1585c0}.workflow-step .remove{margin-left:auto;border:0;background:transparent;color:#b42318;cursor:pointer}
@media(max-width:800px){.workflow-builder{grid-template-columns:1fr}}
</style>
@endpush

@push('scripts')
<script>
document.querySelectorAll('.workflow-builder').forEach(function(builder){
 const input=builder.closest('form').querySelector('.workflow-steps-input');
 const list=builder.querySelector('.workflow-step-list');
 let dragging=null;
 function sync(){input.value=[...list.querySelectorAll('.workflow-step')].map(x=>x.dataset.role).join('\n');}
 function add(role){if(!role)return; const el=document.createElement('div'); el.className='workflow-step'; el.draggable=true; el.dataset.role=role; el.innerHTML='<i class="bi bi-grip-vertical handle"></i><span></span><button type="button" class="remove" title="นำออก"><i class="bi bi-x-lg"></i></button>'; el.querySelector('span').textContent=role; el.querySelector('.remove').onclick=()=>{el.remove();sync()}; el.ondragstart=()=>{dragging=el}; el.ondragend=()=>{dragging=null}; list.appendChild(el);sync();}
 const initial=JSON.parse(builder.dataset.steps||'[]'); initial.forEach(add);
 builder.querySelectorAll('.workflow-role').forEach(btn=>{btn.ondragstart=()=>{dragging=btn.dataset.role};btn.onclick=()=>add(btn.dataset.role)});
 list.ondragover=e=>{e.preventDefault()}; list.ondrop=e=>{e.preventDefault();if(typeof dragging==='string'){add(dragging);dragging=null;return} if(dragging){const after=[...list.children].find(x=>e.clientY<x.getBoundingClientRect().top+x.offsetHeight/2);list.insertBefore(dragging,after||null);sync()}};
});
</script>
@endpush
