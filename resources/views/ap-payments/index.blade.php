@extends('layout')

@section('title', 'จ่ายชำระเจ้าหนี้ - PopCentral')
@section('page-title', 'จ่ายชำระเจ้าหนี้')
@section('page-subtitle', 'เลือกใบซื้อค้างจ่ายได้หลายใบ จ่ายด้วยเงินสด โอน หรือเช็ค และหัก ณ ที่จ่ายได้')

@section('content')
<div class="list-toolbar">
    <div class="list-toolbar-left">
        <h2 class="h5 fw-bold mb-0">เจ้าหนี้ค้างจ่าย</h2>
    </div>
    <a href="{{ route('ap-payments.create') }}" class="btn btn-primary rounded-pill px-4">
        <i class="bi bi-plus-lg me-1"></i> สร้างใบจ่ายชำระ
    </a>
</div>

<div class="content-card p-4 mb-4">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>ผู้จำหน่าย</th>
                    <th class="text-end">ใบค้างจ่าย</th>
                    <th>ครบกำหนดเร็วสุด</th>
                    <th class="text-end">ยอดค้างจ่าย</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($outstanding as $row)
                    @php($supplier = $suppliers[$row->supplier_id] ?? null)
                    @php($oldestDue = $row->oldest_due ? \Illuminate\Support\Carbon::parse($row->oldest_due) : null)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $supplier?->code }}</span> - {{ $supplier?->name_th ?? '-' }}
                        </td>
                        <td class="text-end">{{ number_format($row->item_count) }}</td>
                        <td>
                            @if($oldestDue)
                                {{ $oldestDue->thaiDate() }}
                                @if($oldestDue->isPast())
                                    <span class="badge text-bg-danger ms-1">เกินกำหนด</span>
                                @endif
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">{{ number_format((float) $row->balance, 2) }}</td>
                        <td class="text-end">
                            <a href="{{ route('ap-payments.create', ['supplier_id' => $row->supplier_id]) }}" class="btn btn-sm btn-primary">จ่ายชำระ</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-5 text-center text-muted">ไม่มีเจ้าหนี้ค้างจ่าย</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">ใบจ่ายชำระล่าสุด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr><th>เลขที่</th><th>วันที่</th><th>ผู้จำหน่าย</th><th>สาขา</th><th class="text-end">ยอดจ่าย</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td class="fw-semibold">{{ $payment->doc_number }}</td>
                        <td>{{ $payment->doc_date->thaiDate() }}</td>
                        <td>{{ $payment->supplier?->name_th ?? '-' }}</td>
                        <td>{{ $payment->branch?->name_th ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $payment->total_amount, 2) }}</td>
                        <td class="text-end"><a href="{{ route('payments.show', $payment) }}" class="btn btn-sm btn-light border">ดู</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-5 text-center text-muted">ยังไม่มีใบจ่ายชำระ</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
