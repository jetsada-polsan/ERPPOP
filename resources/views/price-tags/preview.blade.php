<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>พิมพ์ป้ายราคา - {{ $template->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', 'Tahoma', sans-serif; margin: 0; padding: 10mm; background: #eee; }
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
        @foreach($labels as $label)
            <div class="label">
                <div>
                    <div class="label-name">{{ $label['product']->name_th }}</div>
                    <div class="label-sku">{{ $label['product']->sku_code }}</div>
                </div>
                @if($label['price'] !== null)
                    <div class="label-price">฿{{ number_format($label['price'], 2) }}</div>
                @else
                    <div class="label-price no-price">ไม่แสดงราคา</div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
