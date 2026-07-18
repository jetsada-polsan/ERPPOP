@extends('layout')
@section('title', 'ทะเบียนเช็ค - POPSTAR ERP')
@section('page-title', 'ทะเบียนเช็ครับ - เช็คจ่าย')
@section('page-subtitle', 'ติดตามเช็คทุกใบ: รับ นำฝาก ผ่าน คืน และเช็คจ่ายรอตัดบัญชี')
@section('content')
<div x-data="{ addOpen: false }" x-cloak>

    {{-- แท็บ เช็ครับ / เช็คจ่าย --}}
    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <a href="{{ route('cheques.index', ['direction' => 'in']) }}" class="nav-link {{ $direction === 'in' ? 'active' : '' }}">
                <i class="bi bi-box-arrow-in-down me-1"></i>เช็ครับ
            </a>
        </li>
        <li class="nav-item">
            <a href="{{ route('cheques.index', ['direction' => 'out']) }}" class="nav-link {{ $direction === 'out' ? 'active' : '' }}">
                <i class="bi bi-box-arrow-up me-1"></i>เช็คจ่าย
            </a>
        </li>
    </ul>

    {{-- การ์ดสรุปสถานะ --}}
    <div class="row g-2 mb-3">
        @php($cards = $direction === 'in'
            ? [['on_hand', 'ในมือ รอนำฝาก', 'text-bg-warning'], ['deposited', 'นำฝาก รอผ่าน', 'text-bg-info'], ['cleared', 'ผ่านแล้ว', 'text-bg-success'], ['bounced', 'เช็คคืน/เด้ง', 'text-bg-danger']]
            : [['issued', 'ออกเช็ค รอตัดบัญชี', 'text-bg-warning'], ['cleared', 'ตัดบัญชีแล้ว', 'text-bg-success'], ['cancelled', 'ยกเลิก', 'text-bg-secondary']])
        @foreach($cards as [$st, $label, $badge])
            @php($info = $statusCounts[$st] ?? null)
            <div class="col-6 col-lg-3">
                <a href="{{ route('cheques.index', ['direction' => $direction, 'status' => $st]) }}" class="text-decoration-none">
                    <div class="content-card p-3 h-100 {{ $status === $st ? 'border border-primary' : '' }}">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge {{ $badge }}">{{ $label }}</span>
                            <span class="fw-bold fs-5">{{ number_format($info->c ?? 0) }}</span>
                        </div>
                        <div class="text-muted small mt-1">฿{{ number_format($info->total ?? 0, 2) }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
    @if($dueSoon > 0)
        <div class="alert alert-warning py-2 small mb-3"><i class="bi bi-alarm me-1"></i>มีเช็คครบกำหนดภายใน 7 วัน <strong>{{ $dueSoon }}</strong> ใบ</div>
    @endif

    {{-- ฟิลเตอร์ --}}
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <input type="hidden" name="direction" value="{{ $direction }}">
        @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <span class="text-muted small">วันที่บนเช็ค</span>
        <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm" style="width:150px">
        <span class="text-muted small">ถึง</span>
        <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm" style="width:150px">
        <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" style="width:220px" placeholder="เลขเช็ค / ธนาคาร / ชื่อคู่ค้า">
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        @if($status || $filters['q'] || $filters['from'])
            <a href="{{ route('cheques.index', ['direction' => $direction]) }}" class="btn btn-sm btn-light border">ล้าง</a>
        @endif
        <button type="button" class="btn btn-sm btn-success px-3 ms-auto" @click="addOpen = !addOpen">
            <i class="bi bi-plus-lg me-1"></i>บันทึกเช็คเอง
        </button>
    </form>

    {{-- ฟอร์มเพิ่มเช็คเอง --}}
    <div class="content-card p-4 mb-3" x-show="addOpen">
        <h3 class="h6 fw-bold mb-3">บันทึกเช็คเข้าทะเบียน (กรณีไม่ได้มาจากการรับ/จ่ายชำระในระบบ)</h3>
        <form method="post" action="{{ route('cheques.store') }}" class="row g-3">
            @csrf
            <input type="hidden" name="direction" value="{{ $direction }}">
            <div class="col-md-2"><label class="form-label small text-muted">เลขที่เช็ค</label><input name="cheque_no" required class="form-control"></div>
            <div class="col-md-2"><label class="form-label small text-muted">ธนาคารของเช็ค</label><input name="bank_name" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small text-muted">จำนวนเงิน</label><input type="number" step="0.01" min="0.01" name="amount" required class="form-control text-end"></div>
            <div class="col-md-2"><label class="form-label small text-muted">วันที่บนเช็ค</label><input type="date" name="cheque_date" required class="form-control" value="{{ now()->toDateString() }}"></div>
            <div class="col-md-2">
                <label class="form-label small text-muted">สาขา</label>
                <select name="branch_id" class="form-select">
                    <option value="">-</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">บันทึก</button></div>
            <div class="col-12"><input name="remark" class="form-control" placeholder="หมายเหตุ เช่น รับจากลูกค้า..."></div>
        </form>
    </div>

    {{-- ทะเบียน --}}
    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>เลขที่เช็ค</th><th>ธนาคาร</th><th>{{ $direction === 'in' ? 'ลูกค้า' : 'ซัพพลายเออร์' }}</th>
                        <th>วันที่บนเช็ค</th><th class="text-end">จำนวนเงิน</th>
                        <th>{{ $direction === 'in' ? 'บัญชีนำฝาก' : 'บัญชีสั่งจ่าย' }}</th>
                        <th>สถานะ</th><th style="width:230px"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($cheques as $cheque)
                    <tr class="{{ $cheque->status === 'bounced' ? 'table-danger' : '' }}">
                        <td class="fw-semibold">{{ $cheque->cheque_no }}</td>
                        <td class="small">{{ $cheque->bank_name ?? '-' }}</td>
                        <td class="small">{{ $cheque->customer?->name_th ?? $cheque->supplier?->name_th ?? '-' }}</td>
                        <td class="text-nowrap {{ ! $cheque->isFinal() && $cheque->cheque_date->lte(now()->addDays(7)) ? 'text-danger fw-bold' : '' }}">
                            {{ $cheque->cheque_date->thaiDate() }}
                        </td>
                        <td class="text-end fw-semibold">{{ number_format($cheque->amount, 2) }}</td>
                        <td class="small">{{ $cheque->bankAccount ? $cheque->bankAccount->bank_name.' '.$cheque->bankAccount->account_no : '-' }}</td>
                        <td>
                            <span class="badge {{ ['on_hand' => 'text-bg-warning', 'deposited' => 'text-bg-info', 'issued' => 'text-bg-warning', 'cleared' => 'text-bg-success', 'bounced' => 'text-bg-danger', 'cancelled' => 'text-bg-secondary'][$cheque->status] ?? 'text-bg-light' }}">
                                {{ $cheque->statusLabel() }}
                            </span>
                        </td>
                        <td class="text-end">
                            @if($cheque->status === 'on_hand')
                                <form method="post" action="{{ route('cheques.deposit', $cheque) }}" class="d-inline-flex gap-1">
                                    @csrf
                                    <select name="bank_account_id" required class="form-select form-select-sm" style="width:150px">
                                        <option value="">-- บัญชีนำฝาก --</option>
                                        @foreach($bankAccounts as $acc)<option value="{{ $acc->id }}">{{ $acc->bank_name }} {{ $acc->account_no }}</option>@endforeach
                                    </select>
                                    <button class="btn btn-sm btn-info text-white text-nowrap">นำฝาก</button>
                                </form>
                            @endif
                            @if(in_array($cheque->status, ['deposited', 'issued'], true))
                                <form method="post" action="{{ route('cheques.clear', $cheque) }}" class="d-inline" onsubmit="return confirm('ยืนยันเช็คผ่าน/ตัดบัญชีแล้ว?')">
                                    @csrf<button class="btn btn-sm btn-success">ผ่านแล้ว</button>
                                </form>
                            @endif
                            @if($cheque->direction === 'in' && ! $cheque->isFinal())
                                <form method="post" action="{{ route('cheques.bounce', $cheque) }}" class="d-inline" onsubmit="return confirm('บันทึกเช็คคืน/เด้ง? ต้องติดตามหนี้จากลูกค้าต่อ')">
                                    @csrf<button class="btn btn-sm btn-outline-danger">เช็คคืน</button>
                                </form>
                            @endif
                            @if(! $cheque->isFinal())
                                <form method="post" action="{{ route('cheques.cancel', $cheque) }}" class="d-inline" onsubmit="return confirm('ยกเลิกเช็คใบนี้?')">
                                    @csrf<button class="btn btn-sm btn-light border text-muted">ยกเลิก</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีเช็ค{{ $direction === 'in' ? 'รับ' : 'จ่าย' }}ในเงื่อนไขนี้ — เช็คจากการรับ/จ่ายชำระจะเข้าทะเบียนอัตโนมัติ</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $cheques->links() }}</div>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
