@extends('layout')
@section('title', "ใบจอง {$booking->document->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบจอง')
@section('page-subtitle', $booking->document->doc_number)

@php
$statusLabel = ['pending' => 'รอดำเนินการ', 'converted_to_sale' => 'แปลงเป็นใบขายแล้ว', 'cancelled' => 'ยกเลิกแล้ว'];
$statusColor = ['pending' => 'text-bg-warning', 'converted_to_sale' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'];
$isPending = $booking->status === 'pending';
$isConverted = $booking->status === 'converted_to_sale';
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
        <div class="flow-line {{ $isConverted ? 'done' : '' }}"></div>
        <div class="flow-step {{ $isConverted ? 'done' : 'pending' }}">
            <div class="flow-dot"><i class="bi bi-receipt-cutoff"></i></div>
            <div class="flow-label">ใบขาย/ใบส่งของ
                @if($isConverted && $booking->confirmedDocument)
                    <div class="flow-sub">{{ $booking->confirmedDocument->doc_number }}</div>
                @else
                    <div class="flow-sub text-muted">ยังไม่แปลง</div>
                @endif
            </div>
        </div>
        <div class="flow-line"></div>
        <div class="flow-step pending">
            <div class="flow-dot"><i class="bi bi-cash-coin"></i></div>
            <div class="flow-label">รับชำระเงิน<div class="flow-sub text-muted">รอดำเนินการ</div></div>
        </div>
    </div>

    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <h2 class="h4 fw-bold mb-1">ใบจอง {{ $booking->document->doc_number }}</h2>
            <div class="text-muted small">
                {{ $booking->document->doc_date->thaiDate() }} &middot;
                {{ $booking->document->branch->name_th }}
                @if($booking->document->salesman) &middot; {{ $booking->document->salesman->name }}@endif
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
            <a href="{{ route('documents.delivery-note', $booking->document) }}" target="_blank" class="btn btn-light border px-3">
                <i class="bi bi-truck me-1"></i> ใบส่งของชั่วคราว (A5)
            </a>
            @if($isPending)
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
                    onclick="Swal.fire({ title:'แปลงเป็นใบขาย?', text:'จะตัดสต็อกและตั้งลูกหนี้ทันที ย้อนกลับไม่ได้', icon:'warning', showCancelButton:true, confirmButtonText:'ยืนยัน', cancelButtonText:'ยกเลิก', confirmButtonColor:'#10b981' }).then(r => r.isConfirmed && document.getElementById('convert-form').submit())"
                    class="btn btn-success px-4">
                    <i class="bi bi-arrow-right-circle me-1"></i> แปลงเป็นใบขาย / ใบส่งของ
                </button>
            </form>
            @elseif($isConverted && $booking->confirmedDocument)
            <a href="{{ route('sales.show', $booking->confirmedDocument) }}" class="btn btn-outline-success px-4">
                <i class="bi bi-receipt-cutoff me-1"></i> ดูใบขาย {{ $booking->confirmedDocument->doc_number }}
            </a>
            @endif
        </div>
    </div>
</div>

{{-- Items --}}
<div class="content-card p-4">
    <h3 class="h6 fw-bold mb-3">รายการสินค้า</h3>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead>
            <tbody>
                @foreach($booking->document->stockDocument->items as $item)
                <tr>
                    <td class="fw-semibold text-primary">{{ $item->product->sku_code }}</td>
                    <td>{{ $item->product->name_th }}</td>
                    <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                    <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
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
.flow-dot { width:44px;height:44px;border-radius:50%;border:2px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;font-size:18px;color:#94a3b8;transition:all .2s; }
.flow-step.done .flow-dot { border-color:#10b981;background:#ecfdf5;color:#10b981; }
.flow-step.pending .flow-dot { border-color:#e2e8f0;background:#f8fafc;color:#cbd5e1; }
.flow-label { font-size:12px;font-weight:700;text-align:center;color:#374151; }
.flow-sub { font-size:11px;font-weight:400;color:#64748b;margin-top:2px; }
.flow-line { flex:1;height:2px;background:#e2e8f0;min-width:40px;margin:0 8px;position:relative;top:-10px; }
.flow-line.done { background:#10b981; }
.doc-book-picker { min-width: 280px; border: 1px solid #dbeafe; background: #f8fbff; border-radius: 12px; padding: 8px 10px; }
.doc-book-label { font-size: 11px; font-weight: 800; color: #2563eb; margin-bottom: 4px; }
</style>
@endpush
