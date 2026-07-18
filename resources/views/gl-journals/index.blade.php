@extends('layout')

@section('title', 'สมุดรายวันทั่วไป - POPSTAR ERP')
@section('page-title', 'สมุดรายวันทั่วไป')
@section('page-subtitle', 'รายการลงบัญชีอัตโนมัติจากการรับ/จ่ายชำระหนี้')

@section('content')
    <ul class="nav nav-pills mb-4">
        <li class="nav-item"><a href="{{ route('chart-of-accounts.index') }}" class="nav-link">ผังบัญชี</a></li>
        <li class="nav-item"><span class="nav-link active">สมุดรายวันทั่วไป</span></li>
    </ul>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="content-card p-3">
                <div class="text-muted small">เดบิตรวม</div>
                <div class="fs-4 fw-bold">{{ number_format($totalDebit, 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="content-card p-3">
                <div class="text-muted small">เครดิตรวม</div>
                <div class="fs-4 fw-bold">{{ number_format($totalCredit, 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="content-card p-3">
                <div class="text-muted small">ผลต่าง (ต้องเป็น 0 เสมอ)</div>
                <div class="fs-4 fw-bold {{ abs($totalDebit - $totalCredit) > 0.01 ? 'text-danger' : 'text-success' }}">
                    {{ number_format($totalDebit - $totalCredit, 2) }}
                </div>
            </div>
        </div>
    </div>

    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>เอกสารอ้างอิง</th>
                        <th>บัญชี</th>
                        <th>คำอธิบาย</th>
                        <th class="text-end">เดบิต</th>
                        <th class="text-end">เครดิต</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($journals as $journal)
                    <tr>
                        <td>{{ $journal->entry_date->thaiDate() }}</td>
                        <td>{{ $journal->paymentDocument?->document?->doc_number ?? '-' }}</td>
                        <td>{{ $journal->account->code }} - {{ $journal->account->name_th }}</td>
                        <td class="text-muted">{{ $journal->remark }}</td>
                        <td class="text-end">{{ $journal->debit > 0 ? number_format($journal->debit, 2) : '' }}</td>
                        <td class="text-end">{{ $journal->credit > 0 ? number_format($journal->credit, 2) : '' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-5 text-center text-muted">ยังไม่มีรายการบัญชี - ตั้งค่าบัญชีเริ่มต้นที่หน้าผังบัญชีก่อน</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $journals->links() }}</div>
    </div>
@endsection
