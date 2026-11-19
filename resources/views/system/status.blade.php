@extends('layout')
@section('title', 'สถานะระบบ - MPM Food Group')
@section('page-title', 'สถานะระบบ')
@section('page-subtitle', 'ภาพรวมทรัพยากร เครื่องมือสำรองข้อมูล คิวงาน และการซิงค์ BPlus')
@section('content')
<div class="status-toolbar">
    <div class="text-muted small">ตรวจล่าสุด {{ $checkedAt->format('d/m/Y H:i:s') }}</div>
    <a class="btn btn-outline-primary" href="{{ route('system.status') }}" aria-label="รีเฟรชสถานะระบบ"><i class="bi bi-arrow-clockwise me-1"></i>รีเฟรช</a>
</div>

<div class="system-status-grid">
    @foreach($cards as $key => $card)
        <section id="status-{{ $key }}" class="system-status-card status-{{ $card['status'] }}">
            <header class="status-card-header">
                <h2>{{ $card['title'] }}</h2>
                <span class="status-badge">{{ ['good' => 'ปกติ', 'warning' => 'เตือน', 'danger' => 'วิกฤต', 'unknown' => 'ไม่มีข้อมูล', 'neutral' => 'ข้อมูล'][ $card['status'] ] ?? 'ตรวจสอบ' }}</span>
            </header>
            <div class="status-summary">{{ $card['summary'] }}</div>
            @if(count($card['details'] ?? []))
                <dl class="status-details">
                    @foreach($card['details'] as $detail)
                        <div><dt>{{ $detail['label'] }}</dt><dd>{{ $detail['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @if(count($card['rows'] ?? []))
                <ul class="status-rows">
                    @foreach($card['rows'] as $row)
                        <li><span>{{ $row['label'] }}</span><strong>{{ $row['value'] }}</strong></li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>

<style>
    .status-toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1rem; }
    .system-status-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; align-items:start; }
    .system-status-card { min-width:0; padding:1.1rem; background:var(--erp-surface,#fff); border:1px solid var(--erp-border,#e5e7eb); border-left:4px solid var(--status-color,#94a3b8); border-radius:14px; box-shadow:0 4px 14px rgba(20,35,55,.04); }
    .system-status-card.status-good { --status-color:#15803d; --status-bg:#dcfce7; }
    .system-status-card.status-warning { --status-color:#b45309; --status-bg:#fef3c7; }
    .system-status-card.status-danger { --status-color:#b91c1c; --status-bg:#fee2e2; }
    .system-status-card.status-unknown { --status-color:#64748b; --status-bg:#e2e8f0; }
    .system-status-card.status-neutral { --status-color:#2563eb; --status-bg:#dbeafe; }
    .status-card-header { display:flex; align-items:center; justify-content:space-between; gap:.6rem; }
    .status-card-header h2 { margin:0; font-size:1.05rem; font-weight:700; color:var(--erp-text,#1f2937); }
    .status-badge { flex:0 0 auto; padding:.25rem .65rem; border-radius:999px; background:var(--status-bg); color:var(--status-color); font-size:.78rem; font-weight:700; }
    .status-summary { margin:.65rem 0; color:var(--erp-text,#1f2937); font-weight:650; overflow-wrap:anywhere; }
    .status-details,.status-rows { margin:0; padding:0; list-style:none; }
    .status-details>div,.status-rows>li { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; padding:.48rem 0; border-top:1px solid var(--erp-border,#e5e7eb); font-size:.9rem; }
    .status-details dt,.status-rows>li>span { color:var(--erp-muted,#64748b); font-weight:500; }
    .status-details dd,.status-rows>li>strong { margin:0; text-align:right; font-weight:600; overflow-wrap:anywhere; }
    .status-rows>li { display:block; }
    .status-rows>li>span { display:block; margin-bottom:.2rem; }
    .status-rows>li>strong { display:block; text-align:left; font-weight:500; }
    @media(max-width:760px) { .system-status-grid { grid-template-columns:1fr; } .system-status-card { padding:1rem; } }
</style>
@endsection
