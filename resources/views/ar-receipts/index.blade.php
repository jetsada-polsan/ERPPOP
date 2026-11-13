@extends('layout')
@section('title', 'ใบเสร็จรับเงิน')
@section('page-title', 'ใบเสร็จรับเงิน (รับชำระลูกหนี้)')
@section('page-subtitle', 'เลือกลูกค้า ดึงบิลขายเชื่อที่ค้าง แล้วรับเงินสด/โอน/เช็ค')

@section('content')
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('ar-receipts.daily-summary') }}" class="btn btn-outline-secondary me-2">
        <i class="bi bi-file-earmark-bar-graph me-1"></i> รายงานสรุปรับเงินรายวัน
    </a>
    <a href="{{ route('ar-receipts.create') }}" class="btn btn-primary px-4"><i class="bi bi-plus-lg me-1"></i> ออกใบเสร็จรับเงิน</a>
</div>
<div class="row g-3">
    <div class="col-xl-6">
        <div class="content-card p-3">
            <h2 class="h6 fw-bold mb-3">ลูกค้าที่มียอดค้าง</h2>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>ลูกค้า</th><th class="text-end">บิลค้าง</th><th class="text-end">ยอดค้าง</th><th>ครบกำหนดเก่าสุด</th><th></th></tr></thead>
                <tbody>
                @forelse($outstanding as $row)
                    @php $c = $customers->get($row->customer_id); @endphp
                    <tr>
                        <td><span class="text-muted me-1">{{ $c?->code }}</span>{{ $c?->name_th }}</td>
                        <td class="text-end">{{ $row->item_count }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $row->balance, 2) }}</td>
                        <td>{{ $row->oldest_due ? \Illuminate\Support\Carbon::parse($row->oldest_due)->thaiDate() : '-' }}</td>
                        <td class="text-end"><a href="{{ route('ar-receipts.create', ['customer_id' => $row->customer_id]) }}" class="btn btn-sm btn-success">รับชำระ</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">ไม่มีลูกค้าค้างชำระ</td></tr>
                @endforelse
                </tbody>
            </table></div>
            <div class="mt-3">{{ $receipts->links() }}</div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="content-card p-3">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <h2 class="h6 fw-bold mb-0">ใบเสร็จรับเงิน</h2>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    @include('partials.list-date-range', ['dateRange' => $dateRange, 'wrapForm' => true])
                </div>
            </div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>ลูกค้า</th><th>ผู้รับเงิน</th><th class="text-end">ยอด</th><th>สถานะ</th></tr></thead>
                <tbody>
                @forelse($receipts as $doc)
                    <tr>
                        <td><a href="{{ route('payments.show', $doc) }}">{{ $doc->doc_number }}</a></td>
                        <td>{{ $doc->doc_date?->thaiDate() }} <span class="text-muted small">{{ $doc->created_at?->format('H:i') }}</span></td>
                        <td>{{ $doc->customer?->name_th }}</td>
                        <td class="text-muted small">{{ $doc->salesman?->name ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $doc->total_amount, 2) }}</td>
                        <td>@if($doc->status === 'active')<span class="badge text-bg-success">ใช้งาน</span>@else<span class="badge text-bg-secondary">ยกเลิก</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีใบเสร็จ</td></tr>
                @endforelse
                </tbody>
            </table></div>
            <div class="mt-3">{{ $receipts->links() }}</div>
        </div>
    </div>
</div>
@endsection
