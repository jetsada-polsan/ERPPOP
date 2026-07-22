@extends('layout')
@section('title', "ใบเสนอราคา {$quotation->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบเสนอราคา ' . $quotation->doc_number)
@section('page-subtitle', $quotation->customerLabel())
@section('content')
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <a href="{{ route('quotations.index') }}" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-1"></i>พิมพ์ใบเสนอราคา</button>
    @if($quotation->status === 'open')
        @if($quotation->customer_id)
            <form method="post" action="{{ route('quotations.convert', $quotation) }}" onsubmit="return confirm('แปลงเป็นใบจอง? จะสร้างใบจองและจองสต๊อกให้ลูกค้า')">
                @csrf<button class="btn btn-success"><i class="bi bi-arrow-right-circle me-1"></i>แปลงเป็นใบจอง</button>
            </form>
        @endif
        <form method="post" action="{{ route('quotations.status', $quotation) }}" class="ms-auto">
            @csrf <input type="hidden" name="status" value="cancelled">
            <button class="btn btn-light border text-danger">ยกเลิก</button>
        </form>
    @elseif($quotation->converted_booking_id)
        <a href="{{ route('bookings.show', $quotation->converted_booking_id) }}" class="btn btn-outline-success ms-auto"><i class="bi bi-receipt me-1"></i>ดูใบจองที่แปลงแล้ว</a>
    @else
        <span class="badge ms-auto align-self-center px-3 py-2 text-bg-secondary">{{ $quotation->statusLabel() }}</span>
    @endif
</div>

<div class="content-card p-4 print-sheet">
    <div class="d-flex justify-content-between align-items-start border-bottom border-2 border-dark pb-3">
        <div class="d-flex gap-3 align-items-start">
            @if($logo = \App\Models\AppSetting::logoUrl())<img src="{{ $logo }}" alt="" style="max-height:56px;max-width:150px;object-fit:contain">@endif
            <div>
                <div class="fw-bold" style="font-size:16px">{{ \App\Models\AppSetting::company('name_th') }}</div>
                <div class="small text-muted">{{ \App\Models\AppSetting::company('address') }}</div>
                <div class="small text-muted">เลขประจำตัวผู้เสียภาษี {{ \App\Models\AppSetting::company('tax_id') }}@if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif</div>
            </div>
        </div>
        <div class="text-end">
            <div class="h4 fw-bold mb-1">ใบเสนอราคา</div>
            <div class="small">เลขที่: <strong>{{ $quotation->doc_number }}</strong></div>
            <div class="small">วันที่: {{ $quotation->doc_date->thaiDate() }}</div>
            <div class="small">ยืนราคาถึง: {{ $quotation->valid_until?->thaiDate() ?? '-' }}</div>
        </div>
    </div>

    <div class="py-3 border-bottom">
        <div><strong>เรียน:</strong> {{ $quotation->customerLabel() }} @if($quotation->customer)({{ $quotation->customer->code }})@endif</div>
        @if($addr = $quotation->customer?->addresses?->first())<div class="small text-muted">{{ $addr->address_line }}</div>@endif
        @if($quotation->salesman)<div class="small text-muted">พนักงานขาย: {{ $quotation->salesman->name }}</div>@endif
    </div>

    <div class="table-responsive">
    <table class="table mt-2">
        <thead><tr><th style="width:50px">ลำดับ</th><th>รหัส</th><th>รายการ</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">จำนวนเงิน</th></tr></thead>
        <tbody>
            @foreach($quotation->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                <td>{{ $item->product->name_th }}</td>
                <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-end fw-semibold">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot><tr class="fw-bold"><td colspan="6" class="text-end">รวมทั้งสิ้น ({{ \App\Models\AppSetting::get('doc_price_includes_vat', '1') === '1' ? 'ราคานี้รวม VAT แล้ว' : 'ราคานี้ยังไม่รวม VAT' }})</td><td class="text-end fs-5 text-success">฿{{ number_format($quotation->total_amount, 2) }}</td></tr></tfoot>
    </table>
    </div>

    @if($quotation->note)<div class="small text-muted mt-2">หมายเหตุ: {{ $quotation->note }}</div>@endif
    @if($docNote = \App\Models\AppSetting::get('doc_footer_note'))<div class="small text-muted mt-1">{{ $docNote }}</div>@endif

    <div class="d-flex justify-content-around mt-5 pt-3">
        <div class="text-center small" style="width:40%"><div style="border-top:1px dashed #64748b;margin-bottom:6px"></div>ผู้เสนอราคา</div>
        <div class="text-center small" style="width:40%"><div style="border-top:1px dashed #64748b;margin-bottom:6px"></div>ผู้อนุมัติสั่งซื้อ (ลูกค้า)</div>
    </div>
</div>
@endsection

@push('head')
<style>
    @include('documents.partials.print-theme')
    @media print { .app-sidebar, .app-header, .no-print { display: none !important; } .content-card { border: none; box-shadow: none; } }
</style>
@endpush
