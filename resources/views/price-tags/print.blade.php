@extends('layout')
@section('title', 'พิมพ์ป้ายราคา - POPSTAR ERP')
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
            <div class="col-md-6 position-relative">
                <label class="form-label small text-muted">ค้นหาสินค้า</label>
                <input type="text" x-model="query" @input.debounce.300ms="search()"
                    placeholder="รหัส / ชื่อสินค้า" class="form-control" autocomplete="off">
                <div x-show="results.length" style="position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);padding:6px;max-height:240px;overflow-y:auto">
                    <template x-for="p in results" :key="p.id">
                        <div @click="addProduct(p)" style="padding:8px 12px;cursor:pointer;border-radius:7px;font-size:13px" @mouseenter="$el.style.background='#f1f5f9'" @mouseleave="$el.style.background=''">
                            <span class="fw-semibold" x-text="p.sku_code"></span> — <span x-text="p.name_th"></span>
                        </div>
                    </template>
                </div>
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
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush

@push('scripts')
<script>
function priceTagPrint() {
    return {
        templateId: '',
        query: '', results: [],
        items: [],

        async search() {
            if (!this.query) { this.results = []; return; }
            const r = await fetch(`{{ route('price-tags.search-products') }}?q=${encodeURIComponent(this.query)}`);
            this.results = await r.json();
        },

        addProduct(p) {
            if (!this.items.find(i => i.product_id === p.id)) {
                this.items.push({ product_id: p.id, sku_code: p.sku_code, name_th: p.name_th, qty: 1 });
            }
            this.query = '';
            this.results = [];
        },

        generate() {
            this.$nextTick(() => this.$refs.printForm.submit());
        },
    };
}
</script>
@endpush
