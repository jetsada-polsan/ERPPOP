<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>พิมพ์ป้ายสินค้า (Packing Labels) - {{ $document->doc_number }}</title>
    <style>
        * {
            box-sizing: border-box;
        }
        @page {
            size: 80mm 50mm;
            margin: 2mm;
        }
        body {
            margin: 0;
            padding: 10mm;
            background: #e2e8f0;
            font-family: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif;
            color: #0f172a;
        }
        .toolbar {
            max-width: 80mm;
            margin: 0 auto 6mm auto;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }
        .btn-print {
            border: 0;
            background: #0f172a;
            color: #fff;
            padding: 8px 16px;
            font-weight: 700;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-print:hover {
            background: #1e293b;
        }
        .btn-close {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #475569;
            padding: 8px 14px;
            font-size: 13px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
        }
        .sheet {
            display: flex;
            flex-direction: column;
            gap: 6mm;
            align-items: center;
        }
        .label-page {
            width: 80mm;
            height: 50mm;
            background: #fff;
            border: 1px dashed #94a3b8;
            border-radius: 4px;
            padding: 3mm 4mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            page-break-inside: avoid;
        }
        .label-head {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1.5mm;
            margin-bottom: 1.5mm;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .label-company {
            font-size: 8pt;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #0f172a;
            text-transform: uppercase;
        }
        .label-branch {
            font-size: 6.5pt;
            color: #64748b;
        }
        .label-meta-doc {
            font-size: 6.5pt;
            color: #64748b;
            text-align: right;
            font-family: monospace;
        }
        .label-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .label-product-name {
            font-size: 11pt;
            font-weight: 800;
            line-height: 1.2;
            color: #0f172a;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .label-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2mm;
            margin-top: 1.5mm;
            padding: 1.5mm 0;
            border-top: 1px dashed #cbd5e1;
            border-bottom: 1px dashed #cbd5e1;
        }
        .detail-item {
            font-size: 7.5pt;
        }
        .detail-label {
            color: #64748b;
            font-size: 6.5pt;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .detail-value {
            font-weight: 700;
            color: #0f172a;
        }
        .detail-weight {
            font-size: 9pt;
            font-weight: 800;
            color: #047857;
        }
        .label-barcode-wrap {
            text-align: center;
            margin-top: 1.5mm;
        }
        .label-barcode-wrap svg {
            width: 100%;
            max-height: 13mm;
            display: block;
            margin: 0 auto;
        }
        @media print {
            @page {
                size: 80mm 50mm;
                margin: 2mm;
            }
            body {
                padding: 0;
                background: #fff;
            }
            .toolbar {
                display: none !important;
            }
            .sheet {
                gap: 0;
                display: block;
            }
            .label-page {
                border: 0;
                box-shadow: none;
                border-radius: 0;
                page-break-after: always;
                break-after: page;
                margin: 0;
            }
            .label-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button type="button" class="btn-print" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/></svg>
            พิมพ์ {{ $outputItems->count() }} ป้าย
        </button>
        <button type="button" class="btn-close" onclick="window.close()">ปิดหน้าต่าง</button>
    </div>

    <div class="sheet">
        @forelse($outputItems as $item)
            @php
                $product = $item->product;
                $lotNumber = $item->lot_no ?: ($item->lot_number ?: ($item->sourceStockLot?->lot_number ?: $document->doc_number));
                $barcodeText = $product->sku_code ?: $document->doc_number;
                $unitName = $product->baseUnit?->cleanName() ?: 'กก.';
            @endphp
            <div class="label-page">
                <div class="label-head">
                    <div>
                        <div class="label-company">MPM Food Group</div>
                        <div class="label-branch">{{ $document->branch->name_th }}</div>
                    </div>
                    <div class="label-meta-doc">
                        <div>{{ $document->doc_number }}</div>
                        <div>{{ $document->doc_date?->thaiDate() ?? now()->thaiDate() }}</div>
                    </div>
                </div>

                <div class="label-body">
                    <div class="label-product-name">{{ $product->name_th }}</div>

                    <div class="label-details">
                        <div class="detail-item">
                            <div class="detail-label">รหัสสินค้า (SKU)</div>
                            <div class="detail-value font-monospace">{{ $product->sku_code }}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">น้ำหนักจริง (Weight)</div>
                            <div class="detail-weight">{{ number_format($item->qty, 3) }} {{ $unitName }}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">รุ่นผลิต / เลข Lot</div>
                            <div class="detail-value font-monospace">{{ $lotNumber }}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">คลังรับเข้า</div>
                            <div class="detail-value">{{ $item->warehouseLocation?->name ?? 'คลังหลัก' }}</div>
                        </div>
                    </div>
                </div>

                <div class="label-barcode-wrap">
                    {!! \App\Support\Code128::svg($barcodeText, 34) !!}
                </div>
            </div>
        @empty
            <div class="label-page" style="justify-content:center; text-align:center; color:#64748b;">
                <div>ไม่พบรายการผลผลิตในใบแปรรูปสินค้านี้</div>
            </div>
        @endforelse
    </div>
</body>
</html>
