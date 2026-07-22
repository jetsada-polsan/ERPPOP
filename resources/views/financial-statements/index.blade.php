@extends('layout')
@section('title', 'งบการเงิน - POPSTAR ERP')
@section('page-title', 'งบการเงิน')
@section('page-subtitle', 'งบทดลอง งบกำไรขาดทุน และงบแสดงฐานะการเงิน จากการลงบัญชีอัตโนมัติ')
@section('content')

<form method="get" class="fs-toolbar no-print mb-3">
    <input type="hidden" name="sheet" value="{{ $sheet }}">
    <div class="btn-group" role="group">
        @foreach($sheets as $key => $label)
            <a href="{{ route('financial-statements.index', ['sheet' => $key, 'from' => $from, 'to' => $to]) }}"
               class="btn btn-sm {{ $sheet === $key ? 'btn-primary' : 'btn-light border' }}">{{ $label }}</a>
        @endforeach
    </div>
    <span class="text-muted small ms-2">{{ $sheet === 'balance_sheet' ? 'ณ วันที่' : 'ช่วงวันที่' }}</span>
    @if($sheet !== 'balance_sheet')
        <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm" style="width:150px">
        <span class="text-muted small">ถึง</span>
    @endif
    <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm" style="width:150px">
    <button class="btn btn-sm btn-primary px-3"><i class="bi bi-arrow-repeat me-1"></i>แสดง</button>
    <button type="button" onclick="window.print()" class="btn btn-sm btn-light border"><i class="bi bi-printer me-1"></i>พิมพ์</button>
</form>

