@extends('layout')
@section('title', "ใบวางบิล {$note->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบวางบิล ' . $note->doc_number)
@section('page-subtitle', $note->customer->name_th)
@section('content')
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <a href="{{ route('billing-notes.index') }}" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-1"></i>พิมพ์ใบวางบิล</button>
    @if($note->status === 'open')
        <form method="post" action="{{ route('billing-notes.status', $note) }}" class="ms-auto" onsubmit="return confirm('ทำเครื่องหมายว่าเก็บเงินครบแล้ว?')">
            @csrf <input type="hidden" name="status" value="collected">
            <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>เก็บเงินแล้ว</button>
        </form>
        <form method="post" action="{{ route('billing-notes.status', $note) }}" onsubmit="return confirm('ยกเลิกใบวางบิลนี้?')">
            @csrf <input type="hidden" name="status" value="cancelled">
            <button class="btn btn-light border text-danger">ยกเลิก</button>
        </form>
    @else
        <span class="badge ms-auto align-self-center px-3 py-2 {{ $note->status === 'collected' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $note->statusLabel() }}</span>
    @endif
</div>

<div class="print-sheet">
    <div class="bill-head">
        <div class="d-flex gap-3 align-items-start">
            @if($logo = \App\Models\AppSetting::logoUrl())
                <img src="{{ $logo }}" alt="" style="max-height:56px;max-width:150px;object-fit:contain">
            @endif
            <div>
                <div class="fw-bold" style="font-size:16px">{{ \App\Models\AppSetting::company('name_th') }}</div>
                <div class="small text-muted">{{ \App\Models\AppSetting::company('address') }}</div>
                <div class="small text-muted">เลขประจำตัวผู้เสียภาษี {{ \App\Models\AppSetting::company('tax_id') }}@if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif</div>
            </div>
        </div>
        <div class="text-end">
            <div class="h4 fw-bold mb-1">ใบวางบิล</div>
            <div class="small">เลขที่: <strong>{{ $note->doc_number }}</strong></div>
            <div class="small">วันที่: {{ $note->doc_date->thaiDate() }}</div>
            <div class="small">ครบกำหนดชำระ: {{ $note->due_date?->thaiDate() ?? '-' }}</div>
        </div>
    </div>

    <div class="bill-customer">
        <div><strong>ลูกค้า:</strong> {{ $note->customer->name_th }} ({{ $note->customer->code }})</div>
        @if($addr = $note->customer->addresses->first())<div class="small text-muted">{{ $addr->address_line }}</div>@endif
        @if($note->customer->tax_id)<div class="small text-muted">เลขผู้เสียภาษี: {{ $note->customer->tax_id }}</div>@endif
    </div>

    <div class="table-responsive">
    <table class="table bill-table">
        <thead>
            <tr>
                <th style="width:50px">ลำดับ</th>
                <th>เลขที่ใบขายเชื่อ</th>
                <th>วันที่</th>
                <th>ครบกำหนด</th>
                <th class="text-end">ยอดรวม</th>
                <th class="text-end">ยอดค้าง ณ วางบิล</th>
            </tr>
        </thead>
        <tbody>
            @foreach($note->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="fw-semibold">{{ $item->openItem->document->doc_number }}</td>
                <td>{{ $item->openItem->document->doc_date->thaiDate() }}</td>
                <td>{{ $item->openItem->due_date?->thaiDate() ?? '-' }}</td>
                <td class="text-end">{{ number_format($item->openItem->net_amount, 2) }}</td>
                <td class="text-end fw-semibold">{{ number_format($item->balance_at_billing, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="fw-bold">
                <td colspan="5" class="text-end">รวมยอดที่วางบิลทั้งสิ้น</td>
                <td class="text-end fs-5 text-danger">฿{{ number_format($note->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>
    </div>

    @if($note->note)<div class="small text-muted mt-2">หมายเหตุ: {{ $note->note }}</div>@endif

    <div class="bill-sign">
        <div class="sign-box"><div class="line"></div>ผู้วางบิล</div>
        <div class="sign-box"><div class="line"></div>ผู้รับวางบิล</div>
        <div class="sign-box"><div class="line"></div>วันที่รับวางบิล</div>
    </div>
</div>
@endsection

@push('head')
<style>
    .print-sheet { background: #fff; padding: 20px; border-radius: 10px; }
    .bill-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #0f172a; padding-bottom: 12px; }
    .bill-customer { padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
    .bill-table { margin-top: 12px; }
    .bill-table thead th { background: #f1f5f9; font-size: 13px; }
    .bill-sign { display: flex; justify-content: space-around; margin-top: 50px; }
    .bill-sign .sign-box { text-align: center; font-size: 13px; width: 30%; }
    .bill-sign .line { border-top: 1px dashed #64748b; margin-bottom: 6px; }
    @include('documents.partials.print-theme')
    @media print {
        .app-sidebar, .app-header, .no-print { display: none !important; }
        .print-sheet { padding: 0; }
        .content-card, main, .app-main { background: #fff !important; }
    }
</style>
@endpush
