@extends('layout')
@section('title', 'เงินสด/ธนาคาร')
@section('page-title', 'เงินสด/ธนาคาร')
@section('page-subtitle', 'สมุดเงินสด ฝาก ถอน โอน และบัญชีธนาคาร')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-xl-5"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">บันทึกสมุดเงินสด</h2><form method="post" action="{{ route('bplus.cash-books.store') }}" class="row g-3">@csrf
        <div class="col-md-6"><label class="form-label small text-muted">วันที่</label><input type="date" name="entry_date" value="{{ now()->toDateString() }}" required class="form-control"></div>
        <div class="col-md-6"><label class="form-label small text-muted">สาขา</label><select name="branch_id" class="form-select"><option value="">--</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label small text-muted">รับ</label><input type="number" step="0.0001" name="debit" value="0" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small text-muted">จ่าย</label><input type="number" step="0.0001" name="credit" value="0" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small text-muted">คงเหลือ</label><input type="number" step="0.0001" name="balance" class="form-control"></div>
        <div class="col-12"><input name="description" class="form-control" placeholder="รายการ"></div>
        <div class="col-12 text-end"><button class="btn btn-primary px-4">บันทึก</button></div>
    </form></div></div>
    <div class="col-xl-7"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">บัญชีธนาคาร</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ธนาคาร</th><th>เลขบัญชี</th><th>ชื่อบัญชี</th><th>สถานะ</th></tr></thead><tbody>@foreach($bankAccounts as $account)<tr><td>{{ $account->bank_name }}</td><td>{{ $account->account_no }}</td><td>{{ $account->account_name }}</td><td><span class="badge {{ $account->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $account->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td></tr>@endforeach</tbody></table></div></div></div>
</div>
<div class="content-card p-4"><h2 class="h5 fw-bold mb-3">สมุดเงินสด</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>วันที่</th><th>สาขา</th><th>รายการ</th><th class="text-end">รับ</th><th class="text-end">จ่าย</th><th class="text-end">คงเหลือ</th></tr></thead><tbody>@forelse($cashBooks as $entry)<tr><td>{{ $entry->entry_date?->thaiDate() }}</td><td>{{ $entry->branch?->name_th ?? '-' }}</td><td>{{ $entry->description }}</td><td class="text-end">{{ number_format((float) $entry->debit, 2) }}</td><td class="text-end">{{ number_format((float) $entry->credit, 2) }}</td><td class="text-end fw-bold">{{ number_format((float) $entry->balance, 2) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีรายการ</td></tr>@endforelse</tbody></table></div>{{ $cashBooks->links() }}</div>
@endsection
