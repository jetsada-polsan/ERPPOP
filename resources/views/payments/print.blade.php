@extends('layouts.print')
@php($isReceipt = $payment->documentType->code === 'RECEIPT')
@php($party = $isReceipt ? $payment->customer : $payment->supplier)
@php($methodLabels = ['cash' => 'เงินสด', 'transfer' => 'โอนเงิน', 'cheque' => 'เช็ค'])
@section('title', ($isReceipt ? 'ใบเสร็จรับเงิน ' : 'ใบสำคัญจ่าย ').$payment->doc_number)

@section('doc-title')
    <h1>{{ $isReceipt ? 'ใบเสร็จรับเงิน' : 'ใบสำคัญจ่าย' }}</h1>
    <div class="muted">{{ $isReceipt ? 'RECEIPT' : 'PAYMENT VOUCHER' }}</div>
@endsection

@section('body')
    <div class="meta-grid">
        <div class="box">
            <div class="box-label">{{ $isReceipt ? 'ได้รับเงินจาก' : 'จ่ายชำระให้' }}</div>
            <div style="font-weight:800">{{ $party?->name_th ?? '-' }} @if($party?->code)({{ $party->code }})@endif</div>
            <div class="muted">
                @if($party?->tax_id)เลขประจำตัวผู้เสียภาษี {{ $party->tax_id }} ({{ $party->tax_branch ?: 'สำนักงานใหญ่' }})@endif
            </div>
        </div>
        <div class="box">
            <div class="meta-row"><span class="muted">เลขที่</span><b>{{ $payment->doc_number }}</b></div>
            <div class="meta-row"><span class="muted">วันที่</span><b>{{ $payment->doc_date->thaiDate() }}</b></div>
            <div class="meta-row"><span class="muted">สาขา</span><b>{{ $payment->branch?->name_th ?? '-' }}</b></div>
        </div>
    </div>

    {{-- ช่องทางชำระ --}}
    <table class="items">
        <thead><tr><th class="c" style="width:40px">#</th><th>ช่องทางชำระ</th><th>รายละเอียด</th><th class="r" style="width:120px">จำนวนเงิน</th></tr></thead>
        <tbody>
            @foreach($payment->paymentDocument?->lines ?? [] as $i => $line)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $methodLabels[$line->method] ?? $line->method }}</td>
                <td>
                    @if($line->method === 'cheque')เช็คเลขที่ {{ $line->cheque_no }} @if($line->cheque_bank)ธนาคาร{{ $line->cheque_bank }}@endif @if($line->cheque_due_date)ลงวันที่ {{ \Illuminate\Support\Carbon::parse($line->cheque_due_date)->thaiDate() }}@endif
                    @else - @endif
                </td>
                <td class="r">{{ number_format((float) ($line->amount ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ตัดชำระเอกสารไหนบ้าง --}}
    @if(($payment->paymentDocument?->allocations ?? collect())->isNotEmpty())
    <table class="items">
        <thead><tr><th class="c" style="width:40px">#</th><th>ชำระค่าเอกสาร</th><th>ครบกำหนด</th><th class="r" style="width:120px">ยอดที่ชำระ</th><th class="r" style="width:120px">คงค้างหลังชำระ</th></tr></thead>
        <tbody>
            @foreach($payment->paymentDocument->allocations as $i => $alloc)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $alloc->openItem?->document?->doc_number ?? '-' }}</td>
                <td>{{ $alloc->openItem?->due_date ? \Illuminate\Support\Carbon::parse($alloc->openItem->due_date)->thaiDate() : '-' }}</td>
                <td class="r">{{ number_format((float) ($alloc->amount ?? 0), 2) }}</td>
                <td class="r">{{ number_format((float) ($alloc->openItem?->balance_amount ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="totals">
        <div class="baht-text">({{ \App\Support\ThaiBaht::text((float) $payment->total_amount) }})</div>
        <div class="sum">
            <div class="sum-row grand"><span>{{ $isReceipt ? 'รวมรับชำระทั้งสิ้น' : 'รวมจ่ายชำระทั้งสิ้น' }}</span><span>{{ number_format($payment->total_amount, 2) }} บาท</span></div>
        </div>
    </div>

    <div class="signs">
        <div class="sign"><div class="line"></div>{{ $isReceipt ? 'ผู้ชำระเงิน' : 'ผู้รับเงิน' }} / วันที่</div>
        <div class="sign"><div class="line"></div>{{ $isReceipt ? 'ผู้รับเงิน' : 'ผู้จ่ายเงิน' }} / วันที่</div>
        <div class="sign"><div class="line"></div>ผู้มีอำนาจลงนาม / วันที่</div>
    </div>
@endsection
