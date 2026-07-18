@extends('layout')
@section('title', "{$sale->doc_number} - ใบขาย/ใบส่งของ - POPSTAR ERP")
@section('page-title', 'ใบขาย / ใบส่งของ / ใบกำกับภาษี')
@section('page-subtitle', $sale->doc_number)

@php
$openItem = $sale->openItem;
$isPaid = $openItem && $openItem->status === 'paid';
$isPartial = $openItem && $openItem->status === 'partial';
@endphp

@section('content')
<div x-data="saleShow()" x-cloak>
<a href="{{ route('bookings.index') }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับรายการใบจอง
</a>

{{-- Flow status bar --}}
<div class="content-card p-4 mb-4">
    <div class="d-flex align-items-center gap-0 mb-4 flex-wrap">
        <div class="flow-step done">
            <div class="flow-dot"><i class="bi bi-journal-text"></i></div>
            <div class="flow-label">ใบจอง
                @if($booking)<div class="flow-sub"><a href="{{ route('bookings.show', $booking) }}">{{ $booking->document->doc_number }}</a></div>@endif
            </div>
        </div>
        <div class="flow-line done"></div>
        <div class="flow-step done">
            <div class="flow-dot"><i class="bi bi-receipt-cutoff"></i></div>
            <div class="flow-label">ใบขาย / ใบส่งของ<div class="flow-sub">{{ $sale->doc_number }}</div></div>
        </div>
        <div class="flow-line {{ $isPaid ? 'done' : '' }}"></div>
        <div class="flow-step {{ $isPaid ? 'done' : ($isPartial ? 'partial' : 'pending') }}">
            <div class="flow-dot"><i class="bi bi-cash-coin"></i></div>
            <div class="flow-label">รับชำระเงิน
                @if($isPaid)<div class="flow-sub text-success">ชำระครบแล้ว</div>
                @elseif($isPartial)<div class="flow-sub text-warning">ชำระบางส่วน</div>
                @else<div class="flow-sub text-muted">ค้างชำระ</div>@endif
            </div>
        </div>
    </div>

    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <h2 class="h4 fw-bold mb-1">{{ $sale->doc_number }}</h2>
            <div class="text-muted small">
                {{ $sale->doc_date->thaiDate() }} &middot; {{ $sale->branch->name_th }}
                @if($sale->salesman) &middot; {{ $sale->salesman->name }}@endif
            </div>
            <div class="fw-semibold mt-1">{{ $sale->customer->name_th }}
                <span class="text-muted fw-normal">({{ $sale->customer->code }})</span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('documents.tax-invoice', $sale) }}" target="_blank" class="btn btn-primary px-3">
                <i class="bi bi-receipt me-1"></i> ใบกำกับภาษี (A4)
            </a>
            <a href="{{ route('documents.delivery-note', $sale) }}" target="_blank" class="btn btn-light border px-3">
                <i class="bi bi-truck me-1"></i> ใบส่งของ (A5)
            </a>
            @if($openItem && !$isPaid)
            <button type="button" class="btn btn-success px-4" @click="payOpen = true">
                <i class="bi bi-cash-coin me-1"></i>
                รับชำระ
                @if($isPartial)
                    (ค้าง ฿{{ number_format($openItem->balance_amount, 2) }})
                @endif
            </button>
            @elseif($isPaid)
            <span class="badge text-bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle me-1"></i>ชำระครบแล้ว</span>
            @endif
        </div>
    </div>
</div>

