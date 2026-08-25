@extends('layout')

@section('title', 'ภาพรวมกิจการ')
@section('page-title', 'แผงควบคุม')
@section('page-subtitle', 'ยอดขาย สต๊อก และงานที่ต้องจัดการ')

@php
    /**
     * แผงควบคุมโครงแบบ Odoo — ใช้ controller และข้อมูลชุดเดียวกับ layout เดิม
     * ทุกตัวเลขในหน้านี้มาจากฐานจริง ไม่มีค่าจำลอง
     */
    $money = fn ($v) => '฿'.number_format((float) $v, 2);
    $short = fn ($v) => number_format((float) $v);

    $barMax = max(1, (float) ($byBranch->max('total_sales') ?? 0));
    $lowStockCount = $lowStock->where('on_hand_qty', '<=', 0)->count() ?: $lowStock->count();

    $stateMeta = [
        'completed' => ['ปิดบิลแล้ว', 'ok'],
        'open' => ['ค้างปิด', 'wait'],
        'voided' => ['ยกเลิก', 'fail'],
    ];
    $receiptTotal = max(1, (int) $receiptStatus->sum());
@endphp

@section('content')
<div class="od-ctrl">
    <h2>แผงควบคุม</h2>
    <div class="od-ctrl-right">
        <a href="{{ route('dashboard', ['from' => $from, 'to' => $to]) }}" class="od-btn">
            <i class="bi bi-arrow-clockwise"></i>รีเฟรช
        </a>
        <form method="get" action="{{ route('dashboard') }}" class="od-range">
            <label for="od-from">ช่วงเวลา</label>
            <input type="date" id="od-from" name="from" value="{{ $from }}">
            <span>ถึง</span>
            <input type="date" name="to" value="{{ $to }}">
            <button type="submit" class="od-btn od-btn-primary">แสดงผล</button>
        </form>
        @if ($scopeBranchName)
            <span class="od-scope"><i class="bi bi-shop"></i>{{ $scopeBranchName }}</span>
        @endif
    </div>
</div>

<div class="od-kpis">
    <div class="od-kpi">
        <span class="od-ico t-blue"><i class="bi bi-receipt"></i></span>
        <div>
            <div class="od-lbl">ยอดขายสุทธิ</div>
            <div class="od-val">{{ $money($summary->total_sales) }}</div>
            <div class="od-sub">
                ช่วงก่อน {{ $money($summary->previous_sales) }}
                @if ($summary->sales_change_percent !== null)
                    <b class="{{ $summary->sales_change_percent < 0 ? 'dn' : 'up' }}">
                        {{ $summary->sales_change_percent < 0 ? '▼' : '▲' }} {{ abs($summary->sales_change_percent) }}%
                    </b>
                @endif
            </div>
        </div>
    </div>

    <div class="od-kpi">
        <span class="od-ico t-green"><i class="bi bi-graph-up-arrow"></i></span>
        <div>
            <div class="od-lbl">กำไรขั้นต้น</div>
            <div class="od-val">{{ $summary->gross_margin_percent !== null ? $summary->gross_margin_percent.'%' : '—' }}</div>
            <div class="od-sub">กำไร {{ $money($summary->gross_profit) }}</div>
        </div>
    </div>

    <div class="od-kpi">
        <span class="od-ico t-info"><i class="bi bi-hourglass-split"></i></span>
        <div>
            <div class="od-lbl">บิลค้างปิด</div>
            <div class="od-val">{{ $short($openReceipts) }}</div>
            <div class="od-sub">บิล · จากทั้งหมด {{ $short($summary->receipt_count) }}</div>
        </div>
    </div>

    <div class="od-kpi">
        <span class="od-ico t-amber"><i class="bi bi-box-seam"></i></span>
        <div>
            <div class="od-lbl">สต๊อกต่ำ / ติดลบ</div>
            <div class="od-val">{{ $short($lowStockCount) }}</div>
            <div class="od-sub">รายการ</div>
        </div>
    </div>

    <div class="od-kpi">
        <span class="od-ico t-red"><i class="bi bi-inboxes"></i></span>
        <div>
            <div class="od-lbl">ใบสั่งซื้อรอรับ</div>
            <div class="od-val">{{ $short($poPending) }}</div>
            <div class="od-sub">รายการ</div>
        </div>
    </div>
