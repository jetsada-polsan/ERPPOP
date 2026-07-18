@extends('layout')
@section('title', $flashSale->name . ' - ราคานาทีทอง - POPSTAR ERP')
@section('page-title', 'แคมเปญนาทีทอง: ' . $flashSale->name)
@section('page-subtitle', 'กำหนดสินค้าและราคาพิเศษสำหรับแคมเปญนี้')

@section('content')
<div x-data="flashSaleShow()" x-cloak>
    <a href="{{ route('flash-sales.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการแคมเปญ
    </a>

    <div class="row g-4 mb-4">
        {{-- Campaign settings (editable) --}}
        <div class="col-lg-4">
            <div class="content-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="h6 fw-bold mb-0">ตั้งค่าแคมเปญ</h3>
                    <span class="badge {{ $flashSale->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $flashSale->is_active ? 'ใช้งาน' : 'ปิด' }}</span>
                </div>
                @php($selectedDays = $flashSale->days_of_week ? explode(',', $flashSale->days_of_week) : [])
                <form method="post" action="{{ route('flash-sales.update', $flashSale) }}" class="row g-2">
                    @csrf @method('PUT')
                    <div class="col-4"><label class="form-label small text-muted mb-1">รหัส</label><input name="code" required class="form-control form-control-sm" value="{{ $flashSale->code }}"></div>
                    <div class="col-8"><label class="form-label small text-muted mb-1">ชื่อกลุ่มนาทีทอง</label><input name="name" required class="form-control form-control-sm" value="{{ $flashSale->name }}"></div>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-1">สาขา</label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">-- ทุกสาขา --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($flashSale->branch_id === $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label small text-muted mb-1">วันที่เริ่ม</label><input type="date" name="starts_date" required class="form-control form-control-sm" value="{{ $flashSale->starts_date?->format('Y-m-d') }}"></div>
                    <div class="col-6"><label class="form-label small text-muted mb-1">วันที่สิ้นสุด</label><input type="date" name="ends_date" class="form-control form-control-sm" value="{{ $flashSale->ends_date?->format('Y-m-d') }}"></div>
                    <div class="col-6"><label class="form-label small text-muted mb-1">เวลาเริ่ม</label><input type="time" name="start_time" class="form-control form-control-sm" value="{{ $flashSale->start_time?->format('H:i') }}"></div>
                    <div class="col-6"><label class="form-label small text-muted mb-1">เวลาสิ้นสุด</label><input type="time" name="end_time" class="form-control form-control-sm" value="{{ $flashSale->end_time?->format('H:i') }}"></div>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-1 d-block">วันที่ใช้ (ไม่เลือก = ทุกวัน)</label>
                        @foreach(['0'=>'อา','1'=>'จ','2'=>'อ','3'=>'พ','4'=>'พฤ','5'=>'ศ','6'=>'ส'] as $val => $label)
                            <div class="form-check form-check-inline me-2">
                                <input type="checkbox" name="days_of_week[]" value="{{ $val }}" class="form-check-input" id="edow{{ $val }}" @checked(in_array($val, $selectedDays, true))>
                                <label class="form-check-label small" for="edow{{ $val }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-6 d-flex align-items-center">
                        <div class="form-check mb-0">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="editActive" @checked($flashSale->is_active)>
                            <label class="form-check-label small" for="editActive">เปิดใช้งาน</label>
                        </div>
                    </div>
                    <div class="col-6"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>บันทึก</button></div>
                    <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="หมายเหตุ" value="{{ $flashSale->note }}"></div>
                </form>
            </div>
        </div>

        {{-- Add product to flash sale --}}
        <div class="col-lg-8">
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3">เพิ่ม / แก้ไขราคานาทีทองของสินค้า</h3>
                <div class="row g-3 align-items-end mb-0">
                    <div class="col-md-5 position-relative">
                        <label class="form-label text-muted small">ค้นหาสินค้า</label>
                        <input type="text" x-model="newProductQuery" @input.debounce.300ms="searchNewProduct()"
                            placeholder="รหัส / ชื่อ / สแกนบาร์โค้ด" class="form-control" autocomplete="off">
                        <div x-show="newResults.length" style="position:absolute;z-index:100;left:0;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);padding:6px;max-height:240px;overflow-y:auto">
                            <template x-for="p in newResults" :key="p.id">
                                <div @click="selectNewProduct(p)" style="padding:8px 12px;cursor:pointer;border-radius:7px;font-size:13px" @mouseenter="$el.style.background='#f1f5f9'" @mouseleave="$el.style.background=''">
                                    <span class="fw-semibold" x-text="p.sku_code"></span> — <span x-text="p.name_th"></span>
                                    <span class="text-muted ms-2" x-text="'฿' + Number(p.default_price).toFixed(2)"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted small">ราคานาทีทอง</label>
                        <input type="number" step="0.01" min="0" x-model.number="newPrice" class="form-control text-end" placeholder="0.00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-muted small">จำกัดจำนวน/บิล (ไม่บังคับ)</label>
                        <input type="number" step="0.0001" min="0" x-model.number="newMaxQty" class="form-control text-end" placeholder="ไม่จำกัด">
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-success w-100" @click="saveItem()" :disabled="!newProductId || newPrice < 0">
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

    {{-- Item list --}}
    <div class="content-card p-4">
        <div class="list-toolbar mb-3">
            <div class="list-toolbar-left">
                <h3 class="h6 fw-bold mb-0">สินค้าในแคมเปญนี้</h3>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อสินค้า'])
            </div>
            <span class="text-muted small">{{ $items->total() }} รายการ</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วยนับ</th><th class="text-end">ราคาต่อหน่วย</th><th class="text-end">ส่วนลดต่อหน่วย</th><th class="text-end">สุทธิต่อหน่วย</th><th class="text-end">จำกัด/บิล</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                    @php($discount = (float) $item->product->default_price - (float) $item->flash_price)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td class="text-end text-muted">{{ number_format($item->product->default_price, 2) }}</td>
                        <td class="text-end {{ $discount > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">
                            @if($discount > 0)
                                {{ number_format($discount, 2) }}
                                <span class="text-muted small">({{ number_format($discount / max(0.01, (float) $item->product->default_price) * 100, 0) }}%)</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="text-end fw-bold text-danger">{{ number_format($item->flash_price, 2) }}</td>
                        <td class="text-end text-muted small">{{ $item->max_qty_per_bill ? number_format($item->max_qty_per_bill, 2) : 'ไม่จำกัด' }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light border"
                                @click="editExisting({{ $item->product_id }}, '{{ addslashes($item->product->sku_code) }}', '{{ addslashes($item->product->name_th) }}', {{ $item->flash_price }}, {{ $item->max_qty_per_bill ?? 'null' }})">
                                แก้ไข
                            </button>
                            <form method="post" action="{{ route('flash-sales.items.destroy', [$flashSale, $item]) }}" class="d-inline" onsubmit="return confirm('ลบสินค้านี้ออกจากแคมเปญ?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light border text-danger"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="py-5 text-center text-muted">ยังไม่มีสินค้าในกลุ่มนี้ — ค้นหาแล้วเพิ่มสินค้าด้านบน</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $items->links() }}</div>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush

