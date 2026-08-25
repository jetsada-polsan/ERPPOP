@extends('layout')

@section('title', 'ฝาก/ถอนเงินสด - PopCentral')
@section('page-title', 'ฝาก/ถอนเงินสดกับธนาคาร')
@section('page-subtitle', 'ทุกรายการเข้าสมุดเงินสดและลงบัญชีอัตโนมัติ')

@section('content')
<div class="row g-3">
    <div class="col-xl-5">
        <div class="content-card p-4">
            <h2 class="h6 fw-bold mb-1">บันทึกรายการใหม่</h2>
            <p class="small text-muted">
                ฝาก = เงินสดออกจากลิ้นชักเข้าบัญชีธนาคาร · ถอน = เงินสดเข้าลิ้นชัก
                ระบบลงสมุดเงินสดและ GL ให้เอง ไม่ต้องคีย์ซ้ำ
            </p>

            @if(session('success'))
                <div class="alert alert-success py-2 small">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('cash-transfers.store') }}" class="row g-3">
                @csrf
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">ประเภท</label>
                    <select name="type" class="form-select" required>
                        <option value="CASH_DEPOSIT">ฝากเงินสดเข้าธนาคาร</option>
                        <option value="CASH_WITHDRAWAL">ถอนเงินสดจากธนาคาร</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">วันที่</label>
                    <input type="date" name="transfer_date" value="{{ old('transfer_date', now()->toDateString()) }}" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">จำนวนเงิน</label>
                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" class="form-control text-end" required>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">สาขา</label>
                    <select name="branch_id" class="form-select" required @disabled($defaultBranchId)>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id', $defaultBranchId) == $branch->id)>
                                {{ $branch->code }} - {{ $branch->name_th }}
                            </option>
                        @endforeach
                    </select>
                    @if($defaultBranchId)
                        <input type="hidden" name="branch_id" value="{{ $defaultBranchId }}">
                        <div class="form-text">บันทึกได้เฉพาะสาขาที่คุณสังกัด</div>
                    @endif
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">บัญชีธนาคาร</label>
                    <select name="bank_account_id" class="form-select" required>
                        @forelse($bankAccounts as $account)
                            <option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>
                                {{ $account->bank_name }} {{ $account->account_no }}
                            </option>
                        @empty
                            <option value="">ยังไม่มีบัญชีธนาคารในระบบ</option>
                        @endforelse
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">เลขที่อ้างอิง / สลิป</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" maxlength="100" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-1">หมายเหตุ</label>
                    <input type="text" name="remark" value="{{ old('remark') }}" maxlength="500" class="form-control">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" @disabled($bankAccounts->isEmpty())>
                        <i class="bi bi-bank me-1"></i>บันทึกรายการ
                    </button>
                    @if($bankAccounts->isEmpty())
                        <div class="form-text text-danger">ต้องเพิ่มบัญชีธนาคารก่อนจึงจะบันทึกได้</div>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="content-card p-4">
            <h2 class="h6 fw-bold mb-3">รายการล่าสุด</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>วันที่</th><th>เลขที่</th><th>ประเภท</th><th>สาขา</th>
                            <th class="text-end">จำนวนเงิน</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($transfers as $transfer)
                        <tr>
                            <td>{{ $transfer->doc_date->format('d/m/Y') }}</td>
                            <td><code class="small">{{ $transfer->doc_number }}</code></td>
                            <td>
                                @if($transfer->documentType->code === 'CASH_DEPOSIT')
                                    <span class="badge text-bg-danger-subtle text-danger">ฝากเข้าธนาคาร</span>
                                @else
                                    <span class="badge text-bg-success-subtle text-success">ถอนเข้าลิ้นชัก</span>
                                @endif
                            </td>
                            <td class="small">{{ $transfer->branch?->name_th ?? '-' }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $transfer->total_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีรายการฝาก/ถอนเงินสด</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $transfers->links() }}
        </div>
    </div>
</div>
@endsection
