@extends('layout')
@section('title', 'Payroll '.$run->period.' - POPSTAR ERP')
@section('page-title', 'Payroll งวด '.$run->period)
@section('page-subtitle', 'ตรวจภาษีหัก ณ ที่จ่าย รายการหัก แล้วอนุมัติและจ่าย')

@section('content')
@php
    $statusLabel = ['draft' => 'ร่าง (แก้ไขได้)', 'approved' => 'อนุมัติแล้ว', 'paid' => 'จ่ายแล้ว'][$run->status] ?? $run->status;
    $statusClass = ['draft' => 'text-bg-warning', 'approved' => 'text-bg-info', 'paid' => 'text-bg-success'][$run->status] ?? 'text-bg-secondary';
    $editable = $run->status === 'draft';
@endphp
<a href="{{ route('management-controls.index', ['period' => $run->period]) }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับศูนย์ควบคุมบริหาร
</a>

<div x-data="{ dirty: false }">
    <div class="content-card p-4 mb-3">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">งวด {{ $run->period }}</h2>
                <div class="text-muted small">
                    รายได้รวม {{ number_format($run->gross_amount, 2) }} ·
                    หักรวม {{ number_format($run->deduction_amount, 2) }} ·
                    <span class="fw-bold">สุทธิ {{ number_format($run->net_amount, 2) }}</span> บาท
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge {{ $statusClass }} fs-6 px-3 py-2">{{ $statusLabel }}</span>
                @if($run->status === 'draft' && $canApprove)
                    <form method="post" action="{{ route('management-controls.payroll.approve', $run->id) }}"
                          onsubmit="return confirm('อนุมัติงวดนี้? หลังอนุมัติจะแก้ไขรายการหักไม่ได้')">
                        @csrf<button class="btn btn-info"><i class="bi bi-check2-circle me-1"></i>อนุมัติ</button>
                    </form>
                @elseif($run->status === 'approved' && $canApprove)
                    <form method="post" action="{{ route('management-controls.payroll.pay', $run->id) }}"
                          onsubmit="return confirm('ยืนยันว่าจ่ายเงินเดือนงวดนี้แล้ว?')">
                        @csrf<button class="btn btn-success"><i class="bi bi-cash-stack me-1"></i>บันทึกจ่ายแล้ว</button>
                    </form>
                @endif
            </div>
        </div>
        @if($run->status === 'draft' && ! $canApprove)
            <div class="alert alert-light border mt-3 mb-0 small"><i class="bi bi-info-circle me-1"></i>กรอกภาษี/รายการหักให้ครบ แล้วให้ผู้มีสิทธิ์อนุมัติ (GM) ยืนยัน — ผู้จัดทำอนุมัติงวดตนเองไม่ได้</div>
        @endif
    </div>

    <form method="post" action="{{ route('management-controls.payroll.items', $run->id) }}">
        @csrf
        <div class="content-card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>พนักงาน</th>
                            <th class="text-end">เงินเดือน</th>
                            <th class="text-end">OT</th>
                            <th class="text-end">ขาดงาน</th>
                            <th class="text-end">ปกส.</th>
                            <th class="text-end" style="width:130px">ภาษี ณ ที่จ่าย</th>
                            <th class="text-end" style="width:130px">หักอื่น</th>
                            <th class="text-end">สุทธิ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $i => $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->full_name }}</div>
                                <div class="text-muted small">{{ $item->employee_code }}</div>
                                <input type="hidden" name="items[{{ $i }}][id]" value="{{ $item->id }}">
                            </td>
                            <td class="text-end">{{ number_format($item->base_salary, 2) }}</td>
                            <td class="text-end">{{ number_format($item->overtime_amount, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($item->absence_deduction, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($item->social_security, 2) }}</td>
                            <td class="text-end">
                                @if($editable)
                                <input type="number" step="0.01" min="0" name="items[{{ $i }}][withholding_tax]"
                                       value="{{ rtrim(rtrim(number_format($item->withholding_tax, 2, '.', ''), '0'), '.') ?: '0' }}"
                                       @input="dirty = true" class="form-control form-control-sm text-end">
                                @else
                                {{ number_format($item->withholding_tax, 2) }}
                                @endif
                            </td>
                            <td class="text-end">
                                @if($editable)
                                <input type="number" step="0.01" min="0" name="items[{{ $i }}][other_deduction]"
                                       value="{{ rtrim(rtrim(number_format($item->other_deduction, 2, '.', ''), '0'), '.') ?: '0' }}"
                                       @input="dirty = true" class="form-control form-control-sm text-end">
                                @else
                                {{ number_format($item->other_deduction, 2) }}
                                @endif
                            </td>
                            <td class="text-end fw-bold">{{ number_format($item->net_amount, 2) }}</td>
                            <td class="text-end">
                                <a href="{{ route('management-controls.payroll.payslip', $item->id) }}" target="_blank"
                                   class="btn btn-sm btn-light border" title="สลิปเงินเดือน"><i class="bi bi-receipt"></i></a>
                            </td>
                        </tr>
                        @endforeach
                        @if($items->isEmpty())
                        <tr><td colspan="9" class="text-center text-muted py-4">ไม่มีรายการพนักงานในงวดนี้</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            @if($editable)
            <div class="p-3 border-top d-flex justify-content-end">
                <button class="btn btn-primary" :disabled="!dirty"><i class="bi bi-save me-1"></i>บันทึกภาษี/รายการหัก แล้วคำนวณสุทธิใหม่</button>
            </div>
            @endif
        </div>
    </form>
</div>
@endsection
