@extends('layout')

@section('content')
<div class="comparison-page" style="max-width: 1400px; margin: 0 auto; padding: 20px;">
    <!-- Heading -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fs-4 text-primary"><i class="bi bi-arrow-left-right"></i></span>
            <div>
                <h1 class="h5 fw-bold mb-0">รายงานเทียบใบจองกับใบขายเชื่อ</h1>
                <small class="text-muted">เปรียบเทียบยอดรวมจองกับยอดรวมใบขายที่คอนเฟิร์มแล้ว ตรวจสอบยอดตรงกัน/ต่างกัน</small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('bookings.summary') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-clipboard2-check me-1"></i> ใบรวมจอง
            </a>
            <a href="{{ route('bookings.index') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> กลับหน้ารายการใบจอง
            </a>
        </div>
    </div>

    <!-- Filters Card (no-print) -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
        <div class="card-body p-3">
            <form method="get" action="{{ route('bookings.comparison') }}">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-secondary mb-1">ตั้งแต่วันที่</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-secondary mb-1">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
                    </div>
                    @if(count($branches) > 1)
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary mb-1">สาขา</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">ทุกสาขา</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->code }} {{ $b->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-secondary mb-1">สายขาย</label>
                        <select name="sales_area_id" class="form-select form-select-sm">
                            <option value="">ทุกสาย</option>
                            @foreach($salesAreas as $area)
                                <option value="{{ $area->id }}" @selected($salesAreaId === $area->id)>{{ $area->code }} {{ $area->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-secondary mb-1">สถานะ</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" @selected($statusFilter === 'all')>ทั้งหมด</option>
                            <option value="pending" @selected($statusFilter === 'pending')>ยังไม่ออกบิล</option>
                            <option value="fully_converted" @selected($statusFilter === 'fully_converted')>ออกครบ (ยอดตรง)</option>
                            <option value="partially_converted" @selected($statusFilter === 'partially_converted')>ออกบางส่วน (มียอดต่าง)</option>
                            <option value="cancelled" @selected($statusFilter === 'cancelled')>ยกเลิก</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-search me-1"></i> ค้นหา
                        </button>
                        <a href="{{ route('bookings.comparison', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-file-earmark-excel me-1"></i> CSV
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Comparison Table Card -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;" class="text-center">#</th>
                            <th style="width: 140px;">เลขที่ใบจอง</th>
                            <th style="width: 100px;">วันที่จอง</th>
                            <th>ลูกค้า</th>
                            <th style="width: 130px;">สายขาย</th>
                            <th style="width: 120px;" class="text-end">ยอดใบจอง</th>
                            <th style="width: 160px;">เลขที่ใบขาย</th>
                            <th style="width: 120px;" class="text-end">ยอดใบขายรวม</th>
                            <th style="width: 110px;" class="text-end">ส่วนต่าง</th>
                            <th style="width: 110px;" class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="text-center text-muted small">{{ $loop->iteration }}</td>
                                <td class="font-monospace fw-bold text-primary">{{ $row->doc_number }}</td>
                                <td class="small">{{ $row->doc_date }}</td>
                                <td class="fw-semibold">{{ $row->customer_name }}</td>
                                <td class="small">{{ $row->sales_area_name }}</td>
                                <td class="text-end fw-bold">{{ number_format($row->booking_amount, 2) }}</td>
                                <td class="font-monospace small">{{ $row->sale_doc_numbers }}</td>
                                <td class="text-end fw-bold">{{ number_format($row->sale_amount, 2) }}</td>
                                <td class="text-end fw-bold {{ abs($row->variance) > 0.005 ? 'text-danger bg-danger bg-opacity-10' : 'text-success' }}">
                                    {{ number_format($row->variance, 2) }}
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $row->status_badge }} px-2 py-1">{{ $row->status_label }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูลการจองตามเงื่อนไขที่เลือก</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($rows) > 0)
                        <tfoot class="table-light fw-bold border-top border-dark">
                            <tr>
                                <td colspan="5" class="text-center">รวมทั้งหมด ({{ count($rows) }} รายการ)</td>
                                <td class="text-end text-primary fs-6">{{ number_format($totalBookingAmount, 2) }}</td>
                                <td></td>
                                <td class="text-end text-success fs-6">{{ number_format($totalSaleAmount, 2) }}</td>
                                <td class="text-end fs-6 {{ abs($totalVariance) > 0.005 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format($totalVariance, 2) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
