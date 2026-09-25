@extends('layout')
@section('title', 'ใบเพิ่ม/ลดหนี้ผู้ขาย')
@section('page-title', 'ใบเพิ่ม/ลดหนี้ผู้ขาย')
@section('content')
<form method="post" action="{{ route('supplier-notes.store') }}" class="row g-3 mb-4">
    @csrf
    <div class="col-md-3"><label class="form-label" for="kind">ประเภท</label><select id="kind" name="kind" class="form-select"><option value="credit">ใบลดหนี้ผู้ขาย</option><option value="debit">ใบเพิ่มหนี้ผู้ขาย</option></select></div>
    <div class="col-md-9"><label class="form-label" for="source">ใบซื้ออ้างอิง</label><select id="source" name="supplier_open_item_id" class="form-select" required>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->document_no }} · {{ $item->supplier->name_th }} · ค้าง {{ number_format($item->balance_amount, 2) }}</option>@endforeach</select></div>
    <div class="col-md-6"><label class="form-label" for="account">บัญชีปรับมูลค่า</label><select id="account" name="account_id" class="form-select" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} {{ $account->name_th }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label" for="base">ยอดก่อนภาษี</label><input id="base" name="base_amount" type="number" step="0.01" min="0.01" required class="form-control" value="{{ old('base_amount') }}"></div>
    <div class="col-md-3"><label class="form-label" for="vat">ภาษีซื้อ</label><input id="vat" name="vat_amount" type="number" step="0.01" min="0" required class="form-control" value="{{ old('vat_amount', 0) }}"></div>
    <div class="col-md-10"><label class="form-label" for="reason">เหตุผล / เลขที่เอกสารผู้ขาย</label><input id="reason" name="reason" required maxlength="1000" class="form-control" value="{{ old('reason') }}"></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary"><i class="bi bi-send me-1"></i>ส่งอนุมัติ</button></div>
</form>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>เอกสาร</th><th>ผู้ขาย</th><th>อ้างอิง</th><th>ยอดเงิน</th><th>สถานะ</th><th>อนุมัติ</th></tr></thead><tbody>
@foreach($notes as $note)
<tr><td>{{ $note->doc_number }}</td><td>{{ $note->supplier?->name_th }}</td><td>{{ $note->reference }}</td><td>{{ number_format($note->total_amount, 2) }}</td><td>{{ ['pending_approval'=>'รออนุมัติ','active'=>'อนุมัติแล้ว','rejected'=>'ไม่อนุมัติ'][$note->status] ?? $note->status }}</td><td>
@if($note->status === 'pending_approval' && auth()->user()->hasPermission('finance.note.approve') && (int)$note->created_by !== (int)auth()->id())
<form method="post" action="{{ route('supplier-notes.approve', $note) }}">@csrf<button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> อนุมัติ</button></form>
<form method="post" action="{{ route('supplier-notes.reject', $note) }}" class="d-flex gap-2 mt-2">@csrf<input name="reason" class="form-control form-control-sm" aria-label="เหตุผลไม่อนุมัติ" required maxlength="1000"><button class="btn btn-sm btn-outline-danger">ไม่อนุมัติ</button></form>
@endif
</td></tr>
@endforeach
</tbody></table></div>
{{ $notes->links() }}
@endsection
