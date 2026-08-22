@extends('layout')
@section('title', 'เงินสด/ธนาคาร')
@section('page-title', 'เงินสด/ธนาคาร')
@section('page-subtitle', 'สมุดเงินสด ฝาก ถอน โอน และบัญชีธนาคาร')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-xl-5"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">ปรับปรุงสมุดเงินสด</h2>
        <p class="small text-muted">รายการปกติ (ขาย POS, ปิดกะ, ขายสด, รับชำระ, ค่าใช้จ่าย) ระบบเดินให้อัตโนมัติ — ฟอร์มนี้ใช้เฉพาะรายการปรับปรุงที่ต้องมีเหตุผลและผู้อนุมัติ</p>
        <form method="post" action="{{ route('bplus.cash-books.store') }}" class="row g-3">@csrf
        <div class="col-md-6"><label class="form-label small text-muted">วันที่</label><input type="date" name="entry_date" value="{{ now()->toDateString() }}" required class="form-control"></div>
        <div class="col-md-6"><label class="form-label small text-muted">สาขา</label><select name="branch_id" class="form-select"><option value="">--</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label small text-muted">รับ</label><input type="number" step="0.0001" name="cash_in" value="0" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small text-muted">จ่าย</label><input type="number" step="0.0001" name="cash_out" value="0" class="form-control"></div>
        <div class="col-12"><input name="description" class="form-control" placeholder="รายการ" required></div>
        <div class="col-12"><input name="reason" class="form-control" placeholder="เหตุผลที่ต้องปรับ (บังคับ)" required></div>
        <div class="col-12"><label class="form-label small text-muted">ผู้อนุมัติ</label><select name="approved_by" class="form-select" required><option value="">-- เลือกผู้อนุมัติ --</option>@foreach($approvers as $approver)<option value="{{ $approver->id }}">{{ $approver->name }}</option>@endforeach</select></div>
        <div class="col-12 text-end"><button class="btn btn-primary px-4">บันทึก</button></div>
    </form></div></div>
    <div class="col-xl-7"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">บัญชีธนาคาร</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ธนาคาร</th><th>เลขบัญชี</th><th>ชื่อบัญชี</th><th>สถานะ</th></tr></thead><tbody>@foreach($bankAccounts as $account)<tr><td>{{ $account->bank_name }}</td><td>{{ $account->account_no }}</td><td>{{ $account->account_name }}</td><td><span class="badge {{ $account->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $account->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td></tr>@endforeach</tbody></table></div></div></div>
</div>
<div class="content-card p-4"><h2 class="h5 fw-bold mb-3">สมุดเงินสด</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>วันที่</th><th>สาขา</th><th>ที่มา</th><th>รายการ</th><th class="text-end">รับ</th><th class="text-end">จ่าย</th><th class="text-end">คงเหลือ</th></tr></thead><tbody>@forelse($cashBooks as $entry)<tr><td>{{ $entry->entry_date?->thaiDate() }}</td><td>{{ $entry->branch?->name_th ?? '-' }}</td><td><code class="small">{{ $entry->source_type }}</code></td><td>{{ $entry->description }}</td><td class="text-end">{{ number_format((float) $entry->cash_in, 2) }}</td><td class="text-end">{{ number_format((float) $entry->cash_out, 2) }}</td><td class="text-end fw-bold">{{ number_format((float) $entry->running_balance, 2) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีรายการ</td></tr>@endforelse</tbody></table></div>{{ $cashBooks->links() }}</div>
@endsection
