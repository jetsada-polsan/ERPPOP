<div class="page delivery-note-page">
    @if($copyLabel)<div style="text-align:right;font-size:10px;font-weight:700">{{ $copyLabel }}</div>@endif
    <div class="doc-head">
        <div class="logo-box">
            @if($logo = \App\Models\AppSetting::logoUrl())
                <img src="{{ $logo }}" alt="logo">
            @else
                <div class="logo-text">POP STAR<br>SHOP</div>
            @endif
        </div>
        <div class="company">
            <div class="doc-title">{{ $title }}</div>
            <div class="name">{{ \App\Models\AppSetting::company('name_th') }}</div>
            <div class="sub">{{ \App\Models\AppSetting::company('address') }}</div>
            <div class="sub">
                เลขประจำตัวผู้เสียภาษี {{ \App\Models\AppSetting::company('tax_id') ?: '-' }}@if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif
            </div>
        </div>
        <div class="doc-meta">
            <div><b>เลขที่</b> {{ $document->doc_number }}</div>
            <div><b>วันที่</b> {{ $document->doc_date->thaiDate() }}</div>
            <div><b>พิมพ์</b> {{ now()->thaiDate(true) }}</div>
        </div>
    </div>

    <div class="info-row">
        <div class="info-box left">
            <div><b>การชำระเงิน</b> {{ $paymentLabel }}</div>
            <div><b>พนักงาน</b> {{ $document->salesman?->name ?? '-' }}</div>
            <div><b>สาขา</b> {{ $document->branch->name_th }}</div>
        </div>
        <div class="info-box right">
            <div>
                <b>รหัสลูกค้า</b> {{ $document->customer?->code ?? '-' }}
                &nbsp;&nbsp;<b>ชื่อลูกค้า</b> {{ $document->customer?->name_th ?? 'ลูกค้าทั่วไป' }}
            </div>
            <div><b>ที่อยู่</b> {{ $document->customer?->addresses?->first()?->address_line ?? '-' }}</div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:10mm">ลำดับ</th>
                <th style="width:30mm">รหัสสินค้า</th>
                <th>ชนิดสินค้า</th>
                <th style="width:18mm">หน่วยนับ</th>
                <th class="text-end" style="width:24mm">ราคาขาย/บาท</th>
                <th class="text-end" style="width:16mm">จำนวน</th>
                <th class="text-end" style="width:24mm">ราคารวม/บาท</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->stockDocument->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product->barcodes->first()?->barcode ?? $item->product->sku_code }}</td>
                <td>{{ $item->product->name_th }}</td>
                <td>{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                <td class="text-end">{{ number_format($item->unit_price ?? 0, 2) }}</td>
                <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                <td class="text-end">{{ number_format(($item->unit_price ?? 0) * $item->qty, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="qc-row">
        <div class="qc-left">
            <div style="margin-bottom:2.5mm">
                <b>Final QC</b>&nbsp;&nbsp;
                <span class="qc-item"><span class="qc-box"></span> ได้รับจำนวนตามที่สั่ง</span>
                <span class="qc-item"><span class="qc-box"></span> ชนิดสินค้าตรงตามที่สั่ง</span>
                <span class="qc-item"><span class="qc-box"></span> บรรจุภัณฑ์ไม่ชำรุด</span>
            </div>
            <div><b>หมายเหตุ</b> {{ $document->remark ?? '' }}</div>
        </div>
        <div class="totals">
            <div class="row"><span>ราคารวมสินค้า</span><span class="val">{{ number_format($document->total_amount, 2) }}</span></div>
            <div class="row"><span>ส่วนลด</span><span class="val">0.00</span></div>
            <div class="row"><span>รวมสุทธิ</span><span class="val">{{ number_format($document->total_amount, 2) }}</span></div>
        </div>
    </div>

    @if($typeCode === 'BOOKING')
    <div style="display:flex;gap:6mm;margin:2mm 0;font-size:10px"><b>Final QC</b><span>☐ ได้รับจำนวนตามที่สั่ง</span><span>☐ ชนิดสินค้าตรงตามที่สั่ง</span><span>☐ บรรจุภัณฑ์ไม่ชำรุด</span></div>
    @endif
    <div class="sign-row">
        <div class="sign"><div class="line"></div>ผู้รับสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ส่งสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ขาย</div>
    </div>
</div>
