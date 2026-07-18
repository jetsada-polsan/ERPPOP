@extends('layout')
@section('title', "{$asset->asset_code} - ทรัพย์สิน - POPSTAR ERP")
@section('page-title', $asset->name)
@section('page-subtitle', 'รหัส ' . $asset->asset_code . ' · ' . $asset->statusLabel())
@section('content')
<a href="{{ route('fixed-assets.index') }}" class="text-decoration-none small d-inline-block mb-3"><i class="bi bi-arrow-left me-1"></i>กลับทะเบียนทรัพย์สิน</a>

<div class="row g-3 mb-3">
    <div class="col-md-8">
        <div class="content-card p-4 h-100">
            <div class="row">
                <div class="col-6"><dl class="small mb-0">
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">หมวด</dt><dd class="mb-0">{{ $asset->category ?? '-' }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">สาขา</dt><dd class="mb-0">{{ $asset->branch?->name_th ?? 'ส่วนกลาง' }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">วันที่ได้มา</dt><dd class="mb-0">{{ $asset->acquired_date->thaiDate() }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">อายุใช้งาน</dt><dd class="mb-0">{{ number_format($asset->useful_life_months / 12, 1) }} ปี ({{ $asset->useful_life_months }} เดือน)</dd></div>
                </dl></div>
                <div class="col-6"><dl class="small mb-0">
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">ราคาทุน</dt><dd class="mb-0 fw-semibold">{{ number_format($asset->cost, 2) }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">มูลค่าซาก</dt><dd class="mb-0">{{ number_format($asset->salvage_value, 2) }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">ค่าเสื่อม/เดือน</dt><dd class="mb-0">{{ number_format($asset->monthlyDepreciation(), 2) }}</dd></div>
                    <div class="d-flex justify-content-between mb-1"><dt class="fw-normal text-muted">ค่าเสื่อมสะสม</dt><dd class="mb-0 text-warning">{{ number_format($asset->accumulated_depreciation, 2) }}</dd></div>
                </dl></div>
            </div>
            @if($asset->note)<div class="text-muted small mt-2">หมายเหตุ: {{ $asset->note }}</div>@endif
        </div>
    </div>
    <div class="col-md-4">
        <div class="content-card p-4 h-100 text-center d-flex flex-column justify-content-center">
            <div class="text-muted small">มูลค่าตามบัญชีคงเหลือ</div>
            <div class="display-6 fw-bold text-success">฿{{ number_format($asset->bookValue(), 2) }}</div>
            @if($asset->status !== 'disposed')
                <form method="post" action="{{ route('fixed-assets.dispose', $asset) }}" class="mt-3" onsubmit="return confirm('จำหน่าย/ตัดทรัพย์สินนี้ออกจากทะเบียน?')">
                    @csrf<button class="btn btn-outline-dark btn-sm"><i class="bi bi-x-octagon me-1"></i>จำหน่าย/ตัดออก</button>
                </form>
            @else
                <div class="badge text-bg-dark mt-3 align-self-center">จำหน่ายเมื่อ {{ $asset->disposed_date?->thaiDate() }}</div>
            @endif
        </div>
    </div>
</div>

<div class="content-card overflow-hidden">
    <div class="px-3 py-2 border-bottom fw-bold">ประวัติค่าเสื่อมราคา</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>งวด</th><th class="text-end">ค่าเสื่อมงวดนี้</th><th class="text-end">ค่าเสื่อมสะสม</th><th class="text-end">มูลค่าคงเหลือ</th></tr></thead>
            <tbody>
                @forelse($asset->depreciationRecords as $rec)
                    <tr>
                        <td>{{ $rec->period_date->thaiDate() }}</td>
                        <td class="text-end">{{ number_format($rec->amount, 2) }}</td>
                        <td class="text-end text-warning">{{ number_format($rec->accumulated_after, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($rec->book_value_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีการคิดค่าเสื่อม — กด "คิดค่าเสื่อมงวดนี้" ที่หน้าทะเบียน</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
