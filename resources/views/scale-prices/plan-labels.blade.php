<!doctype html><html lang="th"><head><meta charset="utf-8"><title>ป้ายทดสอบ PLU</title><style>@page{size:A4 portrait;margin:10mm}body{font-family:sans-serif}.labels{display:flex;flex-wrap:wrap;gap:12px}article{width:240px;padding:12px;border:1px solid #888;break-inside:avoid}svg{width:230px}@media print{button{display:none}}</style></head><body>
<h1>ป้ายทดสอบ 1.000 กก.</h1><p>ใช้ทดสอบหลังตั้งราคาเครื่องชั่งตรงกับ ERP และซิงก์สินค้า POS แล้ว ราคานี้เป็นราคาตอนสร้างแผน ตรวจราคาเฉพาะสาขาก่อนขาย</p><button onclick="print()">พิมพ์ป้าย</button><div class="labels">
@foreach($labels as $r)<article><strong>{{ $r['name'] }}</strong><p>{{ $r['sku'] }} · PLU {{ $r['plu'] }}<br>1.000 กก. × {{ number_format($r['price'], 2) }} บาท/กก.</p>{!! $r['svg'] !!}</article>@endforeach
</div></body></html>
