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
            @if($payment->salesman)
                <div class="meta-row"><span class="muted">ผู้รับเงิน</span><b>{{ $payment->salesman->name }} ({{ $payment->salesman->code }})</b></div>
            @endif
            @if($payment->paymentDocument?->wht_cert_no)
                <div class="meta-row"><span class="muted">หนังสือรับรอง หงด.</span><b>{{ $payment->paymentDocument->wht_cert_no }}</b></div>
            @endif
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
                    @elseif($line->method === 'transfer')@if($line->transfer_ref)อ้างอิง {{ $line->transfer_ref }}@endif @if($line->transfer_at)โอนเมื่อ {{ \Illuminate\Support\Carbon::parse($line->transfer_at)->format('d/m/Y H:i') }}@endif @if(! $line->transfer_ref && ! $line->transfer_at) - @endif
                    @else - @endif
                </td>
                <td class="r">{{ number_format((float) ($line->amount ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ตัดชำระเอกสารไหนบ้าง --}}
    @if(($payment->paymentDocument?->allocations ?? collect())->isNotEmpty())
    <?php $hasDiscOrWht = $payment->paymentDocument->allocations->contains(fn($a) => (float)$a->discount_amount > 0 || (float)$a->wht_amount > 0); ?>
    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:40px">#</th>
                <th>ชำระค่าเอกสาร</th>
                <th>ครบกำหนด</th>
                <th class="r" style="width:110px">ยอดตัดหนี้</th>
                @if($hasDiscOrWht)
                    <th class="r" style="width:90px">ลดสด</th>
                    <th class="r" style="width:90px">หัก ณ ที่จ่าย</th>
                    <th class="r" style="width:110px">รับจริง</th>
                @endif
                <th class="r" style="width:110px">คงค้างหลังชำระ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payment->paymentDocument->allocations as $i => $alloc)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $alloc->referenceNumber() }}</td>
                <td>{{ ($alloc->supplierOpenItem ?? $alloc->openItem)?->due_date ? \Illuminate\Support\Carbon::parse(($alloc->supplierOpenItem ?? $alloc->openItem)->due_date)->thaiDate() : '-' }}</td>
                <td class="r">{{ number_format((float) ($alloc->allocated_amount ?? 0), 2) }}</td>
                @if($hasDiscOrWht)
                    <td class="r">{{ number_format((float) ($alloc->discount_amount ?? 0), 2) }}</td>
                    <td class="r">{{ number_format((float) ($alloc->wht_amount ?? 0), 2) }}</td>
                    <td class="r">{{ number_format((float) ($alloc->allocated_amount - $alloc->discount_amount - $alloc->wht_amount), 2) }}</td>
                @endif
                <td class="r">{{ number_format((float) (($alloc->supplierOpenItem ?? $alloc->openItem)?->balance_amount ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- รายการสินค้าในบิลที่รับชำระ (ใบเสร็จเท่านั้น) — ลูกค้าเห็นว่าจ่ายค่าอะไร --}}
    @foreach($paidDocs as $paidDoc)
        @if(($paidDoc->stockDocument?->items ?? collect())->isNotEmpty())
        <table class="items">
            <thead><tr>
                <th class="c" style="width:40px">#</th>
                <th>สินค้า ({{ $paidDoc->doc_number }})</th>
                <th class="r" style="width:90px">จำนวน</th>
                <th class="r" style="width:100px">ราคา/หน่วย</th>
                <th class="r" style="width:120px">จำนวนเงิน</th>
            </tr></thead>
            <tbody>
                @foreach($paidDoc->stockDocument->items as $n => $item)
                <tr>
                    <td class="c">{{ $n + 1 }}</td>
                    <td>{{ $item->product?->name_th }}</td>
                    <td class="r">{{ number_format((float) $item->qty, 2) }} {{ $item->product?->baseUnit?->name }}</td>
                    <td class="r">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="r">{{ number_format((float) $item->qty * (float) $item->unit_price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    @endforeach

    <div class="totals">
        <div class="baht-text">({{ \App\Support\ThaiBaht::text((float) $payment->total_amount) }})</div>
        <div class="sum">
            <div class="sum-row grand"><span>{{ $isReceipt ? 'รวมรับชำระทั้งสิ้น' : 'รวมจ่ายชำระทั้งสิ้น' }}</span><span>{{ number_format($payment->total_amount, 2) }} บาท</span></div>
        </div>
    </div>

    <div class="signs">
        <div class="sign"><div class="line"></div>{{ $isReceipt ? 'ผู้ชำระเงิน' : 'ผู้รับเงิน' }} / วันที่</div>
        <div class="sign">
            <div class="line"></div>
            {{ $isReceipt ? 'ผู้รับเงิน' : 'ผู้จ่ายเงิน' }}
            @if($payment->salesman)
                ({{ $payment->salesman->name }})
            @endif
            / วันที่
        </div>
        <div class="sign"><div class="line"></div>ผู้มีอำนาจลงนาม / วันที่</div>
    </div>
@endsection
