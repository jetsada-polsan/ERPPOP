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
            <a href="{{ route('bookings.cover-sheet') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-truck me-1"></i> ใบปะหน้า (ขนส่ง)
            </a>
            <a href="{{ route('bookings.index') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="bi bi-arrow-left me-1"></i> กลับหน้ารายการใบจอง
            </a>
        </div>
    </div>

    @if(!empty($dateRangeWarning))
        <div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex align-items-center mb-3 p-3">
            <i class="bi bi-exclamation-triangle-fill fs-3 me-3 text-warning"></i>
            <div>
                <strong class="d-block mb-1">แจ้งเตือนจำกัดช่วงเวลา</strong>
                <span>{{ $dateRangeWarning }}</span>
            </div>
        </div>
    @endif

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
                        <label class="form-label small fw-bold text-secondary mb-1">คลังตัดสต็อก</label>
                        <select name="warehouse_id" id="comparisonWarehouseSelect" class="form-select form-select-sm">
                            <option value="">ทุกคลัง</option>
                            @foreach($warehouses as $w)
                                <option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->code }} {{ $w->name }}</option>
                            @endforeach
                        </select>
                    </div>
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
                            <option value="converted_to_sale" @selected($statusFilter === 'converted_to_sale')>แปลงเป็นใบขายแล้ว</option>
                            <option value="partial" @selected($statusFilter === 'partial')>ออกบางส่วน</option>
                            <option value="cancelled" @selected($statusFilter === 'cancelled')>ยกเลิก</option>
                            <option value="variance" @selected($statusFilter === 'variance')>เฉพาะยอดไม่ตรง</option>
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
                            <th style="width: 140px;">คลัง</th>
                            <th style="width: 120px;" class="text-end">ยอดใบจอง</th>
                            <th style="width: 160px;">เลขที่ใบขาย</th>
                            <th style="width: 120px;" class="text-end">ยอดใบขายรวม</th>
                            <th style="width: 130px;" class="text-end">ส่วนต่าง</th>
                            <th style="width: 130px;" class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr @class(['opacity-75' => $row->status_key === 'cancelled'])>
                                <td class="text-center text-muted small">{{ $loop->iteration }}</td>
                                <td class="font-monospace fw-bold text-primary">{{ $row->doc_number }}</td>
                                <td class="small">{{ $row->doc_date }}</td>
                                <td class="fw-semibold">{{ $row->customer_name }}</td>
                                <td class="small">{{ $row->sales_area_name }}</td>
                                <td class="small">{{ $row->warehouse_names }}</td>
                                <td class="text-end fw-bold">{{ number_format($row->booking_amount, 2) }}</td>
                                <td class="font-monospace small">{{ $row->sale_doc_numbers }}</td>
                                <td class="text-end fw-bold">{{ number_format($row->sale_amount, 2) }}</td>
                                <td class="text-end fw-bold {{ $row->has_variance && $row->status_key !== 'cancelled' ? 'text-danger bg-danger bg-opacity-10' : 'text-success' }}">
                                    {{ number_format($row->variance, 2) }}
                                    @if($row->has_variance && $row->status_key !== 'cancelled')
                                        <div class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle d-inline-block p-1 mt-1 small" style="font-size: 10px;">ยอดไม่ตรง</div>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $row->status_badge }} px-2 py-1">{{ $row->status_label }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">ไม่พบข้อมูลการจองตามเงื่อนไขที่เลือก</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($rows) > 0)
                        <tfoot class="table-light fw-bold border-top border-dark">
                            <tr>
                                <td colspan="6" class="text-center">รวมทั้งหมด (ไม่รวมใบยกเลิก) ({{ $rows->where('status_key', '!=', 'cancelled')->count() }} รายการ)</td>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    try {
        var storageKey = 'booking_comparison_warehouse_id_{{ auth()->id() ?? 'guest' }}';
        var urlParams = new URLSearchParams(window.location.search);
        var selectElem = document.getElementById('comparisonWarehouseSelect');

        if (urlParams.has('warehouse_id')) {
            var currentVal = urlParams.get('warehouse_id') || '';
            localStorage.setItem(storageKey, currentVal);
        } else {
            var savedVal = localStorage.getItem(storageKey);
            if (savedVal && selectElem) {
                var optionExists = Array.from(selectElem.options).some(function (opt) {
                    return opt.value === savedVal;
                });
                if (optionExists && savedVal !== '') {
                    urlParams.set('warehouse_id', savedVal);
                    window.location.replace(window.location.pathname + '?' + urlParams.toString());
                }
            }
        }

        if (selectElem) {
            selectElem.addEventListener('change', function () {
                try {
                    localStorage.setItem(storageKey, this.value || '');
                } catch (e) {
                    // Ignore localStorage errors
                }
            });
        }
    } catch (e) {
        // Ignore localStorage errors
    }
});
</script>
@endpush
