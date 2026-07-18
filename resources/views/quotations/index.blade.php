@extends('layout')
@section('title', 'ใบเสนอราคา - POPSTAR ERP')
@section('page-title', 'ใบเสนอราคา')
@section('page-subtitle', 'เสนอราคาสินค้าให้ลูกค้า พิมพ์ได้ และแปลงเป็นใบจองเมื่อลูกค้าตอบรับ')
@section('content')
<div x-data="quotationPage()" x-cloak>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" style="width:170px" onchange="this.form.submit()">
                <option value="">ทุกสถานะ</option>
                <option value="open" @selected($status === 'open')>รอลูกค้าตอบรับ</option>
                <option value="accepted" @selected($status === 'accepted')>ตอบรับแล้ว</option>
                <option value="expired" @selected($status === 'expired')>หมดอายุ</option>
                <option value="cancelled" @selected($status === 'cancelled')>ยกเลิก</option>
            </select>
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" style="width:240px" placeholder="เลขที่ / ลูกค้า">
            <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </form>
        <button type="button" class="btn btn-success ms-auto" @click="modalOpen = true"><i class="bi bi-plus-lg me-1"></i>สร้างใบเสนอราคา</button>
    </div>

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>ลูกค้า</th><th>ยืนราคาถึง</th><th class="text-end">รายการ</th><th class="text-end">ยอดรวม</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                @forelse($quotations as $qt)
                    <tr>
                        <td class="fw-semibold" style="color:#0284c7">{{ $qt->doc_number }}</td>
                        <td class="text-nowrap">{{ $qt->doc_date->thaiDate() }}</td>
                        <td>{{ $qt->customerLabel() }}</td>
                        <td class="text-nowrap small {{ $qt->status === 'open' && $qt->valid_until && $qt->valid_until->isPast() ? 'text-danger' : '' }}">{{ $qt->valid_until?->thaiDate() ?? '-' }}</td>
                        <td class="text-end">{{ $qt->items_count }}</td>
                        <td class="text-end fw-semibold">{{ number_format($qt->total_amount, 2) }}</td>
                        <td><span class="badge {{ ['open' => 'text-bg-warning', 'accepted' => 'text-bg-success', 'expired' => 'text-bg-secondary', 'cancelled' => 'text-bg-dark'][$qt->status] ?? 'text-bg-light' }}">{{ $qt->statusLabel() }}</span></td>
                        <td class="text-end"><a href="{{ route('quotations.show', $qt) }}" class="btn btn-sm btn-light border">ดู / พิมพ์</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีใบเสนอราคา</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $quotations->links() }}</div>
    </div>

    {{-- Create modal --}}
    <div class="qt-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
        <div class="qt-modal" @click.outside="modalOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <h3 class="h5 fw-bold mb-0">สร้างใบเสนอราคา</h3>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('quotations.store') }}" @submit="onSubmit">
                @csrf
                <div class="px-4 pb-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">สาขา</label>
                            <select name="branch_id" required class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach</select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted">ลูกค้า (เลือกจากทะเบียน)</label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- ไม่ระบุ / พิมพ์ชื่อด้านล่าง --</option>
                                @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">ยืนราคาถึง</label>
                            <input type="date" name="valid_until" class="form-control" value="{{ now()->addDays(15)->toDateString() }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">หรือชื่อลูกค้า (กรณียังไม่ลงทะเบียน)</label>
                            <input name="customer_name" class="form-control" placeholder="เช่น ร้านต้มยำ ก.ไก่">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">พนักงานขาย</label>
                            <select name="salesman_id" class="form-select"><option value="">-- ไม่ระบุ --</option>@foreach($salesmen as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h4 class="h6 fw-bold mb-0">รายการสินค้า</h4>
                        <button type="button" class="btn btn-sm btn-light border" @click="addItem()"><i class="bi bi-plus-lg me-1"></i>เพิ่มรายการ</button>
                    </div>
                    <div class="table-responsive border rounded-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th style="min-width:260px">สินค้า</th><th class="text-end" style="width:110px">จำนวน</th><th class="text-end" style="width:130px">ราคา/หน่วย</th><th class="text-end" style="width:110px">รวม</th><th style="width:40px"></th></tr></thead>
                            <tbody>
                                <template x-for="(item, idx) in items" :key="idx">
                                    <tr>
                                        <td class="position-relative">
                                            <input type="text" x-model="item.q" @input.debounce.300ms="search(idx)" placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control form-control-sm" autocomplete="off">
                                            <input type="hidden" :name="`items[${idx}][product_id]`" x-model="item.product_id">
                                            <div x-show="item.results.length" style="position:absolute;z-index:50;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.12);max-height:200px;overflow:auto">
                                                <template x-for="p in item.results" :key="p.id">
                                                    <div @click="pick(idx, p)" style="padding:7px 10px;cursor:pointer;font-size:13px" @mouseenter="$el.style.background='#f1f5f9'" @mouseleave="$el.style.background=''"><span class="fw-semibold" x-text="p.sku_code"></span> <span x-text="p.name_th"></span></div>
                                                </template>
                                            </div>
                                        </td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][qty]`" x-model.number="item.qty" required class="form-control form-control-sm text-end"></td>
                                        <td><input type="number" step="0.01" min="0" :name="`items[${idx}][unit_price]`" x-model.number="item.unit_price" required class="form-control form-control-sm text-end"></td>
                                        <td class="text-end fw-semibold" x-text="((item.qty||0)*(item.unit_price||0)).toLocaleString('th-TH',{minimumFractionDigits:2})"></td>
                                        <td><button type="button" class="btn btn-sm btn-light text-danger" @click="items.splice(idx,1)" x-show="items.length > 1"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot><tr class="fw-bold"><td colspan="3" class="text-end">รวมทั้งสิ้น</td><td class="text-end" x-text="grandTotal.toLocaleString('th-TH',{minimumFractionDigits:2})"></td><td></td></tr></tfoot>
                        </table>
                    </div>
                    <input name="note" class="form-control mt-3" placeholder="หมายเหตุ / เงื่อนไข">
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-success px-5"><i class="bi bi-check2-circle me-1"></i>สร้างใบเสนอราคา</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .qt-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .qt-modal { width: min(860px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
</style>
@endpush

@push('scripts')
<script>
function quotationPage() {
    return {
        modalOpen: false,
        items: [{ q: '', product_id: '', qty: 1, unit_price: 0, results: [] }],
        addItem() { this.items.push({ q: '', product_id: '', qty: 1, unit_price: 0, results: [] }); },
        get grandTotal() { return this.items.reduce((s, i) => s + (i.qty || 0) * (i.unit_price || 0), 0); },
        async search(idx) {
            if (this.items[idx].q.length < 1) { this.items[idx].results = []; this.items[idx].product_id = ''; return; }
            const res = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(this.items[idx].q)}`);
            this.items[idx].results = await res.json();
        },
        pick(idx, p) {
            this.items[idx].product_id = p.id;
            this.items[idx].q = `${p.sku_code} - ${p.name_th}`;
            this.items[idx].unit_price = Number(p.default_price) || 0;
            this.items[idx].results = [];
        },
        onSubmit(e) { if (this.items.some(i => !i.product_id)) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกแถว' }); } },
    };
}
</script>
@endpush
