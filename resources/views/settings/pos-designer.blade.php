@extends('layouts.app')

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
    .canvas-wrap { background:#e9eef5; border:1px solid var(--line); border-radius:8px; padding:16px; min-height:620px; }
    .canvas { display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); grid-auto-rows:54px; gap:8px; min-height:588px; padding:10px; background:#fff; border:1px solid #d9e1eb; border-radius:7px; }
    .canvas-item { position:relative; display:flex; align-items:center; gap:10px; padding:12px; border:1px solid #b8cdf4; border-left:4px solid var(--blue); border-radius:6px; background:#f7faff; color:var(--ink); cursor:move; font-weight:700; }
    .canvas-item small { display:block; color:var(--muted); font-weight:500; font-size:11px; }
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
                <span x-text="icon(item.type)"></span><div><div x-text="label(item.type)"></div><small x-text="`${item.w} x ${item.h} · ลากเพื่อย้ายตำแหน่ง`"></small></div><button type="button" class="remove" title="ลบ" @click="remove(index)">×</button>
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
