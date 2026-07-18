<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document->doc_number }}</title>
    <style>
        @page { size: A5 landscape; margin: 8mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sarabun', 'Segoe UI', 'Tahoma', sans-serif; font-size: 12px; color: #111; background: #e5e7eb; }
        .no-print { background: #0f172a; padding: 10px 20px; display: flex; gap: 12px; align-items: center; }
        .no-print button { background: #10b981; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .no-print a { color: #94a3b8; font-size: 13px; text-decoration: none; }
        .page { width: 210mm; min-height: 140mm; margin: 12px auto; background: #fff; padding: 8mm; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
        .doc-head { display: flex; gap: 6mm; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 3mm; }
        .logo-box { flex-shrink: 0; }
        .logo-box img { max-height: 16mm; max-width: 36mm; object-fit: contain; }
        .logo-text { border: 2px solid #dc2626; color: #dc2626; font-weight: 900; padding: 2mm 4mm; border-radius: 2mm; font-size: 14px; text-align: center; line-height: 1.2; }
        .company { flex: 1; }
        .company .name { font-size: 14px; font-weight: 700; }
        .company .sub { font-size: 11px; color: #333; }
        .doc-title { text-align: center; font-size: 16px; font-weight: 900; }
        .doc-meta { text-align: right; font-size: 11.5px; white-space: nowrap; }
        .doc-meta b { display: inline-block; min-width: 14mm; }
        .info-row { display: flex; gap: 4mm; margin: 3mm 0; }
        .info-box { border: 1.5px solid #111; border-radius: 4mm; padding: 2mm 4mm; font-size: 11.5px; }
        .info-box.left { width: 60mm; flex-shrink: 0; }
        .info-box.right { flex: 1; }
        .info-box div { margin: 0.7mm 0; }
        .info-box b { display: inline-block; min-width: 20mm; font-weight: 700; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 1mm; }
        table.items th { border-top: 1.5px solid #111; border-bottom: 1.5px solid #111; padding: 1.5mm 2mm; font-size: 11.5px; text-align: left; }
        table.items td { padding: 1.5mm 2mm; font-size: 11.5px; }
        .text-end { text-align: right; }
        .qc-row { display: flex; gap: 6mm; align-items: flex-start; border-top: 1.5px solid #111; padding-top: 2.5mm; margin-top: 2mm; }
        .qc-left { flex: 1; font-size: 11.5px; }
        .qc-item { display: inline-flex; align-items: center; gap: 2mm; margin-right: 6mm; }
        .qc-box { width: 4.5mm; height: 4.5mm; border: 1.5px solid #111; display: inline-block; }
        .totals { width: 52mm; font-size: 12px; }
        .totals .row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5mm; }
        .totals .val { border: 1.5px solid #111; padding: 1mm 2.5mm; min-width: 24mm; text-align: right; font-weight: 700; }
        .sign-row { display: flex; justify-content: space-around; margin-top: 12mm; }
        .sign { text-align: center; font-size: 11.5px; width: 44mm; }
        .sign .line { border-top: 1.5px dashed #111; margin-bottom: 1.5mm; }
        @media print { body { background: #fff; } .no-print { display: none; } .page { margin: 0; box-shadow: none; width: auto; } }
        @include('documents.partials.print-theme')
    </style>
</head>
<body>
<div class="no-print">
    <span style="color:#f1f5f9;font-size:13px">{{ $document->doc_number }}</span>
    <button onclick="window.print()">🖨️ พิมพ์ (A5)</button>
    <a href="javascript:history.back()">← กลับ</a>
</div>

<div class="page">
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

    <div class="sign-row">
        <div class="sign"><div class="line"></div>ผู้รับสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ส่งสินค้า</div>
        <div class="sign"><div class="line"></div>ผู้ขาย</div>
    </div>
</div>
</body>
</html>
