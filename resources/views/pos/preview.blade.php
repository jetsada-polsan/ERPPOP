<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ตัวอย่าง PopCentral POS รุ่น {{ $publishedVersion }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        :root { --ink:#162331; --muted:#6c7b88; --line:#dce3e8; --red:#c92336; --red-dark:#9f1f2b; --green:#147a55; --canvas:#edf1f4; }
        * { box-sizing:border-box; }
        html,body { margin:0; min-height:100%; font-family:"Noto Sans Thai","Leelawadee UI",Tahoma,sans-serif; color:var(--ink); background:var(--canvas); }
        button,input { font:inherit; }
        .preview-shell { min-height:100vh; display:grid; grid-template-rows:54px 1fr 36px; }
        .topbar { display:flex; align-items:center; gap:14px; padding:0 18px; background:#172033; color:#fff; box-shadow:0 5px 18px #1720332b; }
        .brand { font-size:20px; font-weight:900; white-space:nowrap; }
        .brand span { color:#f55060; }
        .terminal { display:flex; align-items:center; gap:9px; min-width:0; color:#cbd5e1; font-size:12px; }
        .terminal b { color:#fff; }
        .top-spacer { flex:1; }
        .preview-badge { display:flex; align-items:center; gap:7px; padding:6px 10px; border:1px solid #526173; border-radius:6px; color:#dbe5ef; font-size:12px; }
        .back { color:#fff; text-decoration:none; width:34px; height:34px; display:grid; place-items:center; border:1px solid #526173; border-radius:6px; }
        .pos-stage { min-height:0; padding:10px; overflow:auto; }
        .pos-canvas { min-width:960px; min-height:690px; height:calc(100vh - 110px); display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); grid-template-rows:repeat(12,minmax(48px,1fr)); gap:8px; }
        .widget { min-width:0; min-height:0; overflow:hidden; border:1px solid var(--line); border-radius:8px; background:#fff; box-shadow:0 5px 18px #1623310b; }
        .widget-title { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:9px 11px; border-bottom:1px solid #edf1f4; font-size:12px; font-weight:800; }
        .widget-title small { color:var(--muted); font-weight:500; }
        .search-box { height:100%; display:flex; align-items:center; gap:11px; padding:0 16px; }
        .search-box i { color:var(--red); font-size:20px; }
        .search-box input { width:100%; height:42px; border:0; outline:0; color:var(--ink); font-size:15px; background:transparent; }
        .keycap { padding:4px 7px; border:1px solid var(--line); border-radius:5px; background:#f7f9fa; color:var(--muted); font-size:10px; }
        .category-list { height:100%; display:flex; align-items:center; gap:7px; padding:8px 10px; overflow:hidden; }
        .category-list button { border:1px solid var(--line); border-radius:6px; padding:8px 13px; background:#fff; color:#425466; white-space:nowrap; cursor:default; }
        .category-list button.active { border-color:var(--red); background:#fff1f3; color:var(--red-dark); font-weight:800; }
        .products { height:100%; display:grid; grid-template-rows:auto 1fr; }
        .product-grid { min-height:0; overflow:hidden; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); grid-auto-rows:minmax(104px,1fr); gap:8px; padding:10px; }
        .product { position:relative; display:flex; flex-direction:column; justify-content:space-between; min-width:0; padding:12px; border:1px solid var(--line); border-radius:7px; background:#fff; text-align:left; color:var(--ink); }
        .product::before { content:""; position:absolute; top:0; left:0; right:0; height:4px; background:var(--product-accent,#d8e0e7); border-radius:7px 7px 0 0; }
        .product .sku { color:var(--muted); font-size:10px; }
        .product strong { display:-webkit-box; overflow:hidden; -webkit-line-clamp:2; -webkit-box-orient:vertical; font-size:13px; }
        .product .price { color:var(--red-dark); font-size:17px; font-weight:900; }
        .cart { height:100%; display:grid; grid-template-rows:auto 1fr auto; }
        .cart-lines { min-height:0; overflow:hidden; padding:0 11px; }
        .cart-line { display:grid; grid-template-columns:1fr auto; gap:6px; padding:11px 0; border-bottom:1px solid #edf1f4; }
        .cart-line strong { font-size:13px; }
        .cart-line small { display:block; margin-top:3px; color:var(--muted); }
        .cart-line .amount { font-weight:800; }
        .cart-total { padding:10px 12px; border-top:1px solid var(--line); background:#f8fafb; }
        .total-row { display:flex; justify-content:space-between; padding:3px 0; color:var(--muted); font-size:12px; }
        .total-row.grand { margin-top:4px; color:var(--ink); font-size:20px; font-weight:900; }
        .payment { height:100%; display:grid; grid-template-columns:repeat(3,1fr) 1.5fr; gap:8px; padding:10px; }
        .pay-method { border:1px solid var(--line); border-radius:7px; background:#fff; color:#425466; font-weight:800; }
        .pay-method i { display:block; margin-bottom:4px; font-size:20px; color:var(--green); }
        .pay-now { border:0; border-radius:7px; background:var(--red); color:#fff; font-size:15px; font-weight:900; box-shadow:0 5px 14px #c9233633; }
        .customer,.held,.shift { height:100%; padding:12px; display:flex; align-items:center; gap:10px; }
        .customer i,.held i,.shift i { width:38px; height:38px; display:grid; place-items:center; border-radius:7px; background:#eef4ff; color:#2563eb; font-size:18px; }
        .customer small,.held small,.shift small { display:block; color:var(--muted); }
        .numpad { height:100%; display:grid; grid-template-columns:repeat(3,1fr); gap:5px; padding:8px; }
        .numpad button { border:1px solid var(--line); border-radius:5px; background:#f8fafb; font-weight:800; }
        .statusbar { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:0 14px; background:#fff; border-top:1px solid var(--line); color:var(--muted); font-size:11px; }
        .statusbar b { color:var(--green); }
        @media (max-width:700px) { .terminal { display:none; } .preview-badge span { display:none; } .pos-stage { padding:6px; } }
    </style>
</head>
<body>
@php
    $products = $previewProducts->values();
    $cartProducts = $products->take(3);
    $cartTotal = $cartProducts->sum(fn ($product, $index) => (float) $product->default_price * ($index === 0 ? 2 : 1));
    $accents = ['#e84959','#16a085','#2979ff','#f59e0b','#8b5cf6','#0891b2','#65a30d','#db2777'];
@endphp
<div class="preview-shell">
    <header class="topbar">
        <div class="brand">PopCentral <span>POS</span></div>
        <div class="terminal"><i class="bi bi-display"></i><span>เครื่อง <b>POS001</b></span><span>สาขา <b>B001</b></span><span>แคชเชียร์ <b>{{ auth()->user()?->name ?? 'Demo Cashier' }}</b></span></div>
        <div class="top-spacer"></div>
        <div class="preview-badge"><i class="bi bi-eye"></i><span>ตัวอย่างจาก Build รุ่น {{ $publishedVersion }}</span></div>
        <a class="back" href="{{ route('settings.pos-designer') }}" title="กลับไป POS Designer"><i class="bi bi-sliders"></i></a>
    </header>

    <main class="pos-stage">
        <div class="pos-canvas">
            @foreach($layout['components'] as $component)
                <section class="widget" style="grid-column:{{ $component['x'] }} / span {{ $component['w'] }};grid-row:{{ $component['y'] }} / span {{ $component['h'] }}">
                    @switch($component['type'])
                        @case('search')
                            <div class="search-box"><i class="bi bi-upc-scan"></i><input value="" placeholder="สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า" readonly><span class="keycap">F2</span></div>
                            @break
                        @case('category_tabs')
                            <div class="category-list"><button class="active">สินค้าทั้งหมด</button>@foreach($previewCategories as $category)<button>{{ $category->name_th }}</button>@endforeach</div>
                            @break
                        @case('product_grid')
                            <div class="products"><div class="widget-title"><span>สินค้า</span><small>{{ number_format($previewProducts->count()) }} รายการตัวอย่าง</small></div><div class="product-grid">
                                @forelse($products as $index => $product)<button class="product" style="--product-accent:{{ $accents[$index % count($accents)] }}"><span class="sku">{{ $product->sku_code }}</span><strong>{{ $product->name_th }}</strong><span class="price">฿{{ number_format((float) $product->default_price, 2) }}</span></button>@empty
                                    @foreach(['หมูสามชั้น','น้ำจิ้มสุกี้','ข้าวหอมมะลิ','ไข่ไก่สด'] as $index => $name)<button class="product" style="--product-accent:{{ $accents[$index] }}"><span class="sku">DEMO-00{{ $index + 1 }}</span><strong>{{ $name }}</strong><span class="price">฿{{ number_format(49 + ($index * 35), 2) }}</span></button>@endforeach
                                @endforelse
                            </div></div>
                            @break
                        @case('cart')
                            <div class="cart"><div class="widget-title"><span><i class="bi bi-receipt me-1"></i> บิลปัจจุบัน</span><small>{{ max(1, $cartProducts->count()) }} รายการ</small></div><div class="cart-lines">
                                @forelse($cartProducts as $index => $product)<div class="cart-line"><div><strong>{{ $product->name_th }}</strong><small>{{ $index === 0 ? '2' : '1' }} × ฿{{ number_format((float) $product->default_price, 2) }}</small></div><span class="amount">฿{{ number_format((float) $product->default_price * ($index === 0 ? 2 : 1), 2) }}</span></div>@empty
                                    <div class="cart-line"><div><strong>หมูสามชั้น</strong><small>2 × ฿189.00</small></div><span class="amount">฿378.00</span></div><div class="cart-line"><div><strong>น้ำจิ้มสุกี้</strong><small>1 × ฿69.00</small></div><span class="amount">฿69.00</span></div>
                                @endforelse
                            </div><div class="cart-total"><div class="total-row"><span>ยอดก่อนภาษี</span><span>฿{{ number_format($cartTotal > 0 ? $cartTotal / 1.07 : 417.76, 2) }}</span></div><div class="total-row"><span>VAT 7%</span><span>฿{{ number_format($cartTotal > 0 ? $cartTotal - ($cartTotal / 1.07) : 29.24, 2) }}</span></div><div class="total-row grand"><span>ยอดสุทธิ</span><span>฿{{ number_format($cartTotal > 0 ? $cartTotal : 447, 2) }}</span></div></div></div>
                            @break
                        @case('payment')
                            <div class="payment"><button class="pay-method"><i class="bi bi-cash-stack"></i>เงินสด</button><button class="pay-method"><i class="bi bi-qr-code"></i>พร้อมเพย์</button><button class="pay-method"><i class="bi bi-credit-card"></i>บัตร</button><button class="pay-now">รับชำระ ฿{{ number_format($cartTotal > 0 ? $cartTotal : 447, 2) }}</button></div>
                            @break
                        @case('customer')
                            <div class="customer"><i class="bi bi-person"></i><div><strong>ลูกค้าทั่วไป</strong><small>ค้นหาสมาชิกด้วยชื่อหรือเบอร์โทร</small></div></div>
                            @break
                        @case('held_bills')
                            <div class="held"><i class="bi bi-pause-circle"></i><div><strong>พักบิล / เรียกบิล</strong><small>ยังไม่มีบิลที่พักไว้</small></div></div>
                            @break
                        @case('shift_status')
                            <div class="shift"><i class="bi bi-person-badge"></i><div><strong>กะขายกำลังเปิด</strong><small>เริ่มกะ 08:00 น. · ออนไลน์</small></div></div>
                            @break
                        @case('numpad')
                            <div class="numpad">@foreach([7,8,9,4,5,6,1,2,3,'.',0,'⌫'] as $key)<button>{{ $key }}</button>@endforeach</div>
                            @break
                    @endswitch
                </section>
            @endforeach
        </div>
    </main>

    <footer class="statusbar"><span><b>● Preview mode</b> หน้านี้ไม่บันทึกยอดขายและไม่ตัดสต็อก</span><span>เผยแพร่ล่าสุด {{ $publishedAt ? \Illuminate\Support\Carbon::parse($publishedAt)->timezone('Asia/Bangkok')->format('d/m/Y H:i') : 'ยังไม่มีข้อมูลเวลา' }}</span></footer>
</div>
</body>
</html>
