@extends('layout')
@section('title', 'ศูนย์ควบคุมบริหาร - POPSTAR ERP')
@section('page-title', 'ศูนย์ควบคุมบริหาร')
@section('page-subtitle', 'Budget · Payroll · Purchase Plan · E-Commerce · Profit · Monitoring')
@section('content')
<div x-data="{tab:'profit'}">
    <form class="d-flex gap-2 mb-3"><input type="month" name="period" value="{{ $period }}" class="form-control" style="max-width:190px"><button class="btn btn-primary">เปลี่ยนงวด</button></form>
    <div class="btn-group mb-4 flex-wrap" role="tablist">
        @php($tabs=['profit'=>'กำไรสุทธิ'])
        @if(auth()->user()->hasPermission('purchasing.manage')) @php($tabs['purchase']='แผนซื้อ') @endif
        @if(auth()->user()->hasPermission('budget.manage')) @php($tabs['budget']='Budget') @endif
        @if(auth()->user()->hasPermission('payroll.manage')) @php($tabs['attendance']='เวลา/Payroll') @endif
        @if(auth()->user()->hasPermission('ecommerce.sync')) @php($tabs['ecommerce']='E-Commerce') @endif
        @if(auth()->user()->hasPermission('monitoring.manage')) @php($tabs['monitor']='Monitoring') @endif
        @foreach($tabs as $key=>$label)
        <button type="button" class="btn" :class="tab==='{{ $key }}'?'btn-dark':'btn-light border'" @click="tab='{{ $key }}'">{{ $label }}</button>
        @endforeach
    </div>

    <section x-show="tab==='profit'" class="content-card p-4"><h2 class="h6 fw-bold mb-3">กำไรหลังต้นทุนและค่าใช้จ่าย {{ $period }}</h2>
        <div class="table-responsive"><table class="table"><thead><tr><th>สาขา</th><th class="text-end">ยอดขาย</th><th class="text-end">ต้นทุนขาย</th><th class="text-end">ค่าใช้จ่าย</th><th class="text-end">กำไรสุทธิ</th></tr></thead><tbody>
        @foreach($profit as $row)<tr><td>{{ $row->code }} {{ $row->name_th }}</td><td class="text-end">{{ number_format($row->sales,2) }}</td><td class="text-end">{{ number_format($row->cogs,2) }}</td><td class="text-end">{{ number_format($row->expenses,2) }}</td><td class="text-end fw-bold {{ $row->net_profit<0?'text-danger':'text-success' }}">{{ number_format($row->net_profit,2) }}</td></tr>@endforeach
        </tbody></table></div>
    </section>

    @if(auth()->user()->hasPermission('purchasing.manage'))
    <section x-show="tab==='purchase'" class="content-card p-4"><div class="d-flex justify-content-between mb-3"><h2 class="h6 fw-bold">แผนซื้อจาก Min/Max</h2><form method="post" action="{{ route('management-controls.purchase-plans.generate') }}">@csrf<button class="btn btn-primary">สร้างคำแนะนำใหม่</button></form></div>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>เลขแผน</th><th>สินค้า</th><th>ผู้ขาย</th><th class="text-end">แนะนำซื้อ</th><th>สถานะ</th></tr></thead><tbody>@forelse($purchasePlans as $p)<tr><td>{{ $p->plan_no }}</td><td>{{ $p->product?->sku_code }} {{ $p->product?->name_th }}</td><td>{{ $p->supplier?->name_th ?? '-' }}</td><td class="text-end">{{ number_format($p->suggested_qty,4) }}</td><td>{{ $p->status }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">ยังไม่มีแผน</td></tr>@endforelse</tbody></table></div>
    </section>

    @endif
    @if(auth()->user()->hasPermission('budget.manage'))
    <section x-show="tab==='budget'" class="content-card p-4"><h2 class="h6 fw-bold">Cost Center และ Budget</h2>
        <form method="post" action="{{ route('management-controls.cost-centers.store') }}" class="row g-2 mb-3">@csrf<div class="col-md-2"><input name="code" required class="form-control" placeholder="รหัส"></div><div class="col-md-4"><input name="name" required class="form-control" placeholder="ชื่อ Cost Center"></div><div class="col-md-2"><button class="btn btn-primary">เพิ่ม Cost Center</button></div></form>
        <form method="post" action="{{ route('management-controls.budgets.store') }}" class="row g-2 mb-4">@csrf
            <div class="col-md-2"><input name="fiscal_year" value="{{ substr($period,0,4) }}" required class="form-control"></div>
            <div class="col-md-2"><select name="cost_center_id" required class="form-select">@foreach($costCenters as $c)<option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-1"><input type="number" min="1" max="12" name="month" value="{{ (int)substr($period,5,2) }}" class="form-control"></div>
            <div class="col-md-3"><select name="account_id" required class="form-select">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name_th }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="number" step="0.01" min="0" name="budget_amount" required class="form-control" placeholder="วงเงิน"></div><div class="col-md-2"><button class="btn btn-primary">บันทึก Budget</button></div>
        </form>
        <table class="table table-sm"><thead><tr><th>เลข Budget</th><th>Cost Center</th><th class="text-end">วงเงินรวม</th><th>สถานะ</th><th></th></tr></thead><tbody>@foreach($budgets as $b)<tr><td>{{ $b->budget_no }}</td><td>{{ $b->cost_center_name }}</td><td class="text-end">{{ number_format($b->total_amount,2) }}</td><td><span class="badge {{ $b->status==='approved'?'text-bg-success':'text-bg-warning' }}">{{ $b->status==='approved'?'อนุมัติแล้ว':'ร่าง' }}</span></td><td class="text-end"><a href="{{ route('management-controls.budgets.show',$b->id) }}" class="btn btn-sm btn-light border">เทียบงบ vs จริง</a></td></tr>@endforeach</tbody></table>
    </section>

    @endif
    @if(auth()->user()->hasPermission('payroll.manage'))
    <section x-show="tab==='attendance'" class="content-card p-4"><h2 class="h6 fw-bold">เวลาเข้างานและ Payroll</h2>
        <form method="post" action="{{ route('management-controls.attendance.store') }}" class="row g-2 mb-3">@csrf
            <div class="col-md-3"><select name="employee_id" required class="form-select">@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->employee_code }} {{ $e->full_name }}</option>@endforeach</select></div>
            <div class="col-md-2"><input type="date" name="work_date" value="{{ now()->toDateString() }}" class="form-control"></div>
            <div class="col-md-2"><select name="status" class="form-select"><option value="present">มาทำงาน</option><option value="late">สาย</option><option value="leave">ลา</option><option value="absent">ขาด</option><option value="holiday">วันหยุด</option></select></div>
            <div class="col-md-2"><input type="number" step="0.25" min="0" name="overtime_hours" class="form-control" placeholder="OT ชั่วโมง"></div><div class="col-md-2"><button class="btn btn-primary">บันทึกเวลา</button></div>
        </form>
        <form method="post" action="{{ route('management-controls.payroll.generate') }}" class="d-flex gap-2 mb-3">@csrf<input type="month" name="period" value="{{ $period }}" class="form-control" style="max-width:190px"><button class="btn btn-danger">คำนวณ Payroll</button></form>
        <table class="table table-sm"><thead><tr><th>งวด</th><th class="text-end">รายได้รวม</th><th class="text-end">หักรวม</th><th class="text-end">สุทธิ</th><th>สถานะ</th><th></th></tr></thead><tbody>@foreach($payrollRuns as $p)<tr><td>{{ $p->period }}</td><td class="text-end">{{ number_format($p->gross_amount,2) }}</td><td class="text-end">{{ number_format($p->deduction_amount,2) }}</td><td class="text-end fw-bold">{{ number_format($p->net_amount,2) }}</td><td><span class="badge {{ ['draft'=>'text-bg-warning','approved'=>'text-bg-info','paid'=>'text-bg-success'][$p->status]??'text-bg-secondary' }}">{{ ['draft'=>'ร่าง','approved'=>'อนุมัติแล้ว','paid'=>'จ่ายแล้ว'][$p->status]??$p->status }}</span></td><td class="text-end"><a href="{{ route('management-controls.payroll.show',$p->id) }}" class="btn btn-sm btn-light border">ตรวจ/อนุมัติ</a></td></tr>@endforeach</tbody></table>
    </section>

    @endif
    @if(auth()->user()->hasPermission('ecommerce.sync'))
    <section x-show="tab==='ecommerce'" class="content-card p-4"><h2 class="h6 fw-bold">คำสั่งซื้อ E-Commerce</h2>
        <form method="post" action="{{ route('management-controls.ecommerce.orders.store') }}" class="row g-2 mb-3">@csrf
            <div class="col-md-2"><select name="ecommerce_channel_id" required class="form-select">@foreach($channels as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><input name="external_order_id" required class="form-control" placeholder="Order ID"></div><div class="col-md-2"><input name="customer_name" class="form-control" placeholder="ลูกค้า"></div>
            <div class="col-md-2"><input name="status" value="new" required class="form-control"></div><div class="col-md-2"><input type="number" step="0.01" name="total_amount" required class="form-control" placeholder="ยอดรวม"></div><div class="col-md-2"><button class="btn btn-primary">นำเข้า Order</button></div>
            <div class="col-12"><textarea name="items" class="form-control" rows="2" placeholder='รายการ JSON เช่น [{"sku":"100001","qty":2,"unit_price":50}]'></textarea></div>
        </form>
        <table class="table table-sm"><thead><tr><th>ช่องทาง</th><th>Order</th><th>ลูกค้า</th><th>สถานะ</th><th class="text-end">ยอด</th></tr></thead><tbody>@foreach($orders as $o)<tr><td>{{ $o->channel_name }}</td><td>{{ $o->external_order_id }}</td><td>{{ $o->customer_name }}</td><td>{{ $o->status }}</td><td class="text-end">{{ number_format($o->total_amount,2) }}</td></tr>@endforeach</tbody></table>
    </section>

    @endif
    @if(auth()->user()->hasPermission('monitoring.manage'))
    <section x-show="tab==='monitor'" class="content-card p-4"><h2 class="h6 fw-bold">Monitoring Events</h2>
        <table class="table table-sm"><thead><tr><th>เวลา</th><th>Check</th><th>ระดับ</th><th>สถานะ</th><th>รายละเอียด</th></tr></thead><tbody>@forelse($monitorEvents as $e)<tr><td>{{ $e->detected_at }}</td><td>{{ $e->check_code }}</td><td>{{ $e->severity }}</td><td>{{ $e->status }}</td><td>{{ $e->message }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">ไม่มีเหตุผิดปกติ</td></tr>@endforelse</tbody></table>
    </section>
    @endif
</div>
@endsection
