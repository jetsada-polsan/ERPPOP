@extends('layout')

@section('title', 'POS Designer - PopCentral')
@section('content')
<style>
    .posd { --ink:#172033; --muted:#718096; --line:#dce4ee; --blue:#2563eb; background:#f4f7fb; min-height:calc(100vh - 70px); padding:24px; }
    .posd-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; }
    .posd h1 { margin:0; color:var(--ink); font-size:28px; font-weight:750; }
    .posd p { margin:5px 0 0; color:var(--muted); }
    .posd-actions { display:flex; gap:10px; }
    .posd-btn { border:0; border-radius:7px; padding:10px 15px; font-weight:700; cursor:pointer; }
    .posd-btn.secondary { background:#fff; border:1px solid var(--line); color:var(--ink); }
    .posd-btn.primary { color:#fff; background:#2563eb; }
    .posd-layout { display:grid; grid-template-columns:230px minmax(0,1fr) 280px; gap:16px; align-items:start; }
    .posd-panel { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 3px 12px #16213d0b; }
    .posd-panel h2 { font-size:13px; letter-spacing:.06em; text-transform:uppercase; margin:0; padding:15px 16px 11px; color:#64748b; border-bottom:1px solid #edf1f6; }
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
    .inspector { padding:15px 16px; color:var(--ink); }
    .inspector label { display:block; margin:13px 0 5px; font-size:12px; color:var(--muted); font-weight:700; }
    .inspector input { width:100%; box-sizing:border-box; border:1px solid var(--line); border-radius:5px; padding:8px; }
    .pos-preview { margin-top:16px; border:1px solid #24344b; border-radius:7px; overflow:hidden; background:#172033; color:#fff; }
    .pos-preview .bar { padding:10px 12px; font-weight:750; background:#24344b; }
    .pos-preview .body { display:grid; grid-template-columns:1.35fr 1fr; gap:8px; padding:9px; min-height:115px; }
    .pos-preview .block { border:1px solid #40536f; border-radius:4px; padding:8px; color:#d8e3f2; font-size:11px; }
    @media (max-width:1000px) { .posd-layout { grid-template-columns:190px minmax(0,1fr); } .inspector { display:none; } }
    @media (max-width:680px) { .posd { padding:14px; } .posd-head { display:block; } .posd-actions { margin-top:14px; } .posd-layout { grid-template-columns:1fr; } .canvas-wrap { min-height:500px; } }
</style>
<div class="posd" x-data="posDesigner(@js($layout))">
    <div class="posd-head">
        <div><h1>POS Designer</h1><p>ออกแบบหน้าขายบนเว็บ แล้ว Build เป็น layout ให้ PopCentral POS</p></div>
        <div class="posd-actions">
            <form method="POST" action="{{ route('settings.pos-designer.save') }}" @submit="sync($event)">@csrf
                <input type="hidden" name="layout" x-ref="layoutDraft"><button class="posd-btn secondary" type="submit">บันทึกแบบร่าง</button>
            </form>
            <form method="POST" action="{{ route('settings.pos-designer.save') }}" @submit="sync($event)">@csrf
                <input type="hidden" name="publish" value="1"><input type="hidden" name="layout" x-ref="layoutPublish"><button class="posd-btn primary" type="submit">Build &amp; Publish</button>
            </form>
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
        <aside class="posd-panel"><h2>ตัวอย่าง POS</h2><div class="inspector"><strong x-text="layout.components.length + ' ส่วนประกอบ'" ></strong><p>โครงร่างนี้จะถูกส่งไปพร้อมการ sync ครั้งถัดไปของเครื่อง POS</p><div class="pos-preview"><div class="bar">PopCentral POS</div><div class="body"><div class="block">ค้นหาสินค้า<br><br>ตารางสินค้า</div><div class="block">บิลปัจจุบัน<br><br>ยอดสุทธิ<br><br>รับชำระ</div></div></div></div></aside>
    </div>
</div>
<script>
function posDesigner(initial) { return { layout: initial, dragType: null, dragIndex: null,
 palette:[{type:'search',label:'ค้นหาสินค้า',hint:'สแกน / พิมพ์ค้นหา',icon:'⌕'},{type:'category_tabs',label:'หมวดสินค้า',hint:'แท็บหมวดหมู่',icon:'▤'},{type:'product_grid',label:'ตารางสินค้า',hint:'ปุ่มสินค้า',icon:'▦'},{type:'cart',label:'บิลปัจจุบัน',hint:'รายการและยอดรวม',icon:'▣'},{type:'payment',label:'รับชำระเงิน',hint:'เงินสด / โอน / บัตร',icon:'฿'},{type:'customer',label:'ลูกค้า',hint:'ข้อมูลลูกค้า',icon:'♙'},{type:'held_bills',label:'พักบิล',hint:'เรียกบิลคืน',icon:'◫'},{type:'numpad',label:'แป้นตัวเลข',hint:'จำนวน / ราคา',icon:'⌨'},{type:'shift_status',label:'สถานะกะ',hint:'แคชเชียร์ / กะขาย',icon:'◷'}],
 label(t){let x=this.palette.find(i=>i.type===t);return x?x.label:t}, icon(t){let x=this.palette.find(i=>i.type===t);return x?x.icon:'□'}, add(type){if(!type)return;let i=this.layout.components.length;this.layout.components.push({id:type+'-'+Date.now(),type,x:1+(i%2)*6,y:1+Math.floor(i/2)*2,w:type==='cart'||type==='payment'?5:6,h:type==='product_grid'?4:2});this.dragType=null}, remove(i){this.layout.components.splice(i,1)}, move(from,to){if(from===null||from===to)return;let x=this.layout.components.splice(from,1)[0];this.layout.components.splice(to,0,x);this.dragIndex=null}, sync(e){e.target.querySelector('input[name=layout]').value=JSON.stringify(this.layout)} } }
</script>
@endsection
