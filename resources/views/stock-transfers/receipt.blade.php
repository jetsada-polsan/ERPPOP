<?php /* resources/views/stock-transfers/receipt.blade.php */ ?>
@extends('layout')

@section('title', 'ตรวจรับสินค้าโอนย้าย ' . $transfer->doc_number . ' - PopCentral')
@section('page-title', 'ตรวจรับสินค้าโอนย้ายด้วยสแกน')
@section('page-subtitle', $transfer->doc_number . ' · เทียบยอดที่สแกนได้จริงกับยอดในใบโอนย้าย ไม่กระทบสต๊อกที่โอนไปแล้ว')

@section('content')
<div x-data="stockTransferReceiptSheet()" x-init="init()" x-cloak>
    <a href="{{ route('stock-transfers.show', $transfer) }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปใบโอนย้าย {{ $transfer->doc_number }}
    </a>

    @if($receipt->isEditable())
    <div class="str-scan-panel mb-3">
        <div class="str-scan-row">
            <i class="bi bi-upc-scan"></i>
            <input x-ref="scanBox" x-model="scanCode" @keydown.enter.prevent="scanNow()" inputmode="none" autocomplete="off" placeholder="สแกนบาร์โค้ดสินค้า แล้ว Enter (ไม่มีบาร์โค้ดใช้ช่องกรอกจำนวนในตารางด้านล่างแทนได้)">
            <button type="button" @click="scanNow()">เพิ่ม +1</button>
        </div>
        <div class="str-last" x-show="lastRow">
            <span><small>รายการล่าสุด</small><b x-text="lastRow?.name"></b><em x-text="lastRow?.sku + ' · ' + lastRow?.unit"></em></span>
            <strong x-text="lastRow ? money(lastRow.scanned || 0) : '0'"></strong>
        </div>
        <div class="str-quick">
            <button type="button" @click="addQuick(1)">+1</button>
            <button type="button" @click="addQuick(5)">+5</button>
            <button type="button" @click="addQuick(lastRow?.pack || 1)">+ลัง <small x-text="lastRow?.pack > 1 ? lastRow.pack : ''"></small></button>
            <button type="button" class="undo" @click="undoLast()"><i class="bi bi-arrow-counterclockwise"></i> ย้อนล่าสุด</button>
        </div>
        <div class="str-stats">
            <span>ทั้งหมด <b x-text="items.length"></b></span>
            <span>สแกน/กรอกแล้ว <b x-text="scannedCount"></b></span>
            <span>ยอดไม่ตรง <b x-text="diffCount"></b></span>
            <span class="ms-auto" x-show="dirty">
                <i class="bi bi-cloud-arrow-up"></i> <span x-text="saving ? 'กำลังบันทึก...' : 'มีการเปลี่ยนแปลงที่ยังไม่ได้ Sync'"></span>
            </span>
        </div>
    </div>
    @endif

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 str-grid">
                <thead>
                    <tr>
                        <th style="width:48px">#</th>
                        <th style="width:120px">รหัส</th>
                        <th>รายการ</th>
                        <th style="width:80px">หน่วย</th>
                        <th class="text-end" style="width:110px">ตามใบโอน</th>
                        <th class="text-end" style="width:130px">สแกน/กรอกจริง</th>
                        <th class="text-end" style="width:100px">ผลต่าง</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, idx) in items" :key="row.id">
                        <tr :class="{ 'str-diff': hasDiff(row), 'str-ok': row.scanned !== null && !hasDiff(row) }">
                            <td class="text-muted" x-text="idx + 1"></td>
                            <td class="fw-semibold" style="color:var(--erp-primary)" x-text="row.sku"></td>
                            <td x-text="row.name"></td>
                            <td class="text-muted" x-text="row.unit"></td>
                            <td class="text-end" x-text="money(row.expected)"></td>
                            <td class="text-end">
                                @if($receipt->isEditable())
                                <input type="number" step="0.0001" min="0" class="form-control form-control-sm text-end str-input"
                                    :value="row.scanned"
                                    @input="setScanned(row, $event.target.value)">
                                @else
                                <span x-text="row.scanned !== null ? money(row.scanned) : '-'"></span>
                                @endif
                            </td>
                            <td class="text-end fw-bold" :style="diffStyle(row)" x-text="diffText(row)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        @if($receipt->isEditable())
        <button type="button" class="btn btn-outline-primary" :disabled="!dirty || saving" @click="saveAll()">
            <i class="bi bi-cloud-arrow-up me-1"></i> Sync ตอนนี้
        </button>
        <form method="post" action="{{ route('stock-transfers.receipt.complete', $transfer) }}" @submit.prevent="finish($el)">
            @csrf
            <input type="hidden" name="note" :value="note">
            <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> จบการตรวจรับ</button>
        </form>
        @else
        <span class="badge text-bg-success fs-6 px-3 py-2">
            ตรวจรับปิดแล้ว @if($receipt->confirmed_at) เมื่อ {{ $receipt->confirmed_at->thaiDate(true) }} @endif
        </span>
        @endif
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .str-grid td { font-size: 13px; }
    .str-grid tbody tr:nth-child(even) { background: var(--erp-surface-2); }
    .str-grid tr.str-ok { background: var(--erp-success-soft) !important; }
    .str-grid tr.str-diff { background: var(--erp-warning-soft) !important; }
    .str-input { max-width: 130px; margin-left: auto; }
    .str-input:focus { border-color: var(--erp-primary); box-shadow: 0 0 0 3px rgba(14,165,233,.15); }
    .str-scan-panel{overflow:hidden;border:1px solid var(--erp-border);border-radius:14px;background:#fff;box-shadow:0 10px 28px rgba(15,70,100,.1)}
    .str-scan-row{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;margin:14px;border:2px solid var(--erp-primary);border-radius:10px;overflow:hidden}
    .str-scan-row>i{font-size:19px;text-align:center;color:var(--erp-primary-ink)}
    .str-scan-row input{height:48px;border:0;outline:0;font-size:15px;font-weight:600;padding:0 8px}
    .str-scan-row button{height:48px;padding:0 18px;border:0;background:var(--erp-primary-ink);color:#fff;font-weight:800}
    .str-last{display:flex;align-items:center;justify-content:space-between;margin:0 14px 10px;padding:10px 12px;border:1px solid var(--erp-border);border-radius:9px;background:var(--erp-surface-2)}
    .str-last small,.str-last b,.str-last em{display:block}
    .str-last small{color:#7890a0;font-size:9px}.str-last b{color:var(--erp-text);font-size:14px}
    .str-last em{color:var(--erp-muted);font-size:10px;font-style:normal}.str-last strong{color:var(--erp-success-ink);font-size:24px}
    .str-quick{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;padding:0 14px 12px}
    .str-quick button{min-height:42px;border:1px solid var(--erp-border);border-radius:8px;background:#fff;color:var(--erp-info);font-weight:800}
    .str-quick button:hover{background:var(--erp-primary-soft)}.str-quick .undo{color:var(--erp-warning-ink)}
    .str-stats{display:flex;gap:20px;padding:9px 14px;background:var(--erp-primary-soft);color:var(--erp-muted);font-size:11px;flex-wrap:wrap}
    .str-stats b{color:var(--erp-text);font-size:13px}
    @media(max-width:760px){.str-quick{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush

@push('scripts')
<script>
function stockTransferReceiptSheet() {
    return {
        items: @json($itemsJson),
        scanCode: '', lastRow: null, history: [], dirty: false, saving: false, note: '',

        init() { this.$nextTick(() => this.$refs.scanBox?.focus()); },

        get scannedCount() { return this.items.filter(i => i.scanned !== null).length; },
        get diffCount() { return this.items.filter(i => this.hasDiff(i)).length; },

        hasDiff(row) { return row.scanned !== null && Math.abs(row.scanned - row.expected) > 0.0001; },
        diffText(row) {
            if (row.scanned === null) return '';
            const d = Math.round((row.scanned - row.expected) * 10000) / 10000;
            return (d > 0 ? '+' : '') + this.money(d);
        },
        diffStyle(row) {
            if (row.scanned === null) return '';
            const d = row.scanned - row.expected;
            if (Math.abs(d) <= 0.0001) return 'color:var(--erp-success-ink)';
            return d > 0 ? 'color:#2563eb' : 'color:var(--erp-danger)';
        },
        money(v) { return Number(v || 0).toLocaleString('th-TH', { maximumFractionDigits: 4 }); },

        findScan(code) {
            const clean = String(code || '').trim();
            return this.items.find(i => i.sku === clean || (i.barcodes || []).includes(clean));
        },
        scanNow() {
            const code = this.scanCode.trim(); if (!code) return;
            const row = this.findScan(code); this.scanCode = '';
            if (!row) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'ไม่พบบาร์โค้ดในใบโอนย้ายนี้', showConfirmButton: false, timer: 1800 });
                this.$refs.scanBox?.focus();
                return;
            }
            this.applyQty(row, 1);
            this.$refs.scanBox?.focus();
        },
        applyQty(row, qty) {
            const before = row.scanned === null ? 0 : Number(row.scanned);
            const after = Math.round((before + Number(qty)) * 10000) / 10000;
            this.history.push({ id: row.id, before });
            row.scanned = after; this.lastRow = row; this.dirty = true;
        },
        addQuick(qty) { if (this.lastRow) this.applyQty(this.lastRow, qty); else this.$refs.scanBox?.focus(); },
        undoLast() {
            const last = this.history.pop(); if (!last) return;
            const row = this.items.find(i => i.id === last.id);
            if (row) { row.scanned = last.before; this.dirty = true; }
        },
        setScanned(row, value) {
            row.scanned = value === '' ? null : Math.max(0, Number(value));
            this.dirty = true;
        },

        async saveAll() {
            this.saving = true;
            try {
                const res = await fetch('{{ route('stock-transfers.receipt.save-items', $transfer) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                    body: JSON.stringify({ items: this.items.map(i => ({ id: i.id, scanned: i.scanned })) }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message || 'บันทึกไม่สำเร็จ');
                this.dirty = false;
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Sync ไม่สำเร็จ', text: e.message });
            } finally {
                this.saving = false;
            }
        },

        async finish(formEl) {
            const missing = this.items.filter(i => i.scanned === null);
            if (missing.length > 0) {
                const ok = await Swal.fire({
                    icon: 'warning', title: 'ยังสแกน/กรอกไม่ครบ',
                    html: `ยังไม่ได้ตรวจ ${missing.length} รายการ: ` + missing.slice(0, 8).map(i => i.sku).join(', '),
                    showCancelButton: true, confirmButtonText: 'กลับไปตรวจต่อ', cancelButtonText: 'ปิด', reverseButtons: true,
                });
                return; // ปิดใบต้องครบทุกรายการ - ระบบฝั่งเซิร์ฟเวอร์บังคับเช่นกัน
            }
            if (this.dirty) await this.saveAll();
            const diffs = this.items.filter(i => this.hasDiff(i));
            if (diffs.length > 0) {
                const result = await Swal.fire({
                    icon: 'warning', title: `พบยอดไม่ตรง ${diffs.length} รายการ`,
                    html: 'ยืนยันปิดการตรวจรับ? ระบบจะบันทึกไว้เป็นหลักฐาน ไม่ได้แก้สต๊อกให้อัตโนมัติ - ถ้าใช่จริงต้องไปสร้างใบปรับสต๊อกแยกต่างหากภายหลัง',
                    showCancelButton: true, confirmButtonText: 'ยืนยันปิด', cancelButtonText: 'ยกเลิก', reverseButtons: true,
                });
                if (!result.isConfirmed) return;
            }
            formEl.submit();
        },
    };
}
</script>
@endpush
