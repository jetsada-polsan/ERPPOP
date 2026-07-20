@extends('layout')

@section('title', 'งวดบัญชี - POPSTAR ERP')
@section('page-title', 'งวดบัญชีและการปิดงวด')
@section('page-subtitle', 'ควบคุมการบันทึก แก้ไข ยกเลิก และลบเอกสารย้อนหลัง')

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .period-shell { display: grid; gap: 14px; }
    .period-panel { background: #fff; border: 1px solid #dbe7ef; border-radius: 8px; }
    .period-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .period-stat { padding: 15px 18px; border-right: 1px solid #e7eef3; }
    .period-stat:last-child { border-right: 0; }
    .period-stat span { display: block; color: #718696; font-size: 11px; font-weight: 800; }
    .period-stat strong { color: #1d3b52; font-size: 22px; font-weight: 900; }
    .period-form { display: grid; grid-template-columns: 1.15fr .85fr .75fr .75fr 1.2fr auto; gap: 10px; align-items: end; padding: 16px; }
    .period-field label { display: block; margin-bottom: 5px; color: #5f7788; font-size: 11px; font-weight: 800; }
    .period-field input, .period-field select { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #cbdbe5; border-radius: 6px; color: #274b63; background: #fff; font-size: 12px; }
    .period-table { width: 100%; min-width: 880px; border-collapse: collapse; }
    .period-table th { padding: 10px 13px; color: #61798a; background: #f6f9fb; border-bottom: 1px solid #dbe7ef; font-size: 11px; font-weight: 900; }
    .period-table td { padding: 11px 13px; color: #425f73; border-bottom: 1px solid #edf2f5; font-size: 12px; vertical-align: middle; }
    .period-table tr:last-child td { border-bottom: 0; }
    .period-table strong { color: #183c54; }
    .period-status { display: inline-flex; align-items: center; gap: 5px; min-width: 76px; padding: 5px 8px; border-radius: 5px; font-size: 10px; font-weight: 900; }
    .period-open { color: #146c43; background: #d1fae5; }
    .period-closed { color: #a61b27; background: #fee2e2; }
    .period-action { width: 34px; height: 34px; display: inline-grid; place-items: center; border: 1px solid #d3e0e8; border-radius: 6px; background: #fff; color: #315f80; }
    .period-action.close-action:hover { color: #fff; border-color: #b4232c; background: #b4232c; }
    .period-action.open-action:hover { color: #fff; border-color: #0f766e; background: #0f766e; }
    .period-empty { padding: 36px 20px; color: #7890a1; text-align: center; }
    .close-checks { min-width: 230px; display: grid; gap: 3px; }
    .close-check { display: flex; gap: 6px; align-items: center; font-size: 10px; }
    .close-check.pass { color: #146c43; } .close-check.block { color: #a61b27; }
    .period-modal-backdrop { position: fixed; inset: 0; z-index: 2000; display: grid; place-items: center; padding: 18px; background: rgba(22, 47, 65, .42); }
    .period-modal { width: min(480px, 100%); padding: 20px; border-radius: 8px; background: #fff; box-shadow: 0 24px 70px rgba(22, 47, 65, .24); }
    .period-modal h2 { margin: 0 0 5px; color: #183c54; font-size: 17px; font-weight: 900; }
    .period-modal p { color: #6b8190; font-size: 12px; line-height: 1.5; }
    .period-modal textarea { width: 100%; min-height: 88px; padding: 10px; border: 1px solid #cbdbe5; border-radius: 6px; font-size: 12px; }
    @media (max-width: 1050px) { .period-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) {
        .period-summary { grid-template-columns: 1fr; }
        .period-stat { border-right: 0; border-bottom: 1px solid #e7eef3; }
        .period-stat:last-child { border-bottom: 0; }
        .period-form { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
@php
    $openCount = $periods->where('status', 'open')->count();
    $closedCount = $periods->where('status', 'closed')->count();
    $currentClosed = $periods->first(fn ($period) => $period->isClosed() && today()->betweenIncluded($period->starts_on, $period->ends_on));
@endphp

<div class="period-shell" x-data="{ closeId: null, closeName: '', closeAction: '' }" x-cloak>
    @if ($errors->any())
        <div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>
    @endif

    <section class="period-panel period-summary">
        <div class="period-stat"><span>งวดเปิด</span><strong>{{ number_format($openCount) }}</strong></div>
        <div class="period-stat"><span>งวดปิด</span><strong>{{ number_format($closedCount) }}</strong></div>
        <div class="period-stat"><span>สถานะวันที่ปัจจุบัน</span><strong style="font-size:15px;color:{{ $currentClosed ? '#a61b27' : '#146c43' }}">{{ $currentClosed ? 'ล็อกโดย '.$currentClosed->name : 'บันทึกได้' }}</strong></div>
    </section>

    <section class="period-panel">
        <form method="post" action="{{ route('accounting-periods.store') }}" class="period-form">
            @csrf
            <div class="period-field"><label>ชื่องวด</label><input name="name" value="{{ old('name') }}" placeholder="เช่น กรกฎาคม 2569" required></div>
            <div class="period-field"><label>ขอบเขต</label><select name="branch_id"><option value="">ทั้งบริษัท</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
            <div class="period-field"><label>วันที่เริ่ม</label><input type="date" name="starts_on" value="{{ old('starts_on', today()->startOfMonth()->toDateString()) }}" required></div>
            <div class="period-field"><label>วันที่สิ้นสุด</label><input type="date" name="ends_on" value="{{ old('ends_on', today()->endOfMonth()->toDateString()) }}" required></div>
            <div class="period-field"><label>หมายเหตุ</label><input name="note" value="{{ old('note') }}" placeholder="เอกสารประกอบหรือเงื่อนไข"></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>สร้างงวด</button>
        </form>
    </section>

    <section class="period-panel" style="overflow-x:auto">
        @if ($periods->isEmpty())
            <div class="period-empty"><i class="bi bi-calendar2-week fs-3 d-block mb-2"></i>ยังไม่มีงวดบัญชี</div>
        @else
            <table class="period-table">
                <thead><tr><th>งวด</th><th>ขอบเขต</th><th>ช่วงวันที่</th><th>สถานะ</th><th>Pre-close checklist</th><th>ผู้ปิดงวด</th><th>หมายเหตุ</th><th></th></tr></thead>
                <tbody>
                @foreach ($periods as $period)
                    <tr>
                        <td><strong>{{ $period->name }}</strong></td>
                        <td>{{ $period->branch ? $period->branch->code.' · '.$period->branch->name_th : 'ทั้งบริษัท' }}</td>
                        <td>{{ $period->starts_on->thaiDate() }} - {{ $period->ends_on->thaiDate() }}</td>
                        <td><span class="period-status period-{{ $period->status }}"><i class="bi bi-{{ $period->isClosed() ? 'lock-fill' : 'unlock' }}"></i>{{ $period->isClosed() ? 'ปิดงวด' : 'เปิดงวด' }}</span></td>
                        <td>@if($period->isClosed())<span class="text-muted small">ตรวจผ่านและล็อกแล้ว</span>@else @php($checks=$readiness[$period->id]??[])<div class="close-checks">@foreach($checks as $check)<div class="close-check {{ $check['status'] }}" title="{{ $check['detail'] }}"><i class="bi bi-{{ $check['status']==='pass'?'check-circle-fill':'x-circle-fill' }}"></i><span>{{ $check['label'] }}: {{ $check['detail'] }}</span></div>@endforeach</div>@endif</td>
                        <td>{{ $period->closedBy?->name ?? '-' }}@if ($period->closed_at)<div class="text-muted" style="font-size:10px">{{ $period->closed_at->thaiDate(true) }}</div>@endif</td>
                        <td>{{ $period->note ?: '-' }}</td>
                        <td class="text-end">
                            @if ($period->isClosed())
                                <form method="post" action="{{ route('accounting-periods.reopen', $period) }}" class="d-inline" onsubmit="return confirm('เปิดงวดนี้อีกครั้งหรือไม่? เอกสารย้อนหลังจะกลับมาแก้ไขได้')">@csrf<button class="period-action open-action" title="เปิดงวด"><i class="bi bi-unlock"></i></button></form>
                            @else
                                @php($blocked=collect($readiness[$period->id]??[])->contains('status','block'))
                                <button type="button" class="period-action close-action" title="{{ $blocked?'ต้องแก้รายการใน checklist ให้ผ่านก่อน':'ปิดงวด' }}" @disabled($blocked) @click="closeId={{ $period->id }};closeName={{ Js::from($period->name) }};closeAction={{ Js::from(route('accounting-periods.close', $period)) }}"><i class="bi bi-lock"></i></button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <div class="period-modal-backdrop" x-show="closeId" x-transition.opacity @keydown.escape.window="closeId=null" @click.self="closeId=null">
        <form method="post" :action="closeAction" class="period-modal">
            @csrf
            <h2><i class="bi bi-lock-fill text-danger me-2"></i>ยืนยันปิดงวด</h2>
            <p>เมื่อปิด <strong x-text="closeName"></strong> ระบบจะห้ามเพิ่ม แก้ไข ยกเลิก และลบ Document/GL ในช่วงวันที่นี้จนกว่าจะเปิดงวดใหม่</p>
            <label class="form-label small fw-bold">หมายเหตุการปิดงวด</label>
            <textarea name="note" placeholder="เช่น ตรวจธนาคาร VAT AR AP สต็อก และงบทดลองแล้ว"></textarea>
            <div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-light border" @click="closeId=null">ยกเลิก</button><button type="submit" class="btn btn-danger"><i class="bi bi-lock me-1"></i>ปิดงวด</button></div>
        </form>
    </div>
</div>
@endsection
