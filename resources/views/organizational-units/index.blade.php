@extends('layout')
@section('title','ผังองค์กร - JET ERP')
@section('page-title','ผังองค์กรและสายบังคับบัญชา')
@section('page-subtitle','แยกหน่วยงาน สาขา ผู้รับผิดชอบ และสังกัดพนักงานออกจากกัน')

@section('content')
<div x-data="{ tab:'structure', viewMode:'chart', addOpen:false, assignOpen:false, peopleOpen:false, selectedUnit:null }">
    <div class="org-toolbar mb-3">
        <div class="org-tabs"><button :class="tab==='structure'&&'active'" @click="tab='structure'">ผังหน่วยงาน</button><button :class="tab==='people'&&'active'" @click="tab='people'">สังกัดพนักงาน</button></div>
        <div class="org-view-switch" x-show="tab==='structure'"><button :class="viewMode==='chart'&&'active'" @click="viewMode='chart'"><i class="bi bi-diagram-3"></i> ผังบนลงล่าง</button><button :class="viewMode==='list'&&'active'" @click="viewMode='list'"><i class="bi bi-list-ul"></i> รายการแก้ไข</button></div>
        <div class="ms-auto d-flex gap-2"><button class="btn btn-light border" @click="assignOpen=true"><i class="bi bi-person-plus me-1"></i>เพิ่มสังกัด</button><button class="btn btn-primary" @click="addOpen=true"><i class="bi bi-diagram-3 me-1"></i>เพิ่มหน่วยงาน</button></div>
    </div>

    <div x-show="tab==='structure' && viewMode==='chart'" class="content-card org-tree-card">
        <div class="org-tree-help"><span><i class="bi bi-info-circle"></i> แผนผังแสดงสายบังคับบัญชาจากบนลงล่าง · กดหน่วยงานเพื่อเปิดรายการแก้ไข</span><span class="org-tree-pan"><button type="button" @click="$refs.orgTreeScroll.scrollBy({left:-500,behavior:'smooth'})" title="เลื่อนไปทางซ้าย"><i class="bi bi-arrow-left"></i></button><b>เลื่อนผัง</b><button type="button" @click="$refs.orgTreeScroll.scrollBy({left:500,behavior:'smooth'})" title="เลื่อนไปทางขวา"><i class="bi bi-arrow-right"></i></button></span></div>
        <div class="org-tree-scroll" x-ref="orgTreeScroll" @wheel.shift.prevent="$refs.orgTreeScroll.scrollLeft += $event.deltaY"><div class="org-tree"><ul>@foreach($roots as $unit) @include('organizational-units._tree-node',['unit'=>$unit,'units'=>$units]) @endforeach</ul></div></div>
    </div>

    <div x-show="tab==='structure' && viewMode==='list'" x-cloak class="content-card org-card">
        <div class="org-head"><span>หน่วยงาน / แผนก</span><span>ประเภท</span><span>สาขา</span><span>ผู้รับผิดชอบ</span><span>พนักงาน</span><span></span></div>
        @foreach($orgRows as $row)
        @php($unit=$row['unit'])
        <details class="org-row" style="--level:{{ $row['level'] }}">
            <summary>
                <span class="org-name"><i class="bi {{ $unit->unit_type==='company'?'bi-building-fill':($unit->unit_type==='management'?'bi-person-workspace':'bi-folder2-open') }}"></i><b>{{ $unit->name }}</b><small>{{ $unit->code }}</small></span>
                <span>{{ ['company'=>'บริษัท','management'=>'บริหาร','division'=>'ฝ่าย','department'=>'แผนก','team'=>'ทีม'][$unit->unit_type] ?? $unit->unit_type }}</span>
                <span>{{ $unit->branch ? $unit->branch->code.' · '.$unit->branch->name_th : 'ใช้ได้ทุกสาขา' }}</span>
                <span>{{ $unit->manager?->full_name ?? 'ยังไม่กำหนด' }}</span>
                <span><b>{{ number_format($unit->assignments_count) }}</b> คน</span>
                <span><i class="bi bi-chevron-down"></i></span>
            </summary>
            <form method="post" action="{{ route('organizational-units.update',$unit) }}" class="org-edit">@csrf @method('PUT')
                <label>รหัส<input class="form-control" name="code" value="{{ $unit->code }}" required></label>
                <label>ชื่อหน่วยงาน<input class="form-control" name="name" value="{{ $unit->name }}" required></label>
                <label>ประเภท<select class="form-select" name="unit_type">@foreach(['company'=>'บริษัท','management'=>'บริหาร','division'=>'ฝ่าย','department'=>'แผนก','team'=>'ทีม'] as $key=>$label)<option value="{{ $key }}" @selected($unit->unit_type===$key)>{{ $label }}</option>@endforeach</select></label>
                <label>อยู่ภายใต้<select class="form-select" name="parent_id"><option value="">หน่วยงานบนสุด</option>@foreach($units->where('id','!=',$unit->id) as $option)<option value="{{ $option->id }}" @selected($unit->parent_id===$option->id)>{{ $option->code }} · {{ $option->name }}</option>@endforeach</select></label>
                <label>ขอบเขตสาขา<select class="form-select" name="branch_id"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($unit->branch_id===$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></label>
                <label>ผู้รับผิดชอบ<select class="form-select" name="manager_employee_id"><option value="">ยังไม่กำหนด</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected($unit->manager_employee_id===$employee->id)>{{ $employee->employee_code }} · {{ $employee->full_name }}</option>@endforeach</select></label>
                <label>ลำดับ<input class="form-control" type="number" name="sort_order" value="{{ $unit->sort_order }}" min="0"></label>
                <label class="org-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($unit->is_active)> ใช้งาน</label>
                <label class="wide">หมายเหตุ<input class="form-control" name="description" value="{{ $unit->description }}"></label>
                <div class="org-actions wide"><button class="btn btn-primary">บันทึก</button></div>
            </form>
        </details>
        @endforeach
    </div>

    <div x-show="tab==='people'" x-cloak class="content-card org-card">
        <div class="table-responsive">
        <table class="table align-middle"><thead><tr><th>พนักงาน</th><th>หน่วยงาน</th><th>ตำแหน่งในหน่วยงาน</th><th>สาขาประจำ</th><th>ประเภทสังกัด</th><th></th></tr></thead><tbody>
        @forelse($assignments as $assignment)<tr><td><b>{{ $assignment->employee?->employee_code }}</b><small>{{ $assignment->employee?->full_name }}</small></td><td>{{ $assignment->organizationalUnit?->name }}</td><td>{{ $assignment->position_title ?: '-' }}</td><td>{{ $assignment->employee?->branch?->code ?? '-' }}</td><td><span class="badge {{ $assignment->is_primary?'text-bg-primary':'text-bg-light border' }}">{{ $assignment->is_primary?'สังกัดหลัก':'สังกัดเสริม' }}</span></td><td class="text-end"><form method="post" action="{{ route('organizational-units.assignments.destroy',$assignment) }}" onsubmit="return confirm('นำสังกัดนี้ออก?')">@csrf @method('DELETE')<button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash"></i></button></form></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีข้อมูลสังกัด</td></tr>@endforelse
        </tbody></table>
        </div>
    </div>

    <div class="booking-modal-backdrop" x-show="addOpen" x-cloak @keydown.escape.window="addOpen=false"><div class="booking-modal org-modal" @click.outside="addOpen=false"><div class="modal-header"><h3>เพิ่มหน่วยงาน</h3><button class="btn btn-light rounded-circle" @click="addOpen=false"><i class="bi bi-x-lg"></i></button></div><form method="post" action="{{ route('organizational-units.store') }}">@csrf<div class="modal-body org-form"><label>รหัส<input class="form-control" name="code" required></label><label>ชื่อหน่วยงาน<input class="form-control" name="name" required></label><label>ประเภท<select class="form-select" name="unit_type"><option value="department">แผนก</option><option value="division">ฝ่าย</option><option value="team">ทีม</option><option value="management">บริหาร</option></select></label><label>อยู่ภายใต้<select class="form-select" name="parent_id"><option value="">หน่วยงานบนสุด</option>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }} · {{ $unit->name }}</option>@endforeach</select></label><input type="hidden" name="is_active" value="1"></div><div class="modal-footer"><button type="button" class="btn btn-light border" @click="addOpen=false">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div></form></div></div>

    <div class="booking-modal-backdrop" x-show="assignOpen" x-cloak @keydown.escape.window="assignOpen=false"><div class="booking-modal org-modal" @click.outside="assignOpen=false"><div class="modal-header"><h3>เพิ่มสังกัดพนักงาน</h3><button class="btn btn-light rounded-circle" @click="assignOpen=false"><i class="bi bi-x-lg"></i></button></div><form method="post" action="{{ route('organizational-units.assign') }}">@csrf<div class="modal-body org-form"><label>พนักงาน<select class="form-select" name="employee_id" required><option value="">เลือกพนักงาน</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_code }} · {{ $employee->full_name }}</option>@endforeach</select></label><label>หน่วยงาน<select class="form-select" name="organizational_unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->code }} · {{ $unit->name }}</option>@endforeach</select></label><label>ตำแหน่งในหน่วยงาน<input class="form-control" name="position_title"></label><label class="org-check"><input type="checkbox" name="is_primary" value="1"> กำหนดเป็นสังกัดหลัก</label></div><div class="modal-footer"><button type="button" class="btn btn-light border" @click="assignOpen=false">ยกเลิก</button><button class="btn btn-primary">บันทึก</button></div></form></div></div>

    @foreach($units as $unit)
    <dialog id="org-people-{{ $unit->id }}" class="org-people-dialog" onclick="if(event.target===this)this.close()">
        <div class="org-people-dialog-card">
            <div class="modal-header">
                <div><h3><i class="bi bi-people me-2"></i>{{ $unit->name }}</h3><small>{{ $unit->code }} · พนักงาน {{ number_format($unit->assignments_count) }} คน</small></div>
                <button type="button" class="btn btn-light rounded-circle" onclick="this.closest('dialog').close()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="org-people-positions">
                @foreach($unit->positions as $position)<span class="{{ $position->holder ? 'filled' : 'vacant' }}"><b>{{ $position->title }}</b>{{ $position->holder?->full_name ?? 'ว่าง' }}</span>@endforeach
            </div>
            <div class="org-people-list">
                @forelse($assignments->where('organizational_unit_id', $unit->id) as $assignment)
                    <div class="org-person-row">
                        <span class="org-person-avatar">{{ mb_substr($assignment->employee?->nickname ?: $assignment->employee?->full_name, 0, 1) }}</span>
                        <span><b>{{ $assignment->employee?->full_name }}</b><small>{{ $assignment->employee?->employee_code }} · {{ $assignment->position_title ?: ($assignment->employee?->position ?: 'ยังไม่ระบุตำแหน่ง') }}</small></span>
                        <span class="org-person-branch">{{ $assignment->employee?->branch?->code ?? 'ไม่ระบุสาขา' }}</span>
                    </div>
                @empty
                    <div class="org-people-empty"><i class="bi bi-person-x"></i><b>ยังไม่มีพนักงานในฝ่ายนี้</b><small>ตำแหน่งว่างยังแสดงไว้ด้านบน</small></div>
                @endforelse
            </div>
        </div>
    </dialog>
    @endforeach

    <div class="booking-modal-backdrop" x-show="peopleOpen" x-cloak @keydown.escape.window="peopleOpen=false">
        <div class="booking-modal org-people-modal" @click.outside="peopleOpen=false">
            @foreach($units as $unit)
                <section x-show="selectedUnit==={{ $unit->id }}">
                    <div class="modal-header">
                        <div><h3><i class="bi bi-people me-2"></i>{{ $unit->name }}</h3><small>{{ $unit->code }} · พนักงาน {{ number_format($unit->assignments_count) }} คน</small></div>
                        <button type="button" class="btn btn-light rounded-circle" @click="peopleOpen=false"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="org-people-positions">
                        @foreach($unit->positions as $position)<span class="{{ $position->holder ? 'filled' : 'vacant' }}"><b>{{ $position->title }}</b>{{ $position->holder?->full_name ?? 'ว่าง' }}</span>@endforeach
                    </div>
                    <div class="org-people-list">
                        @forelse($assignments->where('organizational_unit_id', $unit->id) as $assignment)
                            <div class="org-person-row">
                                <span class="org-person-avatar">{{ mb_substr($assignment->employee?->nickname ?: $assignment->employee?->full_name, 0, 1) }}</span>
                                <span><b>{{ $assignment->employee?->full_name }}</b><small>{{ $assignment->employee?->employee_code }} · {{ $assignment->position_title ?: ($assignment->employee?->position ?: 'ยังไม่ระบุตำแหน่ง') }}</small></span>
                                <span class="org-person-branch">{{ $assignment->employee?->branch?->code ?? 'ไม่ระบุสาขา' }}</span>
                            </div>
                        @empty
                            <div class="org-people-empty"><i class="bi bi-person-x"></i><b>ยังไม่มีพนักงานในฝ่ายนี้</b><small>ตำแหน่งว่างยังแสดงไว้ด้านบน</small></div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
