@extends('layout')
@section('title', 'งบประมาณ '.$budget->budget_no.' - POPSTAR ERP')
@section('page-title', 'งบประมาณ '.$budget->budget_no)
@section('page-subtitle', $budget->cost_center_name.' · ปีงบ '.$budget->fiscal_year)

@section('content')
@php
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $totalBudget = $lines->sum('budget_amount');
    $totalSpent = $lines->sum('spent');
    $totalVariance = $totalBudget - $totalSpent;
@endphp
<a href="{{ route('management-controls.index', ['period' => $budget->fiscal_year.'-01']) }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับศูนย์ควบคุมบริหาร
</a>

<div class="content-card p-4 mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
        <div>
            <h2 class="h4 fw-bold mb-1">{{ $budget->budget_no }}</h2>
            <div class="text-muted small">{{ $budget->cost_center_name }} · ปีงบประมาณ {{ $budget->fiscal_year }}</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge {{ $budget->status === 'approved' ? 'text-bg-success' : 'text-bg-warning' }} fs-6 px-3 py-2">
                {{ $budget->status === 'approved' ? 'อนุมัติแล้ว' : 'ร่าง' }}
            </span>
            @if($budget->status === 'draft' && $canApprove)
                <form method="post" action="{{ route('management-controls.budgets.approve', $budget->id) }}"
                      onsubmit="return confirm('อนุมัติงบประมาณนี้?')">
                    @csrf<button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>อนุมัติงบ</button>
                </form>
            @endif
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-4"><div class="border rounded-3 p-3"><div class="text-muted small">งบตั้งไว้</div><div class="fs-5 fw-bold">{{ number_format($totalBudget, 2) }}</div></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3"><div class="text-muted small">ใช้จริง</div><div class="fs-5 fw-bold">{{ number_format($totalSpent, 2) }}</div></div></div>
        <div class="col-md-4"><div class="border rounded-3 p-3"><div class="text-muted small">คงเหลือ / เกินงบ</div><div class="fs-5 fw-bold {{ $totalVariance < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($totalVariance, 2) }}</div></div></div>
    </div>
</div>

<div class="content-card overflow-hidden">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>เดือน</th><th>บัญชี</th>
                    <th class="text-end">งบตั้งไว้</th><th class="text-end">ใช้จริง</th>
                    <th class="text-end">คงเหลือ</th><th class="text-end">ใช้ไป %</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                @php $pct = $line->budget_amount > 0 ? $line->spent / $line->budget_amount * 100 : 0; @endphp
                <tr>
                    <td>{{ $months[$line->month] ?? $line->month }}</td>
                    <td>{{ $line->account_code }} {{ $line->account_name }}</td>
                    <td class="text-end">{{ number_format($line->budget_amount, 2) }}</td>
                    <td class="text-end">{{ number_format($line->spent, 2) }}</td>
                    <td class="text-end fw-semibold {{ $line->variance < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($line->variance, 2) }}</td>
                    <td class="text-end {{ $pct > 100 ? 'text-danger fw-bold' : '' }}">{{ number_format($pct, 0) }}%</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีบรรทัดงบประมาณ — เพิ่มได้จากศูนย์ควบคุมบริหาร แท็บ Budget</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>ใช้จริง = ผลรวมค่าใช้จ่ายสาขา (branch_expenses) ที่ผูก Cost Center และบัญชีเดียวกันในเดือนนั้น</p>
@endsection
