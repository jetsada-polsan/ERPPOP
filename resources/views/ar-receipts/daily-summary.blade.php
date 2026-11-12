@extends('layout')
@section('title', 'รายงานสรุปรับเงินรายวัน - MPM Food Group')
@section('page-title', 'รายงานสรุปรับเงินรายวัน')
@section('page-subtitle', 'สรุปการรับชำระหนี้แยกตามผู้รับเงินและวิธีชำระ (เงินสด / โอน / เช็ค)')

@section('content')
<div class="content-card p-3 mb-3">
    <form method="get" action="{{ route('ar-receipts.daily-summary') }}" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary mb-1">ตั้งแต่วันที่</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="{{ $startDate }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary mb-1">ถึงวันที่</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="{{ $endDate }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary mb-1">สาขา</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">— ทุกสาขา —</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-fill">
                <i class="bi bi-search me-1"></i> ค้นหา
            </button>
            <a href="{{ route('ar-receipts.daily-summary', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-excel me-1"></i> ส่งออก CSV
            </a>
            <a href="{{ route('ar-receipts.index') }}" class="btn btn-sm btn-outline-secondary">
                กลับ
            </a>
        </div>
    </form>
</div>

<div class="content-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h6 fw-bold mb-0">
            <i class="bi bi-cash-stack text-success me-1"></i> ยอดสรุปแยกตามผู้รับเงิน × วิธีชำระ
        </h2>
        <span class="badge text-bg-light border text-muted">
            ช่วงวันที่ {{ \Illuminate\Support\Carbon::parse($startDate)->thaiDate() }} ถึง {{ \Illuminate\Support\Carbon::parse($endDate)->thaiDate() }}
        </span>
    </div>

    <div class="table-responsive">
        <table class="table align-middle table-hover mb-0">
            <thead>
                <tr class="table-light">
                    <th>ผู้รับเงิน / พนักงานเก็บเงิน</th>
                    <th style="width: 130px;">วิธีชำระ</th>
                    <th class="text-center" style="width: 100px;">จำนวนใบ</th>
                    <th class="text-end" style="width: 150px;">ยอดรับจริง</th>
                    <th class="text-end" style="width: 130px;">ส่วนลดเงินสด</th>
                    <th class="text-end" style="width: 130px;">ภาษีหัก ณ ที่จ่าย</th>
                    <th class="text-end" style="width: 150px;">ยอดตัดหนี้</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td class="fw-semibold">
                            <i class="bi bi-person-badge text-secondary me-1"></i> {{ $r['salesman_name'] }}
                        </td>
                        <td>
                            @if($r['method'] === 'cash')
                                <span class="badge text-bg-success px-2 py-1"><i class="bi bi-cash-stack me-1"></i> เงินสด</span>
                            @elseif($r['method'] === 'transfer')
                                <span class="badge text-bg-primary px-2 py-1"><i class="bi bi-bank me-1"></i> โอนเงิน</span>
                            @elseif($r['method'] === 'cheque')
                                <span class="badge text-bg-warning px-2 py-1 text-dark"><i class="bi bi-card-checklist me-1"></i> เช็ค</span>
                            @else
                                <span class="badge text-bg-secondary px-2 py-1">{{ $r['method_label'] }}</span>
                            @endif
                        </td>
                        <td class="text-center">{{ number_format($r['receipt_count']) }}</td>
                        <td class="text-end fw-bold text-success">{{ number_format($r['net_paid'], 2) }}</td>
                        <td class="text-end text-muted">{{ number_format($r['discount'], 2) }}</td>
                        <td class="text-end text-muted">{{ number_format($r['wht'], 2) }}</td>
                        <td class="text-end fw-bold text-primary">{{ number_format($r['allocated'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-3 d-block mb-1 text-secondary"></i>
                            ไม่พบข้อมูลการรับชำระเงินในช่วงวันที่เลือก
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if($rows->isNotEmpty())
                <tfoot>
                    <tr class="table-light fw-bold border-top border-dark">
                        <td colspan="2">รวมทั้งสิ้น</td>
                        <td class="text-center">{{ number_format($totals['receipt_count']) }} ใบ</td>
                        <td class="text-end text-success fs-6">฿{{ number_format($totals['net_paid'], 2) }}</td>
                        <td class="text-end text-muted">฿{{ number_format($totals['discount'], 2) }}</td>
                        <td class="text-end text-muted">฿{{ number_format($totals['wht'], 2) }}</td>
                        <td class="text-end text-primary fs-6">฿{{ number_format($totals['allocated'], 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
