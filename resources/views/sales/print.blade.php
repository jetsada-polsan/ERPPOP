<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบกำกับภาษี / ใบส่งของ {{ $sale->doc_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Noto Sans Thai', sans-serif;
            font-size: 12.5px; color: #1e293b; background: #fff;
        }
        .page {
            width: 210mm; min-height: 297mm;
            padding: 14mm 16mm; margin: 0 auto;
            background: #fff;
        }

        /* Header */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #0f172a; padding-bottom: 14px; }
        .company-block .logo { font-size: 26px; font-weight: 900; letter-spacing: -1px; color: #0f172a; }
        .company-block .logo span { color: #10b981; }
        .company-block .company-name { font-size: 13px; font-weight: 700; margin-top: 4px; }
        .company-block .company-info { font-size: 11px; color: #64748b; margin-top: 2px; line-height: 1.5; }

        .doc-title-block { text-align: right; }
        .doc-type { font-size: 18px; font-weight: 800; color: #0f172a; }
        .doc-type-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
        .doc-meta { margin-top: 8px; font-size: 12px; }
        .doc-meta table { margin-left: auto; }
        .doc-meta td { padding: 1px 6px; }
        .doc-meta .label { color: #64748b; }
        .doc-meta .value { font-weight: 700; }

        /* Customer info */
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .party-block { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; }
        .party-block h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-bottom: 6px; }
        .party-block .party-name { font-size: 13px; font-weight: 700; }
        .party-block .party-info { font-size: 11px; color: #64748b; margin-top: 3px; line-height: 1.5; }

        /* Table */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        table.items thead th { background: #0f172a; color: #fff; padding: 8px 10px; font-size: 11.5px; font-weight: 700; }
        table.items thead th.right { text-align: right; }
        table.items tbody td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 12px; vertical-align: top; }
        table.items tbody tr:nth-child(even) td { background: #f8fafc; }
        table.items tbody td.right { text-align: right; }
        table.items tbody td.mono { font-variant-numeric: tabular-nums; }

        /* Totals */
        .totals-block { display: flex; justify-content: flex-end; margin-top: 12px; }
        .totals-table { min-width: 280px; }
        .totals-table tr td { padding: 4px 10px; font-size: 12.5px; }
        .totals-table .label-cell { color: #64748b; }
        .totals-table .value-cell { text-align: right; font-variant-numeric: tabular-nums; }
        .totals-table .grand-row td { font-size: 16px; font-weight: 800; border-top: 2px solid #0f172a; padding-top: 8px; }
        .totals-table .grand-row .value-cell { color: #10b981; }

        /* Signature */
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 30px; }
        .sig-block { text-align: center; }
        .sig-line { border-top: 1px solid #cbd5e1; margin: 40px 20px 4px; }
        .sig-label { font-size: 11px; color: #64748b; }

        /* Footer */
        .doc-footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 10.5px; color: #94a3b8; text-align: center; }

        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .page { padding: 10mm 14mm; }
        }
        @include('documents.partials.print-theme')
    </style>
</head>
<body>

{{-- Print / Back buttons --}}
<div class="no-print" style="background:#0f172a;padding:12px 24px;display:flex;gap:12px;align-items:center">
    <span style="color:#f1f5f9;font-family:sans-serif;font-size:13px">{{ $sale->doc_number }}</span>
    <button onclick="window.print()" style="background:#10b981;color:#fff;border:none;padding:8px 20px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:sans-serif">
        🖨️ พิมพ์
    </button>
    <a href="{{ route('sales.show', $sale) }}" style="color:#94a3b8;font-family:sans-serif;font-size:13px;text-decoration:none">← กลับ</a>
</div>

<div class="page">
    {{-- Header --}}
    <div class="doc-header">
        <div class="company-block">
            @if($docLogo = \App\Models\AppSetting::logoUrl())
                <img src="{{ $docLogo }}" alt="logo" style="max-height:56px;max-width:150px;object-fit:contain;margin-bottom:4px">
            @else
                <div class="logo">pop<span>star</span></div>
            @endif
            <div class="company-name">{{ \App\Models\AppSetting::company('name_th') }}</div>
            <div class="company-info">
                สาขา: {{ $sale->branch->name_th }}<br>
                {{ \App\Models\AppSetting::company('address') }}<br>
                เลขประจำตัวผู้เสียภาษี: {{ \App\Models\AppSetting::company('tax_id') }}@if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif
            </div>
        </div>
        <div class="doc-title-block">
            <div class="doc-type">ใบกำกับภาษี / ใบส่งของ</div>
            <div class="doc-type-sub">TAX INVOICE / DELIVERY NOTE</div>
            <div class="doc-meta">
                <table>
                    <tr><td class="label">เลขที่:</td><td class="value">{{ $sale->doc_number }}</td></tr>
                    <tr><td class="label">วันที่:</td><td class="value">{{ $sale->doc_date->thaiDate() }}</td></tr>
                    @if($sale->reference)
                    <tr><td class="label">อ้างอิง:</td><td class="value">{{ $sale->reference }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Customer / Branch --}}
    <div class="parties">
        <div class="party-block">
            <h4>ผู้ซื้อ / BILL TO</h4>
            <div class="party-name">{{ $sale->customer->name_th }}</div>
            <div class="party-info">
                รหัสลูกค้า: {{ $sale->customer->code }}<br>
                @if($sale->salesman)พนักงานขาย: {{ $sale->salesman->name }}@endif
            </div>
        </div>
        <div class="party-block">
            <h4>สาขาที่ขาย / BRANCH</h4>
            <div class="party-name">{{ $sale->branch->name_th }}</div>
            <div class="party-info">
                รหัสสาขา: {{ $sale->branch->code }}<br>
                {{ $sale->doc_date->thaiDateFull() }}
            </div>
        </div>
    </div>

    {{-- Items --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:50px">ลำดับ</th>
                <th style="width:80px">รหัส</th>
                <th>ชื่อสินค้า</th>
                <th class="right" style="width:80px">จำนวน</th>
                <th class="right" style="width:110px">ราคา/หน่วย</th>
                <th class="right" style="width:120px">จำนวนเงิน</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->stockDocument->items as $i => $item)
            <tr>
                <td style="text-align:center">{{ $i + 1 }}</td>
                <td class="mono">{{ $item->product->sku_code }}</td>
                <td>{{ $item->product->name_th }}</td>
                <td class="right mono">{{ number_format($item->qty, 2) }}</td>
                <td class="right mono">{{ number_format($item->unit_price, 2) }}</td>
                <td class="right mono">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
            </tr>
            @endforeach
            {{-- Pad rows to min 10 --}}
            @for($p = $sale->stockDocument->items->count(); $p < 10; $p++)
            <tr><td colspan="6" style="height:26px"></td></tr>
            @endfor
        </tbody>
    </table>

    {{-- Totals --}}
    <div class="totals-block">
        <table class="totals-table">
            <tr>
                <td class="label-cell">มูลค่าสินค้า</td>
                <td class="value-cell">{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="label-cell">ส่วนลด</td>
                <td class="value-cell">0.00</td>
            </tr>
            <tr>
                <td class="label-cell">ภาษีมูลค่าเพิ่ม 7%</td>
                <td class="value-cell">{{ number_format($sale->total_amount * 0.07 / 1.07, 2) }}</td>
            </tr>
            <tr class="grand-row">
                <td class="label-cell">รวมทั้งสิ้น</td>
                <td class="value-cell">฿{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- AR info --}}
    @if($sale->openItem)
    <div style="margin-top:16px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px">
        <span class="fw" style="font-weight:700">เงื่อนไขการชำระ:</span>
        ค้างชำระ ฿{{ number_format($sale->openItem->balance_amount, 2) }} &nbsp;&middot;&nbsp;
        ครบกำหนด {{ $sale->openItem->due_date->thaiDate() }}
    </div>
    @endif

    {{-- Signatures --}}
    <div class="signatures">
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">ผู้รับของ / Received by</div></div>
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">ผู้ส่งของ / Delivered by</div></div>
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">ผู้อนุมัติ / Approved by</div></div>
    </div>

    <div class="doc-footer">
        เอกสารนี้ออกโดยระบบ JET ERP &nbsp;&middot;&nbsp; พิมพ์เมื่อ {{ now()->thaiDate(true) }}
    </div>
</div>
</body>
</html>
