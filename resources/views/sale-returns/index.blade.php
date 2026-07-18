@extends('layout')

@section('title', 'ใบรับคืนสินค้า - POPSTAR ERP')
@section('page-title', 'ใบรับคืนสินค้า')
@section('page-subtitle', 'รับสินค้าคืนเข้าสต็อก และลดมูลค่าขายตามเอกสาร')

@section('content')
<div x-data="docEntryPage({ partyType: 'customer', partyLabel: 'ลูกค้า', partyRequired: false })" x-cloak>
    <div class="doc-shell">
        <div class="doc-tabs">
            <a href="{{ route('bookings.index') }}" class="doc-tab">ใบจอง / ขายเชื่อ</a>
            <a href="{{ route('cash-sales.index') }}" class="doc-tab">ใบขายสด</a>
            <span class="doc-tab active">ใบรับคืน</span>
            <a href="{{ route('purchases.index') }}" class="doc-tab">ใบซื้อ</a>
        </div>

        <div class="doc-toolbar">
            <div class="doc-toolbar-title">
                <span class="doc-mark"><i class="bi bi-arrow-return-left"></i></span>
                <div>
                    <h2 class="h5 fw-bold mb-0">ใบรับคืนสินค้า</h2>
                    <div class="text-muted small">ใช้เมื่อรับสินค้าคืนจากลูกค้า</div>
                </div>
            </div>
            <button type="button" class="btn btn-warning rounded-pill px-4" @click="openModal()">
                <i class="bi bi-plus-lg me-1"></i> สร้างใบรับคืน
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
                            <th>ลูกค้า</th>
                            <th class="text-end">ยอดรวม</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($returns as $ret)
                            <tr>
                                <td class="fw-semibold">{{ $ret->doc_number }}</td>
                                <td>{{ $ret->doc_date->thaiDate() }}</td>
                                <td>{{ $ret->branch->name_th }}</td>
                                <td>{{ $ret->customer?->name_th ?? '-' }}</td>
                                <td class="text-end">{{ number_format($ret->total_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('sale-returns.show', $ret) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="doc-empty">ยังไม่มีใบรับคืน</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $returns->links() }}</div>
        </div>
    </div>

    @include('documents.partials.entry-modal', [
        'title' => 'ใบรับคืนสินค้า',
        'subtitle' => 'เลือกสาขา เพิ่มสินค้าที่รับคืน และระบุเหตุผลไว้ในรายละเอียดเพิ่มเติม',
        'action' => route('sale-returns.store'),
        'branches' => $branches,
        'partyType' => 'customer',
        'partyLabel' => 'ลูกค้า',
        'partyRequired' => false,
        'itemTitle' => 'รายการรับคืน',
        'submitLabel' => 'บันทึกใบรับคืน',
        'remarkPlaceholder' => 'เช่น สินค้าชำรุด / ผิดรายการ / ลูกค้าเปลี่ยนใจ',
    ])
</div>
@endsection

@include('documents.partials.entry-assets')
