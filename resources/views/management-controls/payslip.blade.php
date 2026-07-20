@extends('layout')
@section('title', 'สลิปเงินเดือน '.$slip->employee_code.' '.$slip->period)
@section('page-title', 'สลิปเงินเดือน')
@section('page-subtitle', $slip->period.' · '.$slip->full_name)

@section('content')
@php
    $detail = json_decode($slip->calculation_detail ?? '{}', true) ?: [];
    $earnings = (float) $slip->base_salary + (float) $slip->overtime_amount;
    $deductions = (float) $slip->absence_deduction + (float) $slip->social_security + (float) $slip->withholding_tax + (float) $slip->other_deduction;
@endphp
<div class="d-flex gap-2 mb-3 payslip-actions">
    <a href="{{ url()->previous() }}" class="btn btn-light border btn-sm"><i class="bi bi-arrow-left me-1"></i>กลับ</a>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์สลิป</button>
</div>

<div class="content-card p-4 mx-auto" style="max-width:640px">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <div class="h5 fw-bold mb-0">POPSTAR SHOP</div>
            <div class="text-muted small">สลิปเงินเดือน / Payslip</div>
        </div>
        <div class="text-end">
            <div class="fw-bold">งวด {{ $slip->period }}</div>
            <div class="text-muted small">สถานะงวด: {{ ['draft'=>'ร่าง','approved'=>'อนุมัติแล้ว','paid'=>'จ่ายแล้ว'][$slip->run_status] ?? $slip->run_status }}</div>
        </div>
    </div>

    <div class="row g-2 mb-3 small">
        <div class="col-6"><span class="text-muted">พนักงาน:</span> <span class="fw-semibold">{{ $slip->full_name }}</span></div>
        <div class="col-6"><span class="text-muted">รหัส:</span> {{ $slip->employee_code }}</div>
        <div class="col-6"><span class="text-muted">ตำแหน่ง:</span> {{ $slip->position ?? '-' }}</div>
        <div class="col-6"><span class="text-muted">แผนก:</span> {{ $slip->department ?? '-' }}</div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="fw-bold border-bottom pb-1 mb-2">รายได้</div>
            <table class="table table-sm mb-0">
                <tr><td>เงินเดือน</td><td class="text-end">{{ number_format($slip->base_salary, 2) }}</td></tr>
                <tr><td>ล่วงเวลา (OT{{ isset($detail['overtime_hours']) ? ' '.$detail['overtime_hours'].' ชม.' : '' }})</td><td class="text-end">{{ number_format($slip->overtime_amount, 2) }}</td></tr>
                <tr class="fw-bold border-top"><td>รวมรายได้</td><td class="text-end">{{ number_format($earnings, 2) }}</td></tr>
            </table>
        </div>
        <div class="col-md-6">
            <div class="fw-bold border-bottom pb-1 mb-2">รายการหัก</div>
            <table class="table table-sm mb-0">
                <tr><td>ขาดงาน{{ isset($detail['absent_days']) ? ' '.$detail['absent_days'].' วัน' : '' }}</td><td class="text-end">{{ number_format($slip->absence_deduction, 2) }}</td></tr>
                <tr><td>ประกันสังคม</td><td class="text-end">{{ number_format($slip->social_security, 2) }}</td></tr>
                <tr><td>ภาษีหัก ณ ที่จ่าย</td><td class="text-end">{{ number_format($slip->withholding_tax, 2) }}</td></tr>
                <tr><td>หักอื่น ๆ</td><td class="text-end">{{ number_format($slip->other_deduction, 2) }}</td></tr>
                <tr class="fw-bold border-top"><td>รวมรายการหัก</td><td class="text-end">{{ number_format($deductions, 2) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
        <div class="fw-bold">เงินได้สุทธิ</div>
        <div class="h4 fw-bold mb-0">{{ number_format($slip->net_amount, 2) }} บาท</div>
    </div>
</div>

@push('head')
<style>
    @media print {
        .app-header, .app-sidebar, .payslip-actions { display: none !important; }
        .app-main { margin: 0 !important; }
        .content-card { box-shadow: none !important; border: 1px solid #ccc !important; }
    }
</style>
@endpush
@endsection
