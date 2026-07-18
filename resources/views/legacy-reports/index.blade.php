@extends('layout')

@section('title', 'รายงาน BPlus ทั้งหมด - POPSTAR ERP')
@section('page-title', 'รายงาน BPlus ทั้งหมด')
@section('page-subtitle', 'นำรายการจาก REPORTFILE เดิมมาใช้เป็นแผนที่รายงานของระบบใหม่')

@section('content')
<form method="get" class="legacy-report-page">
    <div class="legacy-hero mb-3">
        <div>
            <div class="legacy-kicker">REPORTFILE</div>
            <h2>ศูนย์รวมรายงานจากระบบเดิม</h2>
            <p>{{ $sourcePath }}</p>
        </div>
        <div class="legacy-stats">
            <div><strong>{{ number_format($totalReports) }}</strong><span>ทั้งหมด</span></div>
            <div><strong>{{ number_format($enabledReports) }}</strong><span>เปิดใช้</span></div>
            <div><strong>{{ number_format($sqlReports) }}</strong><span>มี SQL</span></div>
        </div>
    </div>

    <div class="legacy-toolbar mb-3">
        <div class="legacy-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" value="{{ $q }}" placeholder="ค้นหาชื่อรายงาน / รหัส / ไฟล์ .rpt / SQL">
        </div>
        <select name="status">
            <option value="enabled" @selected($selectedStatus === 'enabled')>เฉพาะเปิดใช้</option>
            <option value="all" @selected($selectedStatus === 'all')>ทั้งหมด</option>
            <option value="disabled" @selected($selectedStatus === 'disabled')>ปิดใช้</option>
        </select>
        <select name="per_page">
            @foreach([25, 50, 100, 200] as $size)
                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} แถว</option>
            @endforeach
        </select>
        <button type="submit"><i class="bi bi-funnel-fill"></i> กรอง</button>
    </div>

    <div class="legacy-category-strip mb-3">
        <a href="{{ route('legacy-reports.index', ['q' => $q, 'status' => $selectedStatus, 'category' => 'all']) }}"
           class="{{ $selectedCategory === 'all' ? 'active' : '' }}">
            <i class="bi bi-grid-fill"></i>
            <span>ทั้งหมด</span>
            <b>{{ number_format($counts['all'] ?? 0) }}</b>
        </a>
        @foreach($categories as $key => $meta)
            <a href="{{ route('legacy-reports.index', ['q' => $q, 'status' => $selectedStatus, 'category' => $key]) }}"
               class="{{ $selectedCategory === $key ? 'active' : '' }} tone-{{ $meta['tone'] }}">
                <i class="bi {{ $meta['icon'] }}"></i>
                <span>{{ $meta['label'] }}</span>
                <b>{{ number_format($counts[$key] ?? 0) }}</b>
            </a>
        @endforeach
    </div>

    <div class="content-card overflow-hidden">
        <div class="legacy-table-head">
            <div>
                <strong>{{ number_format($rows->total()) }}</strong> รายงาน
                <span class="text-muted small">หน้า {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
            </div>
            <a href="{{ route('reports.index', ['category' => 'documents']) }}" class="legacy-link-main">
                <i class="bi bi-window-sidebar"></i> ไปหน้ารายงานใช้งานจริง
            </a>
        </div>

        <div class="table-responsive">
            <table class="table legacy-report-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:120px">รหัส</th>
                        <th>รายงาน</th>
                        <th style="width:170px">ไฟล์</th>
                        <th style="width:130px">หมวด</th>
                        <th class="text-center" style="width:90px">SQL</th>
                        <th class="text-center" style="width:90px">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $report)
                        @php($meta = $categories[$report['category']] ?? ['label' => 'อื่น ๆ', 'icon' => 'bi-grid', 'tone' => 'gray'])
                        <tr>
                            <td>
                                <div class="legacy-code">{{ $report['code'] }}</div>
                                <div class="legacy-key">#{{ $report['key'] }} · G{{ $report['group'] }}</div>
                            </td>
                            <td>
                                <div class="legacy-name">{{ $report['name'] }}</div>
                                @if($report['has_sql'])
                                    <details class="legacy-sql">
                                        <summary>ดู SQL ต้นฉบับ</summary>
                                        <pre>{{ $report['sql'] }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td><span class="legacy-rpt">{{ $report['rpt_file'] ?: '-' }}</span></td>
                            <td>
                                <span class="legacy-cat tone-{{ $meta['tone'] }}">
                                    <i class="bi {{ $meta['icon'] }}"></i>{{ $meta['label'] }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="legacy-dot {{ $report['has_sql'] ? 'yes' : 'no' }}"></span>
                            </td>
                            <td class="text-center">
                                <span class="legacy-status {{ $report['enabled'] ? 'on' : 'off' }}">
                                    {{ $report['enabled'] ? 'เปิดใช้' : 'ปิด' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">ไม่พบรายงานตามเงื่อนไข</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
</form>
@endsection

@push('head')
<style>
    .legacy-hero {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        padding: 22px;
        border: 1px solid #dbe6ef;
        border-radius: 12px;
        background: linear-gradient(135deg, #f8fafc 0%, #ecfeff 100%);
        box-shadow: 0 12px 34px rgba(15,23,42,.07);
    }
    .legacy-kicker { color: #0f766e; font-size: 11px; font-weight: 950; letter-spacing: .08em; }
    .legacy-hero h2 { margin: 4px 0; color: #0f172a; font-size: 28px; font-weight: 950; letter-spacing: 0; }
    .legacy-hero p { margin: 0; color: #64748b; font-size: 12px; word-break: break-all; }
    .legacy-stats { display: grid; grid-template-columns: repeat(3, 112px); gap: 10px; }
    .legacy-stats div {
        min-height: 78px;
        display: grid;
        place-items: center;
        border: 1px solid #ccfbf1;
        border-radius: 10px;
        background: #fff;
    }
    .legacy-stats strong { color: #0f766e; font-size: 24px; line-height: 1; }
    .legacy-stats span { color: #64748b; font-size: 12px; font-weight: 800; }
    .legacy-toolbar {
        display: flex;
        gap: 10px;
        align-items: center;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
    }
    .legacy-search { position: relative; flex: 1; min-width: 260px; }
    .legacy-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
    .legacy-search input,
    .legacy-toolbar select {
        width: 100%;
        min-height: 40px;
        border: 1.5px solid #e2e8f0;
        border-radius: 9px;
        background: #f8fafc;
        color: #0f172a;
        font: inherit;
        outline: none;
    }
    .legacy-search input { padding: 8px 12px 8px 38px; }
    .legacy-toolbar select { width: auto; padding: 8px 34px 8px 10px; }
    .legacy-toolbar button {
        min-height: 40px;
        border: 0;
        border-radius: 9px;
        padding: 0 16px;
        background: #0f766e;
        color: #fff;
        font-weight: 900;
    }
    .legacy-category-strip {
        display: flex;
        gap: 9px;
        overflow-x: auto;
        padding-bottom: 4px;
    }
    .legacy-category-strip a {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 42px;
        padding: 0 12px;
        border: 1.5px solid #e2e8f0;
        border-radius: 999px;
        background: #fff;
        color: #475569;
        text-decoration: none;
        font-weight: 900;
        font-size: 13px;
    }
    .legacy-category-strip a.active { background: #0f172a; color: #fff; border-color: #0f172a; }
    .legacy-category-strip b {
        display: inline-grid;
        place-items: center;
        min-width: 28px;
        height: 24px;
        padding: 0 8px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #0f172a;
        font-size: 12px;
    }
    .legacy-category-strip a.active b { background: rgba(255,255,255,.18); color: #fff; }
    .legacy-table-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-bottom: 1px solid #eef2f7;
    }
    .legacy-link-main {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 36px;
        padding: 0 12px;
        border-radius: 8px;
        background: #ecfeff;
        color: #0f766e;
        font-weight: 900;
        text-decoration: none;
    }
    .legacy-report-table thead th {
        background: #f8fafc;
        color: #64748b;
        font-size: 11px;
        font-weight: 950;
        text-transform: uppercase;
        border-bottom: 1px solid #e2e8f0;
    }
    .legacy-report-table td { border-bottom: 1px solid #eef2f7; }
    .legacy-code { color: #0f766e; font-weight: 950; font-size: 13px; }
    .legacy-key { color: #94a3b8; font-size: 11px; margin-top: 2px; }
    .legacy-name { color: #0f172a; font-weight: 850; line-height: 1.45; }
    .legacy-rpt {
        display: inline-flex;
        padding: 4px 8px;
        border-radius: 7px;
        background: #f1f5f9;
        color: #475569;
        font-size: 12px;
        font-weight: 850;
    }
    .legacy-cat,
    .legacy-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 26px;
        padding: 0 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
    }
    .legacy-cat { background: #eef2ff; color: #4338ca; }
    .legacy-status.on { background: #dcfce7; color: #047857; }
    .legacy-status.off { background: #f1f5f9; color: #64748b; }
    .legacy-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 999px;
    }
    .legacy-dot.yes { background: #10b981; }
    .legacy-dot.no { background: #cbd5e1; }
    .legacy-sql { margin-top: 6px; }
    .legacy-sql summary {
        cursor: pointer;
        color: #2563eb;
        font-size: 12px;
        font-weight: 800;
    }
    .legacy-sql pre {
        margin: 8px 0 0;
        max-height: 220px;
        overflow: auto;
        padding: 10px;
        border-radius: 8px;
        background: #0f172a;
        color: #dbeafe;
        font-size: 11px;
        text-align: left;
        white-space: pre-wrap;
    }
    @media (max-width: 980px) {
        .legacy-hero { flex-direction: column; }
        .legacy-stats { grid-template-columns: repeat(3, 1fr); }
        .legacy-toolbar { flex-wrap: wrap; }
        .legacy-search { flex-basis: 100%; }
    }
</style>
@endpush
