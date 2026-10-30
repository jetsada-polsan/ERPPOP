@extends('layout')
@section('title', "ใบจอง {$booking->document->doc_number} - PopCentral")
@section('page-title', 'ใบจอง')
@section('page-subtitle', $booking->document->doc_number)

@php
$statusLabel = ['pending' => 'รอดำเนินการ', 'converted_to_sale' => 'แปลงเป็นใบขายแล้ว', 'cancelled' => 'ยกเลิกแล้ว'];
$statusColor = ['pending' => 'text-bg-warning', 'converted_to_sale' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
$isPending = $booking->status === 'pending';
$isConverted = $booking->status === 'converted_to_sale';
$isDelivery = $booking->fulfillment_type === 'delivery';
$deliveryLabel = ['pending' => 'ยังไม่ส่ง', 'partial' => 'ส่งบางส่วน', 'delivered' => 'ส่งครบแล้ว', 'cancelled' => 'ยกเลิกการส่ง'];
$deliveryColor = ['pending' => 'text-bg-warning', 'partial' => 'text-bg-info', 'delivered' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
$deliveryDone = in_array($booking->delivery_status, ['delivered', 'cancelled'], true);
$isOverdue = $isDelivery && ! $deliveryDone && $booking->delivery_due_at && $booking->delivery_due_at->isPast();
$transportLoaded = $transportJob?->loaded_at !== null;
$transportDelivered = $transportJob?->status === 'delivered';
$canConvert = ! $isDelivery || ($booking->delivery_status === 'delivered' && $transportDelivered);
$canEdit = $isPending && $booking->delivery_status === 'pending'
    && (! $transportJob || ($transportJob->status === 'booked' && $transportJob->loaded_at === null));
@endphp

@section('content')
<a href="{{ route('bookings.index') }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับรายการใบจอง
</a>

{{-- Flow status bar --}}
<div class="content-card p-4 mb-4">
    <div class="d-flex align-items-center gap-0 mb-4 flex-wrap">
        <div class="flow-step {{ $isPending || $isConverted ? 'done' : '' }}">
            <div class="flow-dot"><i class="bi bi-journal-text"></i></div>
            <div class="flow-label">ใบจอง<div class="flow-sub">{{ $booking->document->doc_number }}</div></div>
        </div>
        @if($isDelivery)
            <div class="flow-line {{ $transportLoaded ? 'done' : '' }}"></div>
            <div class="flow-step {{ $transportLoaded ? 'done' : 'pending' }}">
                <div class="flow-dot"><i class="bi bi-box-seam"></i></div>
                <div class="flow-label">ตรวจของ / ขึ้นรถ
                    <div class="flow-sub">{{ $transportLoaded ? 'ขึ้นรถแล้ว' : 'รอจัดของ' }}</div>
                </div>
            </div>
            <div class="flow-line {{ $transportDelivered ? 'done' : '' }}"></div>
            <div class="flow-step {{ $transportDelivered ? 'done' : 'pending' }}">
                <div class="flow-dot"><i class="bi bi-truck"></i></div>
                <div class="flow-label">ส่งของ
                    <div class="flow-sub {{ $isOverdue ? 'text-danger fw-semibold' : 'text-muted' }}">
                        {{ $transportDelivered ? 'ส่งครบแล้ว' : ($deliveryLabel[$booking->delivery_status] ?? $booking->delivery_status) }}
                        @if($isOverdue) &middot; เกินกำหนด @endif
                    </div>
                </div>
            </div>
            <div class="flow-line {{ $isConverted ? 'done' : '' }}"></div>
            <div class="flow-step {{ $isConverted ? 'done' : 'pending' }}">
                <div class="flow-dot"><i class="bi bi-receipt-cutoff"></i></div>
                <div class="flow-label">ใบขาย / ลูกหนี้
                    <div class="flow-sub">{{ $isConverted && $booking->confirmedDocument ? $booking->confirmedDocument->doc_number : 'หลังส่งครบ' }}</div>
                </div>
            </div>
        @else
            <div class="flow-line {{ $isConverted ? 'done' : '' }}"></div>
            <div class="flow-step {{ $isConverted ? 'done' : 'pending' }}">
                <div class="flow-dot"><i class="bi bi-receipt-cutoff"></i></div>
                <div class="flow-label">ใบขาย / ลูกหนี้
                    <div class="flow-sub">{{ $isConverted && $booking->confirmedDocument ? $booking->confirmedDocument->doc_number : 'รับเองที่สาขา' }}</div>
                </div>
            </div>
            <div class="flow-line"></div>
            <div class="flow-step pending">
                <div class="flow-dot"><i class="bi bi-shop"></i></div>
                <div class="flow-label">รับเองที่สาขา<div class="flow-sub text-muted">ไม่มีการส่งของ</div></div>
            </div>
        @endif
    </div>

    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <h2 class="h4 fw-bold mb-1">ใบจอง {{ $booking->document->doc_number }}</h2>
            <div class="text-muted small">
                {{ $booking->document->doc_date->thaiDate() }} &middot;
                {{ $booking->document->branch->name_th }}
                @if($booking->document->salesArea) &middot; {{ $booking->document->salesArea->name }}@endif
                @if($booking->document->salesUser) &middot; {{ $booking->document->salesUser->name }}@endif
            </div>
            <div class="fw-semibold mt-1">{{ $booking->document->customer->name_th }}
                <span class="text-muted fw-normal">({{ $booking->document->customer->code }})</span>
            </div>
            @if($booking->document->remark)
            <div class="text-muted small mt-1">{{ $booking->document->remark }}</div>
            @endif
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge {{ $statusColor[$booking->status] ?? 'text-bg-light' }} fs-6 px-3 py-2">
                {{ $statusLabel[$booking->status] ?? $booking->status }}
            </span>
            <a href="{{ route('documents.delivery-note', $booking->document) }}" target="_blank" class="btn btn-light border px-3" title="เอกสารประกอบการจัดของ ไม่ตัดสต๊อกและไม่ใช่ใบขาย">
                <i class="bi bi-truck me-1"></i> ใบส่งของชั่วคราว (A5)
            </a>
            @if($canEdit)
                <a href="{{ route('bookings.edit', $booking) }}" class="btn btn-outline-primary px-3">
                    <i class="bi bi-pencil-square me-1"></i> แก้ไขใบจอง
                </a>
            @endif
            @if($isPending && $canConvert)
            <form method="post" action="{{ route('bookings.convert', $booking) }}" id="convert-form" class="d-flex flex-column gap-2 align-items-end">
                @csrf
                @if($creditSaleBooks->count() > 1)
                <div class="doc-book-picker">
                    <div class="doc-book-label">เล่มใบขายเชื่อ</div>
                <select name="document_book_id" class="form-select" style="width:auto" title="เล่มเอกสารขายเชื่อ">
                    @foreach($creditSaleBooks as $book)
                        <option value="{{ $book->id }}" @selected($book->is_default)>{{ $book->code }} - {{ $book->name }} ({{ number_format($book->documents_count) }} ใบ)</option>
                    @endforeach
                </select>
                </div>
                @endif
                <button type="button"
                    onclick="Swal.fire({ title:'แปลงเป็นใบขาย?', text:'จะตัดสต็อกและตั้งลูกหนี้ทันที{{ $isDelivery ? ' โดยใช้วันที่ส่งจริง' : '' }}', icon:'warning', showCancelButton:true, confirmButtonText:'ยืนยัน', cancelButtonText:'ยกเลิก', confirmButtonColor:'var(--erp-success-ink)' }).then(r => r.isConfirmed && document.getElementById('convert-form').submit())"
                    class="btn btn-success px-4">
                    <i class="bi bi-arrow-right-circle me-1"></i> แปลงเป็นใบขาย
                </button>
            </form>
            @elseif($isPending && $isDelivery)
                <button class="btn btn-outline-secondary px-4" disabled title="ต้องตรวจของ ขึ้นรถ และบันทึกส่งครบก่อน">
                    <i class="bi bi-hourglass-split me-1"></i> รอส่งครบก่อนออกใบขาย
                </button>
            @elseif($isConverted && $booking->confirmedDocument)
            <a href="{{ route('sales.show', $booking->confirmedDocument) }}" class="btn btn-outline-success px-4">
                <i class="bi bi-receipt-cutoff me-1"></i> ดูใบขาย {{ $booking->confirmedDocument->doc_number }}
            </a>
            @endif
        </div>
    </div>
</div>

{{-- Items --}}
@if($isDelivery)
<div class="content-card p-4 mb-4">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
        <div>
            <h3 class="h6 fw-bold mb-1"><i class="bi bi-truck me-1"></i>การส่งของ</h3>
            <div class="small text-muted">
                กำหนดส่ง
                <span class="{{ $isOverdue ? 'text-danger fw-semibold' : '' }}">
                    {{ $booking->delivery_due_at?->format('d/m/Y H:i') ?? 'ไม่ได้ระบุ' }}
                </span>
                @if($booking->delivered_at)
                    &middot; ส่งจริง {{ $booking->delivered_at->format('d/m/Y H:i') }}
                @endif
            </div>
        </div>
        <span class="badge {{ $deliveryColor[$booking->delivery_status] ?? 'text-bg-secondary' }} align-self-center">
            {{ $deliveryLabel[$booking->delivery_status] ?? $booking->delivery_status }}
        </span>
    </div>

    @if($booking->delivery_status === 'delivered')
        <div class="alert alert-success mb-0">
            ส่งครบแล้ว{{ $booking->delivered_at ? ' เมื่อ '.$booking->delivered_at->format('d/m/Y H:i') : '' }} — ตรวจใบส่งของที่ลูกค้าเซ็นกลับ แล้วแปลงเป็นใบขายเพื่อบันทึกสต๊อกและลูกหนี้
        </div>
    @elseif($deliveryDone)
        <p class="text-muted small mb-0">การส่งของถูกปิดแล้ว แก้ไขไม่ได้ หากบันทึกผิดให้ผู้ดูแลตรวจสอบประวัติ</p>
    @else
        <div class="alert alert-info mb-3">
            ใบส่งของชั่วคราวใช้ตรวจรายการและแนบเอกสาร ยังไม่ตัดสต๊อกและไม่ใช่ใบขาย
            หากสินค้าไม่ครบ ให้แก้จำนวนในใบจองก่อนยืนยันขึ้นรถ จากนั้นลงสถานะส่งของจริงในบอร์ดขนส่ง
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('fleet.load-sheet', $booking) }}" class="btn btn-outline-primary"><i class="bi bi-box-seam me-1"></i>เปิดใบขึ้นของ</a>
            <a href="{{ route('fleet.board') }}" class="btn btn-primary"><i class="bi bi-truck me-1"></i>ไปบอร์ดขนส่ง</a>
            @if($canEdit)
                <a href="{{ route('bookings.edit', $booking) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil-square me-1"></i>แก้จำนวนก่อนขึ้นรถ</a>
            @endif
        </div>
        @if($transportJob)
            <div class="small text-muted mt-2">สถานะขนส่ง: {{ ['booked'=>'รอจัดของ','loaded'=>'ขึ้นรถแล้ว','in_transit'=>'กำลังส่ง','delivered'=>'ส่งครบแล้ว','paid'=>'รับเงินแล้ว','cancelled'=>'ยกเลิก'][$transportJob->status] ?? $transportJob->status }}</div>
        @endif
    @endif
