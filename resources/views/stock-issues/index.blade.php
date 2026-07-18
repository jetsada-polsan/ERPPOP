@extends('layout')

@section('title', 'เบิก-คืน-ตัดชำรุด - POPSTAR ERP')
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
    <div class="product-picker-backdrop" x-show="pickerOpen" x-transition.opacity @keydown.escape.window="closeProductPicker()" style="display:none">
        <div class="product-picker" @click.outside="closeProductPicker()" x-transition>
            <div class="product-picker-head">
                <div><i class="bi bi-box-seam"></i><h3>แฟ้มสินค้า — เลือกสินค้า</h3></div>
                <strong>รายการสินค้า</strong>
                <button type="button" @click="closeProductPicker()"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="product-picker-menu"><span>แฟ้ม</span><span>แก้ไข</span><span>มุมมอง</span><span>วิธีใช้</span></div>
            <div class="product-picker-tools">
                <button type="button" @click="$refs.productPickerSearch.focus()"><i class="bi bi-search"></i><span>ค้นหา</span></button>
                <button type="button" @click="searchPickerProducts()"><i class="bi bi-arrow-clockwise"></i><span>เรียกใหม่</span></button>
                <button type="button" @click="closeProductPicker()"><i class="bi bi-x-circle"></i><span>ปิด</span></button>
            </div>
            <div class="product-picker-tabs"><span class="active">รายการสินค้า</span><span>ค้นหาจากรหัส / บาร์โค้ด</span></div>
            <div class="product-picker-search">
                <label>ค้นหาสินค้า</label><i class="bi bi-search"></i>
                <input x-ref="productPickerSearch" type="text" x-model="pickerQuery" @input.debounce.220ms="searchPickerProducts()"
                    @keydown.enter.prevent="selectFirstPickerProduct()" placeholder="พิมพ์รหัส ชื่อ หรือยิงบาร์โค้ด..." autocomplete="off">
                <button type="button" x-show="pickerQuery" @click="pickerQuery=''; searchPickerProducts()">ล้าง</button>
            </div>
            <div class="product-picker-body">
                <div class="picker-state" x-show="pickerLoading"><i class="bi bi-arrow-repeat"></i> กำลังค้นหา...</div>
                <div class="picker-state" x-show="!pickerLoading && pickerResults.length === 0">ไม่พบสินค้า ลองเปลี่ยนคำค้นหา</div>
                <div class="product-picker-grid" x-show="!pickerLoading && pickerResults.length">
                    <div class="picker-table-head"><span>รหัสสินค้า</span><span>ชื่อสินค้า</span><span>หน่วยนับ</span><span>บาร์โค้ด</span><span>ราคา</span></div>
                    <template x-for="product in pickerResults" :key="product.id">
                        <button type="button" class="picker-card" @click="selectPickerProduct(product)">
                            <span class="picker-code" x-text="product.sku_code"></span>
                            <span class="picker-copy"><strong x-text="product.name_th"></strong></span>
                            <span class="picker-unit" x-text="product.unit_name"></span>
                            <span class="picker-barcode" x-text="product.barcode || '-' "></span>
                            <span class="picker-price" x-text="'฿' + money(product.default_price)"></span>
                        </button>
                    </template>
                </div>
            </div>
            <div class="product-picker-foot"><span x-text="pickerResults.length + ' รายการ — ดับเบิลคลิกหรือกด Enter เพื่อเลือกสินค้า'"></span><button type="button" @click="closeProductPicker()"><i class="bi bi-x-lg"></i> ยกเลิก</button></div>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .booking-modal { width: min(960px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
    .booking-items-table { border: 1px solid #e7eaf2; border-radius: 14px; overflow: visible !important; }
    .booking-items-table .table { margin-bottom: 0; }
    .selected-product { width:100%; min-height:48px; border:1px solid #dbe4ef; border-radius:10px; background:#f8fbff; padding:6px 10px; text-align:left; color:#1e293b; transition:.15s ease; }
    .selected-product:hover { border-color:#38a7dc; background:#f0f9ff; box-shadow:0 0 0 3px rgba(14,165,233,.08); }
    .selected-product.empty { border-style:dashed; color:#168dcc; background:#fff; }
    .selected-empty { display:flex; align-items:center; justify-content:center; gap:8px; font-weight:700; font-size:13px; }
    .selected-product-grid { display:grid; grid-template-columns:72px minmax(0,1fr) 42px; gap:10px; align-items:center; }
    .selected-code { color:#087eb7; font-size:12px; font-variant-numeric:tabular-nums; }
    .selected-copy { display:grid; min-width:0; gap:1px; }
    .selected-copy strong,.selected-copy small { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .selected-copy strong { font-size:13px; }
    .selected-copy small { color:#718096; font-size:10.5px; }
    .selected-change { color:#168dcc; font-size:11px; font-weight:700; text-align:right; }
    .product-picker-backdrop { position:fixed; inset:0; z-index:2200; padding:24px; display:flex; align-items:center; justify-content:center; background:rgba(15,23,42,.5); backdrop-filter:blur(2px); }
    .product-picker { width:min(980px,100%); height:min(650px,calc(100vh - 48px)); overflow:hidden; display:grid; grid-template-rows:42px 28px 66px 31px 50px minmax(0,1fr) 40px; background:#ececec; border:1px solid #6f7780; border-radius:2px; box-shadow:0 24px 70px rgba(15,23,42,.38); font-family:Tahoma,"Noto Sans Thai",sans-serif; }
    .product-picker-head { padding:0 10px; display:grid; grid-template-columns:1fr auto 36px; align-items:center; gap:10px; background:#fafafa; border-bottom:1px solid #aeb4ba; }
    .product-picker-head > div { display:flex; align-items:center; gap:8px; }
    .product-picker-head > div > i { color:#168dcc; font-size:16px; }
    .product-picker-head h3 { margin:0; font-size:14px; font-weight:700; color:#161616; }
    .product-picker-head strong { font-size:12px; color:#111; }
    .product-picker-head button { width:30px; height:30px; border:0; background:transparent; color:#374151; }
    .product-picker-head button:hover { background:#e6e6e6; }
    .product-picker-menu { display:flex; align-items:center; gap:25px; padding:0 12px; background:#f5f5f5; border-bottom:1px solid #aaa; color:#111; font-size:11.5px; }
    .product-picker-tools { display:flex; align-items:stretch; padding:4px 10px; gap:2px; background:#e3e3e3; border-bottom:1px solid #aaa; }
    .product-picker-tools button { width:88px; display:grid; place-items:center; align-content:center; gap:1px; border:0; border-right:1px solid #b7b7b7; background:transparent; color:#111; font-size:10.5px; }
    .product-picker-tools button:hover { background:#f7f7f7; }
    .product-picker-tools i { color:#008dd2; font-size:19px; line-height:20px; }
    .product-picker-tabs { display:flex; align-items:end; padding-left:10px; background:#ddd; border-bottom:1px solid #9ca3aa; }
    .product-picker-tabs span { height:28px; padding:5px 18px 4px; border:1px solid #999; border-bottom:0; background:#e9e9e9; font-size:11px; }
    .product-picker-tabs .active { background:#fff; color:#d62828; font-weight:700; }
    .product-picker-search { margin:7px 10px; min-height:36px; padding:0 8px; display:grid; grid-template-columns:82px 18px minmax(0,1fr) auto; align-items:center; gap:6px; border:1px solid #a6a6a6; background:#f4f4f4; }
    .product-picker-search label { margin:0; font-size:11.5px; font-weight:700; }
    .product-picker-search > i { color:#0786c1; font-size:14px; }
    .product-picker-search input { height:27px; min-width:0; border:1px solid #8f969c; outline:0; padding:3px 8px; background:#fff; font-size:12px; color:#111; }
    .product-picker-search input:focus { border-color:#168dcc; box-shadow:inset 0 0 0 1px #168dcc; }
    .product-picker-search button { height:27px; min-width:52px; border:1px solid #999; padding:2px 10px; background:linear-gradient(#fff,#e5e5e5); color:#111; font-size:11px; }
    .product-picker-body { min-height:0; overflow:auto; margin:0 10px 8px; padding:0; border:1px solid #8f969c; background:#fff; }
    .product-picker-grid { display:block; min-width:700px; }
    .picker-table-head,.picker-card { display:grid; grid-template-columns:105px minmax(260px,1fr) 120px 170px 100px; align-items:center; }
    .picker-table-head { position:sticky; top:0; z-index:1; height:29px; background:linear-gradient(#fafafa,#dcdcdc); border-bottom:1px solid #999; color:#111; font-size:11px; font-weight:700; }
    .picker-table-head span { height:100%; padding:6px 9px; border-right:1px solid #aaa; }
    .picker-card { width:100%; min-height:31px; padding:0; text-align:left; color:#111; background:#fff; border:0; border-bottom:1px solid #d1d5db; font-size:11.5px; }
    .picker-card:nth-of-type(odd) { background:#f4f7f9; }
    .picker-card:hover,.picker-card:focus { outline:0; background:#dbeafe; box-shadow:inset 3px 0 #168dcc; }
    .picker-card > span { min-width:0; padding:6px 9px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; border-right:1px solid #d1d5db; }
    .picker-code { color:#005f99; font-weight:700; font-variant-numeric:tabular-nums; }
    .picker-copy { min-width:0; }
    .picker-copy strong { font-size:11.5px; font-weight:500; }
    .picker-unit,.picker-barcode { color:#374151; }
    .picker-price { text-align:right; color:#111; font-weight:700; font-variant-numeric:tabular-nums; }
    .picker-state { padding:70px 20px; text-align:center; color:#718096; font-size:13px; }
    .picker-state i { margin-right:5px; }
    .product-picker-foot { padding:5px 10px; display:flex; align-items:center; justify-content:space-between; background:#eee; border-top:1px solid #aeb4ba; color:#374151; font-size:10.5px; }
    .product-picker-foot button { min-width:82px; height:28px; border:1px solid #999; padding:3px 12px; background:linear-gradient(#fff,#dedede); color:#111; font-size:11px; }
    @media (max-width: 760px) {
        .product-picker-backdrop { padding:10px; }
        .product-picker { height:calc(100vh - 20px); grid-template-rows:42px 28px 56px 31px 50px minmax(0,1fr) 40px; }
        .product-picker-foot span { display:none; }
        .product-picker-foot { justify-content:flex-end; }
    }
</style>
@endpush

@push('scripts')
<script>
    function stockIssuePage() {
        return {
            modalOpen: false,
            locationId: '',
            items: [{ product_id: '', sku_code: '', name_th: '', unit_name: '', barcode: '', qty: 1, onHandQty: '-' }],
            pickerOpen: false,
            pickerIndex: null,
            pickerQuery: '',
            pickerResults: [],
            pickerLoading: false,

            money(value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

            addItem() {
                this.items.push({ product_id: '', sku_code: '', name_th: '', unit_name: '', barcode: '', qty: 1, onHandQty: '-' });
                this.openProductPicker(this.items.length - 1);
            },

            openProductPicker(index) {
                this.pickerIndex = index;
                this.pickerQuery = '';
                this.pickerResults = [];
                this.pickerOpen = true;
                this.$nextTick(() => {
                    this.$refs.productPickerSearch?.focus();
                    this.searchPickerProducts();
                });
            },

            closeProductPicker() {
                this.pickerOpen = false;
                this.pickerIndex = null;
                this.pickerQuery = '';
                this.pickerResults = [];
            },

            async searchPickerProducts() {
                if (!this.pickerOpen) return;
                const query = this.pickerQuery.trim();
                this.pickerLoading = true;
                try {
                    const response = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(query)}`);
                    this.pickerResults = response.ok ? await response.json() : [];
                } finally {
                    this.pickerLoading = false;
                }
            },

            selectFirstPickerProduct() {
                const product = this.pickerResults[0];
                if (product) this.selectPickerProduct(product);
            },

            async selectPickerProduct(product) {
                const index = this.pickerIndex;
                if (index === null || !this.items[index]) return;
                this.items[index].product_id = product.id;
                this.items[index].sku_code = product.sku_code || '';
                this.items[index].name_th = product.name_th || '';
                this.items[index].unit_name = product.unit_name || 'ไม่ระบุหน่วย';
                this.items[index].barcode = product.barcode || '';
                this.closeProductPicker();
                if (this.locationId) {
                    const response = await fetch(`{{ route('search.stock-balance') }}?product_id=${product.id}&warehouse_location_id=${this.locationId}`);
                    const data = await response.json();
                    this.items[index].onHandQty = Number(data.on_hand_qty || 0).toLocaleString('th-TH', { minimumFractionDigits: 2 });
                }
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
