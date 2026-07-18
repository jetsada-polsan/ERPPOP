@extends('layout')
@section('title', $priceTable->name . ' - ตารางราคา - POPSTAR ERP')
@section('page-title', 'ตารางราคา: ' . $priceTable->name)
@section('page-subtitle', 'แก้ไขราคาสินค้าและกำหนดสาขาที่ใช้ตารางนี้')

@section('content')
<div x-data="priceTableShow()" x-cloak>
    <a href="{{ route('price-tables.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการตาราง
    </a>

    <div class="row g-4 mb-4">
        {{-- Table info card --}}
        <div class="col-lg-4">
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3">ข้อมูลตาราง</h3>
                <dl class="small mb-3">
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">รหัส</dt><dd class="mb-0 fw-bold">{{ $priceTable->code }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">สถานะ</dt><dd class="mb-0"><span class="badge {{ $priceTable->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $priceTable->is_active ? 'ใช้งาน' : 'ปิด' }}</span></dd></div>
                    @if($priceTable->is_default)<div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">ค่าเริ่มต้น</dt><dd class="mb-0"><span class="badge text-bg-warning">ตารางเริ่มต้น</span></dd></div>@endif
                    @if($priceTable->description)<div class="mt-2 text-muted" style="font-size:12px">{{ $priceTable->description }}</div>@endif
                </dl>
                <hr>
                <h3 class="h6 fw-bold mb-3">สาขาที่ใช้ตารางนี้</h3>
                @forelse($branches as $branch)
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-primary">{{ $branch->code }}</span>
                        <span class="small">{{ $branch->name_th }}</span>
                    </div>
                @empty
                    <p class="text-muted small">ยังไม่มีสาขาที่ใช้ตารางนี้</p>
                @endforelse
                <form method="post" action="{{ route('price-tables.assign-branch', $priceTable) }}" class="mt-3">
                    @csrf
                    <div class="d-flex gap-2">
                        <select name="branch_id" required class="form-select form-select-sm">
                            <option value="">-- กำหนดสาขา --</option>
                            @foreach(\App\Models\Branch::orderBy('code')->get() as $b)
                                <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-success px-3">กำหนด</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Add product to price table --}}
        <div class="col-lg-8">
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3">เพิ่ม / แก้ไขราคาสินค้า</h3>
                <div class="row g-3 align-items-end mb-0">
                    <div class="col-md-5 position-relative">
                        <label class="form-label text-muted small">ค้นหาสินค้า</label>
                        <input type="text" x-model="newProductQuery" @input.debounce.300ms="searchNewProduct()"
                            placeholder="รหัส / ชื่อสินค้า" class="form-control" autocomplete="off">
                        <div x-show="newResults.length" style="position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);padding:6px;max-height:240px;overflow-y:auto">
                            <template x-for="p in newResults" :key="p.id">
                                <div @click="selectNewProduct(p)" style="padding:8px 12px;cursor:pointer;border-radius:7px;font-size:13px" @mouseenter="$el.style.background='#f1f5f9'" @mouseleave="$el.style.background=''">
                                    <span class="fw-semibold" x-text="p.sku_code"></span> — <span x-text="p.name_th"></span>
                                    <span class="text-muted ms-2" x-text="'฿' + Number(p.default_price).toFixed(2)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">หน่วยนับ</label>
                        <select x-model="newUnitId" class="form-select">
                            <option value="">-- ทั่วไป --</option>
                            @foreach($units as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">ราคาขาย</label>
                        <input type="number" step="0.01" min="0" x-model.number="newPrice" class="form-control text-end" placeholder="0.00">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">ทุน</label>
                        <input type="number" step="0.01" min="0" x-model.number="newCost" class="form-control text-end" placeholder="0.00">
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-success w-100" @click="savePrice()" :disabled="!newProductId || newPrice < 0">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </div>
                </div>
                <div x-show="newProductName" class="mt-2">
                    <span class="badge text-bg-info" x-text="newProductName"></span>
                </div>
                <div x-show="saveMsg" class="mt-2 text-success small" x-text="saveMsg"></div>
            </div>
        </div>
    </div>

    {{-- Price list --}}
    <div class="content-card p-4">
        <div class="list-toolbar mb-3">
            <div class="list-toolbar-left">
                <h3 class="h6 fw-bold mb-0">รายการราคาสินค้าในตารางนี้</h3>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อสินค้า'])
            </div>
            <span class="text-muted small">{{ $productPrices->total() }} รายการ</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th class="text-end">ทุน</th><th class="text-end">ราคาขาย</th><th class="text-end">GP%</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($productPrices as $pp)
                    <tr>
                        <td class="fw-semibold">{{ $pp->product->sku_code }}</td>
                        <td>{{ $pp->product->name_th }}</td>
                        <td class="text-muted">{{ $pp->unit?->displayLabel() ?? 'ทั่วไป' }}</td>
                        <td class="text-end">{{ $pp->cost_price > 0 ? number_format($pp->cost_price, 2) : '-' }}</td>
                        <td class="text-end fw-bold text-success">{{ number_format($pp->price, 2) }}</td>
                        <td class="text-end text-muted small">
                            @if($pp->cost_price > 0 && $pp->price > 0)
                                {{ number_format((($pp->price - $pp->cost_price) / $pp->price) * 100, 1) }}%
                            @else -
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light border"
                                @click="editExisting({{ $pp->product_id }}, '{{ addslashes($pp->product->sku_code) }}', '{{ addslashes($pp->product->name_th) }}', {{ $pp->unit_id ?? 'null' }}, {{ $pp->price }}, {{ $pp->cost_price }})">
                                แก้ไข
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-5 text-center text-muted">ยังไม่มีราคาในตารางนี้ — เพิ่มสินค้าด้านบน</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $productPrices->links() }}</div>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush

@push('scripts')
<script>
function priceTableShow() {
    return {
        newProductQuery: '', newProductId: null, newProductName: '',
        newUnitId: '', newPrice: 0, newCost: 0,
        newResults: [], saveMsg: '',

        async searchNewProduct() {
            if (!this.newProductQuery) { this.newResults = []; this.newProductId = null; this.newProductName = ''; return; }
            const r = await fetch(`{{ route('price-tables.search-products', $priceTable) }}?q=${encodeURIComponent(this.newProductQuery)}`);
            this.newResults = await r.json();
        },

        selectNewProduct(p) {
            this.newProductId = p.id;
            this.newProductName = p.sku_code + ' ' + p.name_th;
            this.newProductQuery = p.sku_code + ' - ' + p.name_th;
            this.newPrice = Number(p.default_price) || 0;
            this.newResults = [];
        },

        editExisting(productId, sku, name, unitId, price, cost) {
            this.newProductId = productId;
            this.newProductName = sku + ' ' + name;
            this.newProductQuery = sku + ' - ' + name;
            this.newUnitId = unitId ? String(unitId) : '';
            this.newPrice = Number(price);
            this.newCost = Number(cost);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        async savePrice() {
            if (!this.newProductId) return;
            const res = await fetch(`{{ route('price-tables.prices.upsert', $priceTable) }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    product_id: this.newProductId,
                    unit_id: this.newUnitId || null,
                    price: this.newPrice,
                    cost_price: this.newCost,
                }),
            });
            const data = await res.json();
            if (data.success) {
                this.saveMsg = 'บันทึกแล้ว ✓';
                setTimeout(() => { this.saveMsg = ''; location.reload(); }, 800);
            }
        },
    };
}
</script>
@endpush