</div>
@endif

<div class="content-card p-4">
    <h3 class="h6 fw-bold mb-3">รายการสินค้า</h3>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th class="text-end">จำนวน/น้ำหนัก</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead>
            <tbody>
                @foreach($booking->document->stockDocument->items as $item)
                @php($isScale = $item->product->barcodes->contains(fn ($barcode) => preg_match('/^80[01][0-9]{3}$/', (string) $barcode->barcode) === 1) || preg_match('/ชั่ง|ซั่ง/u', (string) $item->product->name_th) === 1)
                <tr>
                    <td class="fw-semibold text-primary">{{ $item->product->sku_code }}</td>
                    <td>{{ $item->product->name_th }} @if($isScale)<span class="badge text-bg-success ms-1">ชั่ง</span>@endif</td>
                    <td class="text-end">{{ number_format($item->qty, $isScale ? 4 : 2) }} @if($isScale)<small class="text-success fw-bold">{{ $item->product->baseUnit?->cleanName() ?? 'หน่วยฐาน' }}</small>@endif</td>
                    <td class="text-end">{{ number_format($item->unit_price, 2) }} @if($isScale)<small class="text-success fw-bold">/หน่วย</small>@endif</td>
                    <td class="text-end fw-semibold">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold border-top">
                    <td colspan="4" class="text-end py-2">รวมทั้งสิ้น</td>
                    <td class="text-end fs-5 text-success">฿{{ number_format($booking->document->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection

@push('head')
<style>
.flow-step { display:flex;flex-direction:column;align-items:center;gap:6px;min-width:100px; }
.flow-dot { width:44px;height:44px;border-radius:50%;border:2px solid var(--erp-border);background:var(--erp-surface-2);display:grid;place-items:center;font-size:18px;color:var(--erp-muted);transition:all .2s; }
.flow-step.done .flow-dot { border-color:var(--erp-success-ink);background:var(--erp-success-soft);color:var(--erp-success-ink); }
.flow-step.pending .flow-dot { border-color:var(--erp-border);background:var(--erp-surface-2);color:var(--erp-border); }
.flow-label { font-size:12px;font-weight:700;text-align:center;color:var(--erp-text); }
.flow-sub { font-size:11px;font-weight:400;color:var(--erp-muted);margin-top:2px; }
.flow-line { flex:1;height:2px;background:var(--erp-border);min-width:40px;margin:0 8px;position:relative;top:-10px; }
.flow-line.done { background:var(--erp-success-ink); }
.doc-book-picker { min-width: 280px; border: 1px solid var(--erp-border); background: var(--erp-surface-2); border-radius: 12px; padding: 8px 10px; }
.doc-book-label { font-size: 11px; font-weight: 800; color: #2563eb; margin-bottom: 4px; }
</style>
@endpush
