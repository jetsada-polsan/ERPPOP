@extends('layout')
@section('title', 'ใบเพิ่ม/ลดหนี้ - POPSTAR ERP')
@section('page-title', 'ใบเพิ่มหนี้ / ใบลดหนี้')
@section('page-subtitle', 'ปรับยอดหนี้ลูกค้าแบบการเงิน (ไม่กระทบสต๊อก) - ต่างจากใบรับคืนสินค้าที่คืนของจริง')
@section('content')
<div x-data="cdNotePage()" x-cloak>
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a href="{{ route('credit-debit-notes.index', ['type' => 'credit']) }}" class="nav-link {{ $type === 'credit' ? 'active' : '' }}"><i class="bi bi-dash-circle me-1"></i>ใบลดหนี้</a></li>
        <li class="nav-item"><a href="{{ route('credit-debit-notes.index', ['type' => 'debit']) }}" class="nav-link {{ $type === 'debit' ? 'active' : '' }}"><i class="bi bi-plus-circle me-1"></i>ใบเพิ่มหนี้</a></li>
    </ul>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2">
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" style="width:260px" placeholder="เลขที่ / อ้างอิง / ลูกค้า">
            <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </form>
        <button type="button" class="btn ms-auto {{ $type === 'credit' ? 'btn-warning' : 'btn-danger' }}" @click="modalOpen = true">
            <i class="bi bi-plus-lg me-1"></i>สร้าง{{ $type === 'credit' ? 'ใบลดหนี้' : 'ใบเพิ่มหนี้' }}
        </button>
    </div>

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>ลูกค้า</th><th>อ้างอิงใบขายเชื่อ</th><th>เหตุผล</th><th>สถานะ</th><th class="text-end">จำนวนเงิน</th><th></th></tr></thead>
                <tbody>
                @forelse($notes as $note)
                    <tr>
                        <td class="fw-semibold" style="color:{{ $type === 'credit' ? '#d97706' : '#dc2626' }}">{{ $note->doc_number }}</td>
                        <td class="text-nowrap">{{ $note->doc_date->thaiDate() }}</td>
                        <td>{{ $note->customer->name_th }}</td>
                        <td class="small">{{ $note->reference ?? '-' }}</td>
                        <td class="small text-muted">{{ $note->remark }}</td>
                        <td><span class="badge {{ $note->status === 'active' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $note->status === 'pending_approval' ? 'รออนุมัติ' : $note->status }}</span></td>
                        <td class="text-end fw-semibold">{{ $type === 'credit' ? '-' : '+' }}{{ number_format($note->total_amount, 2) }}</td>
                        <td class="text-end">@if($note->status === 'active')<a href="{{ route('credit-debit-notes.print', $note) }}" target="_blank" class="btn btn-sm btn-light border"><i class="bi bi-printer me-1"></i>พิมพ์</a>@elseif(auth()->user()?->hasPermission('finance.note.approve'))<form method="post" action="{{ route('credit-debit-notes.approve',$note) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">อนุมัติ</button></form><form method="post" action="{{ route('credit-debit-notes.reject',$note) }}" class="d-inline">@csrf<input type="hidden" name="reason" value="ข้อมูลไม่ผ่านการตรวจ"><button class="btn btn-sm btn-outline-danger">ไม่อนุมัติ</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มี{{ $type === 'credit' ? 'ใบลดหนี้' : 'ใบเพิ่มหนี้' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $notes->links() }}</div>
    </div>

    {{-- Create modal --}}
    <div class="cd-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
        <div class="cd-modal" @click.outside="modalOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <h3 class="h5 fw-bold mb-0">สร้าง{{ $type === 'credit' ? 'ใบลดหนี้' : 'ใบเพิ่มหนี้' }}</h3>
                <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('credit-debit-notes.store') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <div class="px-4 pb-4">
                    <div class="alert py-2 small {{ $type === 'credit' ? 'alert-warning' : 'alert-danger' }}">
                        @if($type === 'credit')
                            <i class="bi bi-info-circle me-1"></i>ใบลดหนี้ลดยอดค้างของใบขายเชื่อที่อ้างอิง เช่น ลดราคาหลังออกบิล / ชดเชยของเสีย (ไม่รับสินค้ากลับ)
                        @else
                            <i class="bi bi-info-circle me-1"></i>ใบเพิ่มหนี้สร้างรายการหนี้ใหม่ให้ลูกค้า เช่น เรียกเก็บค่าปรับ / ค่าขนส่งเพิ่ม
                        @endif
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small text-muted">ลูกค้า</label>
                            <select name="customer_id" x-model="customerId" @change="loadOpenItems()" required class="form-select">
                                <option value="">-- เลือกลูกค้า --</option>
                                @foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted">สาขา</label>
                            <select name="branch_id" required class="form-select">
                                @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">
                                อ้างอิงใบขายเชื่อค้างชำระ
                                @if($type === 'credit')<span class="text-danger">*</span>@else (ไม่บังคับ)@endif
                            </label>
                            <select name="open_item_id" x-model="openItemId" {{ $type === 'credit' ? 'required' : '' }} class="form-select">
                                <option value="">{{ $type === 'credit' ? '-- เลือกใบขายเชื่อ --' : '-- ไม่อ้างอิง --' }}</option>
                                <template x-for="item in openItems" :key="item.id">
                                    <option :value="item.id" x-text="item.doc_number + ' (ค้าง ' + item.balance_amount.toLocaleString('th-TH',{minimumFractionDigits:2}) + ')'"></option>
                                </template>
                            </select>
                            <div class="small text-muted mt-1" x-show="customerId && openItems.length === 0" x-cloak>ลูกค้ารายนี้ไม่มีใบค้างชำระ</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-muted">จำนวนเงิน</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required class="form-control text-end fw-bold">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small text-muted">เหตุผล / รายละเอียด</label>
                            <input name="reason" required class="form-control" placeholder="{{ $type === 'credit' ? 'เช่น ลดราคาสินค้าชำรุด' : 'เช่น ค่าขนส่งเพิ่ม' }}">
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn {{ $type === 'credit' ? 'btn-warning' : 'btn-danger' }} px-5"><i class="bi bi-check2-circle me-1"></i>บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .cd-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .cd-modal { width: min(680px, 100%); background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); max-height: calc(100vh - 48px); overflow: auto; }
</style>
@endpush

@push('scripts')
<script>
function cdNotePage() {
    return {
        modalOpen: false, customerId: '', openItemId: '', openItems: [],
        async loadOpenItems() {
            this.openItemId = ''; this.openItems = [];
            if (!this.customerId) return;
            const res = await fetch(`{{ url('credit-debit-notes/customers') }}/${this.customerId}/open-items`);
            this.openItems = await res.json();
        },
    };
}
</script>
@endpush
