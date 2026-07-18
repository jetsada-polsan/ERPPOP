@extends('layout')

@section('title', "เอกสารเก่า {$header->doc_number} - POPSTAR ERP")
@section('page-title', 'เอกสารเก่า BPlus')
@section('page-subtitle', $header->doc_number)

@section('content')
<a href="{{ route('documents.browser', ['year' => \Carbon\Carbon::parse($header->doc_date)->year, 'month' => \Carbon\Carbon::parse($header->doc_date)->month]) }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับศูนย์เอกสาร
</a>

<div class="content-card p-4 mb-4">
    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
        <div>
            <div class="badge text-bg-info rounded-pill mb-2">ข้อมูลเก่าจาก BPlus</div>
            <h2 class="h4 fw-bold mb-1">{{ $header->doc_number }}</h2>
            <div class="text-muted">
                {{ \Carbon\Carbon::parse($header->doc_date)->thaiDate() }}
                &middot; {{ $header->legacy_type_code }} {{ $header->legacy_type_name }}
            </div>
            <div class="fw-semibold mt-2">
                {{ $header->customer_name ?: '-' }}
                @if($header->customer_code)
                    <span class="text-muted fw-normal">({{ $header->customer_code }})</span>
                @endif
            </div>
        </div>
        <div class="text-end">
            <div class="text-muted small">ยอดรวม</div>
            <div class="display-6 fw-black text-success">฿{{ number_format((float) $header->total_amount, 2) }}</div>
            <div class="small text-muted">
                {{ number_format((float) $header->item_count) }} รายการ /
                {{ number_format((float) $header->total_qty, 2) }} ชิ้น
            </div>
        </div>
    </div>
</div>

<div class="content-card p-4">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <h3 class="h5 fw-bold mb-0">รายการสินค้า</h3>
        <span class="badge text-bg-light border">{{ number_format($items->count()) }} แถว</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th style="width:70px">#</th>
                    <th>สินค้า</th>
                    <th>รหัส/บาร์โค้ด</th>
                    <th class="text-end">จำนวน</th>
                    <th>หน่วย</th>
                    <th class="text-end">ต่อหน่วย</th>
                    <th class="text-end">รวม</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    <tr>
                        <td class="text-muted">{{ $item->seq }}</td>
                        <td>
                            <div class="fw-semibold">{{ $item->sku_name ?: 'ไม่พบชื่อสินค้า' }}</div>
                            <div class="text-muted small">SKU: {{ $item->sku_code ?: $item->legacy_sku_key }}</div>
                        </td>
                        <td class="text-muted small">{{ $item->barcode ?: '-' }}</td>
                        <td class="text-end">{{ number_format((float) $item->qty, 2) }}</td>
                        <td>{{ $item->unit_name ?: '-' }}</td>
                        <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="doc-empty">ไม่พบรายการสินค้าในเอกสารเก่านี้</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

