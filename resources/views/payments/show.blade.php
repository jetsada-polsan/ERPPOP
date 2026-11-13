@extends('layout')
@section('title', "{$payment->doc_number} - MPM Food Group")
@section('page-title', $payment->documentType->name_th)
@section('page-subtitle', $payment->doc_number)
@section('content')
{{-- เจ้าของ 13 พ.ย.: ใบเสร็จต้องเป็นแบบสากล — หัวเอกสารบนสุด, วิธีรับเงิน, รายการ, ยอดรวมท้าย --}}
@php
    $isReceipt = $payment->documentType->code === 'RECEIPT';
    $lines = $payment->paymentDocument?->lines ?? collect();
    $allocations = $payment->paymentDocument?->allocations ?? collect();
    $methodMeta = [
        'cash' => ['เงินสด', 'bi-cash-stack', 'success'],
        'transfer' => ['โอนเงิน', 'bi-bank', 'primary'],
        'cheque' => ['เช็ค', 'bi-card-checklist', 'warning'],
        'card' => ['บัตร', 'bi-credit-card', 'info'],
    ];
    $sumAllocated = $allocations->sum('allocated_amount');
    $sumDiscount = $allocations->sum('discount_amount');
    $sumWht = $allocations->sum('wht_amount');
    $statusCode = $payment->paymentDocument?->status;
    $isVoid = in_array($payment->status, ['cancelled', 'void'], true) || in_array($statusCode, ['cancelled', 'void'], true);
