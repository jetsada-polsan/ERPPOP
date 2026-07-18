@extends('layouts.print')
@section('title', "ใบสั่งซื้อ {$purchaseOrder->doc_number}")

@section('doc-title')
    <h1>ใบสั่งซื้อ</h1>
    <div class="muted">PURCHASE ORDER</div>
@endsection

@section('body')
    <div class="meta-grid">
        <div class="box">
            <div class="box-label">ผู้ขาย (Supplier)</div>
            <div style="font-weight:800">{{ $purchaseOrder->supplier?->name_th ?? 'ยังไม่ระบุ' }} @if($purchaseOrder->supplier?->code)({{ $purchaseOrder->supplier->code }})@endif</div>
            <div class="muted">
                @if($purchaseOrder->supplier?->tax_id)เลขประจำตัวผู้เสียภาษี {{ $purchaseOrder->supplier->tax_id }} ({{ $purchaseOrder->supplier->tax_branch ?: 'สำนักงานใหญ่' }})@endif
            </div>
        </div>
        <div class="box">
            <div class="meta-row"><span class="muted">เลขที่</span><b>{{ $purchaseOrder->doc_number }}</b></div>
            <div class="meta-row"><span class="muted">วันที่สั่งซื้อ</span><b>{{ $purchaseOrder->doc_date->thaiDate() }}</b></div>
            <div class="meta-row"><span class="muted">ต้องการภายใน</span><b>{{ $purchaseOrder->need_by_date?->thaiDate() ?? '-' }}</b></div>
            <div class="meta-row"><span class="muted">การชำระ</span><b>{{ $purchaseOrder->is_credit ? 'เครดิต' : 'เงินสด' }}</b></div>
            <div class="meta-row"><span class="muted">ส่งสินค้าที่</span><b>{{ $purchaseOrder->branch?->name_th ?? '-' }}</b></div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="c" style="width:40px">#</th>
                <th style="width:90px">รหัส</th>
                <th>รายการสินค้า</th>
                <th class="c" style="width:60px">หน่วย</th>
                <th class="r" style="width:80px">จำนวน</th>
                <th class="r" style="width:95px">ราคาต่อหน่วย</th>
                <th class="r" style="width:100px">ยอดรวม</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchaseOrder->items as $i => $item)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $item->product->sku_code }}</td>
                <td>{{ $item->product->name_th }}</td>
                <td class="c">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                <td class="r">{{ number_format($item->qty, 2) }}</td>
                <td class="r">{{ $item->unit_price > 0 ? number_format($item->unit_price, 2) : '-' }}</td>
                <td class="r">{{ $item->unit_price > 0 ? number_format($item->qty * $item->unit_price, 2) : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="baht-text">({{ \App\Support\ThaiBaht::text((float) $purchaseOrder->total_amount) }})</div>
        <div class="sum">
            <div class="sum-row grand"><span>รวมมูลค่าสั่งซื้อทั้งสิ้น</span><span>{{ number_format($purchaseOrder->total_amount, 2) }} บาท</span></div>
        </div>
    </div>

    @if($purchaseOrder->note)<div class="footnote">เงื่อนไข/หมายเหตุ: {{ $purchaseOrder->note }}</div>@endif

    <div class="signs">
        <div class="sign"><div class="line"></div>ผู้ขอซื้อ<br><span class="muted">{{ $purchaseOrder->requester?->name ?? '' }}</span></div>
        <div class="sign"><div class="line"></div>ผู้อนุมัติ<br><span class="muted">{{ $purchaseOrder->approver?->name ?? '' }}</span></div>
        <div class="sign"><div class="line"></div>ผู้ขายรับทราบ / วันที่</div>
    </div>
@endsection