@push('scripts')
<script>
function flashSaleShow() {
    return {
        newProductQuery: '', newProductId: null, newProductName: '',
        newPrice: 0, newMaxQty: null,
        newResults: [], saveMsg: '',

        async searchNewProduct() {
            if (!this.newProductQuery) { this.newResults = []; this.newProductId = null; this.newProductName = ''; return; }
            const r = await fetch(`{{ route('flash-sales.search-products', $flashSale) }}?q=${encodeURIComponent(this.newProductQuery)}`);
            this.newResults = await r.json();
        },

        selectNewProduct(p) {
            this.newProductId = p.id;
            this.newProductName = p.sku_code + ' ' + p.name_th;
            this.newProductQuery = p.sku_code + ' - ' + p.name_th;
            this.newPrice = Number(p.default_price) || 0;
            this.newResults = [];
        },

        editExisting(productId, sku, name, price, maxQty) {
            this.newProductId = productId;
            this.newProductName = sku + ' ' + name;
            this.newProductQuery = sku + ' - ' + name;
            this.newPrice = Number(price);
            this.newMaxQty = maxQty !== null ? Number(maxQty) : null;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        async saveItem() {
            if (!this.newProductId) return;
            const res = await fetch(`{{ route('flash-sales.items.upsert', $flashSale) }}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    product_id: this.newProductId,
                    flash_price: this.newPrice,
                    max_qty_per_bill: this.newMaxQty || null,
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
