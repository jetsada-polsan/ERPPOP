@extends('layout')
@section('title', 'ภาพรวมผู้บริหาร')

@section('content')
<div class="exec-shell" x-data="execBoard()" x-init="start()">
    <div class="exec-head no-print">
        <div>
            <h1>ภาพรวมผู้บริหาร</h1>
            <p>{{ $branchName }} · วันนี้ {{ now()->format('j M Y') }}</p>
        </div>
        <div class="exec-head-meta">
            <span class="exec-live"><span class="exec-dot"></span> อัปเดตอัตโนมัติทุก {{ $refreshSeconds }} วินาที</span>
            <span class="exec-stamp">ล่าสุด <b x-text="stamp"></b></span>
            <button class="exec-btn" @click="refresh()" :disabled="loading">
                <i class="bi bi-arrow-clockwise"></i> รีเฟรช
            </button>
            <button class="exec-btn" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์</button>
        </div>
    </div>

    <div class="exec-kpis">
        <div class="exec-kpi exec-kpi-lead">
            <span class="exec-kpi-label">ยอดขายวันนี้</span>
            <strong class="exec-kpi-value" x-text="money(data.today.sales)"></strong>
            <span class="exec-kpi-foot" x-html="deltaLabel(data.compare.sales, 'เทียบเมื่อวาน')"></span>
        </div>
        <div class="exec-kpi">
            <span class="exec-kpi-label">กำไรขั้นต้น</span>
            <strong class="exec-kpi-value" x-text="money(data.today.profit)"></strong>
            <span class="exec-kpi-foot"><b x-text="data.today.margin + '%'"></b> ของยอดขาย</span>
        </div>
        <div class="exec-kpi">
            <span class="exec-kpi-label">จำนวนบิล</span>
            <strong class="exec-kpi-value" x-text="number(data.today.bills)"></strong>
            <span class="exec-kpi-foot" x-html="deltaLabel(data.compare.bills, 'เทียบเมื่อวาน')"></span>
        </div>
        <div class="exec-kpi">
            <span class="exec-kpi-label">บิลเฉลี่ย</span>
            <strong class="exec-kpi-value" x-text="money(data.today.average_bill)"></strong>
            <span class="exec-kpi-foot">ต่อใบ</span>
        </div>
    </div>

    <div class="exec-grid">
        <section class="exec-card exec-card-wide">
            <h2>แนวโน้มยอดขาย 14 วัน</h2>
            <template x-if="trendMax() === 0">
                <p class="exec-empty">ยังไม่มียอดขายในช่วงนี้</p>
            </template>
            <svg class="exec-chart" viewBox="0 0 720 220" preserveAspectRatio="none" x-show="trendMax() > 0">
                <polyline :points="trendArea()" class="exec-area"></polyline>
                <polyline :points="trendLine()" class="exec-line"></polyline>
                <template x-for="(point, index) in data.trend" :key="point.date">
                    <circle :cx="trendX(index)" :cy="trendY(point.sales)" r="3.5" class="exec-point">
                        <title x-text="point.label + ' — ' + money(point.sales)"></title>
                    </circle>
                </template>
            </svg>
            <div class="exec-axis" x-show="trendMax() > 0">
                <template x-for="point in data.trend" :key="'l' + point.date">
                    <span x-text="point.label"></span>
                </template>
            </div>
        </section>

        <section class="exec-card">
            <h2>ยอดขายรายสาขาวันนี้</h2>
            <template x-if="data.branches.length === 0">
                <p class="exec-empty">ยังไม่มีสาขาไหนขายวันนี้</p>
            </template>
            <div class="exec-bars">
                <template x-for="row in data.branches" :key="row.code">
                    <div class="exec-bar-row">
                        <span class="exec-bar-name"><b x-text="row.code"></b> <span x-text="row.name"></span></span>
                        <span class="exec-bar-track">
                            <span class="exec-bar-fill" :style="`width:${barWidth(row.sales, data.branches)}%`"></span>
                        </span>
                        <span class="exec-bar-value" x-text="money(row.sales)"></span>
                    </div>
                </template>
            </div>
        </section>

        <section class="exec-card">
            <h2>ช่องทางขายวันนี้</h2>
            <template x-if="data.channels.length === 0">
                <p class="exec-empty">ยังไม่มีการขาย</p>
            </template>
            <div class="exec-channels">
                <template x-for="row in data.channels" :key="row.channel">
                    <div class="exec-channel">
                        <span class="exec-channel-name" x-text="row.channel"></span>
                        <span class="exec-channel-value" x-text="money(row.sales)"></span>
                        <span class="exec-channel-share" x-text="share(row.sales, data.channels) + '%'"></span>
                    </div>
                </template>
            </div>
        </section>

        <section class="exec-card">
            <h2>สินค้าขายดีวันนี้</h2>
            <template x-if="data.topProducts.length === 0">
                <p class="exec-empty">ยังไม่มีสินค้าถูกขาย</p>
            </template>
            <ol class="exec-top">
                <template x-for="row in data.topProducts" :key="row.sku">
                    <li>
                        <span class="exec-top-name"><b x-text="row.sku"></b> <span x-text="row.name"></span></span>
                        <span class="exec-top-qty" x-text="number(row.qty) + ' หน่วย'"></span>
                        <span class="exec-top-amount" x-text="money(row.amount)"></span>
                    </li>
                </template>
            </ol>
        </section>

        <section class="exec-card exec-card-wide">
            <h2>เรื่องที่ต้องตาม</h2>
            <p class="exec-note">ทุกช่องควรเป็นศูนย์ — ตัวไหนไม่เป็นศูนย์คือมีงานค้าง</p>
            <div class="exec-attention">
                <template x-for="row in data.attention" :key="row.label">
                    <div class="exec-attn" :class="row.count > 0 ? 'is-warn' : ''">
                        <span class="exec-attn-label" x-text="row.label"></span>
                        <strong class="exec-attn-count" x-text="number(row.count)"></strong>
                        <span class="exec-attn-amount" x-show="row.amount !== null" x-text="money(row.amount)"></span>
                    </div>
                </template>
            </div>
        </section>
    </div>
