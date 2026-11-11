@extends('layout')

@section('title', 'สินค้า - MPM Food Group')
@section('page-title', 'สินค้า / บริการ')
@section('page-subtitle', 'ทะเบียนสินค้า หน่วยนับ บาร์โค้ด และราคาเริ่มต้น')

@section('content')
    <div x-data="productPage()" x-cloak class="product-page">
        <div class="product-toolbar mb-3">
            <form method="get" class="product-filter-form" x-ref="filterForm">
                <div class="product-filter-bar">
                    <div class="product-filter-field search-field">
                        <label>ค้นหาสินค้า</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="รหัส ชื่อ หรือบาร์โค้ด" autocomplete="off" data-scan aria-describedby="product-search-hint" @input.debounce.1200ms="$refs.filterForm.requestSubmit()" @keydown.enter.prevent="$refs.filterForm.requestSubmit()">
                            <button type="submit" class="btn btn-primary fw-semibold text-nowrap" aria-label="ค้นหาสินค้า">ค้นหา</button>
                        </div>
                    </div>
                    <div class="product-filter-field">
                        <label>ประเภทสินค้า</label>
                        <select name="category_id" class="form-select" @change="$refs.filterForm.requestSubmit()">
                            <option value="">ทุกหมวดสินค้า</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name_th }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="product-filter-field">
                        <label>รูปแบบการขาย</label>
                        <select name="product_type" class="form-select" @change="$refs.filterForm.requestSubmit()">
                            <option value="">สินค้าทั้งหมด</option>
                            <option value="scale" @selected($productType === 'scale')>สินค้าชั่งน้ำหนัก</option>
                        </select>
                    </div>
                    <div class="product-filter-field">
                        <label>สถานะสินค้า</label>
                        <select name="status" class="form-select" @change="$refs.filterForm.requestSubmit()">
                            <option value="all" @selected($status === 'all')>ทุกสถานะ</option>
                            <option value="active" @selected($status === 'active')>ใช้งาน</option>
                            <option value="inactive" @selected($status === 'inactive')>ไม่ใช้งาน / พักไว้</option>
                        </select>
                    </div>
                    @if($q !== '' || $categoryId || $productType)
                        <div class="product-filter-clear">
                            <a href="{{ route('products.index') }}" class="btn btn-light border text-nowrap">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>ล้าง
                            </a>
                        </div>
                    @endif
                </div>
                <small id="product-search-hint" class="product-search-hint">พิมพ์รหัส ชื่อสินค้า หรือยิงบาร์โค้ด แล้วรอ 1 วินาที ระบบจะค้นหาให้อัตโนมัติ หรือกดปุ่มค้นหา</small>
            </form>
            <div class="product-actions">
                <a href="{{ route('products.scheduled-prices.index') }}" class="btn btn-light border px-3">
                    <i class="bi bi-clock-history me-1"></i> นัดเปลี่ยนราคา
                </a>
                <a href="{{ route('product-units.index') }}" class="btn btn-light border px-3">
                    <i class="bi bi-rulers me-1"></i> จัดการหน่วยนับ
                </a>
                <button type="button" class="btn btn-primary px-3" @click="modalOpen = true">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มสินค้า
                </button>
            </div>
        </div>

        <div class="row g-3 mb-3">
            @foreach([['key' => 'all', 'label' => 'สินค้าทั้งหมด', 'tone' => 'primary', 'icon' => 'bi-boxes'], ['key' => 'active', 'label' => 'กำลังใช้งาน', 'tone' => 'success', 'icon' => 'bi-box-seam'], ['key' => 'inactive', 'label' => 'ไม่ใช้งาน / พักไว้', 'tone' => 'secondary', 'icon' => 'bi-box']] as $summary)
                <div class="col-md-4">
                    <a href="{{ route('products.index', array_filter(['q' => $q, 'category_id' => $categoryId, 'product_type' => $productType, 'status' => $summary['key'] === 'all' ? null : $summary['key']])) }}" class="status-summary {{ $status === $summary['key'] ? 'is-selected' : '' }}">
                        <span class="status-summary-icon text-{{ $summary['tone'] }}"><i class="bi {{ $summary['icon'] }}"></i></span>
                        <span>
                            <small>{{ $summary['label'] }}</small>
                            <strong class="product-num">{{ number_format($counts[$summary['key']]) }}</strong>
                        </span>
                    </a>
                </div>
            @endforeach
        </div>

        <div class="scale-plu-summary mb-3">
            <div class="scale-plu-icon"><i class="bi bi-upc-scan"></i></div>
            <div><span>PLU สินค้าชั่งล่าสุด</span><strong class="product-num">{{ $maxScalePlu }}</strong></div>
            <i class="bi bi-arrow-right text-muted"></i>
            <div><span>เลขถัดไปที่ระบบจะใช้</span><strong class="text-success product-num">{{ $nextScalePlu }}</strong></div>
            <a href="{{ route('scale-prices.index') }}" class="btn btn-outline-primary ms-auto"><i class="bi bi-speedometer2 me-1"></i>ทะเบียนสินค้าเครื่องชั่ง</a>
        </div>

        <div class="content-card product-table-card">
            <div class="table-responsive">
                <table class="table align-middle product-table mb-0">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>บาร์โค้ด / หน่วย</th><th>ชื่อสินค้า</th><th>หมวด</th><th>ยี่ห้อ</th>
                            <th>หน่วยหลัก</th><th class="text-end">ราคาเริ่มต้น (บาท)</th><th class="text-center">สถานะ</th><th class="text-end">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td class="fw-bold product-sku">{{ $product->sku_code }}</td>
                                <td class="product-barcode-cell">
                                    @forelse($product->barcodes as $barcode)
                                        <div class="barcode-line">
                                            <span class="barcode-chip {{ $barcode->is_active ? '' : 'is-inactive' }}" title="{{ $barcode->is_active ? 'บาร์โค้ดใช้งาน' : 'บาร์โค้ดปิดใช้งาน' }}">{{ $barcode->barcode }}</span>
                                            <span class="barcode-unit">{{ $barcode->unit?->name ?? $product->baseUnit?->name ?? '-' }}</span>
                                        </div>
                                    @empty
                                        <span class="text-muted">-</span>
                                    @endforelse
                                </td>
                                <td class="product-name-cell">
                                    <div class="fw-semibold">{{ $product->name_th }}</div>
                                    @if(!empty($product->name_en))
                                        <div class="text-muted product-subname">{{ $product->name_en }}</div>
                                    @endif
                                </td>
                                <td>{{ $product->category?->name_th ?? '-' }}</td>
                                <td>{{ $product->brand?->name_th ?? '-' }}</td>
                                <td>{{ $product->baseUnit?->displayLabel() ?? '-' }}</td>
                                <td class="text-end fw-bold product-num">{{ number_format($product->default_price ?? 0, 2) }}</td>
                                <td class="text-center">
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
                                <td colspan="9" class="py-5 text-center text-muted">ไม่พบสินค้า</td>
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
            <div class="booking-modal" style="width: min(760px, 100%);" @click.outside="modalOpen = false" x-transition>
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
                                <label class="form-label text-muted">ประเภทสินค้า <span class="text-danger">*</span></label>
                                <select name="product_category_id" required class="form-select">
                                    <option value="">-- เลือกประเภทเพื่อรันรหัสอัตโนมัติ --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->code }} - {{ $category->name_th }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">ระบบจะรันรหัสตามประเภท เช่น 101001, 101002 โดยไม่ต้องกรอกเอง</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">หน่วยหลัก</label>
                                <select name="base_unit_id" required class="form-select">
                                    @foreach($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->displayLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted">ชื่อสินค้า (ไทย)</label>
                                <input type="text" name="name_th" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted">ชื่อสินค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted">หมายเหตุสินค้า / ข้อมูลช่วยจำ</label>
                                <textarea name="note" rows="2" maxlength="2000" class="form-control" placeholder="เช่น วิธีเก็บรักษา รุ่น สี หรือเงื่อนไขที่พนักงานควรทราบ"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">ขายเมื่อสต๊อกไม่พอ</label>
                                <select name="negative_stock_policy" class="form-select" required>
                                    <option value="allow">เตือนแล้วอนุญาต</option>
                                    <option value="block">ห้ามขายเกินสต๊อก</option>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label text-muted">จุดสั่งซื้อ</label><input type="number" step="0.0001" min="0" name="reorder_point" class="form-control text-end"></div>
                            <div class="col-md-3"><label class="form-label text-muted">สต๊อกขั้นต่ำ</label><input type="number" step="0.0001" min="0" name="minimum_stock" class="form-control text-end"></div>
                            <div class="col-md-3"><label class="form-label text-muted">สต๊อกสูงสุด</label><input type="number" step="0.0001" min="0" name="maximum_stock" class="form-control text-end"></div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">แผนก</label>
                                <select name="product_department_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}">{{ $department->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">ยี่ห้อ</label>
                                <select name="product_brand_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">ราคาเริ่มต้น (บาท)</label>
                                <input type="number" step="0.01" min="0" name="default_price" class="form-control text-end">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_vat" value="1" checked class="form-check-input" id="newProductVat">
                                    <label class="form-check-label" for="newProductVat">คิด VAT</label>
                                </div>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="tracks_expiry" value="1" class="form-check-input" id="newProductExpiry">
                                    <label class="form-check-label" for="newProductExpiry">ควบคุม Lot และวันหมดอายุ</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-muted">ราคาขายสูงสุด/หน่วยฐาน</label>
                                <input type="number" step="0.00000001" min="0" name="maximum_sale_price" class="form-control text-end">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-muted">กำไรขั้นต่ำ (%)</label>
                                <input type="number" step="0.00000001" max="100" name="minimum_margin_percent" class="form-control text-end">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-muted">ควบคุมกำไร</label>
                                <select name="margin_control_policy" class="form-select"><option value="warn">แจ้งเตือน</option><option value="block">บังคับขออนุมัติ</option></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">เตือนก่อนหมดอายุ (วัน)</label>
                                <input type="number" min="0" max="3650" name="expiry_warning_days" value="30" class="form-control text-end">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">อายุสินค้านับจากวันผลิต (วัน)</label>
                                <input type="number" min="1" max="36500" name="shelf_life_days" class="form-control text-end">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">เริ่มระบายก่อนหมดอายุ (วัน)</label>
                                <input type="number" min="0" max="3650" name="clearance_warning_days" value="7" class="form-control text-end">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">ส่วนลดระบายแนะนำ (%)</label>
                                <input type="number" step="0.01" min="0" max="100" name="clearance_discount_percent" value="0" class="form-control text-end">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted">เมื่อ Lot หมดอายุ</label>
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
    .product-page { --product-border: var(--erp-border); --product-ink: var(--erp-text); font-size: 15px; }
    .product-num { font-variant-numeric: tabular-nums; }

    /* แถบค้นหาและตัวกรองสินค้า */
    .product-toolbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 16px;
        border: 1px solid var(--product-border);
        border-radius: 14px;
        background: rgba(255, 255, 255, .95);
        box-shadow: 0 4px 14px rgba(15, 51, 74, .05);
    }
    .product-filter-form {
        flex: 1 1 auto;
        min-width: 0;
    }
    .product-filter-bar {
        display: grid;
        grid-template-columns: minmax(210px, 1.7fr) repeat(3, minmax(115px, 1fr)) auto;
        gap: 10px;
        align-items: end;
    }
    .product-filter-field {
        display: grid;
        gap: 5px;
        min-width: 0;
    }
    .product-filter-field label {
        margin: 0;
        color: var(--erp-muted);
        font-size: 14px;
        line-height: 1.25;
        font-weight: 700;
    }
    .product-filter-field .form-control,
    .product-filter-field .form-select,
    .product-filter-field .input-group-text,
    .product-filter-bar .btn,
    .product-actions .btn {
        min-height: 42px;
        height: 42px;
        border-color: var(--erp-border);
        font-size: 15px;
        border-radius: 9px;
    }
    .search-field .input-group {
        flex-wrap: nowrap;
    }
    .search-field .input-group .input-group-text {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    .search-field .input-group .form-control {
        min-width: 0;
        border-radius: 0;
    }
    .search-field .input-group .btn {
        flex: 0 0 auto;
        padding: 0 16px;
        margin-left: 0;
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
        font-weight: 700;
    }
    .product-filter-field .form-select {
        max-width: none !important;
    }
    .product-filter-clear {
        display: flex;
        align-items: flex-end;
    }
    .product-filter-clear .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        font-weight: 700;
    }
    .product-search-hint {
        display: block;
        margin-top: 6px;
        color: var(--erp-muted);
        font-size: 14px;
    }
    .product-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex: 0 0 auto;
        padding-top: 23px;
    }
    .product-actions .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        white-space: nowrap;
    }

    /* การ์ดสรุปจำนวนตามสถานะ */
    .status-summary {
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 78px;
        padding: 14px 18px;
        background: #fff;
        border: 1px solid var(--erp-border);
        border-radius: 14px;
        color: inherit;
        text-decoration: none;
        transition: .15s ease;
    }
    .status-summary:hover,
    .status-summary.is-selected {
        border-color: #60a5fa;
        box-shadow: 0 6px 18px rgba(30, 64, 175, .10);
        transform: translateY(-1px);
    }
    .status-summary-icon {
        width: 44px;
        height: 44px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        background: var(--erp-surface-2);
        font-size: 21px;
        flex-shrink: 0;
    }
    .status-summary small {
        display: block;
        color: var(--erp-muted);
        font-size: 14px;
        font-weight: 600;
    }
    .status-summary strong {
        display: block;
        color: var(--erp-text);
        font-size: 24px;
        line-height: 1.15;
    }

    /* แถบสรุป PLU เครื่องชั่ง */
    .scale-plu-summary {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 16px;
        border: 1px solid var(--erp-border);
        border-radius: 12px;
        background: linear-gradient(100deg, var(--erp-surface-2), #fff 72%);
        box-shadow: 0 2px 8px rgba(30, 64, 175, .04);
    }
    .scale-plu-summary > div:not(.scale-plu-icon) {
        display: grid;
        gap: 2px;
    }
    .scale-plu-summary span {
        color: var(--erp-muted);
        font-size: 14px;
        font-weight: 700;
    }
    .scale-plu-summary strong {
        color: var(--product-ink);
        font-size: 18px;
        line-height: 1.15;
        letter-spacing: .03em;
    }
    .scale-plu-icon {
        width: 40px;
        height: 40px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: #fff;
        background: linear-gradient(145deg, var(--erp-primary-ink), var(--erp-primary-dark));
        font-size: 18px;
        box-shadow: 0 3px 8px rgba(22, 143, 202, .18);
        flex-shrink: 0;
    }
    .scale-plu-summary .btn {
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        border-radius: 9px;
        font-size: 14px;
        padding: 6px 14px;
        font-weight: 700;
    }

    /* ตารางรายการสินค้า */
    .product-table-card {
        overflow: hidden;
        padding: 0;
        border: 1px solid var(--product-border);
        border-radius: 14px;
        box-shadow: 0 5px 18px rgba(15, 51, 74, .055);
    }
    .product-table {
        font-size: 15px;
    }
    .product-table thead th {
        padding: 12px 14px;
        border: 0;
        background: var(--erp-primary-soft);
        color: var(--erp-primary-dark);
        font-size: 14px;
        font-weight: 800;
        letter-spacing: .01em;
        white-space: nowrap;
    }
    .product-table tbody td {
        padding: 11px 14px;
        border-color: var(--erp-surface-2);
        color: var(--erp-text);
        vertical-align: middle;
        line-height: 1.35;
        font-size: 15px;
    }
    .product-table tbody tr {
        transition: background-color .15s ease;
    }
    .product-table tbody tr:hover {
        background: var(--erp-surface-2);
    }
    .product-sku {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 15px;
        white-space: nowrap;
    }
    .product-name-cell {
        min-width: 200px;
    }
    .product-subname {
        font-size: 14px;
    }
    .product-barcode-cell {
        min-width: 240px;
    }
    .barcode-line {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 2px 0;
    }
    .barcode-chip {
        display: inline-block;
        padding: 4px 9px;
        border: 1px solid #cbdcf5;
        border-radius: 6px;
        background: #f3f7ff;
        color: #1e40af;
        font: 600 14px/1.25 ui-monospace, SFMono-Regular, Menlo, monospace;
        white-space: nowrap;
    }
    .barcode-chip.is-inactive {
        border-color: #e5e7eb;
        background: #f3f4f6;
        color: #6b7280;
        text-decoration: line-through;
    }
    .barcode-unit {
        color: var(--erp-muted);
        font-size: 14px;
        white-space: nowrap;
    }
    .product-table .badge {
        border-radius: 6px;
        padding: 5px 10px;
        font-size: 14px;
        font-weight: 700;
    }
    .product-table tbody .btn-primary {
        min-width: 82px;
        min-height: 38px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 700;
        padding: 6px 12px;
        box-shadow: none;
    }
    .product-pagination {
        padding: 14px 16px 6px;
        border-top: 1px solid var(--erp-surface-2);
        font-size: 14px;
    }

    /* หน้าต่างแก้ไขสินค้า (Popup) และเพิ่มสินค้า (Modal) */
    .booking-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, .45);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .booking-modal {
        width: min(1120px, 100%);
        max-height: calc(100vh - 40px);
        overflow: auto;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
    .booking-modal .form-label,
    .booking-modal .form-text,
    .booking-modal .form-control,
    .booking-modal .form-select,
    .booking-modal .form-check-label,
    .booking-modal .btn {
        font-size: 14px;
    }
    .product-popup-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1950;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, .48);
    }
    .product-popup-window {
        width: min(1020px, calc(100vw - 32px));
        height: min(740px, calc(100vh - 32px));
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid var(--erp-border);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 20px 60px rgba(15, 23, 42, .30);
    }
    .product-popup-titlebar {
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 12px 0 16px;
        color: #111827;
        background: #f8fafc;
        border-bottom: 1px solid var(--erp-border);
        font: 700 15px Tahoma, "Noto Sans Thai", sans-serif;
    }
    .product-popup-titlebar button {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 8px;
        color: #374151;
        background: transparent;
    }
    .product-popup-titlebar button:hover {
        color: #b91c1c;
        background: #fee2e2;
    }
    .product-popup-window iframe {
        width: 100%;
        height: 100%;
        flex: 1 1 auto;
        border: 0;
        background: #eef5f9;
    }

    /* รองรับหน้าจอ Tablet และ Mobile */
    @media (max-width: 1200px) {
        .product-toolbar {
            flex-direction: column;
            align-items: stretch;
        }
        .product-filter-bar {
            grid-template-columns: 1fr 1fr;
        }
        .product-filter-field.search-field {
            grid-column: 1 / -1;
        }
        .product-actions {
            padding-top: 0;
            justify-content: flex-end;
        }
    }
    @media (max-width: 768px) {
        .product-toolbar {
            padding: 12px;
        }
        .product-filter-bar {
            grid-template-columns: 1fr;
        }
        .product-filter-clear .btn {
            width: 100%;
        }
        .product-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        .scale-plu-summary {
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .scale-plu-summary .btn {
            width: 100%;
            justify-content: center;
            margin-left: 0 !important;
        }
        .product-popup-backdrop {
            padding: 0;
        }
        .product-popup-window {
            width: 100vw;
            height: 100vh;
            border: 0;
            border-radius: 0;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    function productPage() {
        return {
            modalOpen: false,
            productPopupOpen: false,
            productUrl: '',
            hasUpdatedProduct: false,
            init() {
                window.addEventListener('message', (event) => {
                    if (event.data === 'erp:product:saved') {
                        this.hasUpdatedProduct = true;
                    }
                });
            },
            openProduct(url) {
                this.hasUpdatedProduct = false;
                this.productUrl = url;
                this.productPopupOpen = true;
                document.body.style.overflow = 'hidden';
            },
            closeProduct() {
                this.productPopupOpen = false;
                this.productUrl = '';
                document.body.style.overflow = '';
                if (this.hasUpdatedProduct) {
                    window.location.reload();
                }
            }
        };
    }
</script>
@endpush
