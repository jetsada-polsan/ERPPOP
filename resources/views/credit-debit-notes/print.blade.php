@extends('layouts.print')
@section('title', ($isCredit ? 'ใบลดหนี้ ' : 'ใบเพิ่มหนี้ ').$note->doc_number)

@section('doc-title')
    <h1>{{ $isCredit ? 'ใบลดหนี้' : 'ใบเพิ่มหนี้' }}</h1>
    <div class="muted">{{ $isCredit ? 'CREDIT NOTE' : 'DEBIT NOTE' }} &middot; ใบกำกับภาษี</div>
@endsection

@section('body')
    <div class="meta-grid">
        <div class="box">
            <div class="box-label">ลูกค้า</div>
            <div style="font-weight:800">
                {{ $note->customer?->name_th ?? '-' }}
                @if($note->customer?->tax_branch)({{ $note->customer->tax_branch }})@else (สำนักงานใหญ่) @endif
            </div>
            <div class="muted">
                @if($addr = $note->customer?->addresses?->first()){{ $addr->address_line }}<br>@endif
                @if($note->customer?->tax_id)เลขประจำตัวผู้เสียภาษี {{ $note->customer->tax_id }}@endif
            </div>
        </div>
        <div class="box">
            <div class="meta-row"><span class="muted">เลขที่</span><b>{{ $note->doc_number }}</b></div>
            <div class="meta-row"><span class="muted">วันที่</span><b>{{ $note->doc_date->thaiDate() }}</b></div>
            <div class="meta-row"><span class="muted">อ้างอิงใบกำกับเดิม</span><b>{{ $note->reference ?? '-' }}</b></div>
            <div class="meta-row"><span class="muted">สาขา</span><b>{{ $note->branch?->name_th ?? '-' }}</b></div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:40px">#</th>
                <th>รายละเอียด / เหตุผล{{ $isCredit ? 'การลดหนี้' : 'การเพิ่มหนี้' }}</th>
                <th class="r" style="width:130px">จำนวนเงิน</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="c">1</td>
                <td>
                    {{ $isCredit ? 'ลดหนี้ตามใบกำกับภาษีเลขที่' : 'เพิ่มหนี้ตามใบกำกับภาษีเลขที่' }} {{ $note->reference ?? '-' }}
                    @if($note->remark)<br><span class="muted">เหตุผล: {{ $note->remark }}</span>@endif
                </td>
                <td class="r">{{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <div class="baht-text">({{ $totalText }})</div>
        <div class="sum">
            <div class="sum-row"><span>{{ $isCredit ? 'มูลค่าที่ลด (ไม่รวมภาษี)' : 'มูลค่าที่เพิ่ม (ไม่รวมภาษี)' }}</span><span>{{ number_format($baseAmount, 2) }} บาท</span></div>
            <div class="sum-row"><span>ภาษีมูลค่าเพิ่ม {{ rtrim(rtrim(number_format($vatRate, 2), '0'), '.') }}%</span><span>{{ number_format($vatAmount, 2) }} บาท</span></div>
            <div class="sum-row grand"><span>{{ $isCredit ? 'รวมมูลค่าที่ลดทั้งสิ้น' : 'รวมมูลค่าที่เพิ่มทั้งสิ้น' }}</span><span>{{ number_format($total, 2) }} บาท</span></div>
        </div>
    </div>

    <div class="signs">
        <div class="sign"><div class="line"></div>ผู้รับเอกสาร / วันที่</div>
        <div class="sign"><div class="line"></div>ผู้จัดทำ / วันที่</div>
        <div class="sign"><div class="line"></div>ผู้มีอำนาจลงนาม / วันที่</div>
    </div>
@endsection
