@once
{{-- ใบส่งของ A5 ต้องจบในแผ่นเดียว: เดิมขอบ 8 มม. + แถว Final QC ซ้ำ ทำให้ 6 รายการขึ้นไปล้นลายเซ็นไปแผ่นใหม่ --}}
<style>
    @page { size: A5 landscape; margin: 5mm 6mm; }
    @media print {
        .page.delivery-note-page { padding: 1.5mm 2mm !important; }
        .page.delivery-note-page .doc-head { margin-bottom: 4px !important; padding-bottom: 1.5mm !important; }
        .page.delivery-note-page .logo-box img { max-height: 12mm !important; }
        .page.delivery-note-page .info-row { margin: 1.5mm 0 !important; }
        .page.delivery-note-page table.items th,
        .page.delivery-note-page table.items td { padding: .6mm 1.2mm !important; line-height: 1.25 !important; }
        .page.delivery-note-page .sign-row { margin-top: 6mm !important; break-inside: avoid; page-break-inside: avoid; }
    }
    /* กล่องยอดรวม: เดิมเรียงแนวนอนจนเกินกรอบ 50 มม. ตัด "ส่วนลด/รวมสุทธิ" หาย — ให้เรียงลงทีละบรรทัด */
    .page.delivery-note-page .totals { display: block !important; width: 58mm !important; max-width: 58mm !important; }
    .page.delivery-note-page .totals .row { display: flex !important; justify-content: space-between !important; align-items: center !important; }
    .page.delivery-note-page .totals .val { font-size: 9.5px !important; font-weight: 700 !important; text-align: right !important; }
    .page.delivery-note-page .qc-row { grid-template-columns: minmax(0, 1fr) 58mm !important; }
    /* ตัวหนังสือเดิม 8–8.5px (พิมพ์ออก ~6pt) เล็กเกินอ่าน — ขยายเป็น ~8pt */
    .page.delivery-note-page .company .name { font-size: 14px !important; }
    .page.delivery-note-page .company .sub,
    .page.delivery-note-page .doc-meta { font-size: 10px !important; }
    .page.delivery-note-page .info-box { font-size: 10.5px !important; }
    .page.delivery-note-page table.items th,
    .page.delivery-note-page table.items td { font-size: 10.5px !important; }
    .page.delivery-note-page .qc-left,
    .page.delivery-note-page .qc-item { font-size: 10px !important; }
    .page.delivery-note-page .totals { font-size: 10px !important; }
    .page.delivery-note-page .totals .val { font-size: 11px !important; }
    .page.delivery-note-page .sign { font-size: 10px !important; }
    /* กล่องยอดรวมกินที่แนวตั้งมาก — บีบระยะห่างให้ตัวหนังสือใหญ่ขึ้นได้โดยยังจบในแผ่นเดียว */
    .page.delivery-note-page .totals .row { margin-bottom: .4mm !important; }
    .page.delivery-note-page .totals .val { padding: .2mm 1.5mm !important; line-height: 1.25 !important; }
    .page.delivery-note-page .qc-left > div:first-child { margin-bottom: 1.2mm !important; }
    .page.delivery-note-page .qc-row { margin-top: 1mm !important; padding-top: 1mm !important; }
</style>
@endonce
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
                <th style="width:24mm">รหัสสินค้า</th>
                <th>ชนิดสินค้า</th>
                <th style="width:14mm">หน่วยนับ</th>
                <th class="text-end" style="width:18mm">ราคาขาย/บาท</th>
                <th class="text-end" style="width:30mm; white-space:nowrap">จำนวน</th>
                <th class="text-end" style="width:20mm">ราคารวม/บาท</th>
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
                <td class="text-end" style="white-space:nowrap">{{-- ฝ่ายขาย 13 พ.ย.: "1 ถุง (10 กิโลกรัม)" ห้ามตกบรรทัด --}}<x-unit-qty :item="$item" /></td>
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

    <div class="sign-row">
        <div class="sign"><div class="line"></div>ผู้รับสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ส่งสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ขาย</div>
    </div>
</div>
