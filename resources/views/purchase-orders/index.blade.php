@extends('layout')
@section('title', 'ใบขอซื้อ / ใบสั่งซื้อ - POPSTAR ERP')
@section('page-title', 'ใบขอซื้อ / ใบสั่งซื้อ')
@section('page-subtitle', 'ขอซื้อ → อนุมัติ → สั่งซื้อ → รับของ (สร้างใบซื้อจริงตัดสต๊อก+ตั้งหนี้อัตโนมัติ)')
@section('content')
<div x-data="poPage()" x-cloak>

    {{-- การ์ดสถานะ --}}
    <div class="row g-2 mb-3">
        @foreach(['requested' => ['ขอซื้อ (รออนุมัติ)', 'text-bg-warning'], 'approved' => ['อนุมัติแล้ว', 'text-bg-info'], 'ordered' => ['สั่งซื้อแล้ว', 'text-bg-primary'], 'received' => ['รับของแล้ว', 'text-bg-success']] as $st => $meta)
            <div class="col-6 col-lg-3">
                <a href="{{ route('purchase-orders.index', ['status' => $st]) }}" class="text-decoration-none">
                    <div class="content-card p-3 {{ $status === $st ? 'border border-primary' : '' }}">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge {{ $meta[1] }}">{{ $meta[0] }}</span>
                            <span class="fw-bold fs-5">{{ number_format($statusCounts[$st] ?? 0) }}</span>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2">
            @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" style="width:240px" placeholder="เลขที่ / ซัพพลายเออร์">
            <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
            @if($status || $q)<a href="{{ route('purchase-orders.index') }}" class="btn btn-sm btn-light border">ล้าง</a>@endif
        </form>
        <button type="button" class="btn btn-success ms-auto" @click="modalOpen = true"><i class="bi bi-plus-lg me-1"></i>สร้างใบขอซื้อ</button>
    </div>

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>ซัพพลายเออร์</th><th>ผู้ขอ</th><th class="text-end">รายการ</th><th class="text-end">ยอดรวม</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td class="fw-semibold" style="color:#0284c7">{{ $order->doc_number }}</td>
                        <td class="text-nowrap">{{ $order->doc_date->thaiDate() }}</td>
                        <td>{{ $order->supplier?->name_th ?? '-' }}</td>
                        <td class="small">{{ $order->requester?->name ?? '-' }}</td>
                        <td class="text-end">{{ $order->items_count }}</td>
                        <td class="text-end fw-semibold">{{ $order->total_amount > 0 ? number_format($order->total_amount, 2) : '-' }}</td>
                        <td>
                            <span class="badge {{ ['requested' => 'text-bg-warning', 'approved' => 'text-bg-info', 'ordered' => 'text-bg-primary', 'received' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$order->status] ?? 'text-bg-light' }}">
                                {{ $order->statusLabel() }}
                            </span>
                        </td>
                        <td class="text-end"><a href="{{ route('purchase-orders.show', $order) }}" class="btn btn-sm btn-light border">ดู</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีใบขอซื้อ</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $orders->links() }}</div>
    </div>

    {{-- Create modal --}}
    <div class="po-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
        <div class="po-modal" @click.outside="modalOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <div>
                    <h3 class="h5 fw-bold mb-0">สร้างใบขอซื้อ</h3>
                    <div class="text-muted small">ระบุสินค้าที่ต้องการซื้อ — เลือกซัพพลายเออร์/ราคาทีหลังตอนสั่งซื้อได้</div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('purchase-orders.store') }}" @submit="onSubmit">
                @csrf
                <div class="px-4 pb-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small text-muted">สาขา</label>
                            <select name="branch_id" required class="form-select">
                                @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">ซัพพลายเออร์ (ไม่บังคับ)</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">-- ระบุทีหลัง --</option>
                                @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">ต้องการภายใน</label>
                            <input type="date" name="need_by_date" class="form-control">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 class="h6 fw-bold mb-0">รายการสินค้า</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addItem()"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
                    </div>
                    <div class="table-responsive border rounded-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th style="min-width:280px">สินค้า</th><th class="text-end" style="width:120px">จำนวน</th><th class="text-end" style="width:130px">ราคา (ถ้ามี)</th><th style="width:44px"></th></tr></thead>
                            <tbody>
                                <template x-for="(item, idx) in items" :key="idx">
                                    <tr>
                                        <td class="position-relative">
                                            <input type="text" x-model="item.q" @input.debounce.300ms="search(idx)" placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control form-control-sm" autocomplete="off">
                                            <input type="hidden" :name="`items[${idx}][product_id]`" x-model="item.product_id">
                                            <div x-show="item.results.length" style="position:absolute;z-index:50;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.12);max-height:200px;overflow:auto">
                                                <template x-for="p in item.results" :key="p.id">
                                                    <div @click="pick(idx, p)" style="padding:7px 10px;cursor:pointer;font-size:13px" @mouseenter="$el.style.background='#f1f5f9'" @mouseleave="$el.style.background=''">
                                                        <span class="fw-semibold" x-text="p.sku_code"></span> <span x-text="p.name_th"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][qty]`" x-model.number="item.qty" required class="form-control form-control-sm text-end"></td>
                                        <td><input type="number" step="0.01" min="0" :name="`items[${idx}][unit_price]`" x-model.number="item.unit_price" class="form-control form-control-sm text-end" placeholder="0.00"></td>
                                        <td><button type="button" class="btn btn-sm btn-light text-danger" @click="items.splice(idx,1)" x-show="items.length > 1"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <input name="note" class="form-control mt-3" placeholder="หมายเหตุ">
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-success px-5"><i class="bi bi-check2-circle me-1"></i>สร้างใบขอซื้อ</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .po-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .po-modal { width: min(820px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
</style>
@endpush

@push('scripts')
<script>
function poPage() {
    return {
        modalOpen: false,
        items: [{ q: '', product_id: '', qty: 1, unit_price: '', results: [] }],
        addItem() { this.items.push({ q: '', product_id: '', qty: 1, unit_price: '', results: [] }); },
        async search(idx) {
            if (this.items[idx].q.length < 1) { this.items[idx].results = []; this.items[idx].product_id = ''; return; }
            const res = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(this.items[idx].q)}`);
            this.items[idx].results = await res.json();
        },
        pick(idx, p) {
            this.items[idx].product_id = p.id;
            this.items[idx].q = `${p.sku_code} - ${p.name_th}`;
            this.items[idx].results = [];
        },
        onSubmit(e) {
            if (this.items.some(i => !i.product_id)) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกแถว' }); }
        },
    };
}
</script>
@endpush
