<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>พิมพ์ใบส่งของรวม ({{ count($printable) }} ใบ)</title>
    <style>
        @page { size: A5 landscape; margin: 8mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sarabun', 'Segoe UI', 'Tahoma', sans-serif; font-size: 12px; color: #111; background: #e5e7eb; }
        .no-print { background: #0f172a; padding: 10px 20px; display: flex; gap: 14px; align-items: center; }
        .no-print button { background: #10b981; color: #fff; border: none; padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .no-print button:hover { background: #059669; }
        .no-print a { color: #94a3b8; font-size: 13px; text-decoration: none; }
        .no-print a:hover { color: #f1f5f9; }
        .batch-badge { background: #334155; color: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
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
        @media print { body { background: #fff; } .no-print { display: none !important; } .page { margin: 0; box-shadow: none; width: auto; } }
        @include('documents.partials.print-theme')

        /* The shared print theme defaults .page to A4. Keep this slip A5 landscape on screen and paper. */
        .page.delivery-note-page .doc-head { gap: 4mm !important; margin-bottom: 8px !important; padding-bottom: 2mm !important; }
        .page.delivery-note-page .company .name { font-size: 12px !important; }
        .page.delivery-note-page .company .sub { font-size: 8.5px !important; line-height: 1.25 !important; }
        .page.delivery-note-page .doc-meta { font-size: 8.5px !important; }
        .page.delivery-note-page .doc-meta b { min-width: 11mm !important; }
        .page.delivery-note-page .info-row { display: grid !important; grid-template-columns: 55mm minmax(0, 1fr) !important; gap: 2mm !important; margin: 2mm 0 !important; }
        .page.delivery-note-page .info-box { padding: 1.5mm 2mm !important; border-radius: 2mm !important; font-size: 8.5px !important; }
        .page.delivery-note-page .info-box.left { width: auto !important; min-width: 0 !important; }
        .page.delivery-note-page .info-box b { min-width: 16mm !important; }
        .page.delivery-note-page table.items { margin-top: 1mm !important; }
        .page.delivery-note-page table.items th,
        .page.delivery-note-page table.items td { padding: 1mm 1.2mm !important; font-size: 8.5px !important; }
        .page.delivery-note-page .qc-row { display: grid !important; grid-template-columns: minmax(0, 1fr) 50mm !important; gap: 2mm !important; margin-top: 1.5mm !important; padding-top: 1.5mm !important; }
        .page.delivery-note-page .qc-left { font-size: 8px !important; }
        .page.delivery-note-page .qc-left { min-width: 0 !important; overflow: hidden !important; }
        .page.delivery-note-page .qc-item { gap: 1mm !important; margin-right: 1mm !important; font-size: 8px !important; }
        .page.delivery-note-page .qc-box { width: 3.5mm !important; height: 3.5mm !important; }
        .page.delivery-note-page .totals { width: 50mm !important; max-width: 50mm !important; min-width: 0 !important; overflow: hidden !important; font-size: 8px !important; }
        .page.delivery-note-page .totals .row span:first-child { min-width: 0 !important; white-space: nowrap !important; }
        .page.delivery-note-page .totals .row { gap: 1mm !important; margin-bottom: 1mm !important; }
        .page.delivery-note-page .totals .val { min-width: 17mm !important; padding: .5mm 1mm !important; }
        .page.delivery-note-page .sign-row { gap: 4mm !important; margin-top: 8mm !important; }
        .page.delivery-note-page .sign { width: auto !important; font-size: 8.5px !important; }
        @media screen {
            body:not(.layout-fixed) .page.delivery-note-page {
                width: min(210mm, calc(100vw - 32px)) !important;
                min-height: 148mm !important;
                margin: 8px auto 18px !important;
                padding: 7mm !important;
                overflow: visible !important;
                zoom: 1 !important;
            }
        }
        @media print {
            .page.delivery-note-page {
                width: auto !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 4mm !important;
                overflow: visible !important;
                zoom: 1 !important;
            }
        }
        .page + .page { page-break-before: always; }
        .skipped-banner {
            background: #fffbeb;
            border-bottom: 1px solid #fde68a;
            color: #92400e;
            padding: 10px 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .empty-box {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 12px;
            margin: 40px auto;
            max-width: 500px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            color: #64748b;
        }
    </style>
</head>
<body>
<div class="no-print">
    <span style="color:#f1f5f9;font-size:14px;font-weight:700">ใบส่งของรวม</span>
    <span class="batch-badge">จำนวน {{ count($printable) }} ใบ</span>
    <span class="batch-badge">จำนวน {{ $totalPages }} หน้า</span>
    @if(count($printable) > 0)
        <button onclick="window.print()">🖨️ พิมพ์ทั้งหมด (A5)</button>
    @endif
    <a href="javascript:history.back()">← กลับ</a>
</div>

@if(!empty($skipped))
<div class="skipped-banner no-print">
    <span style="font-size:16px;">⚠️</span>
    <span>ข้าม {{ count($skipped) }} ใบ: เลขที่ {{ implode(', ', array_column($skipped, 'doc_number')) }} (ยกเลิกแล้วหรือไม่มีรายการสินค้า)</span>
</div>
@endif

@if(empty($printable))
    <div class="empty-box">
        <div style="font-size:36px;margin-bottom:12px;">📄</div>
        <p style="font-size:15px;font-weight:600;color:#334155;margin-bottom:8px;">ไม่มีเอกสารที่สามารถพิมพ์ได้</p>
        <p style="font-size:13px;color:#64748b;margin-bottom:20px;">เอกสารที่เลือกอาจถูกยกเลิกแล้ว หรือยังไม่มีรายการสินค้า</p>
        <a href="javascript:history.back()" style="display:inline-block;padding:8px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;">← ย้อนกลับ</a>
    </div>
@else
    @foreach($printable as $entry)
        @foreach($entry['copies'] as $copyLabel)
            @include('partials.delivery-note-page', [
                'document' => $entry['document'],
                'title' => $entry['title'],
                'typeCode' => $entry['typeCode'],
                'paymentLabel' => $entry['paymentLabel'],
                'copyLabel' => $copyLabel,
            ])
        @endforeach
    @endforeach
@endif
</body>
</html>
