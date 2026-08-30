@extends('layout')

@section('title', 'POS Build Center - PopCentral')
@section('page-title', 'POS Build Center')
@section('page-subtitle', 'สร้าง ทดสอบ และเผยแพร่โปรแกรม POS โดยไม่ต้องใช้คำสั่ง')

@section('content')
<style>
    .build-center { max-width:1180px; margin:0 auto; }
    .build-head { display:flex; align-items:flex-end; justify-content:space-between; gap:18px; margin-bottom:18px; }
    .build-head h1 { margin:0; color:var(--erp-text); font-size:26px; font-weight:800; }
    .build-head p { margin:4px 0 0; color:var(--erp-muted); }
    .build-flow { display:grid; grid-template-columns:repeat(4,1fr); margin-bottom:18px; border:1px solid var(--erp-border); border-radius:8px; overflow:hidden; background:var(--erp-surface); }
    .build-step { position:relative; min-height:88px; padding:16px; border-right:1px solid var(--erp-border); }
    .build-step:last-child { border-right:0; }
    .build-step span { display:block; margin-bottom:6px; color:#2563eb; font-size:11px; font-weight:900; }
    .build-step strong { display:block; font-size:14px; }
    .build-step small { color:var(--erp-muted); }
    .build-panel { display:grid; grid-template-columns:minmax(0,1fr) 330px; gap:16px; margin-bottom:18px; }
    .build-form,.build-policy { padding:18px; border:1px solid var(--erp-border); border-radius:8px; background:var(--erp-surface); }
    .build-form h2,.build-policy h2,.build-history h2 { margin:0 0 5px; font-size:17px; font-weight:800; }
    .build-form-grid { display:grid; grid-template-columns:180px minmax(180px,1fr) auto; gap:12px; align-items:end; margin-top:16px; }
    .build-policy ul { margin:12px 0 0; padding-left:18px; color:var(--erp-muted); }
    .build-policy li { margin:7px 0; }
    .build-history { border:1px solid var(--erp-border); border-radius:8px; overflow:hidden; background:var(--erp-surface); }
    .build-history-head { display:flex; justify-content:space-between; gap:12px; padding:16px 18px; border-bottom:1px solid var(--erp-border); }
    .build-table { width:100%; border-collapse:collapse; }
    .build-table th { padding:10px 12px; color:var(--erp-muted); background:var(--erp-surface-2); font-size:11px; text-align:left; white-space:nowrap; }
    .build-table td { padding:12px; border-top:1px solid var(--erp-border); vertical-align:middle; }
    .build-table code { color:var(--erp-text); }
    .build-status { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:5px; font-size:11px; font-weight:800; }
    .build-status.queued { color:#805b08; background:#fff5d6; }
    .build-status.in_progress { color:#1d4ed8; background:#e8f0ff; }
    .build-status.success { color:#08714d; background:#e4f7ef; }
    .build-status.failed,.build-status.cancelled { color:#a31d2d; background:#fdebed; }
    .build-empty { padding:34px; color:var(--erp-muted); text-align:center; }
    @media(max-width:900px) { .build-flow { grid-template-columns:1fr 1fr; } .build-step:nth-child(2) { border-right:0; } .build-step { border-bottom:1px solid var(--erp-border); } .build-panel { grid-template-columns:1fr; } }
    @media(max-width:650px) { .build-head { display:block; } .build-flow { grid-template-columns:1fr; } .build-step { border-right:0; } .build-form-grid { grid-template-columns:1fr; } .build-history { overflow-x:auto; } }
</style>

<div class="build-center">
    <div class="build-head">
        <div><h1>POS Build Center</h1><p>สร้างไฟล์ติดตั้ง Windows จาก source ที่เก็บใน GitHub พร้อมด่านทดสอบอัตโนมัติ</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('settings.pos-designer') }}"><i class="bi bi-grid-1x2 me-1"></i> POS Designer</a>
    </div>

    @if(session('success'))<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}</div>@endif
    @unless($githubConfigured)
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-key mt-1"></i><div><strong>รอเชื่อม GitHub ครั้งแรก</strong><br><span class="small">IT ต้องเพิ่ม <code>GITHUB_POS_BUILD_TOKEN</code> ใน production เพียงครั้งเดียว หลังจากนั้น Admin กด Build จากหน้านี้ได้เอง</span></div>
        </div>
    @endunless

    <div class="build-flow">
        <div class="build-step"><span>01 · REQUEST</span><strong>กด Build บน ERP</strong><small>ระบุเวอร์ชันและ source</small></div>
        <div class="build-step"><span>02 · TEST</span><strong>GitHub ทดสอบอัตโนมัติ</strong><small>Python, SQLite และ Sync</small></div>
        <div class="build-step"><span>03 · PACKAGE</span><strong>สร้าง Windows Installer</strong><small>PyInstaller + Inno Setup</small></div>
        <div class="build-step"><span>04 · PUBLISH</span><strong>ส่งกลับ Production</strong><small>พร้อมดาวน์โหลดและติดตั้ง</small></div>
    </div>

    <div class="build-panel">
        <form class="build-form" method="POST" action="{{ route('settings.pos-builds.store') }}">
            @csrf
            <h2>สร้างโปรแกรม POS รุ่นใหม่</h2>
            <div class="text-muted small">ใช้เมื่อตัวโปรแกรม Python, SQLite, Sync หรือขั้นตอนขายเปลี่ยนเท่านั้น</div>
            <div class="build-form-grid">
                <div><label class="form-label fw-semibold">เวอร์ชัน</label><input class="form-control" name="version" value="{{ old('version', $suggestedVersion) }}" placeholder="0.5.0" required></div>
                <div><label class="form-label fw-semibold">Source branch / tag / commit</label><input class="form-control font-monospace" name="source_ref" value="{{ old('source_ref', 'main') }}" required></div>
                <button class="btn btn-primary px-4" type="submit" @disabled(!$githubConfigured)><i class="bi bi-play-fill me-1"></i> Build โปรแกรม</button>
            </div>
        </form>
        <aside class="build-policy">
            <h2>ไม่ต้อง Build โปรแกรมเมื่อ</h2>
            <ul>
                <li>ลากวางหน้าจอใน POS Designer</li>
                <li>เปลี่ยนสี ปุ่ม หรือตำแหน่ง Layout</li>
                <li>แก้ข้อมูลสาขา ผู้ใช้ หรือพร้อมเพย์</li>
            </ul>
            <a href="{{ route('settings.pos-designer') }}" class="btn btn-light border w-100 mt-2">ไป Build &amp; Publish Layout</a>
        </aside>
    </div>

    <section class="build-history">
        <div class="build-history-head"><div><h2>ประวัติการ Build</h2><div class="small text-muted">Repository: {{ $repository }}</div></div><a class="btn btn-sm btn-outline-primary" href="{{ route('python-pos.download') }}"><i class="bi bi-download me-1"></i> ดาวน์โหลดรุ่นล่าสุด</a></div>
        @if($builds->isEmpty())
            <div class="build-empty"><i class="bi bi-clock-history fs-2 d-block mb-2"></i>ยังไม่มีประวัติการ Build จาก ERP</div>
        @else
            <table class="build-table"><thead><tr><th>เวอร์ชัน</th><th>Source</th><th>สถานะ</th><th>ผู้สั่ง</th><th>เวลา</th><th>จัดการ</th></tr></thead><tbody>
            @foreach($builds as $build)
                <tr>
                    <td><strong>{{ $build->version }}</strong><div class="small text-muted">{{ $build->channel }}</div></td>
                    <td><code>{{ $build->source_ref }}</code>@if($build->commit_sha)<div class="small text-muted">{{ substr($build->commit_sha, 0, 8) }}</div>@endif</td>
                    <td><span class="build-status {{ $build->status }}"><i class="bi {{ $build->status === 'success' ? 'bi-check-circle-fill' : ($build->status === 'in_progress' ? 'bi-arrow-repeat' : ($build->status === 'queued' ? 'bi-hourglass-split' : 'bi-x-circle-fill')) }}"></i>{{ ['queued'=>'รอคิว','in_progress'=>'กำลัง Build','success'=>'สำเร็จ','failed'=>'ล้มเหลว','cancelled'=>'ยกเลิก'][$build->status] ?? $build->status }}</span>@if($build->failure_message)<div class="small text-danger mt-1">{{ $build->failure_message }}</div>@endif</td>
                    <td>{{ $build->requester?->name ?? '-' }}</td>
                    <td class="small">{{ $build->created_at->timezone('Asia/Bangkok')->format('d/m/Y H:i') }}</td>
                    <td><div class="d-flex gap-2">
                        @if($build->github_run_url)<a class="btn btn-sm btn-light border" href="{{ $build->github_run_url }}" target="_blank" rel="noopener">ดู Log</a>@endif
                        @if($build->isActive())<form method="POST" action="{{ route('settings.pos-builds.refresh', $build) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-clockwise"></i> สถานะ</button></form>@endif
                        @if($build->status === 'success')<a class="btn btn-sm btn-success" href="{{ route('python-pos.download') }}"><i class="bi bi-download"></i></a>@endif
                    </div></td>
                </tr>
            @endforeach
            </tbody></table>
        @endif
    </section>
</div>
@php($activeBuild = $builds->first(fn ($build) => $build->isActive()))
@if($activeBuild && $githubConfigured)
<script>
setTimeout(function checkPosBuild() {
    fetch(@js(route('settings.pos-builds.refresh', $activeBuild)), {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': @js(csrf_token()),
        },
    }).then(function (response) {
        if (!response.ok) throw new Error('status request failed');
        return response.json();
    }).then(function () {
        window.location.reload();
    }).catch(function () {
        setTimeout(checkPosBuild, 30000);
    });
}, 15000);
</script>
@endif
@endsection
