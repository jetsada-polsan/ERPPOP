@extends('layout')

@section('title', 'แปรรูปสินค้า - POPSTAR ERP')
@section('page-title', 'จัดเซ็ต / แปรรูปแบบชั่งจริง')
@section('page-subtitle', 'ตัดวัตถุดิบจริง รับน้ำหนักผลผลิตจริง และล็อกต้นทุนต่อกิโลกรัม')

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
            <h2 class="h5 fw-bold mb-0">Batch จัดเซ็ตและแปรรูป</h2>
            @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาเลขที่เอกสาร'])
        </div>
        <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
            <i class="bi bi-plus-lg me-1"></i> จัดเซ็ตแบบชั่งจริง
        </button>
    </div>

    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>สาขา</th><th>หมายเหตุ</th><th class="text-end">Yield</th><th class="text-end">ทุน/กก.</th><th class="text-end">มูลค่าวัตถุดิบ</th><th></th></tr></thead>
                <tbody>
                    @forelse($documents as $doc)
                        <tr>
                            <td class="fw-semibold">{{ $doc->doc_number }}</td>
                            <td>{{ $doc->doc_date->thaiDate() }}</td>
                            <td>{{ $doc->branch->name_th }}</td>
                            <td class="small text-muted">{{ $doc->remark ?? '-' }}</td>
                            <td class="text-end">{{ $doc->productionBatch ? number_format($doc->productionBatch->yield_percent,2).'%' : '-' }}</td>
                            <td class="text-end">{{ $doc->productionBatch ? number_format($doc->productionBatch->output_unit_cost,2) : '-' }}</td>
                            <td class="text-end">{{ number_format($doc->total_amount, 2) }}</td>
                            <td class="text-end"><a href="{{ route('stock-transforms.show', $doc) }}" class="btn btn-sm btn-light border">ดู</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-5 text-center text-muted">ยังไม่มีใบแปรรูปสินค้า</td></tr>
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
                    <h3 class="h4 fw-bold mb-1">จัดเซ็ตแบบชั่งจริง</h3>
                    <div class="text-muted small">กรอกน้ำหนักที่หยิบใช้และน้ำหนักสินค้าสำเร็จ ระบบใช้ต้นทุนเฉลี่ยและ Lot ให้อัตโนมัติ</div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>

            <form method="post" action="{{ route('stock-transforms.store') }}" @submit="onSubmit">
                @csrf
                <input type="hidden" name="batch_mode" value="1">
                <input type="hidden" name="production_recipe_id" x-model="selectedRecipeId">
                <div class="modal-body px-4 pb-4">
                    @if($recipes->isNotEmpty())
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <label class="form-label text-muted small">โหลดจากสูตร (ไม่บังคับ - จะเติมวัตถุดิบ+ผลผลิตให้อัตโนมัติ ปรับจำนวนได้ทีหลัง)</label>
                            <select class="form-select" x-model="selectedRecipeId" @change="loadRecipe(selectedRecipeId)">
                                <option value="">-- กรอกเองแบบเดิม --</option>
                                @foreach($recipes as $recipe)
                                    <option value="{{ $recipe['id'] }}">{{ $recipe['label'] }} (ได้ {{ number_format($recipe['output_qty'], 4) }} หน่วย/รอบ)</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @endif
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

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label text-muted small">น้ำหนักวัตถุดิบรวมจริง (กก.)</label>
                            <input type="number" step="0.0001" min="0.0001" name="input_weight_qty" x-model.number="inputWeight" class="form-control text-end" required>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <div class="alert alert-light border py-2 px-3 mb-0 w-100 small">ส่วนต่างระหว่างน้ำหนักเข้าและผลผลิตจะถูกบันทึกเป็น Yield/สูญเสีย และต้นทุนทั้งหมดจะอยู่ในผลผลิตจริง</div>
                        </div>
                    </div>

                    {{-- วัตถุดิบ --}}
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 fw-bold mb-0 text-danger"><i class="bi bi-box-arrow-up me-1"></i>วัตถุดิบ (ตัดออกจากสต๊อก)</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addRaw()"><i class="bi bi-plus-lg me-1"></i> เพิ่มวัตถุดิบ</button>
                    </div>
                    <div class="table-responsive booking-items-table mb-2">
                        <table class="table align-middle">
                            <thead><tr><th style="min-width:280px">สินค้า</th><th class="text-end" style="width:110px">ใช้จริง</th><th class="text-end" style="width:130px">ทุนเฉลี่ย</th><th class="text-end" style="width:120px">ต้นทุนรวม</th><th style="width:44px"></th></tr></thead>
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
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`raw_items[${index}][qty]`" x-model.number="item.qty" @input="syncInputWeight()" required class="form-control text-end"></td>
                                        <td class="text-end" x-text="money(item.average_cost)"></td>
                                        <td class="text-end fw-semibold" x-text="money(item.qty * item.average_cost)"></td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-light text-danger" @click="rawItems.splice(index, 1); syncInputWeight()" x-show="rawItems.length > 1"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-end mb-4 small">ต้นทุนวัตถุดิบรวม: <strong class="text-danger" x-text="money(rawTotal)"></strong> บาท</div>

                    {{-- ผลผลิต --}}
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="h6 fw-bold mb-0 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>ผลผลิต (รับเข้าสต๊อก)</h4>
                    </div>
                    <div class="table-responsive booking-items-table">
                        <table class="table align-middle">
                            <thead><tr><th style="min-width:280px">สินค้าชุดสำเร็จ</th><th class="text-end" style="width:150px">น้ำหนักชั่งได้ (กก.)</th><th class="text-end" style="width:150px">ต้นทุน/กก.</th><th class="text-end" style="width:130px">Yield</th></tr></thead>
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
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`output_items[${index}][qty]`" x-model.number="item.qty" @input="rescaleFromRecipe()" required class="form-control text-end"><input type="hidden" :name="`output_items[${index}][percent]`" value="100"></td>
                                        <td class="text-end fw-semibold text-success" x-text="outputUnitCost(item)"></td>
                                        <td class="text-end fw-semibold" x-text="yieldPercent(item)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> ตัดวัตถุดิบและรับชุดสำเร็จ</button>
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
            rawItems: [{ product_id: '', productQuery: '', qty: 1, average_cost: 0, results: [] }],
            outputItems: [{ product_id: '', productQuery: '', qty: 1, percent: null, results: [] }],
            inputWeight: 1,
            recipes: @json($recipes),
            selectedRecipeId: '',
            selectedRecipe: null,

            addRaw() { this.rawItems.push({ product_id: '', productQuery: '', qty: 1, average_cost: 0, results: [] }); this.syncInputWeight(); },
            syncInputWeight() { this.inputWeight = Math.round(this.rawItems.reduce((s, i) => s + (Number(i.qty) || 0), 0) * 10000) / 10000; },

            loadRecipe(recipeId) {
                if (!recipeId) { this.selectedRecipeId = ''; this.selectedRecipe = null; return; }
                const recipe = this.recipes.find(r => String(r.id) === String(recipeId));
                if (!recipe) return;
                this.selectedRecipeId = recipe.id;
                this.selectedRecipe = recipe;
                this.rawItems = recipe.items.map(i => ({
                    product_id: i.product_id, productQuery: i.label, qty: i.qty, average_cost: i.average_cost, results: [],
                }));
                this.outputItems = [{
                    product_id: recipe.finished_product_id, productQuery: recipe.finished_product_label,
                    qty: recipe.output_qty, percent: 100, results: [],
                }];
                this.syncInputWeight();
            },

            // ปรับสัดส่วนวัตถุดิบอัตโนมัติเมื่อแก้จำนวนผลผลิตที่ต้องการ (เทียบกับสูตรที่โหลดไว้)
            rescaleFromRecipe() {
                if (!this.selectedRecipe || !this.selectedRecipe.output_qty) return;
                const ratio = (Number(this.outputItems[0]?.qty) || 0) / this.selectedRecipe.output_qty;
                this.rawItems = this.selectedRecipe.items.map(i => ({
                    product_id: i.product_id, productQuery: i.label,
                    qty: Math.round(i.qty * ratio * 10000) / 10000, average_cost: i.average_cost, results: [],
                }));
                this.syncInputWeight();
            },

            get rawTotal() {
                return this.rawItems.reduce((s, i) => s + (Number(i.qty) || 0) * (Number(i.average_cost) || 0), 0);
            },

            outputUnitCost(item) {
                const qty = Number(item.qty) || 0;
                if (qty <= 0 || this.rawTotal <= 0) return '-';
                return this.money(this.rawTotal / qty);
            },

            yieldPercent(item) {
                const output = Number(item.qty) || 0;
                return this.inputWeight > 0 ? this.money(output * 100 / this.inputWeight) + '%' : '-';
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
                item.average_cost = Number(product.average_cost) || 0;
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
