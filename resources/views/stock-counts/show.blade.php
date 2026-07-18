@extends('layout')
@section('title', $stockCount->doc_number . ' - ตรวจนับสินค้า - POPSTAR ERP')
@section('page-title', 'ใบตรวจนับสินค้า ' . $stockCount->doc_number)
@section('page-subtitle', $stockCount->branch->name_th . ' · ' . $stockCount->warehouseLocation->name . ' · เปิดใบ ' . $stockCount->created_at->thaiDate(true))

@section('content')
<div x-data="stockCountSheet()" x-init="init()" x-cloak>
    <a href="{{ route('stock-counts.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการใบตรวจนับ
    </a>

    @if($stockCount->isEditable())
    <div class="sc-tablet-panel mb-3">
        <div class="sc-tablet-head">
            <span><b>{{ $stockCount->doc_number }}</b><small>{{ $stockCount->count_mode === 'full_zero_missing' ? 'นับเต็ม — รายการไม่ได้นับจะเป็น 0' : 'นับบางส่วน' }}</small></span>
            <span class="sc-online" :class="online?'is-online':'is-offline'"><i class="bi" :class="online?'bi-wifi':'bi-wifi-off'"></i><b x-text="online?'Online':'Offline — เก็บในเครื่อง'"></b></span>
        </div>
        <div class="sc-scan-row">
            <i class="bi bi-upc-scan"></i>
            <input x-ref="scanBox" x-model="scanCode" @keydown.enter.prevent="scanNow()" inputmode="none" autocomplete="off" placeholder="รอสแกนบาร์โค้ด แล้วกด Enter">
            <button type="button" @click="scanNow()">เพิ่ม +1</button>
        </div>
        <div class="sc-last" x-show="lastRow">
            <span><small>รายการล่าสุด</small><b x-text="lastRow?.name"></b><em x-text="lastRow?.sku + ' · ' + lastRow?.unit"></em></span>
            <strong x-text="lastRow ? money(lastRow.counted || 0) : '0'"></strong>
        </div>
        <div class="sc-quick">
            <button type="button" @click="addQuick(1)">+1</button><button type="button" @click="addQuick(5)">+5</button><button type="button" @click="addQuick(10)">+10</button>
            <button type="button" @click="addQuick(lastRow?.pack || 1)">+ลัง <small x-text="lastRow?.pack > 1 ? lastRow.pack : ''"></small></button>
            <button type="button" class="undo" @click="undoLast()"><i class="bi bi-arrow-counterclockwise"></i> ย้อนล่าสุด</button>
            <button type="button" class="sync" @click="saveAll()" :disabled="!dirty||saving"><i class="bi bi-cloud-arrow-up"></i> <span x-text="saving?'กำลัง Sync':'Sync ตอนนี้'"></span></button>
        </div>
        <div class="sc-tablet-stats"><span>นับแล้ว <b x-text="countedCount"></b></span><span>รอ Sync <b x-text="Object.keys(changed).length"></b></span><span>ผลต่าง <b x-text="diffCount"></b></span></div>
    </div>
    @endif

    {{-- Toolbar: ค้นหา + export/import + สรุป + ปุ่มบันทึก/ปรับปรุง --}}
    <div class="content-card p-3 mb-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="position-relative flex-grow-1" style="min-width:280px;max-width:480px">
                <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8"></i>
                <input type="text" x-model="q" x-ref="searchBox" @input="openDrop = true" @keydown.enter.prevent="pickFirst()"
                    @keydown.escape="openDrop = false"
                    class="form-control ps-5" placeholder="ค้นหา/สแกน รหัส บาร์โค้ด ชื่อสินค้า แล้ว Enter">
                {{-- Dropdown เด้งผลลัพธ์เหมือน select --}}
                <div x-show="openDrop && q.length > 0" @click.outside="openDrop = false"
                    style="position:absolute;z-index:120;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.15);max-height:300px;overflow-y:auto">
                    <template x-for="row in matches.slice(0, 12)" :key="row.id">
                        <div @click="pick(row)" style="padding:9px 14px;cursor:pointer;font-size:13px;display:flex;gap:10px;border-bottom:1px solid #f1f5f9"
                            @mouseenter="$el.style.background='#f0f9ff'" @mouseleave="$el.style.background=''">
                            <span x-text="row.sku" style="color:#0284c7;font-weight:700;min-width:90px"></span>
                            <span x-text="row.name" class="text-truncate"></span>
                            <span x-text="money(row.system)" style="margin-left:auto;color:#64748b"></span>
                        </div>
                    </template>
                    <div x-show="matches.length === 0" class="text-muted small p-3">ไม่พบสินค้าในใบนี้</div>
                </div>
            </div>

            <span class="badge text-bg-light border">ทั้งหมด <span x-text="items.length.toLocaleString()"></span></span>
            <span class="badge text-bg-info">นับแล้ว <span x-text="countedCount.toLocaleString()"></span></span>
            <span class="badge text-bg-warning">ต่าง <span x-text="diffCount.toLocaleString()"></span></span>

            <div class="ms-auto d-flex gap-2 flex-wrap">
                <a href="{{ route('stock-counts.export', $stockCount) }}" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel (CSV)
                </a>
                @if($stockCount->isEditable())
                <form method="post" action="{{ route('stock-counts.import', $stockCount) }}" enctype="multipart/form-data" class="d-flex gap-1">
                    @csrf
                    <input type="file" name="file" accept=".csv" required class="form-control form-control-sm" style="max-width:220px">
                    <button class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-upload me-1"></i>Import</button>
                </form>
                <button type="button" class="btn btn-primary btn-sm" :disabled="!dirty || saving" @click="saveAll()">
                    <i class="bi bi-check-lg me-1"></i><span x-text="saving ? 'กำลังบันทึก...' : 'บันทึกยอดนับ'"></span>
                </button>
                <form method="post" action="{{ route('stock-counts.submit', $stockCount) }}"
                    @submit.prevent="finishCount($el)">
                    @csrf
                    <button class="btn btn-info btn-sm"><i class="bi bi-send-check me-1"></i>จบการนับ / ส่งตรวจ</button>
                </form>
                @else
                @if($stockCount->status === 'review')
                <form method="post" action="{{ route('stock-counts.post', $stockCount) }}" onsubmit="return confirm('ยืนยันปรับสต็อกจริงตาม Scope นี้? การทำงานนี้ย้อนกลับอัตโนมัติไม่ได้')">@csrf
                    <button class="btn btn-success btn-sm"><i class="bi bi-shield-check me-1"></i>Manager ยืนยันปรับสต็อกจริง</button>
                </form>
                @else
                <span class="badge text-bg-success align-self-center px-3 py-2">ปรับปรุงแล้ว
                    @if($stockCount->postedDocument)
                        · <a href="{{ route('stock-adjustments.show', $stockCount->posted_document_id) }}" class="text-white">{{ $stockCount->postedDocument->doc_number }}</a>
                    @endif
                </span>
                @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Entry grid --}}
    <div class="content-card overflow-hidden">
        <div class="table-responsive" style="max-height:calc(100vh - 300px);overflow-y:auto">
            <table class="table table-sm align-middle mb-0 sc-grid">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th style="width:120px">รหัสซื้อขาย</th>
                        <th>รายการ</th>
                        <th style="width:90px">หน่วยนับ</th>
                        <th class="text-end" style="width:110px">ยอดตามระบบ</th>
                        <th class="text-end" style="width:130px">นับจริง</th>
                        <th class="text-end" style="width:110px">ผลต่าง</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, idx) in visible" :key="row.id">
                        <tr :class="{ 'sc-diff': hasDiff(row), 'sc-counted': row.counted !== null && !hasDiff(row), 'sc-active': activeId === row.id }" :id="'scrow-' + row.id">
                            <td class="text-muted" x-text="idx + 1"></td>
                            <td class="fw-semibold" style="color:#0284c7" x-text="row.sku"></td>
                            <td x-text="row.name"></td>
                            <td class="text-muted" x-text="row.unit"></td>
                            <td class="text-end" x-text="money(row.system)"></td>
                            <td class="text-end">
                                @if($stockCount->isEditable())
                                <input type="number" step="0.0001" min="0" class="form-control form-control-sm text-end sc-input"
                                    :id="'scin-' + row.id"
                                    :value="row.counted"
                                    @input="setCounted(row, $event.target.value)"
                                    @focus="activeId = row.id; $event.target.select()"
                                    @keydown.enter.prevent="focusNext(row)">
                                @else
                                <span x-text="row.counted !== null ? money(row.counted) : '-'"></span>
                                @endif
                            </td>
                            <td class="text-end fw-bold" :style="diffStyle(row)" x-text="diffText(row)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="p-2 text-center text-muted small border-top" x-show="visible.length < filtered.length">
            แสดง <span x-text="visible.length.toLocaleString()"></span> จาก <span x-text="filtered.length.toLocaleString()"></span> รายการ — พิมพ์ค้นหาเพื่อกรอง หรือ
            <button type="button" class="btn btn-link btn-sm p-0" @click="limit += 500">แสดงเพิ่ม</button>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .sc-grid thead th {
        position: sticky; top: 0; z-index: 5;
        background: #0f172a; color: #e2e8f0;
        font-size: 12px; font-weight: 800; white-space: nowrap;
    }
    .sc-grid td { font-size: 13px; }
    .sc-grid tbody tr:nth-child(even) { background: #f8fafc; }
    .sc-grid tr.sc-counted { background: #ecfdf5 !important; }
    .sc-grid tr.sc-diff { background: #fef3c7 !important; }
    .sc-grid tr.sc-active { outline: 2px solid #0ea5e9; outline-offset: -2px; }
    .sc-input { max-width: 120px; margin-left: auto; }
    .sc-input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,.15); }
    .sc-tablet-panel{overflow:hidden;border:1px solid #b8d5e4;border-radius:14px;background:#fff;box-shadow:0 10px 28px rgba(15,70,100,.1)}
    .sc-tablet-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:linear-gradient(120deg,#0b789f,#149dc8);color:#fff}.sc-tablet-head span:first-child b,.sc-tablet-head span:first-child small{display:block}.sc-tablet-head small{opacity:.82;font-size:10px}.sc-online{display:flex;align-items:center;gap:6px;padding:5px 9px;border-radius:14px;background:rgba(255,255,255,.16);font-size:10px}.sc-online.is-offline{background:#fff0d1;color:#9a5800}
    .sc-scan-row{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;margin:12px;border:2px solid #20a6d6;border-radius:10px;overflow:hidden}.sc-scan-row>i{font-size:19px;text-align:center;color:#168eb9}.sc-scan-row input{height:48px;border:0;outline:0;font-size:17px;font-weight:700}.sc-scan-row button{height:48px;padding:0 18px;border:0;background:#168fbe;color:#fff;font-weight:800}
    .sc-last{display:flex;align-items:center;justify-content:space-between;margin:0 12px 10px;padding:10px 12px;border:1px solid #d9e7ef;border-radius:9px;background:#f6fbfd}.sc-last small,.sc-last b,.sc-last em{display:block}.sc-last small{color:#7890a0;font-size:9px}.sc-last b{color:#18384c;font-size:14px}.sc-last em{color:#6e8797;font-size:10px;font-style:normal}.sc-last strong{color:#09895d;font-size:24px}
    .sc-quick{display:grid;grid-template-columns:repeat(6,1fr);gap:7px;padding:0 12px 10px}.sc-quick button{min-height:42px;border:1px solid #bdd4e0;border-radius:8px;background:#fff;color:#176d92;font-weight:800}.sc-quick button:hover{background:#eaf7fc}.sc-quick .undo{color:#a26413}.sc-quick .sync{border-color:#1599c6;background:#1599c6;color:#fff}.sc-tablet-stats{display:flex;gap:20px;padding:7px 14px;background:#edf6fa;color:#587183;font-size:10px}.sc-tablet-stats b{color:#123d55;font-size:12px}@media(max-width:760px){.sc-quick{grid-template-columns:repeat(3,1fr)}.sc-scan-row input{font-size:14px}}
</style>
@endpush

@push('scripts')
<script>
function stockCountSheet() {
    return {
        items: @json($itemsJson),
        q: '', scanCode: '', openDrop: false, activeId: null, lastRow: null, history: [], online: navigator.onLine,
        dirty: false, saving: false, changed: {},
        limit: 500,

        init() {
            const saved = localStorage.getItem('stock-count-draft-{{ $stockCount->id }}');
            if (saved) { try { const draft=JSON.parse(saved); Object.entries(draft).forEach(([id,val])=>{ const row=this.items.find(i=>i.id===Number(id)); if(row){row.counted=val;this.changed[id]=val;} }); this.dirty=Object.keys(this.changed).length>0; } catch(e){} }
            window.addEventListener('online',()=>{this.online=true;this.saveAll()});
            window.addEventListener('offline',()=>this.online=false);
            this.$nextTick(()=>this.$refs.scanBox?.focus());
        },

        get filtered() {
            const term = this.q.trim().toLowerCase();
            if (!term) return this.items;
            return this.items.filter(i => i.search.includes(term));
        },

        get matches() { return this.filtered === this.items ? [] : this.filtered; },
        get visible() { return this.filtered.slice(0, this.limit); },
        get countedCount() { return this.items.filter(i => i.counted !== null).length; },
        get diffCount() { return this.items.filter(i => this.hasDiff(i)).length; },

        hasDiff(row) {
            return row.counted !== null && Math.abs(row.counted - row.system) > 0.0001;
        },
        diffText(row) {
            if (row.counted === null) return '';
            const d = Math.round((row.counted - row.system) * 10000) / 10000;
            return (d > 0 ? '+' : '') + this.money(d);
        },
        diffStyle(row) {
            if (row.counted === null) return '';
            const d = row.counted - row.system;
            if (Math.abs(d) <= 0.0001) return 'color:#059669';
            return d > 0 ? 'color:#2563eb' : 'color:#dc2626';
        },
        money(v) {
            return Number(v || 0).toLocaleString('th-TH', { maximumFractionDigits: 4 });
        },

        setCounted(row, value) {
            row.counted = value === '' ? null : Math.max(0, Number(value));
            this.changed[row.id] = row.counted;
            this.dirty = true;
            this.persistDraft();
        },

        persistDraft(){ localStorage.setItem('stock-count-draft-{{ $stockCount->id }}',JSON.stringify(this.changed)); },
        findScan(code){
            const clean=String(code||'').trim();
            let row=this.items.find(i=>i.sku===clean || (i.barcodes||[]).includes(clean));
            if(!row && /^\d{13}$/.test(clean)) row=this.items.find(i=>i.sku===clean.slice(0,6) || (i.barcodes||[]).some(b=>b.slice(0,6)===clean.slice(0,6)));
            return row;
        },
        scanNow(){
            const code=this.scanCode.trim(); if(!code)return;
            const row=this.findScan(code); this.scanCode='';
            if(!row){ Swal.fire({toast:true,position:'top-end',icon:'error',title:'ไม่พบบาร์โค้ดใน Scope นี้',showConfirmButton:false,timer:1800}); this.$refs.scanBox?.focus(); return; }
            let qty=1;
            if(/^\d{13}$/.test(code) && row.price>0 && row.sku===code.slice(0,6)){ const amount=Number(code.slice(6,12))/100; qty=amount/row.price; }
            this.applyQty(row,qty); this.$refs.scanBox?.focus();
        },
        applyQty(row,qty){
            const before=row.counted===null?0:Number(row.counted); const after=Math.round((before+Number(qty))*10000)/10000;
            this.history.push({id:row.id,before}); row.counted=after; this.changed[row.id]=after; this.lastRow=row; this.activeId=row.id; this.dirty=true; this.persistDraft();
        },
        addQuick(qty){ if(this.lastRow)this.applyQty(this.lastRow,qty); else this.$refs.scanBox?.focus(); },
        undoLast(){ const h=this.history.pop(); if(!h)return; const row=this.items.find(i=>i.id===h.id); if(row){row.counted=h.before;this.changed[row.id]=h.before;this.lastRow=row;this.dirty=true;this.persistDraft();} },

        // ค้นหาแล้วเด้งเลือก: คลิกหรือ Enter → เลื่อนไปแถวนั้นและโฟกัสช่องนับทันที
        pick(row) {
            this.openDrop = false;
            this.q = '';
            this.activeId = row.id;
            this.$nextTick(() => {
                const el = document.getElementById('scrow-' + row.id);
                el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                document.getElementById('scin-' + row.id)?.focus();
            });
        },
        pickFirst() {
            if (this.matches.length > 0) this.pick(this.matches[0]);
        },
        focusNext(row) {
            const idx = this.visible.findIndex(i => i.id === row.id);
            const next = this.visible[idx + 1];
            if (next) {
                this.activeId = next.id;
                document.getElementById('scin-' + next.id)?.focus();
            } else {
                this.$refs.searchBox?.focus();
            }
        },

        async saveAll() {
            const payload = Object.entries(this.changed).map(([id, counted]) => ({ id: Number(id), counted }));
            if (payload.length === 0) return true;
            this.saving = true;
            let success = false;
            try {
                const res = await fetch('{{ route('stock-counts.items.save', $stockCount) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ items: payload }),
                });
                const data = await res.json();
                if (data.success) {
                    this.changed = {};
                    this.dirty = false;
                    localStorage.removeItem('stock-count-draft-{{ $stockCount->id }}');
                    success = true;
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'บันทึกยอดนับ ' + data.updated + ' รายการแล้ว', showConfirmButton: false, timer: 2200 });
                } else {
                    Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'เชื่อมต่อ server ไม่ได้' });
            }
            this.saving = false;
            return success;
        },

        async finishCount(form) {
            if (this.dirty && !(await this.saveAll())) return;
            if (confirm('ส่งใบตรวจนับให้หัวหน้าตรวจสอบ? ขั้นตอนนี้ยังไม่ปรับสต็อกจริง')) {
                form.submit();
            }
        },
    };
}
</script>
@endpush
