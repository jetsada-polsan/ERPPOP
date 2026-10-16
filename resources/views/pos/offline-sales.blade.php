@extends('layout')
@section('title', 'บิลขายออฟไลน์ - PopCentral')
@section('page-title', 'บิลขายออฟไลน์ (Web POS)')
@section('page-subtitle', 'บิลที่ขายตอนเน็ต/Host ล่ม แล้วระบบลงบัญชีอัตโนมัติไม่ได้ ต้องตรวจก่อน')

@section('content')
@php
    $labels = ['needs_review' => 'ต้องตรวจสอบ', 'posted' => 'ลงบิลแล้ว', 'resolved' => 'ปิดด้วยมือ', 'processing' => 'กำลังส่ง', 'all' => 'ทั้งหมด'];
@endphp
<div class="d-flex gap-2 flex-wrap mb-3">
    @foreach($labels as $key => $label)
        <a href="{{ route('pos.offline-sales.index', ['status' => $key]) }}"
           class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ $label }} @if($key !== 'all')({{ $counts[$key] ?? 0 }})@endif
        </a>
    @endforeach
</div>


<div class="card"><div class="table-responsive">
<table class="table table-sm align-middle mb-0">
    <thead><tr>
        <th>เวลาขายจริง</th><th>สาขา</th><th>พนักงาน</th><th class="text-end">ยอดในเครื่อง</th>
        <th>รายการ</th><th>สถานะ</th><th>เหตุผล / บิลที่ลง</th><th></th>
    </tr></thead>
    <tbody>
    @forelse($sales as $sale)
        <tr @class(['table-warning' => $sale->status === 'needs_review'])>
            <td class="text-nowrap">{{ $sale->sold_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}<br><small class="text-muted font-monospace">{{ substr($sale->offline_uuid, 0, 8) }}</small></td>
            <td>{{ $sale->branch?->code }}</td>
            <td>{{ $sale->user?->name }}<br><small class="text-muted">{{ $sale->source === 'desktop' ? 'เครื่อง POS '.($sale->device?->terminal_code ?? $sale->device?->name) : 'Web POS' }}</small></td>
            <td class="text-end">{{ number_format((float) $sale->offline_total, 2) }}</td>
            <td><small>
                @foreach(($sale->payload['items'] ?? []) as $item)
                    <div>#{{ $item['product_id'] ?? '?' }} × {{ $item['qty'] ?? '?' }} @ {{ number_format((float) ($item['unit_price'] ?? 0), 2) }}</div>
                @endforeach
            </small></td>
            <td><span class="badge {{ $sale->status === 'needs_review' ? 'bg-warning text-dark' : ($sale->status === 'posted' ? 'bg-success' : 'bg-secondary') }}">{{ $labels[$sale->status] ?? $sale->status }}</span></td>
            <td style="max-width:320px"><small style="white-space:pre-line">{{ $sale->receipt_no ? 'บิล '.$sale->receipt_no."\n" : '' }}{{ $sale->review_reason }}</small></td>
            <td class="text-nowrap">
                @if($sale->status === 'needs_review' && $sale->source !== 'desktop')
                    <form method="post" action="{{ route('pos.offline-sales.retry', $sale) }}" class="d-flex gap-1 mb-1">
                        @csrf
                        <input type="number" name="shift_id" class="form-control form-control-sm" style="width:90px" placeholder="กะใหม่" title="ระบุรหัสกะที่เปิดอยู่ ถ้ากะเดิมปิดไปแล้ว">
                        <button class="btn btn-sm btn-primary">ส่งใหม่</button>
                    </form>
                @endif
                @if($sale->status === 'needs_review')
                    @if($sale->source === 'desktop')<small class="d-block text-muted mb-1">ส่งใหม่ได้ที่เครื่อง POS</small>@endif
                    <form method="post" action="{{ route('pos.offline-sales.resolve', $sale) }}" class="d-flex gap-1">
                        @csrf
                        <input name="note" required maxlength="500" class="form-control form-control-sm" placeholder="จัดการแล้วอย่างไร">
                        <button class="btn btn-sm btn-outline-secondary">ปิดรายการ</button>
                    </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีรายการ</td></tr>
    @endforelse
    </tbody>
</table>
</div></div>
<div class="mt-3">{{ $sales->links() }}</div>
@endsection
