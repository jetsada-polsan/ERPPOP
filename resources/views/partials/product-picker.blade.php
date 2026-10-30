{{--
    Product picker ที่ใช้ร่วมกันทุกหน้าที่ต้องเลือกสินค้าลงตาราง.

    วางไว้ "นอก" ตารางและนอก modal ของหน้า เพราะ dropdown แบบ absolute ที่อยู่ใน
    <td> จะถูก overflow ของ .table-responsive / .booking-modal บังจนมองไม่เห็น.

    วิธีใช้ในหน้าที่เรียก:
      1. @include('partials.product-picker')  (ไว้ท้าย ๆ ใน x-data เดียวกัน)
      2. x-data ของหน้าต้อง spread mixin: { ...erpProductPicker(), items: [...] }
      3. แต่ละ item ต้องมี key: product_id, sku_code, name_th, unit_name, barcode
      4. ปุ่มเปิด picker: @click="openProductPicker(index)"
      5. ถ้าต้องทำอะไรต่อหลังเลือกสินค้า ให้ประกาศ onProductPicked(index, product) ในหน้า
--}}

{{-- ปิดเมื่อคลิกพื้นที่มืดรอบนอก (@click.self) แทน @click.outside ซึ่งบางจังหวะ
     จับคลิกเดียวกับที่ใช้เปิด picker แล้วปิดทันที --}}
<div class="product-picker-backdrop" x-show="pickerOpen" x-transition.opacity
    @click.self="closeProductPicker()"
    @keydown.escape.window="closeProductPicker()" style="display:none">
    <div class="product-picker" role="dialog" aria-label="เลือกสินค้า" x-transition>
        <div class="product-picker-head">
            <span class="product-picker-icon"><i class="bi bi-box-seam"></i></span>
            <div>
                <h3>เลือกสินค้า</h3>
                <small>ค้นหาด้วยรหัส ชื่อ หรือยิงบาร์โค้ด</small>
            </div>
            <button type="button" class="product-picker-close" @click="closeProductPicker()" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="product-picker-search">
            <i class="bi bi-search"></i>
            <input x-ref="productPickerSearch" type="text" x-model="pickerQuery"
                @input.debounce.220ms="searchPickerProducts()"
                @keydown.enter.prevent="selectActivePickerProduct()"
                @keydown.arrow-down.prevent="movePickerActive(1)"
                @keydown.arrow-up.prevent="movePickerActive(-1)"
                @keydown.home.prevent="movePickerActive(-9999)"
                @keydown.end.prevent="movePickerActive(9999)"
                placeholder="พิมพ์รหัส ชื่อ หรือยิงบาร์โค้ด..." autocomplete="off">
            <button type="button" x-show="pickerQuery" @click="pickerQuery=''; searchPickerProducts(); $refs.productPickerSearch.focus()">ล้าง</button>
        </div>
        <div class="product-picker-body" x-ref="productPickerBody">
            <div class="picker-state" x-show="pickerLoading"><i class="bi bi-arrow-repeat"></i> กำลังค้นหา...</div>
            <div class="picker-state" x-show="!pickerLoading && pickerResults.length === 0">ไม่พบสินค้า ลองเปลี่ยนคำค้นหา</div>
            <div class="product-picker-grid" x-show="!pickerLoading && pickerResults.length">
                <div class="picker-table-head"><span>รหัสสินค้า</span><span>ชื่อสินค้า</span><span>หน่วยนับ</span><span>บาร์โค้ด</span><span>ราคา</span></div>
                <template x-for="(product, pickerRow) in pickerResults" :key="product.id">
                    <button type="button" class="picker-card" :class="{ 'is-active': pickerRow === pickerActiveIndex }"
                        @click="selectPickerProduct(product)" @mouseenter="pickerActiveIndex = pickerRow">
                        <span class="picker-code" x-text="product.sku_code"></span>
                        <span class="picker-copy"><strong x-text="product.name_th"></strong></span>
                        <span class="picker-unit" x-text="product.unit_name"></span>
                        <span class="picker-barcode" x-text="product.barcode || '-' "></span>
                        <span class="picker-price" x-text="'฿' + money(product.default_price)"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="product-picker-foot">
            <span><b x-text="pickerResults.length"></b> รายการ <span class="picker-keys"><kbd>↑</kbd><kbd>↓</kbd> เลื่อน <kbd>Enter</kbd> เลือก <kbd>Esc</kbd> ปิด</span></span>
            <button type="button" class="btn btn-light border btn-sm px-3" @click="closeProductPicker()">ยกเลิก</button>
        </div>
    </div>
</div>