</div>

<div class="od-row3">
    <section class="od-card">
        <header><h3>ยอดขายแยกตามสาขา</h3><span class="od-meta">{{ $from }} — {{ $to }}</span></header>
        <div class="od-pad">
            @forelse ($byBranch as $branch)
                <div class="od-bar">
                    <span class="od-bar-name">{{ $branch->name_th ?: $branch->code }}</span>
                    <span class="od-track">
                        <span class="od-fill" style="width: {{ max(2, round((float) $branch->total_sales / $barMax * 100)) }}%"></span>
                    </span>
                    <span class="od-bar-val">{{ $short($branch->total_sales) }}</span>
                </div>
            @empty
                <p class="od-empty">ยังไม่มียอดขายในช่วงนี้</p>
            @endforelse
        </div>
    </section>

    <section class="od-card">
        <header><h3>สินค้าขายดี</h3><span class="od-meta">10 อันดับ</span></header>
        <ol class="od-top">
            @forelse ($topProducts->take(10) as $i => $product)
                <li>
                    <span class="od-rk">{{ $i + 1 }}.</span>
                    <span class="od-nm">{{ $product->name_th }}</span>
                    <span class="od-q">{{ number_format((float) $product->total_qty, 2) }}</span>
                    <span class="od-m">{{ $short($product->total_amount) }}</span>
                </li>
            @empty
                <li class="od-empty">ยังไม่มีข้อมูลการขาย</li>
            @endforelse
        </ol>
    </section>

    <section class="od-card">
        <header><h3>ต้องจัดการ</h3></header>
        <div class="od-nts">
            @if ($lowStockCount > 0)
                <div class="od-nt">
                    <span class="od-ico2 t-amber"><i class="bi bi-exclamation-triangle-fill"></i></span>
                    <div><div class="od-nt-t">สต๊อกต่ำหรือติดลบ {{ $short($lowStockCount) }} รายการ</div>
                        <a href="{{ route('products.index') }}" class="od-nt-d">คลิกเพื่อดูรายการ</a></div>
                </div>
            @endif
            @if ($openReceipts > 0)
                <div class="od-nt">
                    <span class="od-ico2 t-blue"><i class="bi bi-info-circle-fill"></i></span>
                    <div><div class="od-nt-t">บิลค้างปิด {{ $short($openReceipts) }} บิล</div>
                        <span class="od-nt-d">ตรวจสอบที่หน้าบิลขาย</span></div>
                </div>
            @endif
            @if (($receivables->overdue_amount ?? 0) > 0)
                <div class="od-nt">
                    <span class="od-ico2 t-red"><i class="bi bi-cash-stack"></i></span>
                    <div><div class="od-nt-t">ลูกหนี้เกินกำหนด {{ $money($receivables->overdue_amount) }}</div>
                        <span class="od-nt-d">เอกสารค้าง {{ $short($receivables->open_count) }} รายการ</span></div>
                </div>
            @endif
            @foreach ($expiryAlerts->take(2) as $lot)
                <div class="od-nt">
                    <span class="od-ico2 t-amber"><i class="bi bi-calendar-x"></i></span>
                    <div><div class="od-nt-t">{{ $lot->name_th }} ใกล้หมดอายุ</div>
                        <span class="od-nt-d">คงเหลือ {{ number_format((float) $lot->remaining_qty, 2) }}
                            @if ($lot->days_left !== null) · อีก {{ $lot->days_left }} วัน @endif</span></div>
                </div>
            @endforeach
            @if ($lowStockCount === 0 && $openReceipts === 0 && ($receivables->overdue_amount ?? 0) <= 0 && $expiryAlerts->isEmpty())
                <p class="od-empty"><i class="bi bi-check2-circle"></i> ไม่มีเรื่องค้าง</p>
            @endif
        </div>
    </section>
</div>

