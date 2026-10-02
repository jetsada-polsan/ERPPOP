<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>พิมพ์ป้ายราคา - {{ $template->name }}</title>
    <script src="https://unpkg.com/bwip-js@4.5.1/dist/bwip-js-min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif; margin: 0; padding: 10mm; background: #eee; }
        .toolbar { margin-bottom: 10mm; }
        .toolbar button {
            padding: 10px 18px; border: none; border-radius: 8px; background: #0f172a; color: #fff;
            font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .sheet {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4mm;
        }
        .label {
            border: 1px dashed #94a3b8;
            border-radius: 6px;
            padding: 4mm;
            min-height: 32mm;
            background: #fff;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .label-name {
            font-size: 12px; font-weight: 700; color: #0f172a;
            line-height: 1.25;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .label-sku { font-size: 10px; color: #475569; letter-spacing: .5px; margin-top: 2mm; font-family: 'Courier New', monospace; }
        .label-price { font-size: 22px; font-weight: 900; color: #b91c1c; text-align: right; margin-top: 2mm; }
        .label-price.no-price { font-size: 12px; color: #94a3b8; font-weight: 700; }
        .label-code { width: 100%; height: 14mm; margin-top: 2mm; }
        .label-code-text { font-size: 8px; text-align: center; color: #334155; font-family: monospace; }
        .label-qr { width: 18mm; height: 18mm; margin-left: auto; display: block; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .label { border: 1px solid #cbd5e1; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="toolbar">
        <button onclick="window.print()">พิมพ์ป้ายราคา ({{ count($labels) }} ป้าย)</button>
    </div>
    <div class="sheet">
        @foreach($labels as $index => $label)
            @php($barcode = $label['product']->barcodes->first()?->barcode ?: $label['product']->sku_code)
            @php($barcodeType = $label['product']->barcodes->first()?->barcode_type ?: 'CODE128')
            <div class="label">
                <div>
                    <div class="label-name">{{ $label['product']->name_th }}</div>
                    <div class="label-sku">{{ $label['product']->sku_code }}</div>
                    <canvas class="label-code" id="barcode-{{ $index }}"></canvas>
                    <div class="label-code-text">{{ $barcode }}</div>
                    <canvas class="label-qr" id="qr-{{ $index }}"></canvas>
                </div>
                @if($label['price'] !== null)
                    <div class="label-price">฿{{ number_format($label['price'], 2) }}</div>
                @else
                    <div class="label-price no-price">ไม่แสดงราคา</div>
                @endif
            </div>
        @endforeach
    </div>
    <script>
        document.querySelectorAll('.label-code').forEach((canvas) => {
            const index = canvas.id.replace('barcode-', '');
            const value = @json(collect($labels)->map(fn ($label) => $label['product']->barcodes->first()?->barcode ?: $label['product']->sku_code)->values());
            const type = @json(collect($labels)->map(fn ($label) => match ($label['product']->barcodes->first()?->barcode_type) {
                'EAN13_STANDARD' => 'ean13', 'CODE39' => 'code39', default => 'code128',
            })->values());
            try { bwipjs.toCanvas(canvas, { bcid: type[index], text: value[index], scale: 2, height: 10, includetext: false }); } catch (e) {}
            try { bwipjs.toCanvas(document.getElementById('qr-' + index), { bcid: 'qrcode', text: value[index], scale: 3, padding: 0 }); } catch (e) {}
        });
    </script>
</body>
</html>
