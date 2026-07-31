@extends('layout')

@section('title', 'ยอดขายหลังบ้าน')
@section('page-title', 'ยอดขายหลังบ้าน')
@section('page-subtitle', 'สรุปจาก MSSQL แบบอ่านอย่างเดียว')

@section('content')
@php
    $rankedRows = $rows->sortByDesc(fn ($row) => (float) ($row['amount'] ?? 0))->values();
    $maxAmount = max(1, (float) ($rankedRows->first()['amount'] ?? 0));
@endphp

<style>
    .legacy-sales-page { color:#17324d; }
    .legacy-filter { display:flex; align-items:center; gap:14px; padding:18px 24px; background:#fff; border-radius:14px; box-shadow:0 5px 18px rgba(20,62,110,.08); border-left:5px solid #dc2626; }
    .legacy-filter-title { display:flex; align-items:center; gap:8px; color:#1d4ed8; font-weight:800; white-space:nowrap; }
    .legacy-filter form { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .legacy-filter input { min-width:185px; font-weight:700; }
    .legacy-filter .btn { font-weight:800; padding-inline:18px; }
    .legacy-sheet { display:grid; grid-template-columns:minmax(0,1.55fr) minmax(330px,1fr); gap:20px; margin-top:22px; }
    .legacy-panel { overflow:hidden; background:#fff; border-radius:14px; box-shadow:0 6px 20px rgba(20,62,110,.10); }
    .legacy-panel-title { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:19px 24px; color:#fff; font-size:1.05rem; font-weight:800; }
    .legacy-panel-title.blue { background:linear-gradient(105deg,#1d4ed8,#3759c9); }
    .legacy-panel-title.green { background:linear-gradient(105deg,#059669,#18a56f); }
    .legacy-chip { padding:4px 10px; background:#fff; border-radius:7px; color:#2563eb; font-size:.78rem; }
    .legacy-route-head,.legacy-route-row { display:grid; grid-template-columns:86px minmax(0,1fr) 135px; align-items:center; gap:16px; padding:17px 24px; }
    .legacy-route-head { background:#f8fafc; color:#7a8596; font-size:.8rem; font-weight:800; }
    .legacy-route-row { border-top:1px solid #e9eef5; }
    .legacy-route-row:nth-child(odd) { background:#fbfdff; }
    .legacy-code { color:#1f2937; font-weight:900; }
    .legacy-name { overflow:hidden; white-space:nowrap; text-overflow:ellipsis; font-weight:700; }
    .legacy-bar-track { height:10px; margin-top:8px; overflow:hidden; border-radius:999px; background:#e8edf8; box-shadow:inset 0 1px 2px rgba(0,0,0,.06); }
    .legacy-bar { height:100%; min-width:4px; border-radius:999px; background:linear-gradient(90deg,#2d68dd,#759af4); }
    .legacy-amount { color:#16a34a; font-size:1.05rem; font-weight:900; text-align:right; }
    .legacy-percent { color:#748195; font-size:.78rem; font-weight:700; text-align:right; }
    .legacy-total { padding:35px 26px 28px; text-align:center; }
    .legacy-total-label { color:#64748b; font-size:.9rem; font-weight:800; }
    .legacy-total-value { margin:25px 0 17px; color:#3b61d5; font-size:clamp(2rem,4vw,3.75rem); font-weight:900; line-height:1; letter-spacing:0; }
    .legacy-total-value small { font-size:.32em; color:#3159c7; }
    .legacy-divider { height:1px; background:#e5e7eb; }
    .legacy-meta { padding-top:18px; color:#475569; font-size:.85rem; font-weight:700; }
    .legacy-chart { margin-top:20px; padding:20px 24px 12px; }
    .legacy-chart-row { display:grid; grid-template-columns:minmax(135px,1fr) minmax(90px,1.15fr); gap:13px; align-items:center; margin:11px 0; }
    .legacy-chart-label { overflow:hidden; white-space:nowrap; text-overflow:ellipsis; color:#526174; font-size:.78rem; font-weight:700; text-align:right; }
    .legacy-chart-track { position:relative; height:20px; border-bottom:1px solid #dce5f1; }
    .legacy-chart-bar { height:14px; border:2px solid #3867d3; border-radius:4px; background:#89a8f6; }
    .legacy-check { margin-top:20px; padding:15px 18px; border-radius:11px; background:#effaf4; color:#176c48; font-size:.87rem; font-weight:700; }
    @media (max-width: 980px) { .legacy-sheet { grid-template-columns:1fr; } }
    @media (max-width: 640px) { .legacy-filter { align-items:flex-start; flex-direction:column; } .legacy-route-head,.legacy-route-row { grid-template-columns:55px minmax(0,1fr) 105px; gap:10px; padding:14px; } .legacy-amount { font-size:.88rem; } }
</style>

<div class="legacy-sales-page">
    <div class="legacy-filter">
        <div class="legacy-filter-title"><i class="bi bi-funnel-fill"></i> ตัวกรองข้อมูล</div>
        <form method="get">
            <input type="date" name="date" value="{{ $date }}" class="form-control">
            <button class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>แสดงผล</button>
            <a href="{{ route('legacy-backoffice-sales.index', ['date' => now()->toDateString()]) }}" class="btn btn-light border">วันนี้</a>
        </form>
    </div>

    @if(! $syncedAt)
        <div class="alert alert-warning border-0 mt-3 mb-0">ยังไม่มีข้อมูลของวันที่เลือก ให้สั่ง Sync Summary จากเครื่องสำนักงานก่อน</div>
    @else
        <div class="legacy-sheet">
            <section class="legacy-panel">
                <div class="legacy-panel-title blue"><span><i class="bi bi-list-ul me-2"></i>สรุปยอดขายรายเส้นทาง / ประเภทเอกสาร</span><span class="legacy-chip">{{ number_format($rankedRows->count()) }} รายการ</span></div>
                <div class="legacy-route-head"><span>รหัส</span><span>เส้นทาง / ประเภทเอกสาร</span><span class="text-end">ยอดเอกสาร</span></div>
                @forelse($rankedRows as $row)
                    @php $amount = (float) ($row['amount'] ?? 0); $percent = ($amount / $maxAmount) * 100; @endphp
                    <div class="legacy-route-row">
                        <div class="legacy-code">{{ $row['doc_code'] ?? '-' }}</div>
                        <div><div class="legacy-name" title="ประเภท {{ $row['doc_code'] ?? '-' }} · Properties {{ $row['doc_properties'] ?? '-' }}">เอกสารขาย / ใบจอง {{ $row['doc_code'] ?? '-' }}</div><div class="legacy-bar-track"><div class="legacy-bar" style="width:{{ number_format($percent, 2, '.', '') }}%"></div></div></div>
                        <div><div class="legacy-amount">{{ number_format($amount, 2) }}</div><div class="legacy-percent">{{ number_format($percent, 1) }}% ของประเภทสูงสุด</div></div>
                    </div>
                @empty
                    <div class="p-5 text-center text-muted">ไม่มีข้อมูล</div>
                @endforelse
            </section>

            <aside>
                <section class="legacy-panel">
                    <div class="legacy-panel-title green"><span><i class="bi bi-database-fill me-2"></i>ยอดขายหลังบ้านประจำวัน</span></div>
                    <div class="legacy-total">
                        <div class="legacy-total-label">ยอดขาย DS / DSN วันที่ {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
                        <div class="legacy-total-value">{{ number_format($creditAmount, 2) }} <small>฿</small></div>
                        <div class="legacy-divider"></div>
                        <div class="legacy-meta"><i class="bi bi-clock-fill me-1"></i>ข้อมูลล่าสุดเมื่อ {{ \Carbon\Carbon::parse($syncedAt)->format('H:i น.') }}</div>
                    </div>
                </section>
                <section class="legacy-panel mt-3">
                    <div class="legacy-panel-title blue"><span><i class="bi bi-bar-chart-line-fill me-2"></i>กราฟวิเคราะห์สัดส่วนเอกสาร</span></div>
                    <div class="legacy-chart">
                        @foreach($rankedRows->take(10) as $row)
                            @php $amount = (float) ($row['amount'] ?? 0); $percent = ($amount / $maxAmount) * 100; @endphp
                            <div class="legacy-chart-row"><div class="legacy-chart-label">{{ $row['doc_code'] ?? '-' }}</div><div class="legacy-chart-track"><div class="legacy-chart-bar" style="width:{{ number_format($percent, 2, '.', '') }}%"></div></div></div>
                        @endforeach
                        <div class="legacy-check"><i class="bi bi-shield-check me-1"></i>DS/DSN {{ number_format($creditCount) }} เอกสาร · ฿{{ number_format($creditAmount, 2) }}<br><span class="text-muted">เอกสารสถานะ 207 ใช้กระทบยอด ไม่รวมเป็นรายได้ซ้ำ</span></div>
                    </div>
                </section>
            </aside>
        </div>
    @endif
</div>
@endsection
