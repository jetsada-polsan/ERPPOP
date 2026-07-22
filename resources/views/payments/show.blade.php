@extends('layout')
@section('title', "{$payment->doc_number} - POPSTAR ERP")
@section('page-title', $payment->documentType->name_th)
@section('page-subtitle', $payment->doc_number)
@section('content')
    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">{{ $payment->documentType->name_th }} {{ $payment->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $payment->doc_date->thaiDate() }} &middot;
                    {{ $payment->branch->name_th }} &middot;
                    @if($payment->customer) ลูกค้า: {{ $payment->customer->name_th }} @endif
                    @if($payment->supplier) ซัพพลายเออร์: {{ $payment->supplier->name_th }} @endif
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a href="{{ route('payments.print', $payment) }}" target="_blank" class="btn btn-primary px-3">
                    <i class="bi bi-receipt me-1"></i> {{ $payment->documentType->code === 'RECEIPT' ? 'ใบเสร็จรับเงิน (A4)' : 'ใบสำคัญจ่าย (A4)' }}
                </a>
                <span class="badge text-bg-success fs-6 px-3 py-2">{{ $payment->paymentDocument?->status }}</span>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="content-card p-4 mb-4">
                <h3 class="h6 fw-bold mb-3">รายละเอียดการชำระ</h3>
                <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>วิธีชำระ</th><th class="text-end">จำนวนเงิน</th></tr></thead>
                    <tbody>
                        @foreach($payment->paymentDocument?->lines ?? [] as $line)
                        <tr>
                            <td>{{ ['cash'=>'เงินสด','transfer'=>'โอนเงิน','cheque'=>'เช็ค'][$line->method] ?? $line->method }}</td>
                            <td class="text-end fw-bold">{{ number_format($line->amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="fw-bold border-top"><td>รวมรับชำระ</td><td class="text-end">{{ number_format($payment->total_amount, 2) }}</td></tr></tfoot>
                </table>
                </div>
            </div>

            @if($payment->paymentDocument?->allocations->isNotEmpty())
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3">รายการหนี้ที่ตัดชำระ</h3>
                <div class="table-responsive">
                <table class="table align-middle table-sm">
                    <thead><tr><th>เอกสารอ้างอิง</th><th class="text-end">ยอดตัดชำระ</th></tr></thead>
                    <tbody>
                        @foreach($payment->paymentDocument->allocations as $alloc)
                        <tr>
                            <td>{{ $alloc->openItem?->document?->doc_number ?? '-' }}</td>
                            <td class="text-end">{{ number_format($alloc->allocated_amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            @endif
        </div>
        <div class="col-lg-5">
            <div class="content-card p-4">
                <dl class="small mb-0">
                    <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">เลขที่เอกสาร</dt><dd class="mb-0 fw-bold">{{ $payment->doc_number }}</dd></div>
                    <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">วันที่</dt><dd class="mb-0">{{ $payment->doc_date->thaiDate() }}</dd></div>
                    <div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">สาขา</dt><dd class="mb-0">{{ $payment->branch->name_th }}</dd></div>
                    @if($payment->customer)<div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">ลูกค้า</dt><dd class="mb-0">{{ $payment->customer->name_th }}</dd></div>@endif
                    @if($payment->supplier)<div class="d-flex justify-content-between mb-2"><dt class="fw-normal text-muted">ซัพพลายเออร์</dt><dd class="mb-0">{{ $payment->supplier->name_th }}</dd></div>@endif
                    <hr>
                    <div class="d-flex justify-content-between fw-bold fs-5"><dt>ยอดรวม</dt><dd class="mb-0 text-success">{{ number_format($payment->total_amount, 2) }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
@endsection