@endphp
<style>
    .doc-head { border-top: 4px solid var(--erp-primary, #1a9bdc); }
    .doc-head .doc-type { font-size: 12px; font-weight: 700; letter-spacing: .08em; color: var(--erp-muted, #6b8294); text-transform: uppercase; }
    .doc-head .doc-no { font-size: 22px; font-weight: 800; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .doc-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px 24px; margin-top: 18px; padding-top: 16px; border-top: 1px dashed var(--erp-border, #dce7ef); }
    .doc-meta dt { font-size: 11.5px; font-weight: 600; color: var(--erp-muted, #6b8294); margin-bottom: 2px; }
    .doc-meta dd { margin: 0; font-weight: 600; }
    .doc-amount { text-align: right; }
    .doc-amount small { display: block; font-size: 12px; color: var(--erp-muted, #6b8294); }
    .doc-amount strong { font-size: 28px; font-weight: 800; color: #0f7a4f; }
    .pay-chip { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid var(--erp-border, #dce7ef); border-radius: 12px; height: 100%; }
    .pay-chip .ico { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; font-size: 18px; flex: none; }
    .pay-chip .amt { margin-left: auto; font-size: 18px; font-weight: 800; white-space: nowrap; }
    .pay-chip .sub { font-size: 12px; color: var(--erp-muted, #6b8294); }
    .doc-total { max-width: 380px; margin-left: auto; }
    .doc-total div { display: flex; justify-content: space-between; padding: 6px 0; }
    .doc-total .grand { border-top: 2px solid #17324d; margin-top: 6px; padding-top: 10px; font-size: 18px; font-weight: 800; }
</style>

{{-- 1. หัวเอกสาร --}}
<div class="content-card doc-head p-4 mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <div class="doc-type">{{ $payment->documentType->name_th }}</div>
            <div class="doc-no">{{ $payment->doc_number }}</div>
            <span class="badge {{ $isVoid ? 'text-bg-danger' : 'text-bg-success' }} mt-2">{{ \App\Support\DocumentStatus::label($payment->paymentDocument?->status) }}</span>
        </div>
        <div class="d-flex gap-2 align-items-start flex-wrap">
            <div class="doc-amount me-3">
                <small>{{ $isReceipt ? 'ยอดรับชำระ' : 'ยอดจ่าย' }}</small>
                <strong>฿{{ number_format($payment->total_amount, 2) }}</strong>
            </div>
            @unless($isVoid)
            <a href="{{ route('payments.print', $payment) }}" target="_blank" class="btn btn-primary px-3">
                <i class="bi bi-printer me-1"></i> {{ $isReceipt ? 'พิมพ์ใบเสร็จ (A4)' : 'พิมพ์ใบสำคัญจ่าย (A4)' }}
            </a>
            @endunless
            @include('documents._void-action', ['doc' => $payment])
        </div>
    </div>
    <dl class="doc-meta mb-0">
        <div><dt>วันที่เอกสาร</dt><dd>{{ $payment->doc_date->thaiDate() }}</dd></div>
        <div><dt>สาขา</dt><dd>{{ $payment->branch->name_th }}</dd></div>
        @if($payment->customer)
            <div><dt>ลูกค้า</dt><dd>{{ $payment->customer->code }} {{ $payment->customer->name_th }}</dd></div>
        @endif
        @if($payment->supplier)
            <div><dt>ซัพพลายเออร์</dt><dd>{{ $payment->supplier->code ?? '' }} {{ $payment->supplier->name_th }}</dd></div>
        @endif
        <div><dt>{{ $isReceipt ? 'ผู้รับเงิน' : 'ผู้จ่ายเงิน' }}</dt><dd>{{ $payment->salesman?->name ?? '—' }}</dd></div>
    </dl>
</div>

{{-- 2. วิธีรับเงิน / จ่ายเงิน --}}
<div class="content-card p-4 mb-3">
    <h3 class="h6 fw-bold mb-3"><i class="bi bi-wallet2 me-1"></i> {{ $isReceipt ? 'วิธีรับชำระ' : 'วิธีจ่ายชำระ' }}</h3>
    <div class="row g-3">
        @forelse($lines as $line)
            @php([$label, $icon, $tone] = $methodMeta[$line->method] ?? [$line->method, 'bi-wallet2', 'secondary'])
            <div class="col-md-6 col-xl-4">
                <div class="pay-chip">
                    <span class="ico bg-{{ $tone }}-subtle text-{{ $tone }}"><i class="bi {{ $icon }}"></i></span>
                    <div>
                        <div class="fw-bold">{{ $label }}</div>
                        @if($line->method === 'transfer')
                            <div class="sub">
                                {{ $line->bankAccount ? $line->bankAccount->bank_name.' '.$line->bankAccount->account_no : 'บัญชีของสาขา' }}
                                @if($line->transfer_ref) · อ้างอิง {{ $line->transfer_ref }} @endif
                                @if($line->transfer_at) · {{ \Illuminate\Support\Carbon::parse($line->transfer_at)->format("d/m/Y H:i") }} @endif
                            </div>
                        @elseif($line->method === 'cheque')
                            <div class="sub">เลขที่ {{ $line->cheque_no ?: '-' }} @if($line->cheque_due_date) · ลงวันที่ {{ $line->cheque_due_date->thaiDate() }} @endif</div>
                        @endif
                    </div>
                    <div class="amt">{{ number_format($line->amount, 2) }}</div>
                </div>
            </div>
        @empty
            <div class="col-12 text-muted small">ไม่มีรายละเอียดวิธีชำระ</div>
        @endforelse
    </div>
</div>

{{-- 3. รายการเอกสารที่ตัดชำระ + ยอดรวมท้าย --}}
<div class="content-card p-4">
    <h3 class="h6 fw-bold mb-3"><i class="bi bi-list-check me-1"></i> {{ $isReceipt ? 'รายการบิลที่รับชำระ' : 'รายการที่จ่ายชำระ' }}</h3>
    @if($allocations->isNotEmpty())
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:48px">#</th>
                        <th>เอกสารอ้างอิง</th>
                        <th class="text-end">ยอดตัดหนี้</th>
                        <th class="text-end">ลดสด</th>
                        <th class="text-end">หัก ณ ที่จ่าย</th>
                        <th class="text-end">รับจริง</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allocations as $alloc)
                        <tr>
                            <td class="text-muted">{{ $loop->iteration }}</td>
                            <td class="fw-semibold">{{ $alloc->referenceNumber() }}</td>
                            <td class="text-end">{{ number_format($alloc->allocated_amount, 2) }}</td>
                            <td class="text-end text-muted">{{ number_format($alloc->discount_amount, 2) }}</td>
                            <td class="text-end text-muted">{{ number_format($alloc->wht_amount, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($alloc->allocated_amount - $alloc->discount_amount - $alloc->wht_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-muted small mb-3">ไม่มีรายการเอกสารที่ตัดชำระ</div>
    @endif

    <div class="doc-total">
        <div><span class="text-muted">ยอดตัดหนี้รวม</span><span>{{ number_format($sumAllocated, 2) }}</span></div>
        @if($sumDiscount > 0)<div><span class="text-muted">หัก ส่วนลดเงินสด</span><span>-{{ number_format($sumDiscount, 2) }}</span></div>@endif
        @if($sumWht > 0)<div><span class="text-muted">หัก ภาษีหัก ณ ที่จ่าย</span><span>-{{ number_format($sumWht, 2) }}</span></div>@endif
        <div class="grand"><span>{{ $isReceipt ? 'ยอดรับชำระสุทธิ' : 'ยอดจ่ายสุทธิ' }}</span><span class="text-success">฿{{ number_format($payment->total_amount, 2) }}</span></div>
    </div>
</div>
@endsection
