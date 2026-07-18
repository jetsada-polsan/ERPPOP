@extends('layout')

@section('title', 'ขอโอนสินค้า - POPSTAR ERP')
@section('page-title', 'ขอโอนสินค้า')
@section('page-subtitle', 'ส่งคำขอโอนสินค้าเข้าสาขาของคุณ - รอผู้มีสิทธิ์อนุมัติจึงตัดสต๊อกจริง')

@section('content')
<div x-data="requestTransferPage()" x-cloak>

    @if(! $branch)
        <div class="content-card p-4 text-center text-muted">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-3 d-block mb-2"></i>
            บัญชีของคุณยังไม่ได้กำหนด <b>สาขาประจำ</b> จึงยังขอโอนสินค้าไม่ได้ — ติดต่อผู้ดูแลระบบให้ตั้งค่าที่หน้า "ผู้ใช้และสิทธิ์"
        </div>
    @else
        <div class="content-card p-4 mb-3">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-box-arrow-in-down" style="color:var(--fa-blue);font-size:1.2rem"></i>
                <h2 class="h5 fw-bold mb-0">สร้างคำขอโอนเข้าสาขา {{ $branch->name_th }}</h2>
            </div>
            <p class="text-muted small mb-3">ปลายทาง = คลังของสาขาคุณ (ระบบตั้งให้อัตโนมัติ) — เลือกคลังต้นทางที่จะขอของ แล้วเพิ่มรายการสินค้า</p>

            <form method="post" action="{{ route('stock-transfers.request.store') }}" @submit="onSubmit">
                @csrf
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted">คลังต้นทาง (ขอของจาก)</label>
                        <select name="from_warehouse_location_id" x-model="fromLocationId" @change="onFromLocationChange()" required class="form-select">
                            <option value="">-- เลือกคลังต้นทาง --</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->code }} - {{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">หมายเหตุ</label>
                        <input type="text" name="remark" class="form-control" placeholder="เช่น ของหน้าร้านใกล้หมด">
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h4 class="h6 fw-bold mb-0">รายการสินค้าที่ขอ</h4>
                    <button type="button" class="btn btn-sm btn-light border" @click="addItem()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มรายการ
                    </button>
                </div>

                <div class="table-responsive tr-items-table">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="min-width:320px;">สินค้า</th>
                                <th class="text-end" style="width:150px;">คงเหลือต้นทาง</th>
                                <th class="text-end" style="width:150px;">จำนวนที่ขอ</th>
                                <th style="width:48px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in items" :key="index">
                                <tr>
                                    <td class="position-relative">
                                        <input type="text" x-model="item.productQuery" @input.debounce.300ms="searchProducts(index)"
                                            placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control" autocomplete="off">
                                        <input type="hidden" :name="`items[${index}][product_id]`" x-model="item.product_id">
                                        <div class="typeahead-list" x-show="item.results.length" x-transition>
                                            <template x-for="product in item.results" :key="product.id">
                                                <button type="button" class="typeahead-item" @click="selectProduct(index, product)">
                                                    <span class="fw-semibold" x-text="product.sku_code"></span>
                                                    <span x-text="product.name_th"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="text-end" x-text="item.onHandQty"></td>
                                    <td>
                                        <input type="number" step="0.0001" min="0.0001" :name="`items[${index}][qty]`"
                                            x-model.number="item.qty" required class="form-control text-end">
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-light text-danger" @click="removeItem(index)" x-show="items.length > 1">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-send me-1"></i> ส่งคำขอ</button>
                </div>
            </form>
        </div>
    @endif

    <div class="content-card p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-clock-history me-2" style="color:var(--fa-blue)"></i>คำขอ/ใบโอนของสาขาคุณล่าสุด</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr><th>เลขที่</th><th>วันที่</th><th>ปลายทาง</th><th class="text-end">จำนวน</th><th>สถานะ</th></tr>
                </thead>
                <tbody>
                    @forelse($myRequests as $r)
                        <tr>
                            <td class="fw-semibold">{{ $r->doc_number }}</td>
                            <td>{{ $r->doc_date?->thaiDate() }}</td>
                            <td>{{ $r->stockDocument?->toWarehouseLocation?->name ?? '-' }}</td>
                            <td class="text-end">{{ number_format($r->stockDocument?->total_qty ?? 0, 2) }}</td>
                            <td>
                                @php($st = $r->status)
                                @if($st === 'pending')
                                    <span class="badge text-bg-warning">รออนุมัติ</span>
                                @elseif($st === 'active')
                                    <span class="badge text-bg-success">โอนแล้ว</span>
                                @elseif($st === 'rejected')
                                    <span class="badge text-bg-danger">ปฏิเสธ</span>
                                @else
                                    <span class="badge text-bg-secondary">{{ $st }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-5 text-center text-muted">ยังไม่มีคำขอโอน</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak]{display:none!important}
    .tr-items-table{border:1px solid #e7eaf2;border-radius:14px}
    .typeahead-list{position:absolute;z-index:2050;left:0;right:0;top:calc(100% + 4px);
        max-height:260px;overflow:auto;background:#fff;border:1px solid #dbe1ea;border-radius:12px;
        box-shadow:0 14px 36px rgba(15,23,42,.14);padding:6px}
    .typeahead-item{width:100%;border:0;background:transparent;border-radius:9px;padding:9px 10px;
        display:flex;align-items:center;gap:10px;text-align:left}
    .typeahead-item:hover{background:#f2f6ff}
</style>
@endpush

@push('scripts')
<script>
    function requestTransferPage() {
        return {
            fromLocationId: '',
            items: [{ product_id: '', productQuery: '', qty: 1, onHandQty: '-', results: [] }],
            addItem() { this.items.push({ product_id: '', productQuery: '', qty: 1, onHandQty: '-', results: [] }); },
            removeItem(index) { this.items.splice(index, 1); },
            onFromLocationChange() { this.items.forEach((item, index) => this.refreshOnHandQty(index)); },
            async searchProducts(index) {
                const query = this.items[index].productQuery;
                if (query.length < 1) { this.items[index].results = []; this.items[index].product_id = ''; return; }
                const response = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(query)}`);
                this.items[index].results = await response.json();
            },
            async selectProduct(index, product) {
                this.items[index].product_id = product.id;
                this.items[index].productQuery = `${product.sku_code} - ${product.name_th}`;
                this.items[index].results = [];
                await this.refreshOnHandQty(index);
            },
            async refreshOnHandQty(index) {
                const item = this.items[index];
                if (!item.product_id || !this.fromLocationId) { item.onHandQty = '-'; return; }
                const response = await fetch(`{{ route('search.stock-balance') }}?product_id=${item.product_id}&warehouse_location_id=${this.fromLocationId}`);
                const data = await response.json();
                item.onHandQty = Number(data.on_hand_qty || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
            onSubmit(event) {
                if (!this.fromLocationId) { event.preventDefault(); Swal.fire({ icon: 'warning', title: 'กรุณาเลือกคลังต้นทาง' }); return; }
                if (this.items.some(item => !item.product_id)) { event.preventDefault(); Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกแถว' }); return; }
            },
        };
    }
</script>
@endpush
