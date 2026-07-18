@extends('layout')

@section('title', 'แปรรูปสินค้า - POPSTAR ERP')
@section('page-title', 'ใบแปรรูปสินค้า (DT)')
@section('page-subtitle', 'ตัดวัตถุดิบออกจากสต๊อก รับผลผลิตเข้าสต๊อก พร้อมปันต้นทุนตาม %')

@section('content')
<div x-data="transformPage()" x-cloak>
    <ul class="nav nav-pills mb-4">
        <li class="nav-item"><a href="{{ route('stock-issues.index', ['type' => 'requisition']) }}" class="nav-link">ใบเบิกสินค้า</a></li>
        <li class="nav-item"><a href="{{ route('stock-issues.index', ['type' => 'requisition_return']) }}" class="nav-link">ใบคืนสินค้าจากการเบิก</a></li>
        <li class="nav-item"><a href="{{ route('stock-issues.index', ['type' => 'damage']) }}" class="nav-link">ใบตัดสินค้าชำรุด</a></li>
        <li class="nav-item"><span class="nav-link active">ใบแปรรูปสินค้า</span></li>
    </ul>

    <div class="list-toolbar">
        <div class="list-toolbar-left">
            <h2 class="h5 fw-bold mb-0">ใบแปรรูปสินค้า</h2>
            @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาเลขที่เอกสาร'])
        </div>
        <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
            <i class="bi bi-plus-lg me-1"></i> สร้างใบแปรรูป
        </button>
    </div>

    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>สาขา</th><th>หมายเหตุ</th><th class="text-end">มูลค่าวัตถุดิบ</th><th></th></tr></thead>
                <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td class="fw-semibold">{{ $doc->doc_number }}</td>
                            <td>{{ $doc->doc_date->thaiDate() }}</td>
                            <td>{{ $doc->branch->name_th }}</td>
                            <td class="small text-muted">{{ $doc->remark ?? '-' }}</td>
                            <td class="text-end">{{ number_format($doc->total_amount, 2) }}</td>
                            <td class="text-end"><a href="{{ route('stock-transforms.show', $doc) }}" class="btn btn-sm btn-light border">ดู</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-5 text-center text-muted">ยังไม่มีใบแปรรูปสินค้า</td></tr>
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
                    <h3 class="h4 fw-bold mb-1">สร้างใบแปรรูปสินค้า</h3>
                    <div class="text-muted small">วัตถุดิบจะถูกตัดสต๊อก ผลผลิตรับเข้าสต๊อกพร้อมต้นทุนปันตาม % (ไม่กรอก % = เฉลี่ยเท่ากัน)</div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>

            <form method="post" action="{{ route('stock-transforms.store') }}" @submit="onSubmit">
                @csrf
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
                            <select name="warehouse_location_id" class="form-select">
                                <option value="">-- คลังหลักของสาขา --</option>
                                @foreach($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->code }} - {{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-4">
                            <label class="form-label text-muted small">หมายเหตุ</label>
                            <input type="text" name="remark" class="form-control" placeholder="เช่น แปรรูปปลาเป็นเนื้อแล่">
                        </div>
                    </div>

                    {{-- วัตถุดิบ --}}
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 fw-bold mb-0 text-danger"><i class="bi bi-box-arrow-up me-1"></i>วัตถุดิบ (ตัดออกจากสต๊อก)</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addRaw()"><i class="bi bi-plus-lg me-1"></i> เพิ่มวัตถุดิบ</button>
                    </div>
                    <div class="table-responsive booking-items-table mb-2">
                        <table class="table align-middle">
                            <thead><tr><th style="min-width:280px">สินค้า</th><th class="text-end" style="width:110px">จำนวน</th><th class="text-end" style="width:130px">ทุน/หน่วย</th><th class="text-end" style="width:120px">รวม</th><th style="width:44px"></th></tr></thead>
                            <tbody>
                                <template x-for="(item, index) in rawItems" :key="'r' + index">
                                    <tr>
                                        <td class="position-relative">
                                            <input type="text" x-model="item.productQuery" @input.debounce.300ms="searchProducts(item)" placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control" autocomplete="off">
                                            <input type="hidden" :name="`raw_items[${index}][product_id]`" x-model="item.product_id">
                                            <div class="typeahead-list" x-show="item.results.length" x-transition>
                                                <template x-for="product in item.results" :key="product.id">
                                                    <button type="button" class="typeahead-item" @click="selectProduct(item, product)">
                                                        <span class="fw-semibold" x-text="product.sku_code"></span><span x-text="product.name_th"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`raw_items[${index}][qty]`" x-model.number="item.qty" required class="form-control text-end"></td>
                                        <td><input type="number" step="0.01" min="0" :name="`raw_items[${index}][unit_price]`" x-model.number="item.unit_price" required class="form-control text-end"></td>
                                        <td class="text-end fw-semibold" x-text="money(item.qty * item.unit_price)"></td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger" @click="rawItems.splice(index, 1)" x-show="rawItems.length > 1"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end mb-4 small">มูลค่าวัตถุดิบรวม: <strong class="text-danger" x-text="money(rawTotal)"></strong> บาท</div>

                    {{-- ผลผลิต --}}
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 fw-bold mb-0 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>ผลผลิต (รับเข้าสต๊อก)</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addOutput()"><i class="bi bi-plus-lg me-1"></i> เพิ่มผลผลิต</button>
                    </div>
                    <div class="table-responsive booking-items-table">
                        <table class="table align-middle">
                            <thead><tr><th style="min-width:280px">สินค้า</th><th class="text-end" style="width:110px">จำนวน</th><th class="text-end" style="width:110px">%ปันทุน</th><th class="text-end" style="width:130px">ทุนที่ได้/หน่วย</th><th style="width:44px"></th></tr></thead>
                            <tbody>
                                <template x-for="(item, index) in outputItems" :key="'o' + index">
                                    <tr>
                                        <td class="position-relative">
                                            <input type="text" x-model="item.productQuery" @input.debounce.300ms="searchProducts(item)" placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control" autocomplete="off">
                                            <input type="hidden" :name="`output_items[${index}][product_id]`" x-model="item.product_id">
                                            <div class="typeahead-list" x-show="item.results.length" x-transition>
                                                <template x-for="product in item.results" :key="product.id">
                                                    <button type="button" class="typeahead-item" @click="selectProduct(item, product)">
                                                        <span class="fw-semibold" x-text="product.sku_code"></span><span x-text="product.name_th"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`output_items[${index}][qty]`" x-model.number="item.qty" required class="form-control text-end"></td>
                                        <td><input type="number" step="0.01" min="0" max="100" :name="`output_items[${index}][percent]`" x-model.number="item.percent" class="form-control text-end" placeholder="อัตโนมัติ"></td>
                                        <td class="text-end fw-semibold text-success" x-text="outputUnitCost(item)"></td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger" @click="outputItems.splice(index, 1)" x-show="outputItems.length > 1"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึกใบแปรรูป</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .booking-modal { width: min(1020px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
    .booking-items-table { border: 1px solid #e7eaf2; border-radius: 14px; }
    .booking-items-table .table { margin-bottom: 0; }
    .typeahead-list { position: absolute; z-index: 2050; left: 0; right: 0; top: calc(100% + 4px); max-height: 260px; overflow: auto; background: #fff; border: 1px solid #dbe1ea; border-radius: 12px; box-shadow: 0 14px 36px rgba(15,23,42,.14); padding: 6px; }
    .typeahead-item { width: 100%; border: 0; background: transparent; border-radius: 9px; padding: 9px 10px; display: flex; align-items: center; gap: 10px; text-align: left; }
    .typeahead-item:hover { background: #f2f6ff; }
</style>
@endpush

@push('scripts')
<script>
    function transformPage() {
        return {
            modalOpen: false,
            rawItems: [{ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [] }],
            outputItems: [{ product_id: '', productQuery: '', qty: 1, percent: null, results: [] }],

            addRaw() { this.rawItems.push({ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [] }); },
            addOutput() { this.outputItems.push({ product_id: '', productQuery: '', qty: 1, percent: null, results: [] }); },

            get rawTotal() {
                return this.rawItems.reduce((s, i) => s + (Number(i.qty) || 0) * (Number(i.unit_price) || 0), 0);
            },

            outputUnitCost(item) {
                const qty = Number(item.qty) || 0;
                if (qty <= 0 || this.rawTotal <= 0) return '-';
                const filled = this.outputItems.filter(i => i.percent !== null && i.percent !== '');
                const pct = (item.percent !== null && item.percent !== '')
                    ? Number(item.percent)
                    : (filled.length === 0 ? 100 / this.outputItems.length : 0);
                return this.money(this.rawTotal * pct / 100 / qty);
            },

            money(v) { return Number(v || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

            async searchProducts(item) {
                if (item.productQuery.length < 1) { item.results = []; item.product_id = ''; return; }
                const response = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(item.productQuery)}`);
                item.results = await response.json();
            },

            selectProduct(item, product) {
                item.product_id = product.id;
                item.productQuery = `${product.sku_code} - ${product.name_th}`;
                item.results = [];
                if ('unit_price' in item && !item.unit_price) {
                    item.unit_price = Number(product.default_price) || 0;
                }
            },

            onSubmit(event) {
                if (this.rawItems.some(i => !i.product_id) || this.outputItems.some(i => !i.product_id)) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกแถว' });
                }
            },
        };
    }
</script>
@endpush
