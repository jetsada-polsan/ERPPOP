@extends('layout')

@section('title', 'ภาพรวมกิจการ')
@section('page-title', 'ภาพรวมกิจการ')
@section('page-subtitle', 'AI Business Pulse · ยอดขาย POS สต็อก และสัญญาณที่ต้องติดตาม')

@section('content')
    <section class="executive-hero mb-3">
        <div class="executive-mark"><i class="bi bi-bar-chart-line-fill"></i></div>
        <div class="executive-copy">
            <div class="executive-kicker"><span></span> POPSTAR EXECUTIVE BOARD</div>
            <h2>ภาพรวมเพื่อการตัดสินใจ</h2>
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
        <div class="executive-signal-grid">
            <div><span>ยอดขาย</span><strong>฿{{ number_format($summary->total_sales, 2) }}</strong></div>
            <div><span>รายการขาย</span><strong>{{ number_format($summary->receipt_count) }}</strong></div>
            <div><span>แจ้งเตือน</span><strong>{{ number_format($pendingBatches->count() + $lowStock->count() + $expiryAlerts->count()) }}</strong></div>
        </div>
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
            <a href="{{ route('reports.index', ['category' => 'sales', 'report' => 'daily_sales', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-sales metric-link">
                <div class="metric-icon metric-icon-sales"><i class="bi bi-cash-coin"></i></div>
                <div class="metric-label">ยอดขายสุทธิ</div>
                <div class="metric-value">฿{{ number_format($summary->total_sales, 2) }}</div>
                <div class="metric-mini-list">
                    <div class="metric-mini-row"><span>เทียบช่วงก่อน</span><strong class="{{ $summary->sales_change_percent !== null && $summary->sales_change_percent < 0 ? 'text-danger' : 'text-success' }}">{{ $summary->sales_change_percent === null ? '-' : ($summary->sales_change_percent > 0 ? '+' : '').$summary->sales_change_percent.'%' }}</strong></div>
                    <div class="metric-mini-row"><span>เฉลี่ยต่อบิล</span><strong>฿{{ number_format($summary->average_bill, 2) }}</strong></div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'sales', 'report' => 'gross_margin', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-profit metric-link">
                <div class="metric-icon metric-icon-profit"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="metric-label">กำไรขั้นต้นที่คำนวณได้</div>
                <div class="metric-value">฿{{ number_format($summary->gross_profit, 2) }}</div>
                <div class="metric-mini-list">
                    <div class="metric-mini-row"><span>ต้นทุนที่ผูกเอกสารแล้ว</span><strong>฿{{ number_format($summary->total_cogs, 2) }}</strong></div>
                    <div class="metric-mini-row"><span>Gross margin</span><strong>{{ $summary->gross_margin_percent === null ? '-' : number_format($summary->gross_margin_percent, 1).'%' }}</strong></div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'finance', 'report' => 'ar_aging', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-ar metric-link">
                <div class="metric-icon metric-icon-ar"><i class="bi bi-person-vcard"></i></div>
                <div class="metric-label">ลูกหนี้คงค้าง</div>
                <div class="metric-value">฿{{ number_format($receivables->open_amount, 2) }}</div>
                <div class="metric-mini-list">
                    <div class="metric-mini-row"><span>เอกสารค้าง</span><strong>{{ number_format($receivables->open_count) }} รายการ</strong></div>
                    <div class="metric-mini-row"><span>เกินกำหนดชำระ</span><strong class="{{ $receivables->overdue_amount > 0 ? 'text-danger' : '' }}">฿{{ number_format($receivables->overdue_amount, 2) }}</strong></div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="{{ route('reports.index', ['category' => 'pos', 'report' => 'pos_by_terminal', 'from' => $from, 'to' => $to]) }}" class="metric-card metric-card-pos metric-link">
                <div class="metric-icon metric-icon-pos"><i class="bi bi-display"></i></div>
                <div class="metric-label">ยอดขาย POS</div>
                <div class="metric-value">{{ number_format($posTerminalSummary->sum('amount'), 2) }} ฿</div>
                <div class="metric-mini-list">
                    <div class="metric-mini-row"><span>ส่วนลด POS</span><strong>฿{{ number_format($summary->total_discount, 2) }}</strong></div>
                    <div class="metric-mini-row"><span>รายการค้างตรวจ</span><strong class="{{ $pendingBatches->isNotEmpty() ? 'text-danger' : 'text-success' }}">{{ $pendingBatches->count() }}</strong></div>
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
    .executive-hero { min-height:108px; display:grid; grid-template-columns:auto minmax(0,1fr) auto; align-items:center; gap:18px; padding:16px 18px; background:#fff; border:1px solid #dce4ed; border-top:3px solid #1e3a5f; border-radius:8px; box-shadow:0 6px 20px rgba(15,23,42,.06); }
    .executive-mark { width:46px; height:46px; display:grid; place-items:center; color:#fff; background:#1e3a5f; border-radius:7px; font-size:20px; }
    .executive-kicker { display:flex; align-items:center; gap:7px; color:#62748a; font-size:10px; font-weight:800; letter-spacing:.12em; }
    .executive-kicker span { width:7px; height:7px; border-radius:50%; background:#159f78; }
    .executive-copy h2 { margin:3px 0 3px; color:#172b43; font-size:19px; font-weight:850; }
    .executive-copy p { margin:0; max-width:720px; color:#63748a; font-size:12px; }
    .executive-signal-grid { display:grid; grid-template-columns:repeat(3,minmax(102px,1fr)); gap:8px; }
    .executive-signal-grid div { min-width:104px; padding:8px 10px; border-left:1px solid #e5ebf1; }
    .executive-signal-grid span { display:block; color:#76869a; font-size:10px; margin-bottom:2px; }
    .executive-signal-grid strong { display:block; color:#172b43; font-size:13px; white-space:nowrap; font-variant-numeric:tabular-nums; }

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

    .metric-card { background:#fff; box-shadow:0 4px 16px rgba(15,23,42,.045); }
    .metric-card-sales { border-top:3px solid #159f78; }
    .metric-card-profit { border-top:3px solid #2676a9; }
    .metric-card-ar { border-top:3px solid #ca8a04; }
    .metric-card-pos { border-top:3px solid #d5343f; }

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

    .metric-link:hover { box-shadow:0 12px 28px rgba(15,23,42,.12); }

    .metric-icon {
        width: 36px; height: 36px;
        display: grid; place-items: center;
        border-radius: 12px;
        font-size: 16px;
        margin-bottom: 9px;
    }

    .metric-icon-sales { background:#e7f6f0; color:#13795b; }
    .metric-icon-profit { background:#e9f2f9; color:#20618d; }
    .metric-icon-ar { background:#fff6db; color:#966b06; }
    .metric-icon-pos { background:#fff0f0; color:#c32d38; }

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
        .executive-hero{grid-template-columns:auto minmax(0,1fr)}
        .executive-signal-grid{grid-column:1/-1;width:100%}
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
        .executive-hero{grid-template-columns:1fr}.executive-mark{display:none}.executive-signal-grid{grid-template-columns:1fr 1fr}.executive-signal-grid div:last-child{grid-column:1/-1}
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
