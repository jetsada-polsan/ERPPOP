@extends('layout')

@section('title', 'ใบซื้อ - POPSTAR ERP')
@section('page-title', 'ใบซื้อ / รับสินค้าเข้าคลัง')
@section('page-subtitle', 'คีย์รับสินค้าเข้า ใช้เฉพาะข้อมูลจำเป็นก่อนต่อบัญชีเต็มระบบ')

@section('content')
<div x-data="docEntryPage({ partyType: 'supplier', partyLabel: 'ซัพพลายเออร์', partyRequired: true })" x-cloak>
    <div class="doc-shell">
        <div class="doc-tabs">
            <a href="{{ route('bookings.index') }}" class="doc-tab">ใบจอง / ขายเชื่อ</a>
            <a href="{{ route('cash-sales.index') }}" class="doc-tab">ใบขายสด</a>
            <a href="{{ route('sale-returns.index') }}" class="doc-tab">ใบรับคืน</a>
            <span class="doc-tab active">ใบซื้อ</span>
        </div>

        <div class="doc-toolbar">
            <div class="doc-toolbar-title">
                <span class="doc-mark"><i class="bi bi-basket-fill"></i></span>
                <div>
                    <h2 class="h5 fw-bold mb-0">ใบซื้อ / รับสินค้าเข้า</h2>
                    <div class="text-muted small">บันทึกแล้วเพิ่มสต็อกเข้าคลังทันที</div>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openModal()">
                <i class="bi bi-plus-lg me-1"></i> สร้างใบซื้อ
            </button>
        </div>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>วันที่</th>
                            <th>สาขา</th>
                            <th>ซัพพลายเออร์</th>
                            <th class="text-end">ยอดรวม</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchases as $purchase)
                            <tr>
                                <td class="fw-semibold">{{ $purchase->doc_number }}</td>
                                <td>{{ $purchase->doc_date->thaiDate() }}</td>
                                <td>{{ $purchase->branch->name_th }}</td>
                                <td>{{ $purchase->supplier->name_th }}</td>
                                <td class="text-end">{{ number_format($purchase->total_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="doc-empty">ยังไม่มีใบซื้อ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $purchases->links() }}</div>
        </div>
    </div>

    @include('documents.partials.entry-modal', [
        'title' => 'ใบซื้อ / รับสินค้าเข้า',
        'subtitle' => 'เลือกซัพพลายเออร์ สาขารับสินค้า และรายการสินค้า',
        'action' => route('purchases.store'),
        'branches' => $branches,
        'partyType' => 'supplier',
        'partyLabel' => 'ซัพพลายเออร์',
        'partyRequired' => true,
        'showCreditType' => true,
        'showLotFields' => true,
        'itemTitle' => 'รายการรับสินค้า',
        'submitLabel' => 'บันทึกใบซื้อ',
        'remarkPlaceholder' => 'เลขอ้างอิงจากผู้ขาย / หมายเหตุรับสินค้า',
    ])
</div>
@endsection

@include('documents.partials.entry-assets')
