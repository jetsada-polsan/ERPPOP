<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบกำกับภาษี {{ $document->doc_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Thai', 'Segoe UI', 'Leelawadee UI', sans-serif; font-size: 13px; color: #1a2b3a; background: #eef4f9; }
        .sheet { width: 210mm; min-height: 285mm; margin: 12px auto; background: #fff; padding: 14mm 13mm; position: relative; box-shadow: 0 8px 30px rgba(15,23,42,.12); }
        .ribbon { position: absolute; top: 0; right: 0; border-style: solid; border-width: 0 74px 74px 0; border-color: transparent #1a9bdc transparent transparent; }
        .ribbon span { position: absolute; top: 14px; right: -62px; color: #fff; font-weight: 800; font-size: 15px; }

        .head { display: flex; justify-content: space-between; gap: 16px; }
        .co-name { font-size: 16px; font-weight: 800; }
        .muted { color: #55708a; font-size: 12px; line-height: 1.65; }
        .doc-title { text-align: right; padding-right: 46px; }
        .doc-title h1 { font-size: 22px; font-weight: 900; color: #1585c0; letter-spacing: .01em; }
        .doc-title .orig { font-size: 12px; color: #55708a; font-weight: 700; }

        .meta-grid { display: grid; grid-template-columns: 1fr 250px; gap: 14px; margin-top: 14px; }
        .box { border: 1px solid #dbe8f2; border-radius: 8px; padding: 10px 12px; }
        .box-label { font-size: 11px; font-weight: 800; color: #1585c0; margin-bottom: 4px; }
        .meta-row { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; padding: 2px 0; }
        .meta-row b { font-weight: 800; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items thead th { background: #1b8ecb; color: #fff; font-size: 12px; font-weight: 800; padding: 7px 8px; text-align: left; }
        table.items thead th.r, table.items td.r { text-align: right; }
        table.items thead th.c, table.items td.c { text-align: center; }
        table.items tbody td { padding: 6px 8px; border-bottom: 1px solid #eef4f9; font-size: 12.5px; }

        .totals { display: flex; justify-content: space-between; gap: 16px; margin-top: 10px; align-items: flex-start; }
        .baht-text { border: 1px dashed #b9d2e4; border-radius: 8px; padding: 8px 12px; font-size: 12.5px; color: #2a4a63; flex: 1; }
        .sum { width: 300px; }
        .sum-row { display: flex; justify-content: space-between; padding: 4px 10px; font-size: 12.5px; }
        .sum-row.grand { background: #e3f3fc; border-radius: 8px; font-weight: 900; font-size: 14.5px; color: #12557d; padding: 8px 10px; margin-top: 4px; }

        .signs { display: flex; justify-content: space-between; gap: 14px; margin-top: 44px; }
        .sign { flex: 1; text-align: center; font-size: 12px; color: #43607a; }
        .sign .line { border-bottom: 1px dotted #7fa1bd; height: 34px; margin-bottom: 6px; }

        .footnote { margin-top: 16px; font-size: 11.5px; color: #7d97ac; }
        .toolbar { max-width: 210mm; margin: 10px auto 2px; display: flex; gap: 8px; justify-content: flex-end; }
        .toolbar a, .toolbar button { font-family: inherit; font-size: 13px; font-weight: 700; padding: 8px 16px; border-radius: 8px; border: 1px solid #cfe0ed; background: #fff; color: #2a4a63; cursor: pointer; text-decoration: none; }
        .toolbar .primary { background: #1a9bdc; border-color: #1585c0; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; }
            .toolbar { display: none; }
        }
        @include('documents.partials.print-theme')
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ url()->previous() }}">← กลับ</a>
        <a href="{{ route('documents.tax-invoice', [$document, 'copy' => $isCopy ? 0 : 1]) }}">สลับ {{ $isCopy ? 'ต้นฉบับ' : 'สำเนา' }}</a>
        <button class="primary" onclick="window.print()">🖨 พิมพ์</button>
    </div>

    <div class="sheet">
        <div class="ribbon"><span>{{ $isCopy ? '2' : '1' }}</span></div>

        {{-- 1-2: ผู้ขาย + เลขผู้เสียภาษี / 5: หัวเอกสารชัดเจน --}}
        <div class="head">
            <div style="display:flex; gap:12px">
                @if($logo = \App\Models\AppSetting::logoUrl())
                    <img src="{{ $logo }}" alt="" style="max-height:58px;max-width:140px;object-fit:contain">
                @endif
                <div>
                    <div class="co-name">{{ \App\Models\AppSetting::company('name_th') }} (สำนักงานใหญ่)</div>
                    <div class="muted">
                        {{ \App\Models\AppSetting::company('address') }}<br>
                        เลขประจำตัวผู้เสียภาษี {{ \App\Models\AppSetting::company('tax_id') }}
                        @if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif
                    </div>
                </div>
            </div>
            <div class="doc-title">
                <h1>{{ $docTitle }}</h1>
                <div class="muted">{{ $docSub }}</div>
                <div class="orig">{{ $isCopy ? 'สำเนา' : 'ต้นฉบับ' }}</div>
            </div>
        </div>

        {{-- 3-4: ผู้ซื้อ + เลขผู้เสียภาษี / 6-7: เลขที่ วันที่ เครดิต --}}
        <div class="meta-grid">
            <div class="box">
                <div class="box-label">ลูกค้า</div>
                <div style="font-weight:800">
                    {{ $document->customer?->name_th ?? 'ลูกค้าเงินสด' }}
                    @if($document->customer?->tax_branch){{ ' ('.$document->customer->tax_branch.')' }}@else (สำนักงานใหญ่) @endif
                </div>
                <div class="muted">
                    @if($addr = $document->customer?->addresses?->first()){{ $addr->address_line }}<br>@endif
                    @if($document->customer?->tax_id)เลขประจำตัวผู้เสียภาษี {{ $document->customer->tax_id }}@endif
                    @if($document->customer?->phone) &middot; โทร {{ $document->customer->phone }}@endif
                </div>
            </div>
            <div class="box">
                <div class="meta-row"><span class="muted">เลขที่</span><b>{{ $document->doc_number }}</b></div>
                <div class="meta-row"><span class="muted">วันที่</span><b>{{ $document->doc_date->thaiDate() }}</b></div>
                @if($isCredit)
                    <div class="meta-row"><span class="muted">ครบกำหนด</span><b>{{ $dueDate ? \Illuminate\Support\Carbon::parse($dueDate)->thaiDate() : '-' }}</b></div>
                @endif
                <div class="meta-row"><span class="muted">พนักงานขาย</span><b>{{ $document->salesman?->name ?? '-' }}</b></div>
                @if($document->reference)
                    <div class="meta-row"><span class="muted">อ้างอิง</span><b>{{ $document->reference }}</b></div>
                @endif
            </div>
        </div>

        {{-- 8: รายการสินค้า --}}
        <table class="items">
            <thead>
                <tr>
                    <th class="c" style="width:40px">#</th>
                    <th style="width:90px">รหัส</th>
                    <th>รายละเอียด</th>
                    <th class="c" style="width:60px">หน่วย</th>
                    <th class="r" style="width:80px">จำนวน</th>
                    <th class="r" style="width:95px">ราคาต่อหน่วย</th>
                    <th class="r" style="width:100px">ยอดรวม</th>
                </tr>
            </thead>
            <tbody>
                @foreach($document->stockDocument->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->product->sku_code }}</td>
                    <td>{{ $item->product->name_th }}</td>
                    <td class="c">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                    <td class="r">{{ number_format($item->qty, 2) }}</td>
                    <td class="r">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="r">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- 9: สรุปภาษี + จำนวนเงินตัวอักษร --}}
        <div class="totals">
            <div class="baht-text">({{ $totalText }})</div>
            <div class="sum">
                <div class="sum-row"><span>ราคาไม่รวมภาษีมูลค่าเพิ่ม</span><span>{{ number_format($baseAmount, 2) }} บาท</span></div>
                <div class="sum-row"><span>ภาษีมูลค่าเพิ่ม {{ rtrim(rtrim(number_format($vatRate, 2), '0'), '.') }}%</span><span>{{ number_format($vatAmount, 2) }} บาท</span></div>
                <div class="sum-row grand"><span>จำนวนเงินรวมทั้งสิ้น</span><span>{{ number_format($total, 2) }} บาท</span></div>
            </div>
        </div>

        <div class="signs">
            <div class="sign"><div class="line"></div>ผู้รับสินค้า / วันที่</div>
            <div class="sign"><div class="line"></div>ผู้ส่งสินค้า / วันที่</div>
            <div class="sign"><div class="line"></div>ผู้รับเงิน / วันที่</div>
            <div class="sign"><div class="line"></div>ผู้มีอำนาจลงนาม / วันที่</div>
        </div>

        @if($docNote = \App\Models\AppSetting::get('doc_footer_note'))
            <div class="footnote">หมายเหตุ: {{ $docNote }}</div>
        @endif
        <div class="footnote">เอกสารออกโดยระบบ JET ERP &middot; {{ $document->branch?->name_th }} &middot; พิมพ์เมื่อ {{ now()->thaiDate(true) }} น.</div>
    </div>
</body>
</html>
