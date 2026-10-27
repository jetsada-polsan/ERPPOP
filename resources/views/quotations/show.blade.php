@extends('layout')
@section('title', "ใบเสนอราคา {$quotation->doc_number} - PopCentral")
@section('page-title', 'ใบเสนอราคา ' . $quotation->doc_number)
@section('page-subtitle', $quotation->customerLabel())
@section('content')
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <a href="{{ route('quotations.index') }}" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer me-1"></i>พิมพ์ใบเสนอราคา</button>
    @if(in_array($quotation->status, ['open', 'accepted'], true) && ! $quotation->converted_booking_id)
        @if($quotation->status === 'open')
            <form method="post" action="{{ route('quotations.accept', $quotation) }}">
                @csrf<button class="btn btn-outline-success"><i class="bi bi-hand-thumbs-up me-1"></i>ลูกค้าตอบรับ</button>
            </form>
        @else
            <span class="badge text-bg-success align-self-center px-3 py-2">ลูกค้าตอบรับแล้ว</span>
        @endif
        <button type="button" class="btn btn-success" onclick="document.getElementById('quotation-convert').classList.toggle('d-none')">
            <i class="bi bi-arrow-right-circle me-1"></i>แปลงเป็นใบจอง / ใบขาย
        </button>
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

@if(in_array($quotation->status, ['open', 'accepted'], true) && ! $quotation->converted_booking_id)
{{-- แปลงเป็นเอกสารขาย: คัดลอกรายการและอ้างอิงเลขใบเสนอราคาลงในใบจอง --}}
<div id="quotation-convert" class="content-card p-4 mb-3 no-print d-none" x-data="{ mode: '{{ $customerMatches->isNotEmpty() ? 'existing' : 'new' }}' }">
    <form method="post" action="{{ route('quotations.convert', $quotation) }}"
        data-confirm="แปลงใบเสนอราคา {{ $quotation->doc_number }}? ระบบจะคัดลอกรายการทั้งหมดไปยังเอกสารใหม่">
        @csrf
        <h2 class="h6 fw-bold mb-3">แปลงใบเสนอราคา {{ $quotation->doc_number }}</h2>
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label small text-muted">แปลงเป็น</label>
                <div class="form-check"><input class="form-check-input" type="radio" name="target" value="booking" id="target-booking" checked>
                    <label class="form-check-label" for="target-booking">ใบจอง (จองสต๊อก ยังไม่ตัด)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="target" value="sale" id="target-sale">
                    <label class="form-check-label" for="target-sale">ใบขายเชื่อ (ตัดสต๊อกและตั้งลูกหนี้ทันที)</label></div>
            </div>
            <div class="col-md-7">
                @if($quotation->customer_id)
                    <label class="form-label small text-muted">ลูกค้า</label>
                    <div class="fw-semibold">{{ $quotation->customer->code }} - {{ $quotation->customer->name_th }}</div>
                @else
                    <label class="form-label small text-muted">ลูกค้า "{{ $quotation->customer_name ?: 'ลูกค้าทั่วไป' }}" ยังไม่อยู่ในทะเบียน</label>
                    <div class="d-flex gap-3 mb-2">
                        <label class="form-check"><input class="form-check-input" type="radio" x-model="mode" value="existing" @disabled($customerMatches->isEmpty())> เลือกลูกค้าที่มีอยู่</label>
                        <label class="form-check"><input class="form-check-input" type="radio" x-model="mode" value="new"> สร้างลูกค้าใหม่</label>
                    </div>
                    <div x-show="mode === 'existing'">
                        @if($customerMatches->isEmpty())
                            <div class="small text-muted">ไม่พบลูกค้าที่ชื่อใกล้เคียงในทะเบียน</div>
                        @else
                            <select name="customer_id" class="form-select" :disabled="mode !== 'existing'">
                                @foreach($customerMatches as $match)
                                    <option value="{{ $match->id }}">{{ $match->code }} - {{ $match->name_th }}{{ $match->tax_id ? ' ('.$match->tax_id.')' : '' }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    <div class="row g-2" x-show="mode === 'new'">
                        <div class="col-4"><label class="form-label small text-muted">รหัสลูกค้า</label><input name="new_customer_code" value="{{ $suggestedCustomerCode }}" maxlength="20" class="form-control" :disabled="mode !== 'new'"></div>
                        <div class="col-8"><label class="form-label small text-muted">ชื่อลูกค้า</label><input name="new_customer_name" value="{{ $quotation->customer_name }}" maxlength="250" class="form-control" :disabled="mode !== 'new'"></div>
                        <div class="col-6"><label class="form-label small text-muted">เลขผู้เสียภาษี (ถ้ามี)</label><input name="new_customer_tax_id" maxlength="20" class="form-control" :disabled="mode !== 'new'"></div>
                    </div>
                @endif
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-success px-4"><i class="bi bi-check2-circle me-1"></i>ยืนยันการแปลง</button>
        </div>
    </form>
</div>
@endif

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
        <div class="text-center small" style="width:40%"><div style="border-top:1px dashed var(--erp-muted);margin-bottom:6px"></div>ผู้เสนอราคา</div>
        <div class="text-center small" style="width:40%"><div style="border-top:1px dashed var(--erp-muted);margin-bottom:6px"></div>ผู้อนุมัติสั่งซื้อ (ลูกค้า)</div>
    </div>
</div>
@endsection

@push('head')
<style>
    @include('documents.partials.print-theme')
    /* ใบเสนอราคาเป็นเอกสารส่งลูกค้า ต้องได้ขนาดเดียวกันทุกเครื่องที่สั่งพิมพ์ */
    @page { size: A4 portrait; margin: 10mm; }
    @media print { .app-sidebar, .app-header, .no-print { display: none !important; } .content-card { border: none; box-shadow: none; } }
</style>
@endpush