<div class="content-card p-4 print-sheet">
    <div class="text-center mb-3">
        <div class="fw-bold fs-5">{{ \App\Models\AppSetting::company('name_th') }}</div>
        <div class="fw-bold">{{ $sheets[$sheet] }}</div>
        <div class="small text-muted">
            @if($sheet === 'balance_sheet') ณ วันที่ {{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }}
            @else สำหรับงวด {{ \Illuminate\Support\Carbon::parse($from)->thaiDate() }} ถึง {{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }}
            @endif
        </div>
    </div>

    @if($sheet === 'trial_balance')
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อบัญชี</th><th>ประเภท</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th></tr></thead>
            <tbody>
                @forelse($rows as $acc)
                    <tr>
                        <td class="fw-semibold">{{ $acc->code }}</td>
                        <td>{{ $acc->name_th }}</td>
                        <td class="small text-muted">{{ \App\Models\ChartOfAccount::TYPES[$acc->account_type] ?? $acc->account_type }}</td>
                        <td class="text-end">{{ $acc->total_debit > 0 ? number_format($acc->total_debit, 2) : '-' }}</td>
                        <td class="text-end">{{ $acc->total_credit > 0 ? number_format($acc->total_credit, 2) : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีรายการบัญชีในช่วงนี้ — บันทึกการขาย/ซื้อ/รับชำระก่อน</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="fw-bold border-top border-2">
                    <td colspan="3" class="text-end">รวม</td>
                    <td class="text-end">{{ number_format($totalDebit, 2) }}</td>
                    <td class="text-end">{{ number_format($totalCredit, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="text-center small {{ abs($totalDebit - $totalCredit) < 0.5 ? 'text-success' : 'text-danger' }}">
                        {{ abs($totalDebit - $totalCredit) < 0.5 ? '✓ เดบิตเท่ากับเครดิต — งบดุล' : '⚠ เดบิตไม่เท่ากับเครดิต ต่าง ' . number_format(abs($totalDebit - $totalCredit), 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>

    @elseif($sheet === 'income_statement')
        <div class="table-responsive">
        <table class="table table-sm align-middle" style="max-width:640px;margin:0 auto">
            <tr class="table-light"><td colspan="2" class="fw-bold">รายได้</td></tr>
            @forelse($revenue as $acc)
                <tr><td class="ps-4">{{ $acc->code }} {{ $acc->name_th }}</td><td class="text-end">{{ number_format($acc->balance, 2) }}</td></tr>
            @empty
                <tr><td class="ps-4 text-muted" colspan="2">ไม่มีรายได้ในงวดนี้</td></tr>
            @endforelse
            <tr class="fw-semibold border-top"><td class="ps-4">รวมรายได้</td><td class="text-end">{{ number_format($totalRevenue, 2) }}</td></tr>

            <tr class="table-light"><td colspan="2" class="fw-bold pt-3">ค่าใช้จ่าย</td></tr>
            @forelse($expense as $acc)
                <tr><td class="ps-4">{{ $acc->code }} {{ $acc->name_th }}</td><td class="text-end">{{ number_format($acc->balance, 2) }}</td></tr>
            @empty
                <tr><td class="ps-4 text-muted" colspan="2">ไม่มีค่าใช้จ่ายในงวดนี้</td></tr>
            @endforelse
            <tr class="fw-semibold border-top"><td class="ps-4">รวมค่าใช้จ่าย</td><td class="text-end">{{ number_format($totalExpense, 2) }}</td></tr>

            <tr class="fw-bold border-top border-2 fs-6">
                <td>กำไร (ขาดทุน) สุทธิ</td>
                <td class="text-end {{ $netProfit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($netProfit, 2) }}</td>
            </tr>
        </table>
        </div>

    @else
        <div class="row g-4">
            <div class="col-md-6">
                <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tr class="table-light"><td colspan="2" class="fw-bold">สินทรัพย์</td></tr>
                    @foreach($assets as $acc)
                        <tr><td class="ps-3">{{ $acc->code }} {{ $acc->name_th }}</td><td class="text-end">{{ number_format($acc->balance, 2) }}</td></tr>
                    @endforeach
                    <tr class="fw-bold border-top border-2"><td>รวมสินทรัพย์</td><td class="text-end">{{ number_format($totalAssets, 2) }}</td></tr>
                </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <tr class="table-light"><td colspan="2" class="fw-bold">หนี้สิน</td></tr>
                    @foreach($liabilities as $acc)
                        <tr><td class="ps-3">{{ $acc->code }} {{ $acc->name_th }}</td><td class="text-end">{{ number_format($acc->balance, 2) }}</td></tr>
                    @endforeach
                    <tr class="fw-semibold border-top"><td>รวมหนี้สิน</td><td class="text-end">{{ number_format($totalLiabilities, 2) }}</td></tr>

                    <tr class="table-light"><td colspan="2" class="fw-bold pt-3">ส่วนของเจ้าของ</td></tr>
                    @foreach($equity as $acc)
                        <tr><td class="ps-3">{{ $acc->code }} {{ $acc->name_th }}</td><td class="text-end">{{ number_format($acc->balance, 2) }}</td></tr>
                    @endforeach
                    <tr><td class="ps-3">กำไร(ขาดทุน)สะสมงวดนี้</td><td class="text-end {{ $netProfit >= 0 ? '' : 'text-danger' }}">{{ number_format($netProfit, 2) }}</td></tr>
                    <tr class="fw-semibold border-top"><td>รวมส่วนของเจ้าของ</td><td class="text-end">{{ number_format($totalEquity, 2) }}</td></tr>

                    <tr class="fw-bold border-top border-2"><td>รวมหนี้สินและส่วนของเจ้าของ</td><td class="text-end">{{ number_format($totalLiabilities + $totalEquity, 2) }}</td></tr>
                </table>
                </div>
            </div>
        </div>
        <div class="text-center mt-2 small {{ $balanced ? 'text-success' : 'text-danger' }}">
            {{ $balanced ? '✓ สินทรัพย์ = หนี้สิน + ส่วนของเจ้าของ (งบสมดุล)' : '⚠ งบไม่สมดุล — ตรวจสอบการลงบัญชี' }}
        </div>
    @endif
</div>
@endsection

@push('head')
<style>
    .fs-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    @media print { .app-sidebar, .app-header, .no-print { display: none !important; } .content-card { border: none; box-shadow: none; } }
</style>
@endpush
