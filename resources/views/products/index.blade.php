@extends('layout')

@section('title', 'สินค้า - POPSTAR ERP')
@section('page-title', 'สินค้า / บริการ')
@section('page-subtitle', 'ทะเบียนสินค้า หน่วยนับ บาร์โค้ด และราคาเริ่มต้น')

@section('content')
    <div x-data="productPage()" x-cloak class="product-page">
        <div class="product-toolbar mb-3">
            <form method="get" class="product-filter-bar" x-ref="filterForm">
                <div class="product-filter-field search-field">
                    <label>ค้นหาสินค้า</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="รหัสหรือชื่อสินค้า" autocomplete="off" @input.debounce.450ms="$refs.filterForm.requestSubmit()">
                    </div>
                </div>
                <div class="product-filter-field">
                    <label>ประเภทสินค้า</label>
                <select name="category_id" class="form-select" style="max-width:240px" @change="$refs.filterForm.requestSubmit()">
                    <option value="">ทุกหมวดสินค้า</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name_th }}</option>
                    @endforeach
                </select>
                </div>
                <div class="product-filter-field" style="min-width:190px">
                    <label>รูปแบบการขาย</label>
                    <select name="product_type" class="form-select" @change="$refs.filterForm.requestSubmit()">
                        <option value="">สินค้าทั้งหมด</option>
                        <option value="scale" @selected($productType === 'scale')>สินค้าชั่งน้ำหนัก</option>
                    </select>
                </div>
                @if($q !== '' || $categoryId || $productType)<a href="{{ route('products.index') }}" class="btn btn-light border align-self-end">ล้าง</a>@endif
            </form>
            <div class="product-actions">
                <a href="{{ route('product-units.index') }}" class="btn btn-light border px-3">
                    <i class="bi bi-rulers me-1"></i> จัดการหน่วยนับ
                </a>
                <button type="button" class="btn btn-primary px-3" @click="modalOpen = true">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มสินค้า
                </button>
            </div>
        </div>

        <div class="scale-plu-summary mb-3">
            <div class="scale-plu-icon"><i class="bi bi-upc-scan"></i></div>
            <div><span>PLU สินค้าชั่งล่าสุด</span><strong>{{ $maxScalePlu }}</strong></div>
            <i class="bi bi-arrow-right text-muted"></i>
            <div><span>เลขถัดไปที่ระบบจะใช้</span><strong class="text-success">{{ $nextScalePlu }}</strong></div>
            <a href="{{ route('scale-prices.index') }}" class="btn btn-outline-primary ms-auto"><i class="bi bi-speedometer2 me-1"></i>ทะเบียนสินค้าเครื่องชั่ง</a>
        </div>

        <div class="content-card product-table-card">
            <div class="table-responsive">
                <table class="table align-middle product-table mb-0">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อสินค้า</th><th>หมวด</th><th>ยี่ห้อ</th>
                            <th>หน่วยหลัก</th><th class="text-end">ราคาเริ่มต้น</th><th>สถานะ</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td class="fw-semibold">{{ $product->sku_code }}</td>
                                <td>{{ $product->name_th }}</td>
                                <td>{{ $product->category?->name_th ?? '-' }}</td>
                                <td>{{ $product->brand?->name_th ?? '-' }}</td>
                                <td>{{ $product->baseUnit?->displayLabel() ?? '-' }}</td>
                                <td class="text-end">{{ number_format($product->default_price ?? 0, 2) }}</td>
                                <td>
                                    <span class="badge {{ $product->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $product->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-primary" @click.prevent="openProduct('{{ route('products.show', $product) }}?popup=1')"><i class="bi bi-pencil-square me-1"></i>แก้ไข</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-5 text-center text-muted">ไม่พบสินค้า</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="product-pagination">{{ $products->links() }}</div>
        </div>

        <div class="product-popup-backdrop" x-show="productPopupOpen" x-transition.opacity @keydown.escape.window="closeProduct()">
            <div class="product-popup-window" x-transition @click.outside="closeProduct()">
                <div class="product-popup-titlebar">
                    <div><i class="bi bi-box-seam me-2"></i>แฟ้มสินค้า</div>
                    <button type="button" @click="closeProduct()" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
                </div>
                <iframe x-show="productUrl" :src="productUrl" title="รายละเอียดสินค้า"></iframe>
            </div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width: min(720px, 100%);" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">เพิ่มสินค้าใหม่</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false" aria-label="Close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <form method="post" action="{{ route('products.store') }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสสินค้า (SKU)</label>
                                <input type="text" name="sku_code" required class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">หน่วยหลัก</label>
                                <select name="base_unit_id" required class="form-select">
                                    @foreach($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->displayLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อสินค้า (ไทย)</label>
                                <input type="text" name="name_th" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อสินค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">หมายเหตุสินค้า / ข้อมูลช่วยจำ</label>
                                <textarea name="note" rows="2" maxlength="2000" class="form-control" placeholder="เช่น วิธีเก็บรักษา รุ่น สี หรือเงื่อนไขที่พนักงานควรทราบ"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">ขายเมื่อสต๊อกไม่พอ</label>
                                <select name="negative_stock_policy" class="form-select" required>
                                    <option value="allow">เตือนแล้วอนุญาต</option>
                                    <option value="block">ห้ามขายเกินสต๊อก</option>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label text-muted small">จุดสั่งซื้อ</label><input type="number" step="0.0001" min="0" name="reorder_point" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label text-muted small">สต๊อกขั้นต่ำ</label><input type="number" step="0.0001" min="0" name="minimum_stock" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label text-muted small">สต๊อกสูงสุด</label><input type="number" step="0.0001" min="0" name="maximum_stock" class="form-control"></div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">หมวดหมู่</label>
                                <select name="product_category_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">แผนก</label>
                                <select name="product_department_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}">{{ $department->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">ยี่ห้อ</label>
                                <select name="product_brand_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">ราคาเริ่มต้น</label>
                                <input type="number" step="0.01" min="0" name="default_price" class="form-control">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_vat" value="1" checked class="form-check-input" id="newProductVat">
                                    <label class="form-check-label" for="newProductVat">คิด VAT</label>
                                </div>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="tracks_expiry" value="1" class="form-check-input" id="newProductExpiry">
                                    <label class="form-check-label" for="newProductExpiry">ควบคุม Lot และวันหมดอายุ</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">เตือนก่อนหมดอายุ (วัน)</label>
                                <input type="number" min="0" max="3650" name="expiry_warning_days" value="30" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">อายุสินค้านับจากวันผลิต (วัน)</label>
                                <input type="number" min="1" max="36500" name="shelf_life_days" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">เริ่มระบายก่อนหมดอายุ (วัน)</label>
                                <input type="number" min="0" max="3650" name="clearance_warning_days" value="7" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">ส่วนลดระบายแนะนำ (%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="clearance_discount_percent" value="0" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">เมื่อ Lot หมดอายุ</label>
                                <select name="expiry_sale_policy" class="form-select" required>
                                    <option value="block">ห้ามขาย/ห้ามใช้</option>
                                    <option value="allow">เตือนแต่อนุญาต</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-center">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="newProductActive">
                                    <label class="form-check-label" for="newProductActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check2-circle me-1"></i> บันทึกสินค้า
                        </button>
                    </div>
                </form>
            </div>
        </div>
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
        width: min(1120px, 100%); max-height: calc(100vh - 48px); overflow: auto;
        background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
    .product-popup-backdrop { position:fixed; inset:0; z-index:1950; display:flex; align-items:center; justify-content:center; padding:12px; background:rgba(15,23,42,.48); }
    .product-popup-window { width:min(760px,calc(100vw - 24px)); height:min(540px,calc(100vh - 24px)); display:flex; flex-direction:column; overflow:hidden; border:1px solid #777d83; border-radius:2px; background:#ececec; box-shadow:0 18px 52px rgba(15,23,42,.3); }
    .product-popup-titlebar { min-height:32px; display:flex; align-items:center; justify-content:space-between; padding:0 4px 0 9px; color:#111827; background:#f5f5f5; border-bottom:1px solid #9da2a7; font:700 12px Tahoma,"Noto Sans Thai",sans-serif; }
    .product-popup-titlebar button { width:28px; height:27px; display:grid; place-items:center; border:0; border-radius:0; color:#374151; background:transparent; }
    .product-popup-titlebar button:hover { color:#b91c1c; background:#fee2e2; }
    .product-popup-window iframe { width:100%; height:100%; flex:1 1 auto; border:0; background:#eef5f9; }
    .product-page { --product-border:#dce7ef; --product-ink:#17324d; }
    .product-toolbar { display:flex; align-items:flex-end; justify-content:space-between; gap:9px; padding:9px 10px; border:1px solid var(--product-border); border-radius:10px; background:rgba(255,255,255,.88); box-shadow:0 3px 10px rgba(15,51,74,.04); }
    .product-filter-bar { display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap; flex:1 1 auto; }
    .product-filter-field { display:grid; gap:2px; min-width:150px; }
    .product-filter-field.search-field { width:min(250px,100%); }
    .product-filter-field label { margin:0; color:#60788b; font-size:9px; line-height:1.1; font-weight:700; }
    .product-filter-field .form-control,.product-filter-field .form-select,.product-filter-field .input-group-text { min-height:32px; height:32px; border-color:#d7e2ea; font-size:11px; padding-top:3px; padding-bottom:3px; }
    .product-filter-field .form-select { max-width:none!important; }
    .product-filter-bar .btn,.product-actions .btn { min-height:32px; height:32px; border-radius:7px; font-size:11px; padding:4px 10px; font-weight:700; white-space:nowrap; }
    .product-actions { display:flex; gap:8px; align-items:center; flex:0 0 auto; }
    .scale-plu-summary { display:flex; align-items:center; gap:10px; padding:7px 10px; border:1px solid #cfe1ee; border-radius:9px; background:linear-gradient(100deg,#f4f9fd,#fff 72%); box-shadow:0 2px 8px rgba(30,64,175,.03); }
    .scale-plu-summary>div:not(.scale-plu-icon) { display:grid; gap:2px; }
    .scale-plu-summary span { color:#718397; font-size:10px; font-weight:700; }
    .scale-plu-summary strong { color:var(--product-ink); font-size:15px; line-height:1.05; letter-spacing:.035em; }
    .scale-plu-icon { width:31px; height:31px; display:grid; place-items:center; border-radius:7px; color:#fff; background:linear-gradient(145deg,#168fca,#0877ae); font-size:14px; box-shadow:0 3px 8px rgba(22,143,202,.15); }
    .scale-plu-summary .btn { min-height:30px; border-radius:7px; font-size:10px; padding:4px 9px; font-weight:700; }
    .product-table-card { overflow:hidden; padding:0; border:1px solid var(--product-border); border-radius:14px; box-shadow:0 5px 18px rgba(15,51,74,.055); }
    .product-table { font-size:11px; }
    .product-table thead th { padding:8px 10px; border:0; background:#eaf4fa; color:#31556d; font-size:9px; font-weight:800; letter-spacing:.01em; white-space:nowrap; }
    .product-table tbody td { padding:7px 10px; border-color:#e8eef3; color:#263f51; vertical-align:middle; line-height:1.2; }
    .product-table tbody tr { transition:background-color .15s ease; }
    .product-table tbody tr:hover { background:#f7fbfd; }
    .product-table .badge { border-radius:5px; padding:3px 6px; font-size:9px; font-weight:700; }
    .product-table tbody .btn-primary { min-width:58px; min-height:27px; border-radius:6px; font-size:9px; font-weight:700; padding:3px 7px; box-shadow:none; }
    .product-pagination { padding:12px 14px 4px; border-top:1px solid #edf2f5; }
    @media(max-width:1200px){.product-toolbar{align-items:stretch;flex-direction:column}.product-actions{justify-content:flex-end}.product-filter-field.search-field{width:min(280px,100%)}}
    @media(max-width:720px){.product-toolbar{padding:10px}.product-filter-bar,.product-filter-field,.product-filter-field.search-field{width:100%}.product-filter-field{min-width:0}.product-filter-bar .btn{flex:1}.product-actions{display:grid;grid-template-columns:1fr 1fr}.product-actions .btn{display:flex;align-items:center;justify-content:center}.product-table thead th,.product-table tbody td{padding:9px 10px}.product-popup-backdrop{padding:0}.product-popup-window{width:100vw;height:100vh;border:0;border-radius:0}}
    @media(max-width:720px){.scale-plu-summary{align-items:flex-start;flex-wrap:wrap}.scale-plu-summary .btn{width:100%;margin-left:0!important}}
</style>
@endpush

@push('scripts')
<script>
    function productPage() {
        return {
            modalOpen: false,
            productPopupOpen: false,
            productUrl: '',
            openProduct(url) {
                this.productUrl = url;
                this.productPopupOpen = true;
                document.body.style.overflow = 'hidden';
            },
            closeProduct() {
                this.productPopupOpen = false;
                this.productUrl = '';
                document.body.style.overflow = '';
                window.location.reload();
            }
        };
    }
</script>
@endpush
