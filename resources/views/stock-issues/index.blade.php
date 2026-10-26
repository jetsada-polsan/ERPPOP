@extends('layout')

@section('title', 'เบิก-คืน-ตัดชำรุด - PopCentral')
@section('page-title', 'งานคลังสินค้า: เบิก / คืนเบิก / ตัดชำรุด')
@section('page-subtitle', 'ใบเบิกสินค้า (DR) ใบคืนสินค้าจากการเบิก (IR) และใบตัดสินค้าชำรุด (DD)')

@section('content')
<div x-data="stockIssuePage()" x-cloak>
    <ul class="nav nav-pills mb-4">
        @foreach($typeLabels as $key => $label)
            <li class="nav-item">
                <a href="{{ route('stock-issues.index', ['type' => $key]) }}" class="nav-link {{ $type === $key ? 'active' : '' }}">{{ $label }}</a>
            </li>
        @endforeach
        <li class="nav-item"><a href="{{ route('stock-transforms.index') }}" class="nav-link">ใบแปรรูปสินค้า</a></li>
    </ul>

    <div class="list-toolbar">
        <div class="list-toolbar-left">
            <h2 class="h5 fw-bold mb-0">{{ $typeLabels[$type] }}</h2>
            @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาเลขที่เอกสาร'])
        </div>
        <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
            <i class="bi bi-plus-lg me-1"></i> สร้าง{{ $typeLabels[$type] }}
        </button>
    </div>

    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>เลขที่</th><th>วันที่</th><th>สาขา</th>
                        @if($type === 'requisition_return')<th>อ้างอิงใบเบิก</th>@endif
                        <th>หมายเหตุ</th>
                        <th class="text-end">จำนวนรวม</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td class="fw-semibold">{{ $doc->doc_number }}</td>
                            <td>{{ $doc->doc_date->thaiDate() }}</td>
                            <td>{{ $doc->branch->name_th }}</td>
                            @if($type === 'requisition_return')<td class="small">{{ $doc->reference ?? '-' }}</td>@endif
                            <td class="small text-muted">{{ $doc->remark ?? '-' }}</td>
                            <td class="text-end">{{ number_format($doc->stockDocument?->total_qty ?? 0, 2) }}</td>
                            <td class="text-end"><a href="{{ route('stock-issues.show', $doc) }}" class="btn btn-sm btn-light border">ดู</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-5 text-center text-muted">ยังไม่มี{{ $typeLabels[$type] }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $documents->links() }}</div>
    </div>

    {{-- Create modal --}}
    <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
        <div class="booking-modal" @click.outside="modalOpen = false" x-transition>
            <div class="modal-header border-0 px-4 pt-4 pb-2">
                <div>
                    <h3 class="h4 fw-bold mb-1">สร้าง{{ $typeLabels[$type] }}</h3>
                    <div class="text-muted small">
                        @if($type === 'requisition') เบิกสินค้าไปใช้ - ระบบจะตัดสต๊อกทันที
                        @elseif($type === 'requisition_return') คืนสินค้าที่เบิกไปใช้ไม่หมด - ระบบจะรับสินค้ากลับเข้าสต๊อก
                        @else ตัดสินค้าชำรุดที่จำหน่าย/แปรรูปไม่ได้ออกจากสต๊อก @endif
                    </div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>

            <form method="post" action="{{ route('stock-issues.store') }}" @submit="onSubmit">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <div class="modal-body px-4 pb-4">
                    <div class="row g-3 mb-4">
                        <div class="col-lg-4">
                            <label class="form-label text-muted small">สาขา</label>
                            <select name="branch_id" required class="form-select">
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label text-muted small">ตำแหน่งเก็บ (ไม่เลือก = คลังหลักสาขา)</label>
                            <select name="warehouse_location_id" x-model="locationId" class="form-select">
                                <option value="">-- คลังหลักของสาขา --</option>
                                @foreach($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->code }} - {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($type === 'requisition')
                        <div class="col-lg-4">
                            <label class="form-label text-muted small">ประเภทการเบิก</label>
                            <select name="purpose" required class="form-select">
                                @foreach($purposes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @elseif($type === 'requisition_return')
                        <div class="col-lg-4">
                            <label class="form-label text-muted small">อ้างอิงใบเบิก</label>
                            <select name="reference" class="form-select">
                                <option value="">-- ไม่ระบุ --</option>
                                @foreach($recentRequisitions as $req)
                                    <option value="{{ $req->doc_number }}">{{ $req->doc_number }} ({{ $req->doc_date->thaiDate() }})</option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        <div class="col-12">
                            <label class="form-label text-muted small">{{ $type === 'damage' ? 'สาเหตุที่ชำรุด / หมายเหตุ' : 'หมายเหตุ' }}</label>
                            <input type="text" name="remark" class="form-control" placeholder="{{ $type === 'damage' ? 'เช่น สินค้าหมดอายุ แพ็คเกจเสียหาย' : 'รายละเอียดเพิ่มเติม' }}">
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 fw-bold mb-0">รายการสินค้า</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addItem()"><i class="bi bi-plus-lg me-1"></i> เพิ่มรายการ</button>
                    </div>

                    <div class="table-responsive booking-items-table">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th style="min-width:320px">สินค้า</th>
                                    <th class="text-end" style="width:130px">คงเหลือ</th>
                                    <th class="text-end" style="width:140px">จำนวน</th>
                                    <th style="width:48px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, index) in items" :key="index">
                                    <tr>
                                        <td class="position-relative">
                                            <input type="hidden" :name="`items[${index}][product_id]`" x-model="item.product_id">
                                            <button type="button" class="selected-product" :class="{ empty: !item.product_id }" @click="openProductPicker(index)">
                                                <template x-if="!item.product_id">
                                                    <span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกสินค้า</span>
                                                </template>
                                                <template x-if="item.product_id">
                                                    <span class="selected-product-grid">
                                                        <b class="selected-code" x-text="item.sku_code"></b>
                                                        <span class="selected-copy"><strong x-text="item.name_th"></strong><small x-text="item.unit_name + (item.barcode ? ' · ' + item.barcode : '')"></small></span>
                                                        <span class="selected-change">เปลี่ยน</span>
                                                    </span>
                                                </template>
                                            </button>
                                        </td>
                                        <td class="text-end" x-text="item.onHandQty"></td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`items[${index}][qty]`" x-model.number="item.qty" required class="form-control text-end"></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-light text-danger" @click="items.splice(index, 1)" x-show="items.length > 1"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึก{{ $typeLabels[$type] }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Product picker: แยกจากตารางเพื่อไม่ให้ผลค้นหาถูก modal/scroll บัง --}}
    @include('partials.product-picker')
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .booking-modal { width: min(960px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
    .booking-items-table { border: 1px solid var(--erp-border); border-radius: 14px; overflow: visible !important; }
    .booking-items-table .table { margin-bottom: 0; }
</style>
@endpush

@push('scripts')
<script>
    function stockIssueItem() {
        return { product_id: '', sku_code: '', name_th: '', unit_name: '', barcode: '', qty: 1, onHandQty: '-' };
    }

    function stockIssuePage() {
        return {
            ...erpProductPicker(),

            modalOpen: false,
            locationId: '',
            items: [stockIssueItem()],

            addItem() {
                this.items.push(stockIssueItem());
                this.openProductPicker(this.items.length - 1);
            },

            // hook ที่ picker เรียกหลังเลือกสินค้า
            onProductPicked(index) {
                this.refreshOnHandQty(index);
            },

            async refreshOnHandQty(index) {
                const item = this.items[index];
                if (! item?.product_id || ! this.locationId) return;
                const response = await fetch(`{{ route('search.stock-balance') }}?product_id=${item.product_id}&warehouse_location_id=${this.locationId}`);
                const data = await response.json();
                item.onHandQty = Number(data.on_hand_qty || 0).toLocaleString('th-TH', { minimumFractionDigits: 2 });
            },

            onSubmit(event) {
                if (this.items.some(item => !item.product_id)) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกแถว' });
                }
            },
        };
    }
</script>
@endpush
