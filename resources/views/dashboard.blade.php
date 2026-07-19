@extends('layout')

@section('title', 'ภาพรวมกิจการ')
@section('page-title', 'ภาพรวมกิจการ')
@section('page-subtitle', 'AI Business Pulse · ยอดขาย POS สต็อก และสัญญาณที่ต้องติดตาม')

@section('content')
    <section class="ai-command-hero mb-3">
        <div class="ai-orb"><i class="bi bi-stars"></i></div>
        <div class="ai-command-copy">
            <div class="ai-kicker"><span></span> JET AI BUSINESS PULSE</div>
            <h2>ภาพรวมกิจการแบบเรียลไทม์</h2>
            <p>
                @if($pendingBatches->isNotEmpty())
                    พบ POS batch รอตรวจสอบ {{ $pendingBatches->count() }} รายการ ควรยืนยันข้อมูลก่อนปิดยอด
                @elseif($expiryAlerts->isNotEmpty())
                    พบ Lot ใกล้หมดอายุหรือข้อมูลวันหมดอายุไม่ครบ {{ $expiryAlerts->count() }} รายการ ควรจัดการก่อนขาย
                @elseif($lowStock->isNotEmpty())
                    พบสินค้าใกล้หมดหรือติดลบ {{ $lowStock->count() }} รายการ ควรวางแผนเติมสต๊อก
                @else
                    กระแสงานหลักอยู่ในสถานะปกติ ยังไม่พบรายการเร่งด่วนในช่วงที่เลือก
                @endif
            </p>
        </div>
        <div class="ai-signal-grid">
            <div><span>ยอดขาย</span><strong>฿{{ number_format($summary->total_sales, 2) }}</strong></div>
            <div><span>ใบเสร็จ POS</span><strong>{{ number_format($summary->receipt_count) }}</strong></div>
            <div><span>แจ้งเตือน</span><strong>{{ number_format($pendingBatches->count() + $lowStock->count() + $expiryAlerts->count()) }}</strong></div>
        </div>
        <div class="ai-grid-lines"></div>
    </section>

    <form method="get" class="dashboard-filter mb-3">
        <div class="filter-title">
            <i class="bi bi-calendar3"></i>
            <span>ช่วงวันที่</span>
        </div>
        <div class="filter-fields">
            <label>
                <span>จาก</span>
                <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
            </label>
            <label>
                <span>ถึง</span>
                <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
            </label>
        </div>
        <div class="filter-actions">
            <a href="{{ route('dashboard', ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-light btn-sm">วันนี้</a>
            <a href="{{ route('dashboard', ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-light btn-sm">7 วัน</a>
            <button class="btn btn-primary btn-sm">
                <i class="bi bi-funnel me-1"></i>แสดงผล
            </button>
        </div>
    </form>

    @if(!empty($scopeBranchName))
        <div class="alert border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center gap-2"
             style="background:#e3f3fc;color:#1585c0">
            <i class="bi bi-shop"></i>
            <span class="small fw-semibold">แสดงข้อมูลเฉพาะสาขาของคุณ: {{ $scopeBranchName }}</span>
        </div>
    @endif

    @if($pendingBatches->isNotEmpty())
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <div>
                    <div class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>มี POS batch รอตรวจสอบ {{ $pendingBatches->count() }} รายการ</div>
                    <div class="small">ควรตรวจสอบและยืนยันก่อนปิดยอดประจำวัน</div>
                </div>
                <a href="{{ route('pos-import.page') }}" class="btn btn-sm btn-warning rounded-pill px-3">ไปที่ POS Import</a>
            </div>
        </div>
    @endif

    @if($expiryAlerts->isNotEmpty())
        <div class="alert alert-danger border-0 mb-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <div class="fw-bold"><i class="bi bi-calendar-x-fill me-2"></i>Lot ต้องจัดการ {{ $expiryAlerts->count() }} รายการ</div>
                <div class="small">รวม Lot หมดอายุ ใกล้ถึงวันที่เตือน และ Lot ที่ยังไม่ได้ระบุวันหมดอายุ</div>
            </div>
            <a href="{{ route('reports.index', ['category' => 'inventory', 'report' => 'expiring_stock']) }}" class="btn btn-sm btn-danger">เปิดรายงาน Lot</a>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'sales', 'report' => 'daily_sales', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-green metric-link">
                <div class="metric-icon metric-icon-green"><i class="bi bi-cash-coin"></i></div>
                <div class="metric-label">ยอดขายสุทธิ</div>
                <div class="metric-value text-success">฿{{ number_format($summary->total_sales, 2) }}</div>
                <div class="metric-mini-list">
                    @forelse($salesDocumentSummary as $doc)
                        <div class="metric-mini-row">
                            <span>{{ $doc->doc_name }}</span>
                            <strong>{{ number_format($doc->bill_count) }} บิล · ฿{{ number_format($doc->amount, 2) }}</strong>
                        </div>
                    @empty
                        <div class="metric-mini-row muted"><span>เอกสารขาย</span><strong>0 บิล · ฿0.00</strong></div>
                    @endforelse
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'pos', 'report' => 'pos_by_terminal', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-blue metric-link">
                <div class="metric-icon metric-icon-blue"><i class="bi bi-cart-check"></i></div>
                <div class="metric-label">POS ในช่วงนี้</div>
                <div class="metric-value">{{ number_format($summary->receipt_count) }}</div>
                <div class="metric-unit">ใบเสร็จ</div>
                <div class="metric-mini-list">
                    @forelse($posTerminalSummary as $pos)
                        <div class="metric-mini-row">
                            <span>POS {{ $pos->pos_code }}</span>
                            <strong>{{ number_format($pos->bill_count) }} บิล · ฿{{ number_format($pos->amount, 2) }}</strong>
                        </div>
                    @empty
                        <div class="metric-mini-row muted"><span>POS 12345</span><strong>0 บิล · ฿0.00</strong></div>
                    @endforelse
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'sales', 'report' => 'top_products', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-amber metric-link">
                <div class="metric-icon metric-icon-amber"><i class="bi bi-box-seam"></i></div>
                <div class="metric-label">จำนวนสินค้าที่ขาย</div>
                <div class="metric-value">{{ number_format($itemCount, 0) }}</div>
                <div class="metric-unit">ชิ้น</div>
                <div class="metric-mini-list">
                    @forelse($topProducts->take(2) as $p)
                        <div class="metric-mini-row">
                            <span>{{ $p->sku_code }}</span>
                            <strong>{{ number_format($p->total_qty, 0) }} ชิ้น</strong>
                        </div>
                    @empty
                        <div class="metric-mini-row muted"><span>สินค้าขายดี</span><strong>0 ชิ้น</strong></div>
                    @endforelse
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'pos', 'report' => 'pos_receipts', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-rose metric-link">
                <div class="metric-icon metric-icon-rose"><i class="bi bi-tags"></i></div>
                <div class="metric-label">ส่วนลดรวม</div>
                <div class="metric-value text-danger">฿{{ number_format($summary->total_discount, 2) }}</div>
                <div class="metric-mini-list">
                    <div class="metric-mini-row">
                        <span>ยอดก่อนลด</span>
                        <strong>฿{{ number_format($summary->total_gross, 2) }}</strong>
                    </div>
                    <div class="metric-mini-row">
                        <span>ใบเสร็จ POS</span>
                        <strong>{{ number_format($summary->receipt_count) }} บิล</strong>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3 dashboard-visuals">
        <div class="col-xl-6">
            <div class="panel-card chart-panel">
                <div class="panel-title">
                    <i class="bi bi-activity text-primary"></i>
                    ยอดขายรายวัน
                </div>
                <div class="chart-stage"><canvas id="dailyChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="panel-card chart-panel">
                <div class="panel-title">
                    <i class="bi bi-shop text-info"></i>
                    ยอดขายแยกสาขา
                </div>
                <div class="chart-stage"><canvas id="branchChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="panel-card chart-panel mix-panel">
                <div class="panel-title">
                    <i class="bi bi-pie-chart-fill text-success"></i>
                    สัดส่วนเอกสารขาย
                </div>
                <div class="chart-stage doughnut-stage"><canvas id="salesMixChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="panel-card">
                <div class="panel-title">
                    <i class="bi bi-star-fill text-warning"></i>
                    สินค้าขายดี Top 10
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>สินค้า</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">ยอดขาย</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topProducts as $p)
                                <tr>
                                    <td class="fw-semibold">{{ $p->sku_code }}</td>
                                    <td>{{ $p->name_th }}</td>
                                    <td class="text-end">{{ number_format($p->total_qty, 0) }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($p->total_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">ไม่มีข้อมูลในช่วงนี้</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="panel-card">
                <div class="panel-title">
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                    สต็อกต่ำสุด / ติดลบ
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>รหัส</th>
                                <th>สินค้า</th>
                                <th>ที่เก็บ</th>
                                <th class="text-end">คงเหลือ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lowStock as $s)
                                <tr class="{{ $s->on_hand_qty < 0 ? 'table-danger' : '' }}">
                                    <td class="fw-semibold">{{ $s->sku_code }}</td>
                                    <td>{{ $s->name_th }}</td>
                                    <td>{{ $s->location_name }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($s->on_hand_qty, 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">ไม่มีข้อมูลสต็อก</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('head')
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<style>
    .ai-command-hero {
        position:relative; min-height:112px; display:grid; grid-template-columns:auto minmax(0,1fr) auto;
        align-items:center; gap:15px; overflow:hidden; padding:16px 18px;
        border:1px solid rgba(56,189,248,.32); border-radius:14px;
        background:
            radial-gradient(circle at 82% 20%,rgba(34,211,238,.2),transparent 28%),
            radial-gradient(circle at 12% 110%,rgba(99,102,241,.3),transparent 36%),
            linear-gradient(120deg,#071525,#0b2540 52%,#073448);
        box-shadow:0 12px 34px rgba(2,21,38,.18); color:#e8f8ff;
    }
    .ai-command-hero>*:not(.ai-grid-lines){position:relative;z-index:2}
    .ai-grid-lines { position:absolute; inset:0; opacity:.13; background-image:linear-gradient(rgba(125,211,252,.5) 1px,transparent 1px),linear-gradient(90deg,rgba(125,211,252,.5) 1px,transparent 1px); background-size:28px 28px; mask-image:linear-gradient(90deg,transparent,#000 25%,#000); }
    .ai-orb { width:54px;height:54px;display:grid;place-items:center;border:1px solid rgba(103,232,249,.5);border-radius:16px;background:linear-gradient(145deg,rgba(14,165,233,.25),rgba(99,102,241,.2));box-shadow:inset 0 0 22px rgba(34,211,238,.15),0 0 24px rgba(34,211,238,.12);font-size:23px;color:#67e8f9; }
    .ai-kicker { display:flex;align-items:center;gap:7px;color:#67e8f9;font-size:9px;font-weight:900;letter-spacing:.15em; }
    .ai-kicker span { width:6px;height:6px;border-radius:50%;background:#34d399;box-shadow:0 0 10px #34d399;animation:aiPulse 1.8s infinite; }
    .ai-command-copy h2 { margin:3px 0 2px;color:#fff;font-size:18px;font-weight:800; }
    .ai-command-copy p { margin:0;max-width:680px;color:#a9c9d9;font-size:11px; }
    .ai-signal-grid { display:grid;grid-template-columns:repeat(3,minmax(90px,1fr));gap:7px; }
    .ai-signal-grid div { min-width:94px;padding:9px 10px;border:1px solid rgba(125,211,252,.2);border-radius:8px;background:rgba(255,255,255,.055);backdrop-filter:blur(8px); }
    .ai-signal-grid span { display:block;color:#8db5c9;font-size:9px;margin-bottom:2px; }
    .ai-signal-grid strong { display:block;color:#f0fbff;font-size:13px;white-space:nowrap; }
    @keyframes aiPulse{50%{opacity:.38;transform:scale(.75)}}

    .dashboard-filter {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
        padding: 10px 14px;
        box-shadow: 0 1px 4px rgba(15,23,42,.06);
    }

    .filter-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #334155;
        font-size: 14px;
        font-weight: 850;
        white-space: nowrap;
    }

    .filter-title i {
        color: #0f9aaa;
    }

    .filter-fields {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
    }

    .filter-fields label {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0;
        color: #64748b;
        font-size: 12px;
        font-weight: 800;
    }

    .filter-fields .form-control {
        width: 150px;
        min-height: 32px;
        border-color: #e2e8f0;
        background: #f8fafc;
        font-size: 13px;
    }

    .filter-actions {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .filter-actions .btn {
        min-height: 32px;
        border-radius: 7px;
        padding-left: 12px;
        padding-right: 12px;
    }

    /* ── Metric cards ─────────────────────────── */
    .metric-card {
        min-height: 164px;
        padding: 15px;
        border-radius: 12px;
        border: 1px solid rgba(148,163,184,.2);
        position: relative;
        overflow: hidden;
    }

    .metric-card-green  { background: linear-gradient(145deg,#ffffff,#effcf6); box-shadow:inset 0 3px 0 #34d399,0 5px 18px rgba(16,185,129,.07); }
    .metric-card-blue   { background: linear-gradient(145deg,#ffffff,#eff8ff); box-shadow:inset 0 3px 0 #38bdf8,0 5px 18px rgba(59,130,246,.07); }
    .metric-card-amber  { background: linear-gradient(145deg,#ffffff,#fffbeb); box-shadow:inset 0 3px 0 #fbbf24,0 5px 18px rgba(245,158,11,.07); }
    .metric-card-rose   { background: linear-gradient(145deg,#ffffff,#fff1f2); box-shadow:inset 0 3px 0 #fb7185,0 5px 18px rgba(244,63,94,.07); }

    .metric-link {
        display: block;
        color: inherit;
        text-decoration: none;
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .metric-link:hover {
        color: inherit;
        transform: translateY(-3px);
    }

    .metric-link.metric-card-green:hover  { box-shadow: 0 12px 32px rgba(16,185,129,.2); }
    .metric-link.metric-card-blue:hover   { box-shadow: 0 12px 32px rgba(59,130,246,.2); }
    .metric-link.metric-card-amber:hover  { box-shadow: 0 12px 32px rgba(245,158,11,.2); }
    .metric-link.metric-card-rose:hover   { box-shadow: 0 12px 32px rgba(244,63,94,.2); }

    .metric-icon {
        width: 36px; height: 36px;
        display: grid; place-items: center;
        border-radius: 12px;
        font-size: 16px;
        margin-bottom: 9px;
    }

    .metric-icon-green { background: #10b981; color: #fff; box-shadow: 0 4px 12px rgba(16,185,129,.35); }
    .metric-icon-blue  { background: #3b82f6; color: #fff; box-shadow: 0 4px 12px rgba(59,130,246,.35); }
    .metric-icon-amber { background: #f59e0b; color: #fff; box-shadow: 0 4px 12px rgba(245,158,11,.35); }
    .metric-icon-rose  { background: #f43f5e; color: #fff; box-shadow: 0 4px 12px rgba(244,63,94,.35); }

    .metric-label { color: #64748b; font-weight: 600; font-size: 13px; margin-bottom: 6px; }

    .metric-value { color:#0f172a;font-size:22px;line-height:1;font-weight:850;font-variant-numeric:tabular-nums; }

    .metric-unit { color: #94a3b8; font-size: 12px; margin-top: 4px; }

    .metric-mini-list {
        margin-top: 10px;
        padding-top: 8px;
        border-top: 1px solid rgba(0,0,0,.06);
        display: grid; gap: 6px;
    }

    .metric-mini-row {
        display: flex; align-items: center;
        justify-content: space-between; gap: 8px;
        color: #64748b; font-size: 12px; line-height: 1.3;
    }

    .metric-mini-row span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .metric-mini-row strong { flex: 0 0 auto; color: #1e293b; font-weight: 700; white-space: nowrap; }

    .metric-mini-row.muted strong { color: #cbd5e1; }

    /* ── Panel cards ──────────────────────────── */
    .panel-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 11px;
        padding: 14px;
        box-shadow:0 4px 16px rgba(15,23,42,.045);
    }
    .chart-panel { height:268px;display:flex;flex-direction:column; }
    .chart-stage { position:relative;min-height:0;flex:1; }
    .doughnut-stage { max-width:205px;width:100%;margin:0 auto; }

    .panel-title {
        display: flex; align-items: center; gap: 10px;
        font-size:13px;font-weight:800;margin-bottom:10px;
        color: #0f172a;
    }

    .table td { border-bottom-color: #f1f5f9; }

    @media (max-width: 991.98px) {
        .ai-command-hero{grid-template-columns:auto minmax(0,1fr)}
        .ai-signal-grid{grid-column:1/-1;width:100%}
        .dashboard-filter {
            flex-wrap: wrap;
        }

        .filter-fields {
            order: 3;
            width: 100%;
            margin-left: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .filter-fields label {
            align-items: flex-start;
            flex-direction: column;
            gap: 4px;
        }

        .filter-fields .form-control {
            width: 100%;
        }
    }

    @media (max-width: 575.98px) {
        .ai-command-hero{grid-template-columns:1fr}.ai-orb{display:none}.ai-signal-grid{grid-template-columns:1fr 1fr}.ai-signal-grid div:last-child{grid-column:1/-1}
        .filter-fields {
            grid-template-columns: 1fr;
        }

        .filter-actions {
            width: 100%;
        }

        .filter-actions .btn {
            flex: 1 1 auto;
        }
    }
</style>
@endpush

@push('scripts')
    <script>
        new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: {!! json_encode($dailySales->pluck('sale_date')) !!},
                datasets: [{
                    label: 'ยอดขาย',
                    data: {!! json_encode($dailySales->pluck('total_sales')) !!},
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2,132,199,.10)',
                    fill: true,
                    tension: .35,
                    pointRadius: 4,
                    pointBackgroundColor: '#0284c7',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: '#eef1f6' } }
                }
            }
        });

        new Chart(document.getElementById('branchChart'), {
            type: 'bar',
            data: {
                labels: {!! json_encode($byBranch->pluck('name_th')) !!},
                datasets: [{
                    label: 'ยอดขาย',
                    data: {!! json_encode($byBranch->pluck('total_sales')) !!},
                    backgroundColor: '#38bdf8',
                    borderRadius: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: '#eef1f6' }, beginAtZero: true }
                }
            }
        });

        new Chart(document.getElementById('salesMixChart'), {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($salesDocumentSummary->pluck('doc_name')) !!},
                datasets: [{
                    data: {!! json_encode($salesDocumentSummary->pluck('amount')) !!},
                    backgroundColor: ['#10b981','#38bdf8','#f59e0b','#6366f1','#f43f5e'],
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, font: { size: 10 }, padding: 9 } },
                    tooltip: { callbacks: { label: ctx => `${ctx.label}: ฿${Number(ctx.raw || 0).toLocaleString('th-TH', {minimumFractionDigits:2})}` } }
                }
            }
        });
    </script>
@endpush
