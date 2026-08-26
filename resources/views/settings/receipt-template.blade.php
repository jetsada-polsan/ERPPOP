@extends('layout')
@section('title', 'ออกแบบใบเสร็จ POS - PopCentral')
@section('page-title', 'ออกแบบใบเสร็จ POS')
@section('page-subtitle', 'จัดรูปแบบใบเสร็จสำหรับเครื่องแคชเชียร์')

@section('content')
<form method="post" action="{{ route('settings.receipt-template.update') }}"
      x-data="receiptDesigner(@js($receiptTemplate), @js($defaultTemplate), @js($company))">
    @csrf
    <input type="hidden" name="template" :value="JSON.stringify({ paper_width: paperWidth, blocks })">

    <div class="receipt-toolbar">
        <a href="{{ route('settings.index') }}" class="btn btn-light border" title="กลับไปหน้าตั้งค่า">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="receipt-toolbar-title">
            <strong>แบบใบเสร็จหน้าร้าน</strong>
            <span x-text="`${paperWidth} มม. · ${blocks.length} บล็อก`"></span>
        </div>
        <div class="paper-switch" aria-label="ขนาดกระดาษ">
            <button type="button" :class="paperWidth === 58 && 'active'" @click="paperWidth = 58">58 มม.</button>
            <button type="button" :class="paperWidth === 80 && 'active'" @click="paperWidth = 80">80 มม.</button>
        </div>
        <button type="button" class="btn btn-light border" @click="resetTemplate" title="คืนค่าแบบมาตรฐาน">
            <i class="bi bi-arrow-counterclockwise"></i>
        </button>
        <button type="submit" class="btn btn-success px-4">
            <i class="bi bi-cloud-check me-1"></i> บันทึกแบบ
        </button>
    </div>

    @error('template')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

    <div class="receipt-designer">
        <aside class="designer-panel palette-panel">
            <div class="panel-heading">ส่วนประกอบ</div>
            <div class="palette-list">
                <template x-for="item in palette" :key="item.type">
                    <button type="button" draggable="true" class="palette-item"
                            :disabled="!item.repeatable && hasType(item.type)"
                            @dragstart="startPalette($event, item.type)" @click="addBlock(item.type)">
                        <i :class="item.icon"></i>
                        <span><strong x-text="item.label"></strong><small x-text="item.detail"></small></span>
                        <i class="bi bi-plus-lg palette-add"></i>
                    </button>
                </template>
            </div>
        </aside>

        <section class="designer-stage" @dragover.prevent @drop="dropAt($event, blocks.length)">
            <div class="stage-ruler"><span x-text="`${paperWidth} mm`"></span></div>
            <div class="receipt-paper" :class="paperWidth === 58 ? 'paper-58' : 'paper-80'">
                <template x-for="(block, index) in blocks" :key="block.id">
                    <article class="receipt-block" draggable="true"
                             :class="{ selected: selectedId === block.id }"
                             @click="selectedId = block.id"
                             @dragstart.stop="startBlock($event, index)"
                             @dragover.prevent @drop.stop="dropAt($event, index)">
                        <div class="block-actions">
                            <button type="button" title="ลากเพื่อย้าย"><i class="bi bi-grip-vertical"></i></button>
                            <button type="button" title="เลื่อนขึ้น" :disabled="index === 0" @click.stop="moveBlock(index, -1)"><i class="bi bi-chevron-up"></i></button>
                            <button type="button" title="เลื่อนลง" :disabled="index === blocks.length - 1" @click.stop="moveBlock(index, 1)"><i class="bi bi-chevron-down"></i></button>
                            <button type="button" title="ลบบล็อก" :disabled="isRequired(block.type)" @click.stop="removeBlock(index)"><i :class="isRequired(block.type) ? 'bi bi-lock-fill' : 'bi bi-trash3'"></i></button>
                        </div>

                        <div class="receipt-content" :class="blockClasses(block)">
                            <template x-if="block.type === 'logo'">
                                <div class="sample-logo">
                                    <img x-show="company.logo_url" :src="company.logo_url" alt="">
                                    <strong x-show="!company.logo_url">POPSTAR</strong>
                                </div>
                            </template>
                            <template x-if="block.type === 'company'">
                                <div>
                                    <strong x-text="company.name || 'บริษัท ป๊อปสตาร์ฟู้ดเทรดดิ้ง จำกัด'"></strong>
                                    <span x-text="company.address || 'ที่อยู่สำนักงานใหญ่'"></span>
                                    <span x-text="`เลขประจำตัวผู้เสียภาษี ${company.tax_id || '0000000000000'}`"></span>
                                    <span x-show="company.phone" x-text="`โทร ${company.phone}`"></span>
                                </div>
                            </template>
                            <template x-if="block.type === 'title'">
                                <div>ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ</div>
                            </template>
                            <template x-if="block.type === 'meta'">
                                <div class="sample-meta">
                                    <span><b>เลขที่</b><em>CS000120260722001</em></span>
                                    <span><b>วันที่</b><em>22/07/2569 14:35</em></span>
                                    <span><b>สาขา</b><em>สาขาวารินชำราบ</em></span>
                                    <span><b>แคชเชียร์</b><em>POP001</em></span>
                                </div>
                            </template>
                            <template x-if="block.type === 'divider'"><div class="sample-divider"></div></template>
                            <template x-if="block.type === 'items'">
                                <table class="sample-items">
                                    <thead><tr><th>รายการ</th><th>จำนวน</th><th>รวม</th></tr></thead>
                                    <tbody>
                                    <tr><td><span x-show="block.show_sku">301355 </span>Boss Coffee ลาเต้<small x-show="block.show_unit_price">25.00 / ขวด</small></td><td>2</td><td>50.00</td></tr>
                                    <tr><td><span x-show="block.show_sku">401201 </span>น้ำจิ้มสุกี้ POPSTAR<small x-show="block.show_unit_price">69.00 / ขวด</small></td><td>1</td><td>69.00</td></tr>
                                    </tbody>
                                </table>
                            </template>
                            <template x-if="block.type === 'tax-summary'">
                                <div class="sample-row"><span>มูลค่าก่อนภาษี / VAT 7%</span><b>111.21 / 7.79</b></div>
                            </template>
                            <template x-if="block.type === 'totals'">
                                <div class="sample-row sample-total"><span>รวมสุทธิ</span><b>119.00 บาท</b></div>
                            </template>
                            <template x-if="block.type === 'payment'">
                                <div class="sample-payment"><span>ชำระโดย เงินสด</span><span>รับเงิน 200.00 · เงินทอน 81.00</span></div>
                            </template>
                            <template x-if="block.type === 'custom'"><div x-text="block.text || 'ข้อความเพิ่มเติม'"></div></template>
                            <template x-if="block.type === 'footer'"><div x-text="block.text || 'ขอบคุณที่ใช้บริการ'"></div></template>
                        </div>
                    </article>
                </template>
                <div class="receipt-drop-zone" :class="blocks.length === 0 && 'empty'">
                    <i class="bi bi-plus-circle"></i><span>วางส่วนประกอบ</span>
                </div>
            </div>
        </section>

        <aside class="designer-panel inspector-panel">
            <div class="panel-heading">คุณสมบัติ</div>
            <template x-if="selectedBlock">
                <div class="inspector-fields">
                    <div class="selected-kind"><i :class="paletteItem(selectedBlock.type)?.icon"></i><strong x-text="paletteItem(selectedBlock.type)?.label"></strong><span x-show="isRequired(selectedBlock.type)"><i class="bi bi-lock-fill"></i> จำเป็น</span></div>
                    <label>การจัดวาง
                        <div class="align-switch">
                            <button type="button" :class="selectedBlock.align === 'left' && 'active'" @click="selectedBlock.align = 'left'" title="ชิดซ้าย"><i class="bi bi-text-left"></i></button>
                            <button type="button" :class="selectedBlock.align === 'center' && 'active'" @click="selectedBlock.align = 'center'" title="กึ่งกลาง"><i class="bi bi-text-center"></i></button>
                            <button type="button" :class="selectedBlock.align === 'right' && 'active'" @click="selectedBlock.align = 'right'" title="ชิดขวา"><i class="bi bi-text-right"></i></button>
                        </div>
                    </label>
                    <label>ขนาดตัวอักษร
                        <select class="form-select" x-model="selectedBlock.size">
                            <option value="small">เล็ก</option><option value="medium">กลาง</option><option value="large">ใหญ่</option>
                        </select>
                    </label>
                    <label class="form-check inspector-check"><input class="form-check-input" type="checkbox" x-model="selectedBlock.bold"> <span>ตัวหนา</span></label>
                    <template x-if="selectedBlock.type === 'custom' || selectedBlock.type === 'footer'">
                        <label>ข้อความ<textarea class="form-control" rows="4" maxlength="160" x-model="selectedBlock.text"></textarea></label>
                    </template>
                    <template x-if="selectedBlock.type === 'items'">
                        <div class="inspector-options">
                            <label class="form-check"><input class="form-check-input" type="checkbox" x-model="selectedBlock.show_sku"> แสดงรหัสสินค้า</label>
                            <label class="form-check"><input class="form-check-input" type="checkbox" x-model="selectedBlock.show_unit_price"> แสดงราคาต่อหน่วย</label>
                        </div>
                    </template>
                    <button type="button" class="btn btn-outline-danger w-100" x-show="!isRequired(selectedBlock.type)" @click="removeSelected"><i class="bi bi-trash3 me-1"></i> ลบบล็อก</button>
                </div>
            </template>
            <div class="inspector-empty" x-show="!selectedBlock"><i class="bi bi-cursor"></i><span>เลือกบล็อกบนใบเสร็จ</span></div>
        </aside>
    </div>
