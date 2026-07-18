@extends('layout')

@section('title', 'ใบจอง - POPSTAR ERP')
@section('page-title', 'ใบจอง / ขายเชื่อ')
@section('page-subtitle', 'สร้างใบจองจากข้อมูลจำเป็น แล้วแปลงเป็นใบขายเชื่อเมื่อต้องการตัดสต็อกจริง')

@php
    $statusLabel = [
        'pending' => 'รอดำเนินการ',
        'converted_to_sale' => 'แปลงเป็นใบขายแล้ว',
        'cancelled' => 'ยกเลิก',
    ];
    $statusColor = [
        'pending' => 'text-bg-warning',
        'converted_to_sale' => 'text-bg-success',
        'cancelled' => 'text-bg-secondary',
    ];
@endphp

@section('content')
<div x-data="docEntryPage({ partyType: 'customer', partyLabel: 'ลูกค้า', partyRequired: true })" x-cloak>
    <div class="doc-shell">
        <div class="doc-tabs">
            <span class="doc-tab active">ใบจอง / ขายเชื่อ</span>
            <a href="{{ route('cash-sales.index') }}" class="doc-tab">ใบขายสด</a>
            <a href="{{ route('sale-returns.index') }}" class="doc-tab">ใบรับคืน</a>
            <a href="{{ route('purchases.index') }}" class="doc-tab">ใบซื้อ</a>
        </div>

        <div class="doc-toolbar">
            <div class="doc-toolbar-title">
                <span class="doc-mark"><i class="bi bi-receipt-cutoff"></i></span>
                <div>
                    <h2 class="h5 fw-bold mb-0">ใบจองสินค้า</h2>
                    <div class="text-muted small">คีย์ใบจองก่อน แล้วค่อยแปลงเป็นใบขายเชื่อ</div>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openModal()">
                <i class="bi bi-plus-lg me-1"></i> สร้างใบจอง
            </button>
        </div>

        <div class="d-flex gap-2 mb-3 flex-wrap">
            <a href="{{ route('bookings.index', ['q' => $q, 'book' => $bookId, 'legacy_type' => $legacyType]) }}"
               class="btn btn-sm {{ $status === '' ? 'btn-dark' : 'btn-light border' }} rounded-pill px-3">
                ทั้งหมด <span class="badge text-bg-secondary ms-1">{{ number_format($counts['all']) }}</span>
            </a>
            <a href="{{ route('bookings.index', ['q' => $q, 'status' => 'pending', 'book' => $bookId, 'legacy_type' => $legacyType]) }}"
               class="btn btn-sm {{ $status === 'pending' ? 'btn-warning' : 'btn-light border' }} rounded-pill px-3">
                รอดำเนินการ <span class="badge text-bg-warning ms-1">{{ number_format($counts['pending']) }}</span>
            </a>
            <a href="{{ route('bookings.index', ['q' => $q, 'status' => 'converted_to_sale', 'book' => $bookId]) }}"
               class="btn btn-sm {{ $status === 'converted_to_sale' ? 'btn-success' : 'btn-light border' }} rounded-pill px-3">
                แปลงเป็นใบขายแล้ว <span class="badge text-bg-success ms-1">{{ number_format($counts['converted_to_sale']) }}</span>
            </a>
            <div class="ms-auto" style="min-width: min(360px, 100%);">
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาเลขที่ / ชื่อลูกค้า'])
            </div>
        </div>

        <form method="get" class="doc-filter-strip mb-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="filter-field">
                <label>สมุดเอกสารใหม่</label>
                <select name="book" class="form-select form-select-sm">
                    <option value="">ทุกเล่ม</option>
                    @foreach($documentBooks as $book)
                        <option value="{{ $book->id }}" @selected($bookId === $book->id)>{{ $book->code }} - {{ $book->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field">
                <label>ประเภทเอกสารเก่า BPlus</label>
                <select name="legacy_type" class="form-select form-select-sm">
                    <option value="">ทุกประเภท</option>
                    @foreach($legacyTypes as $type)
                        <option value="{{ $type->code }}" @selected($legacyType === $type->code)>{{ $type->code }} - {{ $type->name }} ({{ number_format($type->count) }})</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-field filter-search">
                <label>ค้นหา</label>
                <input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="เลขที่ / ลูกค้า">
            </div>
            <button class="btn btn-sm btn-primary rounded-pill px-3">กรอง</button>
            <a href="{{ route('bookings.index') }}" class="btn btn-sm btn-light border rounded-pill px-3">ล้าง</a>
        </form>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>วันที่</th>
                            <th>สาขา</th>
                            <th>ลูกค้า</th>
                            <th>พนักงาน</th>
                            <th class="text-end">ยอดรวม</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($bookings as $booking)
                            <tr>
                                <td class="fw-semibold">{{ $booking->document->doc_number }}</td>
                                <td class="text-nowrap">{{ $booking->document->doc_date->thaiDate() }}</td>
                                <td class="text-muted small">{{ $booking->document->branch->name_th }}</td>
                                <td>{{ $booking->document->customer->name_th }}</td>
                                <td class="text-muted small">{{ $booking->document->salesman?->name ?? '-' }}</td>
                                <td class="text-end fw-semibold">{{ number_format($booking->document->total_amount, 2) }}</td>
                                <td>
                                    <span class="badge {{ $statusColor[$booking->status] ?? 'text-bg-light' }}">
                                        {{ $statusLabel[$booking->status] ?? $booking->status }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('bookings.show', $booking) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="doc-empty">ยังไม่มีใบจอง</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $bookings->links() }}</div>
        </div>

        @if($legacyBookings && $legacyBookings->count())
        <div class="content-card p-4 mt-4">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3 flex-wrap">
                <div>
                    <h2 class="h5 fw-bold mb-1">ใบจองเก่า BPlus</h2>
                    <div class="text-muted small">
                        ข้อมูลอ่านย้อนหลังจาก MSSQL เดือนมิถุนายน 2569 ยังไม่เอาไปตัดสต็อกหรือแปลงขายในระบบใหม่
                    </div>
                </div>
                <span class="badge text-bg-info rounded-pill px-3 py-2">
                    {{ number_format($counts['legacy']) }} ใบ
                </span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>เลขที่เดิม</th>
                            <th>วันที่</th>
                            <th>ประเภท</th>
                            <th>ลูกค้า</th>
                            <th class="text-end">รายการ</th>
                            <th class="text-end">จำนวน</th>
                            <th class="text-end">ยอดรวม</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($legacyBookings as $legacy)
                            <tr>
                                <td class="fw-semibold">{{ $legacy->doc_number }}</td>
                                <td class="text-nowrap">{{ \Carbon\Carbon::parse($legacy->doc_date)->thaiDate() }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $legacy->legacy_type_code }}</div>
                                    <div class="text-muted small">{{ $legacy->legacy_type_name }}</div>
                                </td>
                                <td>
                                    <div>{{ $legacy->customer_name ?: '-' }}</div>
                                    <div class="text-muted small">{{ $legacy->customer_code }}</div>
                                </td>
                                <td class="text-end">{{ number_format((float) $legacy->item_count) }}</td>
                                <td class="text-end">{{ number_format((float) $legacy->total_qty, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $legacy->total_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('bookings.legacy-show', $legacy->di_key) }}" class="btn btn-sm btn-outline-primary">
                                        ดู
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $legacyBookings->links() }}</div>
        </div>
        @endif
    </div>

    @include('documents.partials.entry-modal', [
        'title' => 'ใบจองสินค้า',
        'subtitle' => 'เลือกสาขา ลูกค้า พนักงาน และรายการสินค้าเท่านั้น',
        'action' => route('bookings.store'),
        'branches' => $branches,
        'salesmen' => $salesmen,
        'partyType' => 'customer',
        'partyLabel' => 'ลูกค้า',
        'partyRequired' => true,
        'showSalesman' => true,
        'itemTitle' => 'รายการจอง',
        'submitLabel' => 'บันทึกใบจอง',
        'remarkPlaceholder' => 'เงื่อนไขส่งของ / หมายเหตุการจอง',
    ])
</div>
@endsection

@include('documents.partials.entry-assets')

@push('head')
<style>
    .doc-filter-strip {
        display: flex;
        align-items: end;
        gap: 10px;
        flex-wrap: wrap;
        border: 1px solid #dbeafe;
        background: #f8fbff;
        border-radius: 14px;
        padding: 10px 12px;
    }
    .doc-filter-strip .filter-field {
        min-width: 190px;
    }
    .doc-filter-strip .filter-search {
        min-width: min(320px, 100%);
        flex: 1;
    }
    .doc-filter-strip label {
        display: block;
        margin-bottom: 4px;
        color: #2563eb;
        font-size: 11px;
        font-weight: 800;
    }
</style>
@endpush