{{-- Items + AR --}}
<div class="row g-4">
    <div class="col-lg-8">
        <div class="content-card p-4">
            <h3 class="h6 fw-bold mb-3">รายการสินค้า</h3>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead>
                    <tbody>
                        @foreach($sale->stockDocument->items as $item)
                        <tr>
                            <td class="fw-semibold text-primary">{{ $item->product->sku_code }}</td>
                            <td>{{ $item->product->name_th }}</td>
                            <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                            <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold border-top">
                            <td colspan="4" class="text-end py-2">รวมทั้งสิ้น</td>
                            <td class="text-end fs-5 text-success">฿{{ number_format($sale->total_amount, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="content-card p-4">
            <h3 class="h6 fw-bold mb-3">ยอดลูกหนี้ (AR)</h3>
            @if($openItem)
            <dl class="small mb-0">
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom"><dt class="fw-normal text-muted">ยอดรวม</dt><dd class="mb-0 fw-bold">฿{{ number_format($openItem->net_amount, 2) }}</dd></div>
                <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">ชำระแล้ว</dt><dd class="mb-0 text-success fw-semibold">฿{{ number_format($openItem->paid_amount, 2) }}</dd></div>
                <div class="d-flex justify-content-between mb-2"><dt class="fw-bold {{ $isPaid ? '' : 'text-danger' }}">ค้างชำระ</dt><dd class="mb-0 fw-bold {{ $isPaid ? 'text-success' : 'text-danger' }}">฿{{ number_format($openItem->balance_amount, 2) }}</dd></div>
                <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">ครบกำหนด</dt><dd class="mb-0">{{ $openItem->due_date->thaiDate() }}</dd></div>
                <div class="d-flex justify-content-between"><dt class="fw-normal text-muted">สถานะ</dt>
                    <dd class="mb-0">
                        <span class="badge {{ ['open'=>'text-bg-warning','partial'=>'text-bg-info','paid'=>'text-bg-success'][$openItem->status] ?? 'text-bg-light' }}">
                            {{ ['open'=>'ค้างชำระ','partial'=>'ชำระบางส่วน','paid'=>'ชำระแล้ว'][$openItem->status] ?? $openItem->status }}
                        </span>
                    </dd>
                </div>
            </dl>
            @else
            <p class="text-muted small mb-0">ไม่มีรายการลูกหนี้</p>
            @endif
        </div>
    </div>
</div>

{{-- Payment modal --}}
@if($openItem && !$isPaid)
<div class="booking-modal-backdrop" x-show="payOpen" x-transition.opacity @keydown.escape.window="payOpen=false">
    <div class="booking-modal" style="width:min(480px,100%)" @click.outside="payOpen=false" x-transition>
        <div class="modal-header border-0 px-4 pt-4 pb-2">
            <h3 class="h5 fw-bold mb-0">รับชำระหนี้ — {{ $sale->customer->name_th }}</h3>
            <button type="button" class="btn btn-light rounded-circle" @click="payOpen=false"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="post" action="{{ route('customers.payments.store', $sale->customer) }}">
            @csrf
            <input type="hidden" name="open_item_id[]" value="{{ $openItem->id }}">
            <div class="modal-body px-4 pb-4">
                <div class="row g-3 mb-3" x-data="{ m: 'cash' }">
                    <div class="col-7">
                        <label class="form-label text-muted small">สาขา</label>
                        <select name="branch_id" required class="form-select">
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected($b->id === $sale->branch_id)>{{ $b->code }} - {{ $b->name_th }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label text-muted small">วิธีชำระ</label>
                        <select name="method" x-model="m" required class="form-select">
                            <option value="cash">เงินสด</option>
                            <option value="transfer">โอนเงิน</option>
                            <option value="cheque">เช็ค</option>
                        </select>
                    </div>
                    <template x-if="m === 'cheque'">
                        <div class="col-12">
                            <div class="row g-2">
                                <div class="col-4"><label class="form-label text-muted small">เลขที่เช็ค</label><input name="cheque_no" required class="form-control"></div>
                                <div class="col-4"><label class="form-label text-muted small">วันที่บนเช็ค</label><input type="date" name="cheque_due_date" required class="form-control" value="{{ now()->toDateString() }}"></div>
                                <div class="col-4"><label class="form-label text-muted small">ธนาคาร</label><input name="cheque_bank" class="form-control" placeholder="เช่น กสิกรไทย"></div>
                            </div>
                            <div class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>เช็คจะเข้าทะเบียนเช็ครับอัตโนมัติ (เมนู การเงิน → ทะเบียนเช็ค)</div>
                        </div>
                    </template>
                </div>
                <div class="p-3 rounded-3 bg-light mb-3">
                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">เอกสาร</span><span class="fw-bold">{{ $openItem->document->doc_number }}</span></div>
                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">ยอดรวม</span><span>฿{{ number_format($openItem->net_amount, 2) }}</span></div>
                    <div class="d-flex justify-content-between fw-bold"><span>ค้างชำระ</span><span class="text-danger">฿{{ number_format($openItem->balance_amount, 2) }}</span></div>
                </div>
                <label class="form-label text-muted small">ยอดชำระ</label>
                <input type="number" step="0.01" min="0.01" max="{{ $openItem->balance_amount }}"
                    name="amount[]" value="{{ $openItem->balance_amount }}" required class="form-control form-control-lg fw-bold text-end">
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button type="button" class="btn btn-light border px-4" @click="payOpen=false">ยกเลิก</button>
                <button type="submit" class="btn btn-success px-5">บันทึกรับชำระ</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
@endsection

@push('head')
<style>
[x-cloak]{display:none!important}
.flow-step{display:flex;flex-direction:column;align-items:center;gap:6px;min-width:100px}
.flow-dot{width:44px;height:44px;border-radius:50%;border:2px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;font-size:18px;color:#94a3b8}
.flow-step.done .flow-dot{border-color:#10b981;background:#ecfdf5;color:#10b981}
.flow-step.partial .flow-dot{border-color:#f59e0b;background:#fffbeb;color:#f59e0b}
.flow-step.pending .flow-dot{border-color:#e2e8f0;background:#f8fafc;color:#cbd5e1}
.flow-label{font-size:12px;font-weight:700;text-align:center;color:#374151}
.flow-sub{font-size:11px;font-weight:400;color:#64748b;margin-top:2px}
.flow-line{flex:1;height:2px;background:#e2e8f0;min-width:40px;margin:0 8px;position:relative;top:-10px}
.flow-line.done{background:#10b981}
.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:24px}
.booking-modal{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24);max-height:calc(100vh - 48px);overflow:auto}
</style>
@endpush

@push('scripts')
<script>function saleShow() { return { payOpen: false }; }</script>
@endpush
