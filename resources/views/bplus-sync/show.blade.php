@extends('layout')
@section('title', $doc->ref)
@section('page-title', ($kindLabels[$doc->kind] ?? $doc->kind).' '.$doc->ref)
@section('page-subtitle', 'จาก BPlus เล่ม '.$doc->book_code.' · สาย '.$doc->route_code.($doc->is_cancelled ? ' · ยกเลิกแล้ว' : ''))
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
<div class="content-card p-4 mb-3">
    <div class="row g-3">
        <div class="col-md-3"><div class="small text-muted">วันที่</div>{{ \Illuminate\Support\Carbon::parse($doc->doc_date)->format('d/m/Y') }}</div>
        <div class="col-md-3"><div class="small text-muted">ลูกค้า</div>{{ $doc->customer_code ?? '-' }} {{ $doc->customer_name }}</div>
        <div class="col-md-2"><div class="small text-muted">อ้างใบจอง</div>{{ $doc->booking_ref ?? '-' }}</div>
        <div class="col-md-2"><div class="small text-muted">ครบกำหนด</div>{{ $doc->due_date ? \Illuminate\Support\Carbon::parse($doc->due_date)->format('d/m/Y') : '-' }}</div>
        <div class="col-md-2"><div class="small text-muted">ผู้คีย์</div>{{ $doc->created_by ?? '-' }}</div>
    </div>
    <div class="row g-3 mt-1">
        @if ($doc->kind === 'receipt')
            <div class="col-md-3"><div class="small text-muted">เงินสด</div>{{ $m($doc->cash_amount) }}</div>
            <div class="col-md-3"><div class="small text-muted">เงินโอน</div>{{ $m($doc->transfer_amount) }}</div>
            <div class="col-md-3"><div class="small text-muted">อื่นๆ / ขาด</div>{{ $m($doc->other_amount) }} / {{ $m($doc->short_amount) }}</div>
        @else
            <div class="col-md-3"><div class="small text-muted">มูลค่ามี VAT</div>{{ $m($doc->vatable_amount) }}</div>
            <div class="col-md-3"><div class="small text-muted">ยกเว้น VAT</div>{{ $m($doc->exempt_amount) }}</div>
            <div class="col-md-3"><div class="small text-muted">VAT</div>{{ $m($doc->vat_amount) }}</div>
        @endif
        <div class="col-md-3"><div class="small text-muted">ยอดรวม</div><span class="fw-bold">{{ $m($doc->total_amount) }}</span></div>
    </div>
</div>

@if ($lines->isNotEmpty())
<div class="content-card p-4 mb-3"><h2 class="h6 fw-bold">รายการสินค้า</h2><div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>#</th><th>รหัส</th><th>บาร์โค้ด</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">แถม</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">ส่วนลด</th><th class="text-end">รวม</th><th>VAT</th></tr></thead>
    <tbody>@foreach ($lines as $line)<tr><td>{{ $line->seq }}</td><td>{{ $line->sku_code }}</td><td>{{ $line->barcode }}</td><td>{{ $line->unit_name }}</td><td class="text-end">{{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</td><td class="text-end">{{ (float) $line->free_qty ? rtrim(rtrim(number_format((float) $line->free_qty, 4), '0'), '.') : '' }}</td><td class="text-end">{{ $m($line->unit_price) }}</td><td class="text-end">{{ (float) $line->discount ? $m($line->discount) : '' }}</td><td class="text-end">{{ $m($line->amount) }}</td><td>{{ $line->has_vat ? 'มี' : 'ไม่มี' }}</td></tr>@endforeach</tbody>
</table></div></div>
@endif

@if ($allocations->isNotEmpty())
<div class="content-card p-4 mb-3"><h2 class="h6 fw-bold">ตัดชำระบิล</h2><div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>ใบขายเชื่อ</th><th class="text-end">ชำระ</th><th class="text-end">เงินสด</th><th class="text-end">เงินโอน</th><th class="text-end">ส่วนลด</th><th class="text-end">หัก ณ ที่จ่าย</th><th class="text-end">ขาด</th></tr></thead>
    <tbody>@foreach ($allocations as $a)<tr><td>{{ $a->sale_ref }}</td><td class="text-end">{{ $m($a->paid_amount) }}</td><td class="text-end">{{ $m($a->cash_amount) }}</td><td class="text-end">{{ $m($a->transfer_amount) }}</td><td class="text-end">{{ $m($a->discount_amount) }}</td><td class="text-end">{{ $m($a->wht_amount) }}</td><td class="text-end">{{ $m($a->short_amount) }}</td></tr>@endforeach</tbody>
</table></div></div>
@endif

<div class="content-card p-4"><h2 class="h6 fw-bold">เอกสารที่เกี่ยวข้อง</h2>
    @forelse ($related as $r)
        <div><a href="{{ route('bplus.sync.show', $r->id) }}">{{ $kindLabels[$r->kind] ?? $r->kind }} {{ $r->ref }}</a> · {{ $m($r->total_amount) }} @if ($r->is_cancelled)<span class="badge text-bg-danger">ยกเลิก</span>@endif</div>
    @empty
        <div class="text-muted">ยังไม่มี</div>
    @endforelse
    <a href="{{ route('bplus.sync.index') }}" class="btn btn-outline-secondary btn-sm mt-3">กลับ</a>
</div>
@endsection
