@extends('layout')

@section('title', 'ใบขายสด - POPSTAR ERP')
@section('page-title', 'ใบขายสด')
@section('page-subtitle', 'คีย์ขายหลังบ้านแบบสั้น ตัดสต็อกทันทีหลังบันทึก')

@section('content')
<div x-data="docEntryPage({ partyType: 'customer', partyLabel: 'ลูกค้า', partyRequired: false })" x-cloak>
    <div class="doc-shell">
        <div class="doc-tabs">
            <a href="{{ route('bookings.index') }}" class="doc-tab">ใบจอง / ขายเชื่อ</a>
            <span class="doc-tab active">ใบขายสด</span>
            <a href="{{ route('sale-returns.index') }}" class="doc-tab">ใบรับคืน</a>
            <a href="{{ route('purchases.index') }}" class="doc-tab">ใบซื้อ</a>
        </div>

        <div class="doc-toolbar">
            <div class="doc-toolbar-title">
                <span class="doc-mark"><i class="bi bi-cash-stack"></i></span>
                <div>
                    <h2 class="h5 fw-bold mb-0">ใบขายสด</h2>
                    <div class="text-muted small">ใช้เมื่อขายหลังบ้าน ไม่เปิดลูกหนี้</div>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openModal()">
                <i class="bi bi-plus-lg me-1"></i> สร้างใบขายสด
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
                        @forelse($sales as $sale)
                            <tr>
                                <td class="fw-semibold">{{ $sale->doc_number }}</td>
                                <td>{{ $sale->doc_date->thaiDate() }}</td>
                                <td>{{ $sale->branch->name_th }}</td>
                                <td>{{ $sale->customer?->name_th ?? 'ลูกค้าเงินสด' }}</td>
                                <td class="text-end">{{ number_format($sale->total_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('cash-sales.show', $sale) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="doc-empty">ยังไม่มีใบขายสด</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $sales->links() }}</div>
        </div>
    </div>

    @include('documents.partials.entry-modal', [
        'title' => 'ใบขายสด',
        'subtitle' => 'เลือกสาขา ลูกค้าไม่บังคับ แล้วเพิ่มรายการสินค้า',
        'action' => route('cash-sales.store'),
        'branches' => $branches,
        'partyType' => 'customer',
        'partyLabel' => 'ลูกค้า',
        'partyRequired' => false,
        'itemTitle' => 'รายการขาย',
        'submitLabel' => 'บันทึกใบขายสด',
        'remarkPlaceholder' => 'หมายเหตุท้ายบิล',
    ])
</div>
@endsection

@include('documents.partials.entry-assets')