@once
@push('head')
<style>
    /* ปุ่มสินค้าที่เลือกแล้ว (ใช้แทน input typeahead ในตาราง) */
    .selected-product { width:100%; min-height:48px; border:1px solid var(--erp-border,#dbe7ef); border-radius:10px; background:var(--erp-soft,#f7fbfe); padding:6px 10px; text-align:left; color:var(--erp-text,var(--erp-ink,#1d3b52)); transition:.15s ease; }
    .selected-product:hover { border-color:var(--erp-primary,#1a9bdc); background:var(--erp-primary-soft,#eef7fd); box-shadow:0 0 0 3px rgba(26,155,220,.10); }
    .selected-product.empty { border-style:dashed; color:var(--erp-primary-ink,var(--erp-primary,#1585c0)); background:var(--erp-surface,#fff); }
    .selected-empty { display:flex; align-items:center; justify-content:center; gap:8px; font-weight:600; font-size:13px; }
    .selected-product-grid { display:grid; grid-template-columns:72px minmax(0,1fr) 42px; gap:10px; align-items:center; }
    .selected-code { color:var(--erp-primary-ink,var(--erp-primary,#1585c0)); font-size:12px; font-variant-numeric:tabular-nums; }
    .selected-copy { display:grid; min-width:0; gap:1px; }
    .selected-copy strong,.selected-copy small { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .selected-copy strong { font-size:13px; }
    .selected-copy small { color:var(--erp-muted,#6b8294); font-size:11px; }
    .selected-change { color:var(--erp-primary-ink,var(--erp-primary,#1585c0)); font-size:11px; font-weight:600; text-align:right; }

    /* หน้าต่างเลือกสินค้า - ใช้ตัวแปรธีมเดียวกับหน้าอื่นของ PopCentral */
    .product-picker-backdrop { position:fixed; inset:0; z-index:2200; padding:24px; display:flex; align-items:center; justify-content:center; background:rgba(15,23,42,.45); backdrop-filter:blur(2px); }
    .product-picker { width:min(920px,100%); height:min(640px,calc(100vh - 48px)); display:flex; flex-direction:column; overflow:hidden; background:var(--erp-surface,#fff); border:1px solid var(--erp-border,#dbe7ef); border-radius:16px; box-shadow:0 24px 70px rgba(15,23,42,.28); font-family:var(--erp-font-family,inherit); color:var(--erp-text,var(--erp-ink,#1d3b52)); }
    .product-picker-head { display:flex; align-items:center; gap:12px; padding:18px 20px 14px; border-bottom:1px solid var(--erp-border,#dbe7ef); }
    .product-picker-icon { width:40px; height:40px; flex:none; display:grid; place-items:center; border-radius:10px; background:var(--erp-primary-soft,#eef7fd); color:var(--erp-primary,#1a9bdc); font-size:18px; }
    .product-picker-head h3 { margin:0; font-size:17px; font-weight:700; color:var(--erp-ink,#1d3b52); }
    .product-picker-head small { color:var(--erp-muted,#6b8294); font-size:12.5px; }
    .product-picker-close { margin-left:auto; width:36px; height:36px; border:0; border-radius:50%; background:var(--erp-soft,#f7fbfe); color:var(--erp-muted,#6b8294); }
    .product-picker-close:hover { background:var(--erp-primary-soft,#eef7fd); color:var(--erp-primary,#1a9bdc); }
    .product-picker-search { position:relative; display:flex; align-items:center; gap:8px; margin:14px 20px 10px; }
    .product-picker-search > i { position:absolute; left:14px; color:var(--erp-muted,#6b8294); pointer-events:none; }
    .product-picker-search input { flex:1; min-width:0; height:44px; padding:0 14px 0 40px; border:1px solid var(--erp-border,#dbe7ef); border-radius:10px; background:var(--erp-surface,#fff); font-size:15px; color:inherit; outline:0; }
    .product-picker-search input:focus { border-color:var(--erp-primary,#1a9bdc); box-shadow:0 0 0 3px rgba(26,155,220,.15); }
    .product-picker-search button { height:44px; padding:0 14px; border:1px solid var(--erp-border,#dbe7ef); border-radius:10px; background:var(--erp-soft,#f7fbfe); color:var(--erp-muted,#6b8294); font-size:13px; }
    .product-picker-body { flex:1; min-height:0; overflow:auto; margin:0 20px; border:1px solid var(--erp-border,#dbe7ef); border-radius:12px; }
    .product-picker-grid { display:block; min-width:640px; }
    .picker-table-head,.picker-card { display:grid; grid-template-columns:110px minmax(220px,1fr) 100px 150px 100px; align-items:center; }
    .picker-table-head { position:sticky; top:0; z-index:1; background:var(--erp-soft,#f7fbfe); border-bottom:1px solid var(--erp-border,#dbe7ef); color:var(--erp-muted,#6b8294); font-size:12px; font-weight:600; }
    .picker-table-head span { padding:10px 12px; }
    .picker-card { width:100%; padding:0; text-align:left; color:inherit; background:var(--erp-surface,#fff); border:0; border-bottom:1px solid var(--erp-border,#dbe7ef); font-size:13.5px; transition:background .1s ease; }
    .picker-card:last-child { border-bottom:0; }
    .picker-card:hover,.picker-card:focus,.picker-card.is-active { outline:0; background:var(--erp-primary-soft,#eef7fd); box-shadow:inset 3px 0 var(--erp-primary,#1a9bdc); }
    .picker-card > span { min-width:0; padding:10px 12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .picker-code { color:var(--erp-primary-ink,var(--erp-primary,#1585c0)); font-weight:600; font-variant-numeric:tabular-nums; }
    .picker-copy { min-width:0; }
    .picker-copy strong { font-weight:500; }
    .picker-unit,.picker-barcode { color:var(--erp-muted,#6b8294); }
    .picker-price { text-align:right; font-weight:600; font-variant-numeric:tabular-nums; }
    .picker-state { padding:70px 20px; text-align:center; color:var(--erp-muted,#6b8294); font-size:14px; }
    .picker-state i { margin-right:5px; }
    .product-picker-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 20px 16px; color:var(--erp-muted,#6b8294); font-size:12.5px; }
    .product-picker-foot b { color:var(--erp-ink,#1d3b52); }
    .picker-keys { margin-left:10px; }
    .picker-keys kbd { padding:1px 6px; margin:0 2px; border:1px solid var(--erp-border,#dbe7ef); border-radius:5px; background:var(--erp-soft,#f7fbfe); color:var(--erp-ink,#1d3b52); font-size:11px; font-family:inherit; box-shadow:none; }
    @media (max-width: 760px) {
        .product-picker-backdrop { padding:10px; }
        .product-picker { height:calc(100vh - 20px); }
        .picker-keys { display:none; }
    }
</style>
@endpush

@push('scripts')
<script>
    // Mixin ของ product picker - spread เข้า x-data ของหน้าที่เรียกใช้ partial นี้.
    window.erpProductPicker = function (config) {
        const searchUrl = (config && config.searchUrl) || @json(route('search.products'));

        return {
            pickerOpen: false,
            pickerIndex: null,
            pickerQuery: '',
            pickerResults: [],
            pickerLoading: false,
            pickerActiveIndex: 0,
            pickerContext: null,

            money(value) {
                return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            openProductPicker(index, context = null) {
                this.pickerIndex = index;
                this.pickerContext = context;
                this.pickerQuery = '';
                this.pickerResults = [];
                this.pickerActiveIndex = 0;
                this.pickerOpen = true;
                this.$nextTick(() => {
                    this.$refs.productPickerSearch?.focus();
                    this.searchPickerProducts();
                });
            },

            closeProductPicker() {
                this.pickerOpen = false;
                this.pickerIndex = null;
                this.pickerContext = null;
                this.pickerQuery = '';
                this.pickerResults = [];
                this.pickerActiveIndex = 0;
            },

            async searchPickerProducts() {
                if (! this.pickerOpen) return;
                this.pickerLoading = true;
                try {
                    const params = new URLSearchParams({ q: this.pickerQuery.trim() });
                    if (typeof this.pickerSearchParams === 'function') this.pickerSearchParams(params);
                    const response = await fetch(`${searchUrl}?${params.toString()}`);
                    const products = response.ok ? await response.json() : [];
                    this.pickerResults = typeof this.filterPickerProduct === 'function'
                        ? products.filter(product => this.filterPickerProduct(product))
                        : products;
                } catch (error) {
                    this.pickerResults = [];
                } finally {
                    this.pickerLoading = false;
                    this.pickerActiveIndex = 0;
                }
            },

            // เลื่อนแถวที่โฟกัสด้วยคีย์บอร์ด (ค่า delta มาก ๆ = ไปหัว/ท้ายรายการ)
            movePickerActive(delta) {
                if (! this.pickerResults.length) return;
                const last = this.pickerResults.length - 1;
                this.pickerActiveIndex = Math.max(0, Math.min(last, this.pickerActiveIndex + delta));
                this.$nextTick(() => {
                    this.$refs.productPickerBody?.querySelector('.picker-card.is-active')
                        ?.scrollIntoView({ block: 'nearest' });
                });
            },

            selectActivePickerProduct() {
                const product = this.pickerResults[this.pickerActiveIndex];
                if (product) this.selectPickerProduct(product);
            },

            selectPickerProduct(product) {
                const index = this.pickerIndex;
                const context = this.pickerContext;
                if (index === null) return;
                const item = typeof this.getPickerItem === 'function'
                    ? this.getPickerItem(index, context)
                    : this.items?.[index];
                if (!item) return;

                item.product_id = product.id;
                item.sku_code = product.sku_code || '';
                item.name_th = product.name_th || '';
                item.unit_name = product.unit_name || 'ไม่ระบุหน่วย';
                item.barcode = product.barcode || '';

                this.closeProductPicker();

                if (typeof this.onProductPicked === 'function') {
                    this.onProductPicked(index, product, context);
                }
            },
        };
    };
</script>
@endpush
@endonce
