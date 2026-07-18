@extends('layout')
@section('title', 'ใบวางบิล - POPSTAR ERP')
@section('page-title', 'ใบวางบิล')
@section('page-subtitle', 'รวบใบขายเชื่อค้างชำระของลูกค้าเป็นใบวางบิลสำหรับรอบเก็บเงิน')
@section('content')
<div x-data="billingNotePage()" x-cloak>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
                <option value="">ทุกสถานะ</option>
                <option value="open" @selected($status === 'open')>รอเก็บเงิน</option>
                <option value="collected" @selected($status === 'collected')>เก็บเงินแล้ว</option>
                <option value="cancelled" @selected($status === 'cancelled')>ยกเลิก</option>
            </select>
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" style="width:240px" placeholder="เลขที่ใบวางบิล / ลูกค้า">
            <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </form>
        <button type="button" class="btn btn-success ms-auto" @click="openCreate()">
            <i class="bi bi-plus-lg me-1"></i>สร้างใบวางบิล
        </button>
    </div>

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr><th>เลขที่</th><th>วันที่</th><th>ลูกค้า</th><th>ครบกำหนด</th><th class="text-end">รายการ</th><th class="text-end">ยอดวางบิล</th><th>สถานะ</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($notes as $note)
                    <tr>
                        <td class="fw-semibold" style="color:#0284c7">{{ $note->doc_number }}</td>
                        <td class="text-nowrap">{{ $note->doc_date->thaiDate() }}</td>
                        <td>{{ $note->customer->name_th }}</td>
                        <td class="text-nowrap">{{ $note->due_date?->thaiDate() ?? '-' }}</td>
                        <td class="text-end">{{ $note->items_count }}</td>
                        <td class="text-end fw-semibold">{{ number_format($note->total_amount, 2) }}</td>
                        <td>
                            <span class="badge {{ ['open' => 'text-bg-warning', 'collected' => 'text-bg-success', 'cancelled' => 'text-bg-secondary'][$note->status] ?? 'text-bg-light' }}">
                                {{ $note->statusLabel() }}
                            </span>
                        </td>
                        <td class="text-end"><a href="{{ route('billing-notes.show', $note) }}" class="btn btn-sm btn-light border">ดู / พิมพ์</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีใบวางบิล</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $notes->links() }}</div>
    </div>

    {{-- Create modal --}}
    <div class="billing-backdrop" x-show="createOpen" x-transition.opacity @keydown.escape.window="createOpen = false">
        <div class="billing-modal" @click.outside="createOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <h3 class="h5 fw-bold mb-0">สร้างใบวางบิล</h3>
                <button type="button" class="btn btn-light rounded-circle" @click="createOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('billing-notes.store') }}" @submit="onSubmit">
                @csrf
                <div class="px-4 pb-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">ลูกค้า (แสดงเฉพาะที่มีหนี้ค้าง)</label>
                            <select name="customer_id" x-model="customerId" @change="loadOpenItems()" required class="form-select">
                                <option value="">-- เลือกลูกค้า --</option>
                                @foreach($customersWithDebt as $c)
                                    <option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name_th }} ({{ $c->unpaid_count }} ใบ)</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted">ครบกำหนดชำระ</label>
                            <input type="date" name="due_date" class="form-control" value="{{ now()->addDays(7)->toDateString() }}">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="w-100 text-end">
                                <div class="small text-muted">ยอดรวมที่เลือก</div>
                                <div class="h4 fw-bold text-success mb-0">฿<span x-text="selectedTotal.toLocaleString('th-TH',{minimumFractionDigits:2})"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive border rounded-3" style="max-height:340px;overflow-y:auto">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light" style="position:sticky;top:0">
                                <tr>
                                    <th style="width:44px"><input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allChecked"></th>
                                    <th>เลขที่ใบขายเชื่อ</th><th>วันที่</th><th>ครบกำหนด</th>
                                    <th class="text-end">ยอดรวม</th><th class="text-end">ค้างชำระ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="loading"><tr><td colspan="6" class="text-center text-muted py-4">กำลังโหลด...</td></tr></template>
                                <template x-if="!loading && openItems.length === 0"><tr><td colspan="6" class="text-center text-muted py-4">เลือกลูกค้าเพื่อดูใบขายเชื่อค้างชำระ</td></tr></template>
                                <template x-for="item in openItems" :key="item.id">
                                    <tr :class="{ 'table-success': selected.includes(item.id) }" style="cursor:pointer" @click="toggle(item.id)">
                                        <td @click.stop><input type="checkbox" name="open_item_id[]" :value="item.id" :checked="selected.includes(item.id)" @change="toggle(item.id)"></td>
                                        <td class="fw-semibold" x-text="item.doc_number"></td>
                                        <td x-text="item.doc_date"></td>
                                        <td x-text="item.due_date"></td>
                                        <td class="text-end" x-text="item.net_amount.toLocaleString('th-TH',{minimumFractionDigits:2})"></td>
                                        <td class="text-end fw-semibold text-danger" x-text="item.balance_amount.toLocaleString('th-TH',{minimumFractionDigits:2})"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <input name="note" class="form-control mt-3" placeholder="หมายเหตุ (ไม่บังคับ)">
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border px-4" @click="createOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-success px-5" :disabled="selected.length === 0">
                        <i class="bi bi-check2-circle me-1"></i>สร้างใบวางบิล (<span x-text="selected.length"></span> ใบ)
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
    .billing-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .billing-modal { width: min(880px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
</style>
@endpush

@push('scripts')
<script>
function billingNotePage() {
    return {
        createOpen: false, customerId: '', openItems: [], selected: [], loading: false,

        openCreate() { this.createOpen = true; this.customerId = ''; this.openItems = []; this.selected = []; },

        async loadOpenItems() {
            this.selected = []; this.openItems = [];
            if (!this.customerId) return;
            this.loading = true;
            const res = await fetch(`{{ url('billing-notes/customers') }}/${this.customerId}/open-items`);
            this.openItems = await res.json();
            this.selected = this.openItems.map(i => i.id); // เลือกทั้งหมดไว้ก่อน
            this.loading = false;
        },

        get selectedTotal() {
            return this.openItems.filter(i => this.selected.includes(i.id)).reduce((s, i) => s + i.balance_amount, 0);
        },
        get allChecked() { return this.openItems.length > 0 && this.selected.length === this.openItems.length; },

        toggle(id) {
            this.selected.includes(id) ? this.selected = this.selected.filter(x => x !== id) : this.selected.push(id);
        },
        toggleAll(checked) { this.selected = checked ? this.openItems.map(i => i.id) : []; },

        onSubmit(e) {
            if (this.selected.length === 0) { e.preventDefault(); Swal.fire({ icon: 'warning', title: 'กรุณาเลือกใบขายเชื่ออย่างน้อย 1 ใบ' }); }
        },
    };
}
</script>
@endpush