</div>
@endsection

@push('styles')
<style>
    .exec-shell { padding: 20px 24px 40px; max-width: 1400px; margin: 0 auto; }
    .exec-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .exec-head h1 { font-size: 26px; font-weight: 900; color: var(--erp-text); margin: 0; }
    .exec-head p { color: var(--erp-muted); margin: 4px 0 0; font-size: 14px; }
    .exec-head-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .exec-live { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--erp-text); }
    .exec-dot { width: 8px; height: 8px; border-radius: 50%; background: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.18); }
    .exec-stamp { font-size: 12.5px; color: var(--erp-muted); }
    .exec-btn { border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; padding: 6px 12px; font-size: 13px; font-weight: 600; color: var(--erp-text); }
    .exec-btn:disabled { opacity: .55; }

    .exec-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
    .exec-kpi { background: #fff; border: 1px solid var(--erp-border); border-radius: 14px; padding: 16px 18px; }
    .exec-kpi-lead { background: linear-gradient(135deg, var(--erp-info), #115e59); border-color: transparent; }
    .exec-kpi-lead .exec-kpi-label, .exec-kpi-lead .exec-kpi-foot { color: rgba(255,255,255,.82); }
    .exec-kpi-lead .exec-kpi-value { color: #fff; }
    .exec-kpi-label { display: block; font-size: 12.5px; color: var(--erp-muted); font-weight: 600; }
    .exec-kpi-value { display: block; font-size: 28px; font-weight: 900; color: var(--erp-text); line-height: 1.25; margin: 4px 0 2px; }
    .exec-kpi-foot { font-size: 12.5px; color: var(--erp-muted); }
    .exec-up { color: #16a34a; font-weight: 700; }
    .exec-down { color: #dc2626; font-weight: 700; }

    .exec-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .exec-card { background: #fff; border: 1px solid var(--erp-border); border-radius: 14px; padding: 16px 18px; }
    .exec-card-wide { grid-column: 1 / -1; }
    .exec-card h2 { font-size: 15px; font-weight: 800; color: var(--erp-text); margin: 0 0 12px; }
    .exec-note { font-size: 12.5px; color: var(--erp-muted); margin: -6px 0 12px; }
    .exec-empty { color: #94a3b8; font-size: 13.5px; padding: 18px 0; text-align: center; }

    .exec-chart { width: 100%; height: 220px; }
    .exec-area { fill: rgba(15,118,110,.12); stroke: none; }
    .exec-line { fill: none; stroke: var(--erp-info); stroke-width: 2.5; stroke-linejoin: round; vector-effect: non-scaling-stroke; }
    .exec-point { fill: var(--erp-info); }
    .exec-axis { display: flex; justify-content: space-between; font-size: 11px; color: #94a3b8; margin-top: 4px; }

    .exec-bar-row { display: grid; grid-template-columns: 150px 1fr 110px; align-items: center; gap: 10px; margin-bottom: 9px; font-size: 13px; }
    .exec-bar-name b { color: var(--erp-info); }
    .exec-bar-track { background: var(--erp-surface-2); border-radius: 999px; height: 12px; overflow: hidden; }
    .exec-bar-fill { display: block; height: 100%; background: linear-gradient(90deg, #14b8a6, var(--erp-info)); border-radius: 999px; }
    .exec-bar-value { text-align: right; font-weight: 700; color: var(--erp-text); }

    .exec-channels { display: grid; gap: 10px; }
    .exec-channel { display: grid; grid-template-columns: 1fr auto 64px; gap: 10px; align-items: baseline; font-size: 13.5px; }
    .exec-channel-value { font-weight: 700; color: var(--erp-text); }
    .exec-channel-share { text-align: right; color: var(--erp-info); font-weight: 700; }

    .exec-top { list-style: none; margin: 0; padding: 0; }
    .exec-top li { display: grid; grid-template-columns: 1fr 96px 110px; gap: 8px; padding: 8px 0; border-bottom: 1px dashed var(--erp-border); font-size: 13px; }
    .exec-top li:last-child { border-bottom: 0; }
    .exec-top-name b { color: var(--erp-info); }
    .exec-top-qty { text-align: right; color: var(--erp-muted); }
    .exec-top-amount { text-align: right; font-weight: 700; }

    .exec-attention { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .exec-attn { border: 1px solid var(--erp-border); border-radius: 12px; padding: 12px 14px; }
    .exec-attn.is-warn { border-color: #fca5a5; background: #fef2f2; }
    .exec-attn-label { display: block; font-size: 12.5px; color: var(--erp-muted); font-weight: 600; }
    .exec-attn-count { display: block; font-size: 24px; font-weight: 900; color: var(--erp-text); }
    .exec-attn.is-warn .exec-attn-count { color: #b91c1c; }
    .exec-attn-amount { font-size: 12.5px; color: var(--erp-muted); }

    @page { size: A4 landscape; margin: 10mm; }
    @media print {
        .app-sidebar, .app-header, .no-print { display: none !important; }
        .exec-shell { padding: 0; max-width: none; }
        .exec-card, .exec-kpi { break-inside: avoid; box-shadow: none; }
        .exec-kpi-lead { background: var(--erp-info) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    @media (max-width: 1100px) {
        .exec-kpis, .exec-attention { grid-template-columns: repeat(2, 1fr); }
        .exec-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .exec-kpis, .exec-attention { grid-template-columns: 1fr; }
        .exec-bar-row { grid-template-columns: 100px 1fr 90px; }
    }
</style>
@endpush

@push('scripts')
<script>
function execBoard() {
    return {
        data: @json($boardData),
        stamp: '{{ now()->format('H:i:s') }}',
        loading: false,

        start() {
            setInterval(() => this.refresh(), {{ $refreshSeconds }} * 1000);
        },

        async refresh() {
            this.loading = true;
            try {
                const response = await fetch('{{ route('executive.data') }}' + window.location.search, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) return;
                const fresh = await response.json();
                // เขียนทับทั้งก้อน ไม่ผสมของเก่ากับของใหม่ ไม่งั้นจอจะโชว์ตัวเลขคนละเวลาปนกัน
                this.data = {
                    today: fresh.today, compare: fresh.compare, trend: fresh.trend,
                    branches: fresh.branches, channels: fresh.channels,
                    topProducts: fresh.topProducts, attention: fresh.attention,
                };
                this.stamp = fresh.refreshed_at;
            } finally {
                this.loading = false;
            }
        },

        money(value) {
            return '฿' + Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        number(value) {
            return Number(value || 0).toLocaleString('th-TH');
        },
        deltaLabel(percent, suffix) {
            if (percent === null || percent === undefined) return 'เมื่อวานไม่มียอด เทียบไม่ได้';
            const cls = percent >= 0 ? 'exec-up' : 'exec-down';
            const arrow = percent >= 0 ? '▲' : '▼';
            return `<span class="${cls}">${arrow} ${Math.abs(percent)}%</span> ${suffix}`;
        },

        trendMax() {
            return Math.max(0, ...this.data.trend.map((point) => Number(point.sales || 0)));
        },
        trendX(index) {
            const step = 720 / Math.max(1, this.data.trend.length - 1);
            return (index * step).toFixed(1);
        },
        trendY(value) {
            const max = this.trendMax() || 1;
            return (210 - (Number(value || 0) / max) * 190).toFixed(1);
        },
        trendLine() {
            return this.data.trend.map((point, index) => `${this.trendX(index)},${this.trendY(point.sales)}`).join(' ');
        },
        trendArea() {
            const line = this.data.trend.map((point, index) => `${this.trendX(index)},${this.trendY(point.sales)}`);
            return `0,215 ${line.join(' ')} 720,215`;
        },
        barWidth(value, rows) {
            const max = Math.max(0, ...rows.map((row) => Number(row.sales || 0)));
            return max > 0 ? Math.max(2, (Number(value || 0) / max) * 100).toFixed(1) : 0;
        },
        share(value, rows) {
            const total = rows.reduce((sum, row) => sum + Number(row.sales || 0), 0);
            return total > 0 ? ((Number(value || 0) / total) * 100).toFixed(1) : '0.0';
        },
    };
}
</script>
@endpush