.org-toolbar{display:flex;align-items:center;gap:10px}.org-tabs,.org-view-switch{display:flex;padding:3px;background:#e5edf3;border-radius:8px}.org-tabs button,.org-view-switch button{border:0;background:transparent;padding:7px 14px;border-radius:6px;color:#587084;font-weight:700}.org-tabs button.active,.org-view-switch button.active{background:#fff;color:#1585c0;box-shadow:0 2px 7px rgba(15,23,42,.1)}.org-card{overflow:hidden}.org-head,.org-row summary{display:grid;grid-template-columns:minmax(280px,1.4fr) 100px 190px minmax(180px,1fr) 80px 28px;align-items:center;gap:8px}.org-head{padding:8px 12px;background:#eaf3f8;color:#49677b;font-size:12px;font-weight:800}.org-row{border-top:1px solid #e1e8ee}.org-row summary{padding:8px 12px;cursor:pointer;list-style:none}.org-row summary:hover{background:#f7fafc}.org-name{padding-left:calc(var(--level)*22px);display:flex;align-items:center;gap:7px}.org-name i{color:#168dcc}.org-name small,.org-card td small{display:block;color:#8091a0;font-size:11px}.org-edit{display:grid;grid-template-columns:130px 1fr 140px 1fr;gap:8px;padding:12px 20px;background:#f5f7f9;border-top:1px dashed #cbd5dd}.org-edit label,.org-form label{color:#536b7d;font-size:12px;font-weight:700}.org-edit .wide{grid-column:1/-1}.org-check{display:flex!important;align-items:center;gap:6px}.org-actions{display:flex;justify-content:flex-end}.org-modal{width:min(640px,calc(100vw - 30px))}.org-form{display:grid;grid-template-columns:1fr 1fr;gap:10px}.org-form label{display:grid;gap:4px}
.org-tree-card{padding:0;overflow:hidden;max-width:100%}.org-tree-help{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 12px;border-bottom:1px solid #dce7ef;background:#f7fbfd;color:#60788b;font-size:12px}.org-tree-pan{display:flex;align-items:center;gap:6px;white-space:nowrap}.org-tree-pan b{font-size:11px}.org-tree-pan button{width:30px;height:28px;border:1px solid #cbdbe5;border-radius:6px;background:#fff;color:#1585c0}.org-tree-pan button:hover{background:#e4f4fc}.org-tree-scroll{width:100%;max-width:100%;overflow-x:scroll;overflow-y:hidden;padding-bottom:8px;scrollbar-gutter:stable;scrollbar-color:#7fa9c2 #e7eef3}.org-tree-scroll::-webkit-scrollbar{height:13px}.org-tree-scroll::-webkit-scrollbar-track{background:#e7eef3}.org-tree-scroll::-webkit-scrollbar-thumb{border:3px solid #e7eef3;border-radius:10px;background:#7fa9c2}.org-tree{width:max-content;min-width:100%;padding:24px 20px 28px}.org-tree ul{position:relative;display:flex;justify-content:center;margin:0;padding-top:24px;padding-left:0}.org-tree li{position:relative;list-style:none;text-align:center;padding:24px 7px 0}.org-tree>ul>li{padding-top:0}.org-tree li::before,.org-tree li::after{content:'';position:absolute;top:0;width:50%;height:24px;border-top:1px solid #9bb5c6}.org-tree li::before{right:50%;border-right:1px solid #9bb5c6}.org-tree li::after{left:50%;border-left:1px solid #9bb5c6}.org-tree li:only-child::before,.org-tree li:only-child::after{display:none}.org-tree li:first-child::before,.org-tree li:last-child::after{border:0}.org-tree li:last-child::before{border-radius:0 6px 0 0}.org-tree li:first-child::after{border-radius:6px 0 0}.org-tree ul ul::before{content:'';position:absolute;top:0;left:50%;height:24px;border-left:1px solid #9bb5c6}.org-tree>ul::before,.org-tree>ul>li::before,.org-tree>ul>li::after{display:none}.org-tree-node{width:160px;min-height:94px;padding:9px;border:1px solid #c9d9e4;border-radius:9px;background:#fff;color:#29465b;box-shadow:0 4px 12px rgba(15,51,74,.07);transition:.15s}.org-tree-node:hover{transform:translateY(-2px);border-color:#4aaddc;box-shadow:0 7px 18px rgba(15,51,74,.13)}.org-tree-node.type-company{border-top:4px solid #1585c0}.org-tree-node.type-management{border-top:4px solid #7357c7}.org-tree-icon{display:grid;place-items:center;width:27px;height:27px;margin:0 auto 5px;border-radius:7px;background:#e4f4fc;color:#1585c0}.org-tree-node strong,.org-tree-node small,.org-tree-manager{display:block}.org-tree-node strong{font-size:13px}.org-tree-node small{margin-top:2px;color:#718697;font-size:10px}.org-tree-manager{margin-top:6px;padding-top:5px;border-top:1px solid #edf2f5;color:#567083;font-size:9.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:1100px){.org-head{display:none}.org-row summary{grid-template-columns:1fr 120px 28px}.org-row summary>span:nth-child(3),.org-row summary>span:nth-child(4),.org-row summary>span:nth-child(5){display:none}.org-edit{grid-template-columns:1fr 1fr}}@media(max-width:650px){.org-toolbar{align-items:stretch;flex-direction:column}.org-toolbar .ms-auto{margin-left:0!important}.org-edit,.org-form{grid-template-columns:1fr}.org-edit .wide{grid-column:auto}}
.org-tree-positions{display:block;margin-top:5px;padding-top:4px;border-top:1px dashed #dce7ed}.org-tree-positions em{display:block;padding:2px 3px;color:#435f72;font-size:9px;font-style:normal;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.org-tree-positions em.vacant{color:#b36a18;background:#fff7e7;border-radius:4px}
.org-people-modal{width:min(680px,calc(100vw - 28px));max-height:min(76vh,720px);overflow:hidden}.org-people-modal section{display:flex;flex-direction:column;max-height:min(76vh,720px)}.org-people-modal .modal-header small{display:block;margin-top:2px;color:#718697;font-size:11px}.org-people-positions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;padding:10px 14px;background:#f3f8fb;border-bottom:1px solid #dae6ed}.org-people-positions span{display:flex;justify-content:space-between;gap:8px;padding:7px 9px;border:1px solid #d4e2eb;border-radius:7px;background:#fff;color:#476275;font-size:11px}.org-people-positions span.vacant{border-color:#f0d7a5;background:#fff8e9;color:#a96416}.org-people-list{padding:8px 14px 14px;overflow-y:auto}.org-person-row{display:grid;grid-template-columns:34px minmax(0,1fr) auto;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid #edf2f5;text-align:left}.org-person-avatar{display:grid;place-items:center;width:32px;height:32px;border-radius:9px;background:#e4f4fc;color:#1386c1;font-weight:800}.org-person-row b,.org-person-row small{display:block}.org-person-row b{color:#29465b;font-size:13px}.org-person-row small{margin-top:2px;color:#7a8e9c;font-size:10.5px}.org-person-branch{padding:3px 7px;border-radius:10px;background:#edf4f8;color:#567083;font-size:10px}.org-people-empty{display:grid;justify-items:center;gap:4px;padding:34px;color:#8a9ba8}.org-people-empty i{font-size:28px}.org-people-empty small{font-size:11px}@media(max-width:560px){.org-people-positions{grid-template-columns:1fr}}
.org-people-dialog{width:min(680px,calc(100vw - 28px));max-height:min(76vh,720px);padding:0;border:0;border-radius:12px;background:#fff;box-shadow:0 22px 70px rgba(8,30,48,.3)}.org-people-dialog::backdrop{background:rgba(17,40,58,.58);backdrop-filter:blur(3px)}.org-people-dialog-card{display:flex;flex-direction:column;max-height:min(76vh,720px)}.org-people-dialog .modal-header small{display:block;margin-top:2px;color:#718697;font-size:11px}
</style>
@endpush
