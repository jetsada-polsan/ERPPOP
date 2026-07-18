{{-- Layout พิมพ์เอกสาร A4 มาตรฐาน: หัวบริษัทครบ (โลโก้/ชื่อ/ที่อยู่/เลขภาษี) ทุกใบ
     เพื่อความน่าเชื่อถือ - เอกสารใหม่ที่ต้องพิมพ์ให้ลูกค้า/คู่ค้า extend ตัวนี้ --}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>{{ str_replace('POPSTAR ERP', 'JET ERP', trim($__env->yieldContent('title', 'เอกสาร - JET ERP'))) }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Thai', 'Segoe UI', 'Leelawadee UI', sans-serif; font-size: 13px; color: #1a2b3a; background: #eef4f9; }
        .sheet { width: 210mm; min-height: 285mm; margin: 12px auto; background: #fff; padding: 14mm 13mm; position: relative; box-shadow: 0 8px 30px rgba(15,23,42,.12); }
        .head { display: flex; justify-content: space-between; gap: 16px; }
        .co-name { font-size: 16px; font-weight: 800; }
        .muted { color: #55708a; font-size: 12px; line-height: 1.65; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 22px; font-weight: 900; color: #1585c0; letter-spacing: .01em; }
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
        @yield('toolbar-extra')
        <button class="primary" onclick="window.print()">🖨 พิมพ์</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div style="display:flex; gap:12px">
                @if($printLogo = \App\Models\AppSetting::logoUrl())
                    <img src="{{ $printLogo }}" alt="" style="max-height:58px;max-width:140px;object-fit:contain">
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
            <div class="doc-title">@yield('doc-title')</div>
        </div>

        @yield('body')

        @if($printFootNote = \App\Models\AppSetting::get('doc_footer_note'))
            <div class="footnote">หมายเหตุ: {{ $printFootNote }}</div>
        @endif
        <div class="footnote">เอกสารออกโดยระบบ JET ERP &middot; พิมพ์เมื่อ {{ now()->thaiDate(true) }} น.</div>
    </div>
</body>
</html>