</form>
@endsection

@push('head')
<style>
    .receipt-toolbar { min-height:58px; display:flex; align-items:center; gap:10px; padding:9px 12px; margin-bottom:12px; background:#fff; border:1px solid var(--erp-border); border-radius:8px; }
    .receipt-toolbar-title { display:flex; flex-direction:column; min-width:190px; }
    .receipt-toolbar-title strong { color:var(--erp-text); font-size:14px; }
    .receipt-toolbar-title span { color:#7890a2; font-size:11px; }
    .paper-switch { display:flex; margin-left:auto; padding:3px; border:1px solid var(--erp-border); border-radius:7px; background:var(--erp-primary-soft); }
    .paper-switch button { min-width:70px; height:32px; border:0; border-radius:5px; background:transparent; color:var(--erp-muted); font-size:11px; font-weight:800; }
    .paper-switch button.active { background:#fff; color:var(--erp-primary-ink); box-shadow:0 1px 4px rgba(34,62,78,.14); }
    .receipt-designer { height:calc(100vh - 205px); min-height:520px; display:grid; grid-template-columns:230px minmax(430px,1fr) 250px; border:1px solid var(--erp-border); border-radius:8px; overflow:hidden; background:var(--erp-surface-2); }
    .designer-panel { min-width:0; overflow:auto; background:#fff; }
    .palette-panel { border-right:1px solid var(--erp-border); }
    .inspector-panel { border-left:1px solid var(--erp-border); }
    .panel-heading { position:sticky; top:0; z-index:2; padding:14px 15px 11px; border-bottom:1px solid var(--erp-success-soft); background:#fff; color:var(--erp-text); font-size:12px; font-weight:900; }
    .palette-list { display:grid; gap:6px; padding:10px; }
    .palette-item { min-height:56px; display:grid; grid-template-columns:30px minmax(0,1fr) 18px; align-items:center; gap:8px; padding:7px 8px; border:1px solid var(--erp-border); border-radius:7px; background:#fff; color:var(--erp-primary-dark); text-align:left; }
    .palette-item:hover:not(:disabled) { border-color:#7ebed3; background:var(--erp-surface-2); }
    .palette-item:disabled { cursor:not-allowed; opacity:.42; }
    .palette-item > i:first-child { width:30px; height:30px; display:grid; place-items:center; border-radius:6px; background:var(--erp-primary-soft); color:var(--erp-primary-ink); font-size:15px; }
    .palette-item span { display:flex; min-width:0; flex-direction:column; }
    .palette-item strong { font-size:11.5px; }
    .palette-item small { overflow:hidden; color:#8295a3; font-size:9.5px; white-space:nowrap; text-overflow:ellipsis; }
    .palette-add { color:#91a3af; font-size:12px; }
    .designer-stage { overflow:auto; padding:26px 42px 50px; background:var(--erp-success-soft); }
    .stage-ruler { width:max-content; min-width:280px; height:24px; margin:0 auto 7px; display:flex; align-items:center; justify-content:center; border-top:1px solid #afbec8; color:var(--erp-muted); font-size:9px; }
    .stage-ruler span { margin-top:-24px; padding:0 8px; background:var(--erp-success-soft); }
    .receipt-paper { min-height:590px; margin:auto; padding:24px 18px 34px; background:#fff; color:#111; box-shadow:0 8px 24px rgba(37,55,66,.16); font-family:Tahoma, 'Leelawadee UI', sans-serif; transition:width .15s; }
    .receipt-paper.paper-58 { width:280px; }
    .receipt-paper.paper-80 { width:380px; }
    .receipt-block { position:relative; min-height:24px; margin:2px -7px; padding:8px 7px; border:1px solid transparent; border-radius:3px; cursor:pointer; }
    .receipt-block:hover { border-color:var(--erp-border); background:var(--erp-surface-2); }
    .receipt-block.selected { border-color:var(--erp-primary-ink); background:var(--erp-surface-2); box-shadow:0 0 0 2px rgba(22,137,173,.1); }
    .block-actions { position:absolute; z-index:3; top:-18px; right:1px; display:none; height:22px; overflow:hidden; border:1px solid #bad3dd; border-radius:4px; background:#fff; box-shadow:0 2px 6px rgba(24,52,67,.12); }
    .receipt-block.selected .block-actions { display:flex; }
    .block-actions button { width:25px; border:0; border-left:1px solid var(--erp-border); background:#fff; color:var(--erp-muted); font-size:10px; }
    .block-actions button:first-child { border-left:0; cursor:grab; }
    .block-actions button:disabled { opacity:.35; cursor:not-allowed; }
    .receipt-content { line-height:1.42; font-size:11px; }
    .receipt-content.align-left { text-align:left; }.receipt-content.align-center { text-align:center; }.receipt-content.align-right { text-align:right; }
    .receipt-content.size-small { font-size:10px; }.receipt-content.size-medium { font-size:12px; }.receipt-content.size-large { font-size:15px; }
    .receipt-content.is-bold { font-weight:800; }
    .receipt-content > div:not(.sample-meta):not(.sample-row):not(.sample-payment):not(.sample-logo):not(.sample-divider) { display:flex; flex-direction:column; }
    .sample-logo img { display:block; max-width:95px; max-height:54px; margin:auto; object-fit:contain; }
    .sample-logo strong { display:block; color:var(--erp-danger); font-size:20px; }
    .sample-meta { display:grid; gap:2px; }
    .sample-meta span { display:flex; justify-content:space-between; gap:10px; }
    .sample-meta b { font-weight:700; }.sample-meta em { font-style:normal; text-align:right; }
    .sample-divider { height:1px; border-top:1px dashed #333; }
    .sample-items { width:100%; border-collapse:collapse; font-size:inherit; text-align:left; }
    .sample-items th { padding:3px 2px; border-top:1px solid #222; border-bottom:1px solid #222; }
    .sample-items td { padding:4px 2px; border-bottom:1px dotted #999; vertical-align:top; }
    .sample-items th:nth-child(n+2), .sample-items td:nth-child(n+2) { text-align:right; white-space:nowrap; }
    .sample-items small { display:block; font-size:.85em; font-weight:400; }
    .sample-row { display:flex; justify-content:space-between; gap:8px; }.sample-row b { white-space:nowrap; }
    .sample-total { padding-top:5px; border-top:1px solid #111; }
    .sample-payment { display:flex; flex-direction:column; }
    .receipt-drop-zone { height:35px; display:flex; align-items:center; justify-content:center; gap:5px; margin-top:8px; border:1px dashed var(--erp-border); color:#9aabb5; font-size:9px; }
    .receipt-drop-zone.empty { height:120px; }
    .inspector-fields { display:grid; gap:15px; padding:14px; }
    .selected-kind { display:grid; grid-template-columns:26px minmax(0,1fr) auto; align-items:center; gap:7px; padding-bottom:12px; border-bottom:1px solid var(--erp-border); color:var(--erp-primary-dark); }
    .selected-kind > i { width:26px; height:26px; display:grid; place-items:center; border-radius:5px; background:var(--erp-primary-soft); color:var(--erp-primary-ink); }
    .selected-kind strong { font-size:12px; }.selected-kind span { color:#7b8f9e; font-size:9px; }
    .inspector-fields > label:not(.form-check), .inspector-fields template + label { display:grid; gap:6px; color:var(--erp-muted); font-size:10px; font-weight:800; }
    .align-switch { display:grid; grid-template-columns:repeat(3,1fr); padding:3px; border:1px solid var(--erp-border); border-radius:6px; background:var(--erp-surface-2); }
    .align-switch button { height:32px; border:0; border-radius:4px; background:transparent; color:var(--erp-muted); }
    .align-switch button.active { background:#fff; color:var(--erp-primary-ink); box-shadow:0 1px 3px rgba(34,62,78,.14); }
    .inspector-check { display:flex; align-items:center; gap:7px; margin:0; color:var(--erp-muted); font-size:11px; }
    .inspector-options { display:grid; gap:9px; padding:10px; border:1px solid var(--erp-border); border-radius:6px; font-size:10.5px; }
    .inspector-empty { min-height:220px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#99a9b4; font-size:11px; }
    .inspector-empty i { font-size:28px; }
    @media (max-width:1200px) { .receipt-designer { grid-template-columns:200px minmax(390px,1fr) 220px; }.designer-stage { padding-left:25px; padding-right:25px; } }
</style>
<script>
function receiptDesigner(savedTemplate, defaultTemplate, company) {
    const palette = [
        { type:'logo', label:'โลโก้', detail:'ตราสัญลักษณ์กิจการ', icon:'bi bi-image', align:'center', size:'medium' },
        { type:'company', label:'ข้อมูลกิจการ', detail:'ชื่อ ที่อยู่ เลขผู้เสียภาษี', icon:'bi bi-building', align:'center', size:'small', required:true },
        { type:'title', label:'ชื่อเอกสาร', detail:'ใบเสร็จ/ใบกำกับภาษีอย่างย่อ', icon:'bi bi-receipt', align:'center', size:'medium', bold:true, required:true },
        { type:'meta', label:'ข้อมูลบิล', detail:'เลขที่ วันที่ สาขา แคชเชียร์', icon:'bi bi-card-list', align:'left', size:'small', required:true },
        { type:'divider', label:'เส้นคั่น', detail:'แบ่งส่วนบนกระดาษ', icon:'bi bi-dash-lg', align:'left', size:'small', repeatable:true },
        { type:'items', label:'ตารางสินค้า', detail:'รายการ จำนวน ราคา', icon:'bi bi-table', align:'left', size:'small', required:true },
        { type:'tax-summary', label:'สรุปภาษี', detail:'มูลค่าก่อนภาษีและ VAT', icon:'bi bi-percent', align:'left', size:'small' },
        { type:'totals', label:'ยอดรวมสุทธิ', detail:'ยอดชำระทั้งบิล', icon:'bi bi-cash-stack', align:'left', size:'large', bold:true, required:true },
        { type:'payment', label:'การชำระเงิน', detail:'วิธีชำระ รับเงิน เงินทอน', icon:'bi bi-credit-card', align:'left', size:'small' },
        { type:'custom', label:'ข้อความกำหนดเอง', detail:'ข้อความเพิ่มเติมบนบิล', icon:'bi bi-fonts', align:'center', size:'small', repeatable:true },
        { type:'footer', label:'ข้อความท้ายบิล', detail:'คำขอบคุณหรือเงื่อนไข', icon:'bi bi-chat-square-text', align:'center', size:'small' },
    ];
    const clone = value => JSON.parse(JSON.stringify(value));
    return {
        palette,
        company,
        paperWidth: savedTemplate.paper_width || 80,
        blocks: clone(savedTemplate.blocks || []),
        selectedId: savedTemplate.blocks?.[0]?.id || null,
        get selectedBlock() { return this.blocks.find(block => block.id === this.selectedId) || null; },
        paletteItem(type) { return this.palette.find(item => item.type === type); },
        hasType(type) { return this.blocks.some(block => block.type === type); },
        isRequired(type) { return ['company','title','meta','items','totals'].includes(type); },
        makeBlock(type) {
            const item = this.paletteItem(type);
            return {
                id: `${type}-${Date.now()}-${Math.random().toString(16).slice(2,7)}`,
                type,
                align: item?.align || 'left',
                size: item?.size || 'small',
                bold: !!item?.bold,
                text: type === 'footer' ? 'ขอบคุณที่ใช้บริการ' : '',
                show_sku: false,
                show_unit_price: true,
            };
        },
        addBlock(type, target = this.blocks.length) {
            const item = this.paletteItem(type);
            if (!item || (!item.repeatable && this.hasType(type))) {
                const existing = this.blocks.find(block => block.type === type);
                if (existing) this.selectedId = existing.id;
                return;
            }
            const block = this.makeBlock(type);
            this.blocks.splice(target, 0, block);
            this.selectedId = block.id;
        },
        startPalette(event, type) {
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', JSON.stringify({ kind:'palette', type }));
        },
        startBlock(event, index) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', JSON.stringify({ kind:'block', index }));
        },
        dropAt(event, target) {
            let payload;
            try { payload = JSON.parse(event.dataTransfer.getData('text/plain')); } catch (_) { return; }
            if (payload.kind === 'palette') return this.addBlock(payload.type, target);
            if (payload.kind !== 'block' || payload.index === target) return;
            const [block] = this.blocks.splice(payload.index, 1);
            const insertAt = payload.index < target ? target - 1 : target;
            this.blocks.splice(insertAt, 0, block);
            this.selectedId = block.id;
        },
        moveBlock(index, direction) {
            const target = index + direction;
            if (target < 0 || target >= this.blocks.length) return;
            [this.blocks[index], this.blocks[target]] = [this.blocks[target], this.blocks[index]];
            this.blocks = [...this.blocks];
            this.selectedId = this.blocks[target].id;
        },
        removeBlock(index) {
            if (this.isRequired(this.blocks[index]?.type)) return;
            this.blocks.splice(index, 1);
            this.selectedId = this.blocks[Math.min(index, this.blocks.length - 1)]?.id || null;
        },
        removeSelected() {
            const index = this.blocks.findIndex(block => block.id === this.selectedId);
            if (index >= 0) this.removeBlock(index);
        },
        resetTemplate() {
            this.paperWidth = defaultTemplate.paper_width;
            this.blocks = clone(defaultTemplate.blocks);
            this.selectedId = this.blocks[0]?.id || null;
        },
        blockClasses(block) {
            return [`align-${block.align}`, `size-${block.size}`, { 'is-bold': block.bold }];
        },
    };
}
</script>
@endpush
