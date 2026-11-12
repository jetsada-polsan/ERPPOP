@extends('layout')

@section('title', 'ปรับปรุงยอดสต็อก')
@section('page-title', 'ปรับปรุงยอดสต็อก')
@section('page-subtitle', 'แก้ยอดสต็อกในระบบเมื่อของจริงไม่ตรง (เสีย หาย เกิน) ส่วนการนับทั้งคลังใช้เมนู ตรวจนับสินค้า')

@section('content')
    <div x-data="stockAdjustmentPage()" x-cloak>
        <ul class="nav nav-pills mb-4">
            <li class="nav-item"><a href="{{ route('stock-transfers.index') }}" class="nav-link">โอนย้ายสต็อก</a></li>
            <li class="nav-item"><span class="nav-link active">ปรับปรุงยอดสต็อก</span></li>
        </ul>

        <div class="list-toolbar flex-wrap gap-2">
            <div class="list-toolbar-left flex-wrap gap-2">
                <h2 class="h5 fw-bold mb-0">ปรับปรุงสต็อก</h2>
                @include('partials.list-date-range', ['dateRange' => $dateRange, 'wrapForm' => true])
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาเลขที่เอกสาร'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openModal()">
                <i class="bi bi-plus-lg me-1"></i> สร้างใบปรับปรุงยอด
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
                            <th class="text-end">จำนวนรายการ</th>
                            <th class="text-end">ผลต่างรวม</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($adjustments as $adjustment)
                            <tr>
                                <td class="fw-semibold">{{ $adjustment->doc_number }}</td>
                                <td>{{ $adjustment->doc_date->thaiDate() }}</td>
                                <td>{{ $adjustment->branch->name_th }}</td>
                                <td class="text-end">{{ $adjustment->stockDocument?->total_items ?? 0 }}</td>
                                <td class="text-end">{{ number_format($adjustment->stockDocument?->total_qty ?? 0, 2) }}</td>
                                <td>@include('partials.doc-status', ['status' => $adjustment->status])</td>
                                <td class="text-end">
                                    <a href="{{ route('stock-adjustments.show', $adjustment) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-5 text-center text-muted">ยังไม่มีรายการปรับปรุงสต็อก</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $adjustments->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="closeModal()">
            <div class="booking-modal" @click.outside="closeModal()" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <div>
                        <h3 class="h4 fw-bold mb-1">สร้างใบปรับปรุงยอดสต็อก</h3>
                        <div class="text-muted small">เลือกคลัง แล้วใส่ยอดนับจริงของแต่ละสินค้า ระบบจะคำนวณส่วนต่างให้อัตโนมัติ</div>
                    </div>
                    <button type="button" class="btn btn-light rounded-circle" @click="closeModal()" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <form method="post" action="{{ route('stock-adjustments.store') }}" @submit="onSubmit">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3 mb-4">
                            <div class="col-lg-6">
                                <label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" x-model="branchId" required class="form-select">
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label text-muted small">คลัง / ตู้ที่ปรับยอด</label>
                                <select name="warehouse_location_id" x-model="locationId" @change="onLocationChange()" required class="form-select">
                                    <option value="">-- เลือกคลัง --</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}">{{ $location->code }} - {{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">หมายเหตุ</label>
                                <input type="text" name="remark" class="form-control" placeholder="รายละเอียดเพิ่มเติม">
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <h4 class="h6 fw-bold mb-0">รายการสินค้า</h4>
                            <button type="button" class="btn btn-sm btn-light border" @click="addItem()">
                                <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการ
                            </button>
                        </div>

                        <div class="table-responsive booking-items-table">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th style="min-width: 320px;">สินค้า</th>
                                        <th class="text-end" style="width: 130px;">ยอดในระบบ</th>
                                        <th class="text-end" style="width: 140px;">ยอดนับจริง</th>
                                        <th class="text-end" style="width: 110px;">ผลต่าง</th>
                                        <th style="width: 48px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, index) in items" :key="index">
                                        <tr>
                                            <td>
                                                <button type="button" class="selected-product" :class="{ empty: !item.product_id }" @click="openProductPicker(index)">
                                                    <template x-if="!item.product_id"><span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกสินค้า</span></template>
                                                    <template x-if="item.product_id"><span class="selected-product-grid"><b class="selected-code" x-text="item.sku_code"></b><span class="selected-copy"><strong x-text="item.name_th || item.productQuery"></strong><small x-text="item.unit_name || 'หน่วยฐาน'"></small></span><span class="selected-change">เปลี่ยน</span></span></template>
                                                </button>
                                                <input type="hidden" :name="`items[${index}][product_id]`" x-model="item.product_id">
                                            </td>
                                            <td class="text-end" x-text="item.previewReady ? item.systemQty : '—'"></td>
                                            <td>
                                                <input type="number" step="0.0001" min="0" :name="`items[${index}][counted_qty]`"
                                                    x-model.number="item.counted_qty" :disabled="!item.previewReady" required class="form-control text-end">
                                            </td>
                                            <td class="text-end fw-semibold" :class="diffClass(index)" x-text="diffLabel(index)"></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-light text-danger" @click="removeItem(index)" x-show="items.length > 1">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="closeModal()">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i> บันทึกการปรับปรุง
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @include('partials.product-picker')
    </div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(15, 23, 42, .42);
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .booking-modal {
        width: min(1000px, 100%);
        max-height: calc(100vh - 48px);
        overflow: auto;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
    .booking-items-table { border: 1px solid var(--erp-border); border-radius: 14px; }
    .booking-items-table .table { margin-bottom: 0; }
    .typeahead-list {
        position: absolute; z-index: 2050; left: 0; right: 0; top: calc(100% + 4px);
        max-height: 260px; overflow: auto; background: #fff; border: 1px solid var(--erp-border);
        border-radius: 12px; box-shadow: 0 14px 36px rgba(15, 23, 42, .14); padding: 6px;
    }
    .typeahead-item {
        width: 100%; border: 0; background: transparent; border-radius: 9px; padding: 9px 10px;
        display: flex; align-items: center; gap: 10px; text-align: left;
    }
    .typeahead-item:hover { background: var(--erp-primary-soft); }
</style>
@endpush

@push('scripts')
<script>
    function stockAdjustmentPage() {
        return {
            ...erpProductPicker(),
            ...erpStockPreview(@js($branches), '{{ route('search.stock-balance') }}'),
            modalOpen: false,
            items: [{ product_id: '', productQuery: '', counted_qty: 0, systemQty: null, results: [] }],

            openModal() { this.modalOpen = true; },
            closeModal() { this.modalOpen = false; },
            addItem() {
                this.items.push({ product_id: '', productQuery: '', counted_qty: 0, systemQty: null, results: [] });
            },
            removeItem(index) { this.items.splice(index, 1); },
            onLocationChange() {},
            async onProductPicked(index, product) {
                this.items[index].productQuery = `${product.sku_code} - ${product.name_th}`;
                await this.refreshSystemQty(index);
            },
            async refreshSystemQty(index) {
                const item = this.items[index];
                await this.previewStock(item);
                if (item.previewReady) item.counted_qty = item.systemQty;
            },
            diff(index) {
                const item = this.items[index];
                return (Number(item.counted_qty) || 0) - (Number(item.systemQty) || 0);
            },
            diffLabel(index) {
                if (!this.items[index].previewReady) return '—';
                const diff = this.diff(index);
                const formatted = diff.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                return diff > 0 ? `+${formatted}` : formatted;
            },
            diffClass(index) {
                const diff = this.diff(index);
                if (diff > 0) return 'text-success';
                if (diff < 0) return 'text-danger';
                return 'text-muted';
            },
            onSubmit(event) {
                if (!this.stockPreviewReady()) {
                    event.preventDefault();
                    window.erpToast?.('warning', 'กรุณารอข้อมูลสต๊อก หรือเลือกคลังและสินค้าใหม่');
                    return;
                }
                if (!this.locationId) {
                    event.preventDefault();
                    window.erpPopup('warning', 'กรุณาเลือกคลังที่จะปรับยอด');
                    return;
                }
                if (this.items.some(item => !item.product_id)) {
                    event.preventDefault();
                    window.erpPopup('warning', 'กรุณาเลือกสินค้าให้ครบทุกแถว');
                    return;
                }
            },
        };
    }
</script>
@endpush
