@extends('layout')
@section('title', 'พิมพ์ป้ายราคา - PopCentral')
@section('page-title', 'พิมพ์ป้ายราคา')
@section('page-subtitle', 'เลือกรูปแบบป้าย เพิ่มสินค้า แล้วสร้างป้ายราคาสำหรับพิมพ์')
@section('content')
<div x-data="priceTagPrint()" x-cloak>
    <div class="content-card p-4 mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">รูปแบบป้ายราคา</label>
                <select x-model="templateId" class="form-select">
                    <option value="">-- เลือกรูปแบบ --</option>
                    @foreach($templates as $tpl)
                        <option value="{{ $tpl->id }}">{{ $tpl->code }} - {{ $tpl->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted">เพิ่มสินค้า</label>
                <button type="button" class="selected-product empty" @click="startProductPick()">
                    <span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกสินค้าเพื่อพิมพ์ป้าย</span>
                </button>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary w-100" :disabled="!templateId || items.length === 0" @click="generate()">
                    <i class="bi bi-printer-fill me-1"></i> สร้างป้าย
                </button>
            </div>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3">รายการสินค้าที่จะพิมพ์</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th style="width:120px" class="text-end">จำนวนป้าย</th><th></th></tr></thead>
                <tbody>
                <template x-for="(item, idx) in items" :key="item.product_id">
                        <tr>
                            <td class="fw-semibold" x-text="item.sku_code"></td>
                            <td x-text="item.name_th"></td>
                            <td class="text-end"><input type="number" min="1" max="200" x-model.number="item.qty" class="form-control form-control-sm text-end" style="width:90px;margin-left:auto"></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-light border text-danger" @click="items.splice(idx, 1)"><i class="bi bi-trash3"></i></button></td>
                        </tr>
                    </template>
                    <tr x-show="items.length === 0"><td colspan="4" class="text-center text-muted py-5">ยังไม่มีสินค้า — ค้นหาแล้วเลือกด้านบน</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <form x-ref="printForm" method="post" action="{{ route('price-tags.preview') }}" target="_blank">
        @csrf
        <input type="hidden" name="price_tag_template_id" x-bind:value="templateId">
        <template x-for="(item, idx) in items" :key="item.product_id">
            <span>
                <input type="hidden" :name="'items['+idx+'][product_id]'" :value="item.product_id">
                <input type="hidden" :name="'items['+idx+'][qty]'" :value="item.qty">
            </span>
        </template>
    </form>
@include('partials.product-picker')
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush

@push('scripts')
<script>
function priceTagPrint() {
    return {
        ...erpProductPicker(),
        templateId: '',
        items: [],
        productDraft: null,

        startProductPick() {
            this.productDraft = { product_id: '', sku_code: '', name_th: '', unit_name: '', barcode: '' };
            this.openProductPicker(0, 'add');
        },
        getPickerItem(index, context) {
            return context === 'add' ? this.productDraft : this.items[index];
        },
        onProductPicked(index, product, context) {
            if (context !== 'add') return;
            if (!this.items.some(item => String(item.product_id) === String(product.id))) {
                this.items.push({ ...this.productDraft, qty: 1 });
            }
            this.productDraft = null;
        },

        generate() {
            this.$nextTick(() => this.$refs.printForm.submit());
        },
    };
}
</script>
@endpush