<div class="od-row2">
    <section class="od-card">
        <header><h3>บิลขายล่าสุด</h3><span class="od-meta">POS ทุกสาขา</span></header>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th>เลขที่บิล</th><th>สาขา</th><th>เครื่อง</th><th>แคชเชียร์</th>
                    <th class="text-end">ยอดรวม</th><th>สถานะ</th><th>เวลา</th>
                </tr></thead>
                <tbody>
                @forelse ($recentReceipts as $receipt)
                    @php
                        $state = $receipt->voided_at ? 'voided' : $receipt->status;
                        [$stateLabel, $stateTone] = $stateMeta[$state] ?? [$state, 'wait'];
                    @endphp
                    <tr>
                        <td>{{ $receipt->receipt_no }}</td>
                        <td>{{ $receipt->branch_name ?: '—' }}</td>
                        <td>{{ $receipt->pos_code ?: '—' }}</td>
                        <td>{{ $receipt->cashier_name ?: '—' }}</td>
                        <td class="text-end {{ $receipt->voided_at ? 'od-struck' : '' }}">{{ $money($receipt->net_sales) }}</td>
                        <td><span class="od-pill {{ $stateTone }}">{{ $stateLabel }}</span></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($receipt->receipt_date)->format('d/m H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีบิลขาย</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="od-card">
        <header><h3>สถานะบิล POS</h3><span class="od-meta">ในช่วงที่เลือก</span></header>
        <div class="od-pad od-donut">
            @php
                $offset = 0;
                $ring = [];
                foreach ($stateMeta as $key => [$label, $tone]) {
                    $count = (int) ($receiptStatus[$key] ?? 0);
                    if ($count === 0) { continue; }
                    $pct = round($count / $receiptTotal * 100, 1);
                    $ring[] = ['label' => $label, 'tone' => $tone, 'count' => $count, 'pct' => $pct, 'offset' => $offset];
                    $offset += $pct;
                }
            @endphp
            @if ($ring)
                <div class="od-ring">
                    <svg viewBox="0 0 42 42" role="img" aria-label="สัดส่วนสถานะบิล">
                        @foreach ($ring as $slice)
                            <circle cx="21" cy="21" r="15.9" fill="none" class="s-{{ $slice['tone'] }}"
                                    stroke-width="7"
                                    stroke-dasharray="{{ $slice['pct'] }} {{ 100 - $slice['pct'] }}"
                                    stroke-dashoffset="{{ -$slice['offset'] }}"></circle>
                        @endforeach
                    </svg>
                    <div class="od-ring-mid"><b>{{ $short($receiptStatus->sum()) }}</b><span>บิล</span></div>
                </div>
                <ul class="od-legend">
                    @foreach ($ring as $slice)
                        <li><i class="d-{{ $slice['tone'] }}"></i>{{ $slice['label'] }}
                            <span class="n">{{ $short($slice['count']) }}</span>
                            <span class="p">({{ $slice['pct'] }}%)</span></li>
                    @endforeach
                </ul>
            @else
                <p class="od-empty">ยังไม่มีบิลในช่วงนี้</p>
            @endif
        </div>
    </section>
</div>

<div class="od-row2">
    <section class="od-card">
        <header><h3>การเคลื่อนไหวสต๊อกล่าสุด</h3></header>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <tbody>
                @forelse ($recentMovements as $movement)
                    <tr>
                        <td>{{ $movement->name_th }}</td>
                        <td class="text-muted">{{ $movement->sku_code }}</td>
                        <td class="text-muted">{{ $movement->location_name ?: '—' }}</td>
                        <td class="text-end {{ (float) $movement->qty < 0 ? 'text-danger' : '' }}">
                            {{ number_format((float) $movement->qty, 2) }}
                        </td>
                        <td>{{ \Illuminate\Support\Carbon::parse($movement->movement_date)->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีการเคลื่อนไหว</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="od-card">
        <header><h3>กิจกรรมล่าสุด</h3></header>
        <div class="od-nts">
            @forelse ($activity as $log)
                <div class="od-nt">
                    <span class="od-av">{{ mb_substr($log->user_name ?: 'ระบบ', 0, 2) }}</span>
                    <div>
                        <div class="od-nt-t"><b>{{ $log->user_name ?: 'ระบบ' }}</b> {{ $log->action }}</div>
                        <span class="od-nt-d">{{ $log->table_name }}@if ($log->record_id) #{{ $log->record_id }}@endif</span>
                    </div>
                    <span class="od-nt-tm">{{ \Illuminate\Support\Carbon::parse($log->created_at)->diffForHumans(short: true) }}</span>
                </div>
            @empty
                <p class="od-empty">ยังไม่มีกิจกรรม</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
