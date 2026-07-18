@extends('layout')
@section('title', 'อนุมัติเอกสาร')
@section('page-title', 'อนุมัติเอกสาร')
@section('page-subtitle', 'วงเงินเครดิตขาย/ซื้อ และงานอนุมัติทั่วไป')
@section('content')
<div class="content-card p-4 mb-3">
    <form method="post" action="{{ route('bplus.approvals.store') }}" class="row g-3">@csrf
        <div class="col-md-2"><label class="form-label small text-muted">เลขคำขอ</label><input name="request_no" value="AP{{ now()->format('ymdHis') }}" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="approval_type" class="form-select"><option value="sales_credit">เครดิตขาย</option><option value="purchase_credit">เครดิตซื้อ</option><option value="payment">จ่ายเงิน</option><option value="stock">ปรับสต็อก</option></select></div>
        <div class="col-md-3"><label class="form-label small text-muted">เรื่อง</label><input name="subject" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ยอดเงิน</label><input type="number" step="0.0001" name="amount" value="0" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="pending">รออนุมัติ</option><option value="approved">อนุมัติ</option><option value="rejected">ไม่อนุมัติ</option></select></div>
        <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
        <div class="col-md-3"><input name="requested_by" class="form-control" placeholder="ผู้ขอ"></div>
        <div class="col-md-3"><input name="approved_by" class="form-control" placeholder="ผู้อนุมัติ"></div>
        <div class="col-md-6"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
    </form>
</div>
<div class="content-card p-4"><h2 class="h5 fw-bold mb-3">รายการอนุมัติ</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>เลขคำขอ</th><th>ประเภท</th><th>เรื่อง</th><th class="text-end">ยอด</th><th>ผู้ขอ</th><th>สถานะ</th></tr></thead><tbody>@forelse($requests as $request)<tr><td class="fw-semibold">{{ $request->request_no }}</td><td>{{ $request->approval_type }}</td><td>{{ $request->subject }}</td><td class="text-end">{{ number_format((float) $request->amount, 2) }}</td><td>{{ $request->requested_by ?? '-' }}</td><td><span class="badge text-bg-light border">{{ $request->status }}</span></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีคำขอ</td></tr>@endforelse</tbody></table></div>{{ $requests->links() }}</div>
@endsection
