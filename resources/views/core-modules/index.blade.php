@extends('layout')

@section('title', 'คู่มือ PopStar 4M')
@section('page-title', 'คู่มือ PopStar 4M')
@section('page-subtitle', 'ระบบงาน คน เงิน สินค้า และการบริหารของ PopStar Shop')

@push('head')
<style>
    [x-cloak] { display: none !important; }

    .manual-shell { display: grid; gap: 14px; color: #1d3b52; }
    .manual-panel { background: #fff; border: 1px solid #dbe7ef; border-radius: 8px; }
    .manual-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; padding: 18px 20px; border-top: 4px solid #1599d3; }
    .manual-eyebrow { color: #0f766e; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    .manual-title { margin: 3px 0 5px; color: #15364d; font-size: 24px; font-weight: 900; line-height: 1.2; }
    .manual-lead { margin: 0; max-width: 860px; color: #647b8c; font-size: 13px; line-height: 1.55; }
    .manual-actions { display: flex; gap: 8px; flex: 0 0 auto; }
    .manual-icon-button { width: 38px; height: 38px; display: grid; place-items: center; border: 1px solid #cbdbe5; border-radius: 7px; background: #fff; color: #315f80; }
    .manual-icon-button:hover { color: #1599d3; border-color: #1599d3; }

    .manual-toolbar { display: grid; grid-template-columns: minmax(240px, 1fr) auto; gap: 12px; padding: 12px; }
    .manual-search { position: relative; }
    .manual-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #7890a1; }
    .manual-search input { width: 100%; height: 40px; padding: 0 12px 0 38px; border: 1px solid #cbdbe5; border-radius: 7px; color: #15364d; background: #fff; }
    .manual-search input:focus { outline: 2px solid rgba(21, 153, 211, .16); border-color: #1599d3; }
    .manual-segments { display: flex; gap: 4px; padding: 3px; border: 1px solid #dbe7ef; border-radius: 7px; background: #f5f8fa; }
    .manual-segment { min-width: 100px; height: 32px; padding: 0 12px; border: 0; border-radius: 5px; background: transparent; color: #5d7485; font-size: 12px; font-weight: 900; }
    .manual-segment.active { color: #fff; background: #315f80; }

    .pillar-summary { display: grid; grid-template-columns: 46px minmax(0, 1fr); gap: 12px; align-items: start; padding: 16px 18px 12px; border-bottom: 1px solid #edf2f5; }
    .pillar-icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 7px; font-size: 19px; }
    .tone-teal { color: #0f766e; background: #ccfbf1; }
    .tone-red { color: #b4232c; background: #fee2e2; }
    .tone-blue { color: #146ca4; background: #e2f3fc; }
    .tone-amber { color: #9a6700; background: #fef3c7; }
    .pillar-name { margin: 0 0 3px; color: #15364d; font-size: 17px; font-weight: 900; }
    .pillar-copy { margin: 0; color: #647b8c; font-size: 13px; line-height: 1.5; }

    .manual-table-wrap { overflow-x: auto; }
    .manual-table { width: 100%; min-width: 760px; border-collapse: collapse; }
    .manual-table th { padding: 9px 12px; color: #5d7485; background: #f7fafc; border-bottom: 1px solid #dbe7ef; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    .manual-table td { padding: 10px 12px; color: #425f73; border-bottom: 1px solid #edf2f5; font-size: 12px; vertical-align: middle; }
    .manual-table tbody tr:last-child td { border-bottom: 0; }
    .manual-table strong { color: #15364d; font-size: 13px; }
    .program-link, .program-locked { width: 32px; height: 32px; display: inline-grid; place-items: center; border-radius: 6px; }
    .program-link { color: #1585c0; background: #e8f5fb; }
    .program-link:hover { color: #fff; background: #1585c0; }
    .program-locked { color: #91a3af; background: #f0f3f5; }

    .manual-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #dbe7ef; }
    .manual-section-head h2 { margin: 0; color: #15364d; font-size: 16px; font-weight: 900; }
    .manual-section-head span { color: #7890a1; font-size: 12px; }
    .flow-tabs { display: flex; gap: 6px; padding: 10px 12px; overflow-x: auto; border-bottom: 1px solid #edf2f5; }
    .flow-tab { flex: 0 0 auto; height: 34px; padding: 0 12px; border: 1px solid #dbe7ef; border-radius: 6px; color: #5d7485; background: #fff; font-size: 12px; font-weight: 800; }
    .flow-tab.active { color: #fff; border-color: #1599d3; background: #1599d3; }
    .flow-content { padding: 16px 18px 18px; }
    .flow-meta { display: grid; grid-template-columns: minmax(180px, .45fr) 1fr; gap: 12px; margin-bottom: 14px; }
    .flow-meta div { padding: 10px 12px; border-left: 3px solid #1599d3; background: #f6fafc; }
    .flow-meta small { display: block; color: #7890a1; font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .flow-meta strong { color: #274b63; font-size: 12px; }
    .flow-steps { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
    .flow-step { position: relative; min-height: 112px; padding: 11px; border: 1px solid #dbe7ef; border-radius: 7px; background: #fff; }
    .flow-step:not(:last-child)::after { content: '\F285'; position: absolute; right: -12px; top: 42px; z-index: 2; color: #1599d3; font-family: bootstrap-icons; font-size: 14px; }
    .flow-number { display: block; color: #1599d3; font-size: 10px; font-weight: 900; }
    .flow-step strong { display: block; margin: 4px 0; color: #15364d; font-size: 12px; }
    .flow-step p { margin: 0; color: #6d8291; font-size: 11px; line-height: 1.4; }
    .flow-step a { color: inherit; text-decoration: none; }

    .manual-two-col { display: grid; grid-template-columns: 1.05fr .95fr; gap: 14px; }
    .gap-list { display: grid; }
    .gap-row { display: grid; grid-template-columns: 86px minmax(0, 1fr); gap: 12px; padding: 12px 16px; border-bottom: 1px solid #edf2f5; }
    .gap-row:last-child { border-bottom: 0; }
    .gap-status { align-self: start; padding: 4px 7px; border-radius: 5px; text-align: center; font-size: 10px; font-weight: 900; }
    .gap-critical { color: #a61b27; background: #fee2e2; }
    .gap-control { color: #8a5a00; background: #fef3c7; }
    .gap-growth { color: #146c43; background: #d1fae5; }
    .gap-row h3 { margin: 0 0 3px; color: #274b63; font-size: 13px; font-weight: 900; }
    .gap-row p { margin: 0; color: #6d8291; font-size: 11px; line-height: 1.45; }
    .routine-row { padding: 12px 16px; border-bottom: 1px solid #edf2f5; }
    .routine-row:last-child { border-bottom: 0; }
    .routine-meta { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
    .routine-meta strong { color: #15364d; font-size: 12px; }
    .routine-meta span { color: #7890a1; font-size: 11px; }
    .routine-items { margin: 0; padding-left: 18px; color: #526d7f; font-size: 11px; line-height: 1.65; }

    @media (max-width: 1100px) {
        .flow-steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .flow-step:nth-child(3)::after { display: none; }
        .manual-two-col { grid-template-columns: 1fr; }
    }
    @media (max-width: 720px) {
        .manual-header { flex-direction: column; padding: 15px; }
        .manual-title { font-size: 20px; }
        .manual-toolbar { grid-template-columns: 1fr; }
        .manual-segments { overflow-x: auto; }
        .manual-segment { min-width: 84px; }
        .flow-meta { grid-template-columns: 1fr; }
        .flow-steps { grid-template-columns: 1fr; }
        .flow-step { min-height: 0; }
        .flow-step::after { display: none !important; }
        .gap-row { grid-template-columns: 74px minmax(0, 1fr); padding: 11px 12px; }
    }
    @media print {
        .app-header, .app-sidebar, .manual-toolbar, .manual-actions, .flow-tabs { display: none !important; }
        .app-main { margin: 0 !important; }
        .app-content { padding: 0 !important; }
        .manual-shell { gap: 8px; }
        .manual-panel { break-inside: avoid; }
        [x-cloak] { display: block !important; }
    }
</style>
@endpush

@section('content')
@php
    $routeAccess = function (string $routeName): bool {
        if (! \Illuminate\Support\Facades\Route::has($routeName)) {
            return false;
        }

        $permission = \App\Support\RoutePermissions::resolve($routeName);

        return $permission === null || (auth()->user() && auth()->user()->hasPermission($permission));
    };
@endphp

<div class="manual-shell" x-data="{ pillar: 'man', flow: 'pos', query: '' }">
    <section class="manual-panel manual-header">
        <div>
            <div class="manual-eyebrow">PopStar 4M ERP Handbook</div>
            <h1 class="manual-title">คู่มือการทำงาน ERP แบบครบวงจร</h1>
            <p class="manual-lead">จุดอ้างอิงกลางสำหรับงานขาย ซื้อ คลัง ผลิต การเงิน บัญชี และการบริหาร แบ่งระบบตาม MAN, MONEY, MATERIAL และ MANAGEMENT พร้อมเส้นทางข้อมูลและรายการที่ยังต้องพัฒนา</p>
        </div>
        <div class="manual-actions">
            <button type="button" class="manual-icon-button" title="พิมพ์คู่มือ" aria-label="พิมพ์คู่มือ" onclick="window.print()"><i class="bi bi-printer"></i></button>
        </div>
    </section>

    <section class="manual-panel manual-toolbar">
        <label class="manual-search">
            <i class="bi bi-search"></i>
            <input type="search" x-model="query" placeholder="ค้นหาโปรแกรม ข้อมูลเข้า หรือผลลัพธ์">
        </label>
        <div class="manual-segments" role="tablist" aria-label="หมวด 4M">
            @foreach ($pillars as $pillar)
                <button type="button" class="manual-segment" :class="pillar === '{{ $pillar['key'] }}' && 'active'" @click="pillar = '{{ $pillar['key'] }}'">{{ $pillar['label'] }}</button>
            @endforeach
        </div>
    </section>

    @foreach ($pillars as $pillar)
        <section class="manual-panel" x-show="pillar === '{{ $pillar['key'] }}'" x-cloak>
            <div class="pillar-summary">
                <div class="pillar-icon tone-{{ $pillar['tone'] }}"><i class="bi {{ $pillar['icon'] }}"></i></div>
                <div>
                    <h2 class="pillar-name">{{ $pillar['label'] }} · {{ $pillar['title'] }}</h2>
                    <p class="pillar-copy">{{ $pillar['summary'] }}</p>
                </div>
            </div>
            <div class="manual-table-wrap">
                <table class="manual-table">
                    <thead><tr><th>โปรแกรม</th><th>รับข้อมูลจาก</th><th>ส่งผลไป</th><th class="text-center">เปิด</th></tr></thead>
                    <tbody>
                    @foreach ($pillar['programs'] as $program)
                        <tr x-show="!query || $el.textContent.toLowerCase().includes(query.toLowerCase())">
                            <td><strong>{{ $program[0] }}</strong></td>
                            <td>{{ $program[2] }}</td>
                            <td>{{ $program[3] }}</td>
                            <td class="text-center">
                                @if ($routeAccess($program[1]))
                                    <a class="program-link" href="{{ route($program[1]) }}" title="เปิด {{ $program[0] }}"><i class="bi bi-arrow-up-right"></i></a>
                                @else
                                    <span class="program-locked" title="ไม่มีสิทธิ์หรือยังไม่มีเส้นทาง"><i class="bi bi-lock"></i></span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <section class="manual-panel">
        <div class="manual-section-head">
            <h2><i class="bi bi-bezier2 me-2"></i>เส้นทางข้อมูลของแต่ละวงจร</h2>
            <span>{{ count($workflows) }} วงจรหลัก</span>
        </div>
        <div class="flow-tabs" role="tablist">
            @foreach ($workflows as $workflow)
                <button type="button" class="flow-tab" :class="flow === '{{ $workflow['key'] }}' && 'active'" @click="flow = '{{ $workflow['key'] }}'">{{ $workflow['label'] }}</button>
            @endforeach
        </div>
        @foreach ($workflows as $workflow)
            <div class="flow-content" x-show="flow === '{{ $workflow['key'] }}'" x-cloak>
                <div class="flow-meta">
                    <div><small>ผู้รับผิดชอบ</small><strong>{{ $workflow['owner'] }}</strong></div>
                    <div><small>ผลลัพธ์ที่ต้องได้</small><strong>{{ $workflow['goal'] }}</strong></div>
                </div>
                <div class="flow-steps">
                    @foreach ($workflow['steps'] as $index => $step)
                        <article class="flow-step">
                            @if ($routeAccess($step[1]))<a href="{{ route($step[1]) }}">@endif
                                <span class="flow-number">STEP {{ $index + 1 }}</span>
                                <strong>{{ $step[0] }}</strong>
                                <p>{{ $step[2] }}</p>
                            @if ($routeAccess($step[1]))</a>@endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    <div class="manual-two-col">
        <section class="manual-panel">
            <div class="manual-section-head">
                <h2><i class="bi bi-exclamation-diamond me-2"></i>สิ่งที่ระบบยังขาด</h2>
                <span>จัดลำดับตามความเสี่ยง</span>
            </div>
            <div class="gap-list">
                @foreach ($gaps as $gap)
                    <article class="gap-row">
                        <span class="gap-status gap-{{ $gap['level'] }}">{{ $gap['status'] }}</span>
                        <div><h3>{{ $gap['title'] }}</h3><p>{{ $gap['detail'] }}</p></div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="manual-panel">
            <div class="manual-section-head">
                <h2><i class="bi bi-calendar-check me-2"></i>รอบควบคุมงาน</h2>
                <span>รายการตรวจประจำรอบ</span>
            </div>
            @foreach ($routines as $routine)
                <article class="routine-row">
                    <div class="routine-meta"><strong>{{ $routine['period'] }}</strong><span>{{ $routine['owner'] }}</span></div>
                    <ul class="routine-items">@foreach ($routine['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                </article>
            @endforeach
        </section>
    </div>
</div>
@endsection
