@extends('layout')

@section('title', 'POS Designer - PopCentral')
@section('content')
<style>
    .posd { --ink:#172033; --muted:#718096; --line:#dce4ee; --blue:#2563eb; background:#f4f7fb; min-height:calc(100vh - 70px); padding:24px; }
    .posd-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; }
    .posd h1 { margin:0; color:var(--ink); font-size:28px; font-weight:750; }
    .posd p { margin:5px 0 0; color:var(--muted); }
    .posd-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .posd-btn { display:inline-flex; align-items:center; border:0; border-radius:7px; padding:10px 15px; font-weight:700; text-decoration:none; cursor:pointer; }
    .posd-btn.secondary { background:#fff; border:1px solid var(--line); color:var(--ink); }
    .posd-btn.primary { color:#fff; background:#2563eb; }
    .posd-btn.primary:disabled { background:#9dbaf5; cursor:not-allowed; }
    .posd-btn.ghost { background:transparent; border:1px solid #e3b7bd; color:#9f1f2b; }
    .posd-layout { display:grid; grid-template-columns:225px minmax(0,1fr) 330px; gap:16px; align-items:start; }
    .posd-panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 3px 12px #16213d0b; }
    .posd-panel h2 { font-size:13px; letter-spacing:.06em; text-transform:uppercase; margin:0; padding:15px 16px 11px; color:#64748b; border-bottom:1px solid #edf1f6; }
    .posd-alert { margin-bottom:16px; padding:12px 14px; border-radius:8px; border-left:4px solid #bd2f45; background:#fff3f4; color:#8c1f2d; font-size:13px; }
    .posd-alert ul { margin:6px 0 0; padding-left:18px; }
    .posd-ok { margin-bottom:16px; padding:12px 14px; border-radius:8px; border-left:4px solid #138a5b; background:#eefaf4; color:#0f6647; font-size:13px; }
    .palette { padding:10px; }
    .palette-item { display:flex; align-items:center; gap:10px; border:1px solid var(--line); background:#fbfcfe; border-radius:6px; padding:11px; margin-bottom:8px; cursor:grab; color:var(--ink); font-weight:650; }
    .palette-item:hover { border-color:#93b4f7; background:#f3f7ff; }
    .palette-item span { color:var(--blue); width:25px; text-align:center; font-size:18px; }
    .canvas-wrap { background:#dfe6ef; border:1px solid var(--line); border-radius:8px; padding:16px; min-height:620px; }
    .canvas { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); grid-auto-rows:54px; gap:8px; min-height:588px; padding:10px; background:#f5f7fa; border:1px solid #c8d2df; border-radius:7px; }
    .canvas-item { position:relative; overflow:hidden; display:block; padding:0; border:2px solid #8fb4f5; border-left:4px solid var(--blue); border-radius:6px; background:#fff; color:var(--ink); cursor:move; font-weight:700; box-shadow:0 2px 5px #16213d12; }
    .canvas-item small { display:block; color:#64748b; font-weight:500; font-size:10px; }
    .canvas-item .drag-label { position:absolute; z-index:2; left:7px; bottom:5px; padding:2px 5px; color:#64748b; background:#ffffffd9; border-radius:3px; font-size:10px; pointer-events:none; }
    .preview-search { margin:10px; border:1px solid #cbd5e1; border-radius:5px; padding:7px 9px; color:#94a3b8; font-size:11px; font-weight:500; }
    .preview-cats { display:flex; gap:5px; padding:8px 10px; white-space:nowrap; overflow:hidden; }
    .preview-cats span { padding:5px 8px; border-radius:4px; background:#eef4ff; color:#2563eb; font-size:10px; }
    .product-preview { display:grid; grid-template-columns:repeat(3,1fr); gap:7px; padding:10px; }
    .product-preview span { min-height:38px; padding:6px; border:1px solid #e2e8f0; border-radius:5px; color:#334155; font-size:10px; }
    .product-preview b { display:block; color:#2563eb; margin-top:5px; }
    .cart-preview { padding:12px; color:#334155; font-size:11px; }
    .cart-preview .line { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid #edf1f5; font-weight:500; }
    .cart-preview .total { display:flex; justify-content:space-between; padding-top:12px; color:#172033; font-size:16px; }
    .payment-preview { display:grid; grid-template-columns:1fr 1fr; gap:7px; padding:10px; }
    .payment-preview span { padding:10px 5px; border-radius:5px; text-align:center; background:#edf8f3; color:#158662; font-size:11px; }
    .payment-preview span:last-child { background:#2563eb; color:#fff; }
    .canvas-item .remove { position:absolute; top:5px; right:7px; border:0; background:transparent; color:#94a3b8; cursor:pointer; font-size:16px; }
    .canvas-item.dragging { opacity:.4; }
    .inspector { padding:14px 16px 18px; color:var(--ink); }
    .inspector label { display:block; margin:13px 0 5px; font-size:12px; color:var(--muted); font-weight:700; }
    .inspector input[type=number], .inspector select { width:100%; box-sizing:border-box; border:1px solid var(--line); border-radius:5px; padding:8px; }
    .inspector input[type=range] { width:100%; }
    .inspector .row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .inspector .value { float:right; color:var(--ink); font-weight:800; }
    .inspector .field-error { margin:4px 0 0; color:#9f1f2b; font-size:11px; font-weight:600; }
    .inspector .note { margin:12px 0 0; padding:9px 10px; border-radius:6px; background:#f4f7fb; color:#64748b; font-size:11px; line-height:1.55; }
    .toggle-list { margin-top:12px; display:grid; gap:7px; }
    .toggle { display:flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid var(--line); border-radius:6px; font-size:12px; font-weight:650; }
    .toggle input { width:17px; height:17px; }
    .runtime-preview { margin-top:16px; border:1px solid #24344b; border-radius:7px; overflow:hidden; background:#172033; color:#fff; }
    .runtime-preview .bar { display:flex; align-items:center; gap:6px; padding:8px 10px; font-size:10px; font-weight:750; background:#24344b; }
    .runtime-preview .chip { padding:3px 6px; border:1px solid #47597a; border-radius:5px; color:#cfe0f2; font-size:9px; font-weight:700; }
    .runtime-preview .body { display:grid; gap:6px; padding:8px; }
    .runtime-preview .pane { border:1px solid #40536f; border-radius:5px; background:#1d2a3e; padding:6px; min-height:120px; }
    .runtime-preview .pane-title { margin-bottom:5px; color:#8fa9c6; font-size:9px; font-weight:800; }
    .runtime-preview .grid { display:grid; }
    .runtime-preview .cell { border:1px solid #47597a; border-radius:4px; background:#26374f; color:#cfe0f2; display:grid; place-items:center; }
    .runtime-preview .cart-line { display:flex; justify-content:space-between; color:#cfe0f2; font-size:9px; padding:3px 0; border-bottom:1px solid #2f4159; }
    .runtime-preview .pay { margin-top:6px; display:grid; place-items:center; border-radius:5px; background:#2563eb; color:#fff; font-weight:800; }
    .runtime-preview .caption { padding:6px 9px; border-top:1px solid #2f4159; color:#8fa9c6; font-size:9px; }
    @media (max-width:1100px) { .posd-layout { grid-template-columns:190px minmax(0,1fr); } }
    @media (max-width:680px) { .posd { padding:14px; } .posd-head { display:block; } .posd-actions { margin-top:14px; } .posd-layout { grid-template-columns:1fr; } .canvas-wrap { min-height:500px; } }
</style>
<div class="posd" x-data="posDesigner(@js($layout), @js($defaultRuntime), @js($limits), @js($metrics))">
    @if(session('success'))
        <div class="posd-ok">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="posd-alert"><strong>ยังบันทึกไม่ได้</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif
    <form method="POST" action="{{ route('settings.pos-designer.save') }}" @submit="sync($event)">
        @csrf
        <input type="hidden" name="layout" x-ref="layoutField">
        <div class="posd-head">
            <div>
                <h1>POS Designer</h1>
                <p>
                    ออกแบบหน้าขาย แล้ว Build เป็น layout ให้ PopCentral POS
                    @if($publishedVersion > 0)
                        — ใช้งานอยู่รุ่น <strong>{{ $publishedVersion }}</strong>@if($publishedAt) ({{ \Illuminate\Support\Carbon::parse($publishedAt)->timezone('Asia/Bangkok')->format('d/m/Y H:i') }})@endif
                    @else
                        — ยังไม่เคย Publish
                    @endif
                </p>
            </div>
            <div class="posd-actions">
                <a class="posd-btn secondary" href="{{ route('pos.preview') }}" target="_blank" rel="noopener"><i class="bi bi-display me-1"></i> ดูหน้าที่ Build แล้ว</a>
                <a class="posd-btn secondary" href="{{ route('settings.pos-builds.index') }}"><i class="bi bi-box-seam me-1"></i> Build โปรแกรม</a>
                <button class="posd-btn ghost" type="submit" name="reset" value="1" formnovalidate
                        onclick="return confirm('คืนค่าแบบร่างกลับเป็นค่าเริ่มต้น? ค่าที่ Publish ไปแล้วจะยังไม่เปลี่ยนจนกว่าจะกด Build &amp; Publish อีกครั้ง')">
                    คืนค่าเริ่มต้น
                </button>
                <button class="posd-btn secondary" type="submit" :disabled="errors.length > 0">บันทึกแบบร่าง</button>
                <button class="posd-btn primary" type="submit" name="publish" value="1" :disabled="errors.length > 0">Build &amp; Publish</button>
            </div>
        </div>
        <div class="posd-layout">
            <aside class="posd-panel"><h2>ส่วนประกอบ</h2><div class="palette">
                <template x-for="item in palette" :key="item.type"><div class="palette-item" draggable="true" @dragstart="dragType=item.type"><span x-text="item.icon"></span><div><div x-text="item.label"></div><small x-text="item.hint"></small></div></div></template>
            </div></aside>
            <main class="canvas-wrap"><div class="canvas" @dragover.prevent @drop="add(dragType)">
                <template x-for="(item,index) in layout.components" :key="item.id"><div class="canvas-item" draggable="true" :class="{dragging:dragIndex===index}" :style="`grid-column:${item.x} / span ${item.w}; grid-row:${item.y} / span ${item.h}`" @dragstart="dragIndex=index" @dragover.prevent @drop.stop="move(dragIndex,index)">
                    <template x-if="item.type==='search'"><div class="preview-search">⌕ &nbsp;ค้นหาสินค้า หรือสแกนบาร์โค้ด</div></template>
                    <template x-if="item.type==='category_tabs'"><div class="preview-cats"><span>ทั้งหมด</span><span>อาหารสด</span><span>เครื่องดื่ม</span><span>ของแห้ง</span></div></template>
                    <template x-if="item.type==='product_grid'"><div class="product-preview"><span>หมูสามชั้น<b>฿189.00</b></span><span>น้ำจิ้ม<b>฿69.00</b></span><span>ไก่สด<b>฿125.00</b></span><span>ผักรวม<b>฿45.00</b></span><span>ข้าวหอม<b>฿55.00</b></span><span>ไข่ไก่<b>฿120.00</b></span></div></template>
                    <template x-if="item.type==='cart'"><div class="cart-preview"><div class="line"><span>หมูสามชั้น × 2</span><strong>378.00</strong></div><div class="line"><span>น้ำจิ้ม × 1</span><strong>69.00</strong></div><div class="total"><span>รวมสุทธิ</span><strong>447.00 ฿</strong></div></div></template>
                    <template x-if="item.type==='payment'"><div class="payment-preview"><span>เงินสด</span><span>รับชำระเงิน</span></div></template>
                    <template x-if="!['search','category_tabs','product_grid','cart','payment'].includes(item.type)"><div class="cart-preview"><div x-text="label(item.type)"></div><small>ตัวอย่างส่วนประกอบ POS</small></div></template>
                    <span class="drag-label" x-text="`${label(item.type)} · ลากเพื่อย้ายตำแหน่ง`"></span><button type="button" class="remove" title="ลบ" @click="remove(index)">×</button>
                </div></template>
            </div></main>
            <aside class="posd-panel">
                <h2>ค่าหน้าขายจริง (Runtime)</h2>
                <div class="inspector">
                    <label for="productWidth">สัดส่วนฝั่งสินค้า <span class="value" x-text="runtime.product_width + '%'"></span></label>
                    <input id="productWidth" type="range" :min="limits.pane_min" :max="limits.pane_max" step="1"
                           x-model.number="runtime.product_width" @input="runtime.cart_width = 100 - runtime.product_width">
                    <input type="hidden" name="runtime[product_width]" :value="runtime.product_width">
                    @error('runtime.product_width')<p class="field-error">{{ $message }}</p>@enderror

                    <label for="cartWidth">สัดส่วนฝั่งบิล <span class="value" x-text="runtime.cart_width + '%'"></span></label>
                    <input id="cartWidth" type="range" :min="limits.pane_min" :max="limits.pane_max" step="1"
                           x-model.number="runtime.cart_width" @input="runtime.product_width = 100 - runtime.cart_width">
                    <input type="hidden" name="runtime[cart_width]" :value="runtime.cart_width">
                    @error('runtime.cart_width')<p class="field-error">{{ $message }}</p>@enderror

                    <div class="row">
                        <div>
                            <label for="productRows">แถวสินค้า</label>
                            <input id="productRows" type="number" name="runtime[product_rows]" x-model.number="runtime.product_rows" :min="limits.rows_min" :max="limits.rows_max" step="1" required>
                            @error('runtime.product_rows')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="productColumns">คอลัมน์สินค้า</label>
                            <input id="productColumns" type="number" name="runtime[product_columns]" x-model.number="runtime.product_columns" :min="limits.columns_min" :max="limits.columns_max" step="1" required>
                            @error('runtime.product_columns')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="row">
                        <div>
                            <label for="density">ความหนาแน่น</label>
                            <select id="density" name="runtime[density]" x-model="runtime.density">
                                <option value="compact">แน่น (compact)</option>
                                <option value="comfortable">ปกติ (comfortable)</option>
                                <option value="roomy">โปร่ง (roomy)</option>
                            </select>
                            @error('runtime.density')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="buttonSize">ขนาดปุ่ม</label>
                            <select id="buttonSize" name="runtime[button_size]" x-model="runtime.button_size">
                                <option value="small">เล็ก (small)</option>
                                <option value="medium">กลาง (medium)</option>
                                <option value="large">ใหญ่ (large)</option>
                            </select>
                            @error('runtime.button_size')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <label>ชิปบนแถบบนของหน้าขาย</label>
                    <div class="toggle-list">
                        <label class="toggle"><input type="hidden" name="runtime[show_branch]" value="0"><input type="checkbox" name="runtime[show_branch]" value="1" x-model="runtime.show_branch"> แสดงสาขา</label>
                        <label class="toggle"><input type="hidden" name="runtime[show_terminal]" value="0"><input type="checkbox" name="runtime[show_terminal]" value="1" x-model="runtime.show_terminal"> แสดงเครื่อง (terminal)</label>
                        <label class="toggle"><input type="hidden" name="runtime[show_seller]" value="0"><input type="checkbox" name="runtime[show_seller]" value="1" x-model="runtime.show_seller"> แสดงคนขาย</label>
                        <label class="toggle"><input type="hidden" name="runtime[show_shift]" value="0"><input type="checkbox" name="runtime[show_shift]" value="1" x-model="runtime.show_shift"> แสดงสถานะกะ</label>
                    </div>

                    <template x-if="errors.length">
                        <div class="posd-alert" style="margin:14px 0 0">
                            <ul><template x-for="message in errors" :key="message"><li x-text="message"></li></template></ul>
                        </div>
                    </template>

                    <div class="runtime-preview">
                        <div class="bar">
                            <span>PopCentral POS</span>
                            <template x-if="runtime.show_branch"><span class="chip">สาขา B001</span></template>
                            <template x-if="runtime.show_terminal"><span class="chip">POS001</span></template>
                            <template x-if="runtime.show_seller"><span class="chip">คนขาย</span></template>
                            <template x-if="runtime.show_shift"><span class="chip">กะเปิดอยู่</span></template>
                        </div>
                        <div class="body" :style="`grid-template-columns:${runtime.product_width}fr ${runtime.cart_width}fr; gap:${metrics.gap}px; padding:${metrics.gap}px`">
                            <div class="pane" :style="`padding:${metrics.padding}px`">
                                <div class="pane-title" x-text="`สินค้า ${runtime.product_columns} × ${runtime.product_rows}`"></div>
                                <div class="grid" :style="`grid-template-columns:repeat(${runtime.product_columns},minmax(0,1fr)); grid-template-rows:repeat(${runtime.product_rows},${Math.max(14, Math.round(metrics.card / 5))}px); gap:${metrics.gap}px`">
                                    <template x-for="cell in (runtime.product_columns * runtime.product_rows)" :key="cell">
                                        <div class="cell" :style="`font-size:${Math.max(7, metrics.font - 4)}px`">฿</div>
                                    </template>
                                </div>
                            </div>
                            <div class="pane" :style="`padding:${metrics.padding}px`">
                                <div class="pane-title">บิลปัจจุบัน</div>
                                <div class="cart-line"><span>หมูสามชั้น × 2</span><span>378.00</span></div>
                                <div class="cart-line"><span>น้ำจิ้ม × 1</span><span>69.00</span></div>
                                <div class="pay" :style="`height:${Math.round(metrics.button * .55)}px; font-size:${Math.max(8, metrics.buttonFont - 6)}px`">รับชำระเงิน</div>
                            </div>
                        </div>
                        <div class="caption" x-text="`ตัวอย่างสัดส่วนจริง ${runtime.product_width}/${runtime.cart_width} · ปุ่มสูง ${metrics.button}px · การ์ดสินค้าสูง ${metrics.card}px`"></div>
                    </div>

                    <p class="note">
                        ค่าเหล่านี้คือค่าที่หน้าขายเอาไปทำ CSS จริง กด <strong>บันทึกแบบร่าง</strong> เพื่อเก็บไว้ก่อน
                        และกด <strong>Build &amp; Publish</strong> เมื่อพร้อมให้สาขาใช้ เครื่อง POS จะรับค่าใหม่ตอน Sync ครั้งถัดไป
                        (ตอนนี้ publish ไปแล้ว {{ $publishedRuntime['product_width'] }}/{{ $publishedRuntime['cart_width'] }},
                        {{ $publishedRuntime['product_columns'] }}×{{ $publishedRuntime['product_rows'] }})
                    </p>
                </div>
            </aside>
        </div>
    </form>
</div>
<script>
function posDesigner(initial, defaultRuntime, limits, metrics) {
    // ตารางค่าพวกนี้ส่งมาจาก App\Support\PosLayout ฝั่งเซิร์ฟเวอร์ ไม่ได้เขียนซ้ำไว้ที่นี่
    const DENSITY = metrics.density;
    const BUTTON = metrics.button;
    return {
        layout: initial,
        limits: limits,
        defaultRuntime: defaultRuntime,
        runtime: Object.assign({}, defaultRuntime, initial.runtime || {}),
        dragType: null,
        dragIndex: null,
        palette: [
            { type: 'search', label: 'ค้นหาสินค้า', hint: 'สแกน / พิมพ์ค้นหา', icon: '⌕' },
            { type: 'category_tabs', label: 'หมวดสินค้า', hint: 'แท็บหมวดหมู่', icon: '▤' },
            { type: 'product_grid', label: 'ตารางสินค้า', hint: 'ปุ่มสินค้า', icon: '▦' },
            { type: 'cart', label: 'บิลปัจจุบัน', hint: 'รายการและยอดรวม', icon: '▣' },
            { type: 'payment', label: 'รับชำระเงิน', hint: 'เงินสด / โอน / บัตร', icon: '฿' },
            { type: 'customer', label: 'ลูกค้า', hint: 'ข้อมูลลูกค้า', icon: '♙' },
            { type: 'held_bills', label: 'พักบิล', hint: 'เรียกบิลคืน', icon: '◫' },
            { type: 'numpad', label: 'แป้นตัวเลข', hint: 'จำนวน / ราคา', icon: '⌨' },
            { type: 'shift_status', label: 'สถานะกะ', hint: 'แคชเชียร์ / กะขาย', icon: '◷' },
        ],
        get metrics() {
            const density = DENSITY[this.runtime.density] || DENSITY.comfortable;
            const button = BUTTON[this.runtime.button_size] || BUTTON.medium;
            return { gap: density.gap, padding: density.padding, card: density.card, font: density.font, button: button.height, buttonFont: button.font };
        },
        // ตรวจซ้ำฝั่งเบราว์เซอร์ให้เห็นผลทันที ของจริงยังตรวจซ้ำที่เซิร์ฟเวอร์เสมอ
        get errors() {
            const messages = [];
            const r = this.runtime;
            if (r.product_width + r.cart_width !== 100) messages.push('สัดส่วนสองฝั่งรวมกันต้องได้ 100%');
            if (r.product_width < limits.pane_min || r.product_width > limits.pane_max) messages.push(`ฝั่งสินค้าต้องอยู่ระหว่าง ${limits.pane_min}% ถึง ${limits.pane_max}%`);
            if (r.cart_width < limits.pane_min || r.cart_width > limits.pane_max) messages.push(`ฝั่งบิลต้องอยู่ระหว่าง ${limits.pane_min}% ถึง ${limits.pane_max}%`);
            if (!Number.isInteger(r.product_rows) || r.product_rows < limits.rows_min || r.product_rows > limits.rows_max) messages.push(`แถวสินค้าต้องอยู่ระหว่าง ${limits.rows_min} ถึง ${limits.rows_max}`);
            if (!Number.isInteger(r.product_columns) || r.product_columns < limits.columns_min || r.product_columns > limits.columns_max) messages.push(`คอลัมน์สินค้าต้องอยู่ระหว่าง ${limits.columns_min} ถึง ${limits.columns_max}`);
            if (this.layout.components.length === 0) messages.push('ต้องมีอย่างน้อย 1 ส่วนประกอบบน canvas');
            return messages;
        },
        label(t) { const x = this.palette.find(i => i.type === t); return x ? x.label : t; },
        icon(t) { const x = this.palette.find(i => i.type === t); return x ? x.icon : '□'; },
        add(type) {
            if (!type) return;
            const i = this.layout.components.length;
            this.layout.components.push({
                id: type + '-' + Date.now(), type,
                x: 1 + (i % 2) * 6, y: 1 + Math.floor(i / 2) * 2,
                w: type === 'cart' || type === 'payment' ? 5 : 6,
                h: type === 'product_grid' ? 4 : 2,
            });
            this.dragType = null;
        },
        remove(i) { this.layout.components.splice(i, 1); },
        move(from, to) {
            if (from === null || from === to) return;
            const x = this.layout.components.splice(from, 1)[0];
            this.layout.components.splice(to, 0, x);
            this.dragIndex = null;
        },
        resetRuntime() { this.runtime = Object.assign({}, this.defaultRuntime); },
        sync(event) {
            // ปุ่มคืนค่าเริ่มต้นให้เซิร์ฟเวอร์เป็นคนตัดสิน ไม่ต้องส่ง canvas ที่ยังแก้ค้างอยู่ไปด้วย
            this.$refs.layoutField.value = JSON.stringify({ version: this.layout.version || 1, components: this.layout.components });
        },
    };
}
</script>
@endsection
