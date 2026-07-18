@extends('layout')
@section('title', 'เตรียมข้อมูล POS')
@section('page-title', 'เตรียมข้อมูล POS')
@section('page-subtitle', 'งาน P/O/S สำหรับส่งข้อมูล ลบข้อมูล และ sync เครื่อง POS')
@section('content')
<div class="content-card p-4 mb-3">
    <form method="post" action="{{ route('bplus.pos-preparation.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">เลขงาน</label><input name="job_no" value="POS{{ now()->format('ymdHis') }}" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="job_type" class="form-select"><option value="prepare_pos">P เตรียมข้อมูล</option><option value="delete_pos">O ลบ POS</option><option value="delete_online">S ลบ Online</option><option value="sync_sales">Y/Z รวมยอดขาย</option></select></div>
        <div class="col-md-2"><label class="form-label small text-muted">สาขา</label><select name="branch_id" class="form-select"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-muted">เครื่อง POS</label><select name="pos_terminal_id" class="form-select"><option value="">ทุกเครื่อง</option>@foreach($terminals as $terminal)<option value="{{ $terminal->id }}">{{ $terminal->code }} - {{ $terminal->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-muted">จากวันที่</label><input type="date" name="from_date" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ถึงวันที่</label><input type="date" name="to_date" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="draft">รอทำ</option><option value="queued">เข้าคิว</option><option value="done">เสร็จแล้ว</option></select></div>
        <div class="col-md-8"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">บันทึกงาน</button></div>
    </form>
</div>
<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการงาน POS</h2>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>เลขงาน</th><th>ประเภท</th><th>สาขา</th><th>เครื่อง</th><th>ช่วงวันที่</th><th>สถานะ</th></tr></thead>
        <tbody>@forelse($jobs as $job)<tr><td class="fw-semibold">{{ $job->job_no }}</td><td>{{ $job->job_type }}</td><td>{{ $job->branch?->name_th ?? 'ทุกสาขา' }}</td><td>{{ $job->terminal?->code ?? 'ทุกเครื่อง' }}</td><td>{{ $job->from_date?->thaiDate() ?? '-' }} - {{ $job->to_date?->thaiDate() ?? '-' }}</td><td><span class="badge text-bg-light border">{{ $job->status }}</span></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีงาน</td></tr>@endforelse</tbody>
    </table></div>
    {{ $jobs->links() }}
</div>
@endsection
