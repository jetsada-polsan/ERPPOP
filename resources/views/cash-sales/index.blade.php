@extends('layout')

@section('title', 'ใบขายสด - MPM Food Group')
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

        <div class="content-card p-4" x-data="{
            selectedIds: [],
            toggleAll(e) {
                if (e.target.checked) {
                    this.selectedIds = Array.from(document.querySelectorAll('.cash-sale-row-check')).map(el => el.value);
                } else {
                    this.selectedIds = [];
                }
            },
            updateSelectAll() {
                const all = document.querySelectorAll('.cash-sale-row-check');
                const master = document.getElementById('cashSaleSelectAll');
                if (master) {
                    master.checked = all.length > 0 && this.selectedIds.length === all.length;
                }
            },
            printSelected() {
                if (this.selectedIds.length === 0) return;
                const url = '{{ route('documents.delivery-notes.batch') }}?' + this.selectedIds.map(id => 'ids[]=' + encodeURIComponent(id)).join('&');
                window.open(url, '_blank');
            }
        }">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-primary rounded-pill px-3"
                            :disabled="selectedIds.length === 0"
                            @click="printSelected()">
                        <i class="bi bi-printer me-1"></i> พิมพ์ใบส่งของที่เลือก <span x-show="selectedIds.length > 0" x-text="'(' + selectedIds.length + ')'"></span>
                    </button>
                    <span class="text-muted small" x-show="selectedIds.length > 0">เลือกแล้ว <b x-text="selectedIds.length"></b> รายการ</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="cashSaleSelectAll" class="form-check-input" @change="toggleAll($event)" title="เลือกทั้งหมดในหน้านี้">
                            </th>
                            <th>เลขที่</th>
                            <th>วันที่</th>
                            <th>เวลา</th>
                            <th>สาขา</th>
                            <th>ลูกค้า</th>
                            <th class="text-end">ยอดรวม</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $sale)
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input cash-sale-row-check" value="{{ $sale->id }}" x-model="selectedIds" @change="updateSelectAll()">
                                </td>
                                <td class="fw-semibold">{{ $sale->doc_number }}</td>
                                <td>{{ $sale->doc_date->thaiDate() }}</td>
                                <td>{{ $sale->created_at?->format('H:i:s') ?? '-' }}</td>
                                <td>{{ $sale->branch->name_th }}</td>
                                <td>{{ $sale->customer?->name_th ?? 'ลูกค้าเงินสด' }}</td>
                                <td class="text-end">{{ number_format($sale->total_amount, 2) }}</td>
                                <td>@include('partials.doc-status', ['status' => $sale->status])</td>
                                <td class="text-end">
                                    <a href="{{ route('cash-sales.show', $sale) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="doc-empty">ยังไม่มีใบขายสด</td></tr>
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
