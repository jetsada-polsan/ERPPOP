@extends('layout')
@section('title', 'ประสิทธิภาพการผลิต - PopCentral')
@section('page-title', 'รายงานประสิทธิภาพการผลิต')
@section('page-subtitle', 'เทียบแผนกับจริง: ใบสั่งผลิตตามสูตร และสรุป yield/ต้นทุนงานแปรรูปชั่งน้ำหนัก')
@section('content')

<div class="content-card p-3 mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small text-muted">จากวันที่</label>
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small text-muted">ถึงวันที่</label>
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">-- ทุกสาขา --</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" {{ (int) $branchId === $branch->id ? 'selected' : '' }}>{{ $branch->code }} - {{ $branch->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </div>
    </form>
</div>

{{-- ส่วนที่ 1: ใบสั่งผลิตตามสูตร (แผน vs จริง) --}}
<div class="content-card p-4 mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h2 class="h5 fw-bold mb-0">ใบสั่งผลิตตามสูตร (แผน vs จริง)</h2>
        <div class="text-muted small">
            เฉพาะใบที่ปิดงานแล้ว {{ $summary['count'] }} ใบ &middot;
            ผลิตได้เฉลี่ย {{ number_format($summary['avg_fulfillment'], 1) }}% ของแผน
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">ต้นทุนตามแผน (ราคาวัตถุดิบปัจจุบัน)</div>
                <div class="fw-bold fs-5">฿{{ number_format($summary['total_planned_cost'], 2) }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">ต้นทุนจริง (ตัด FIFO)</div>
                <div class="fw-bold fs-5">฿{{ number_format($summary['total_actual_cost'], 2) }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">ใบที่ต้นทุนเกินแผน</div>
                <div class="fw-bold fs-5 {{ $summary['over_budget_count'] > 0 ? 'text-danger' : '' }}">{{ $summary['over_budget_count'] }} ใบ</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">ปิดงานทั้งที่ผลิตไม่ครบแผน</div>
                <div class="fw-bold fs-5 {{ $summary['closed_short_count'] > 0 ? 'text-warning' : '' }}">{{ $summary['closed_short_count'] }} ใบ</div>
            </div>
        </div>
    </div>

    @if($summary['missing_recipe_count'] > 0)
        <div class="alert alert-warning small mb-3">
            มี {{ $summary['missing_recipe_count'] }} ใบที่ไม่มีสูตรผลิตผูกไว้ (หรือสูตรไม่ระบุจำนวนที่ได้) จึงคำนวณต้นทุนตามแผนไม่ได้ - แสดงเฉพาะต้นทุนจริง
        </div>
    @endif

    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>เลขที่</th><th>วันที่</th><th>สินค้า</th><th>สาขา</th>
                    <th class="text-end">แผน</th><th class="text-end">ผลิตจริง</th><th class="text-end">% แผน</th>
                    <th class="text-end">ต้นทุนตามแผน</th><th class="text-end">ต้นทุนจริง</th><th class="text-end">ส่วนต่าง</th>
                    <th>หมายเหตุปิดงาน</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php($order = $row['order'])
                    <tr>
                        <td class="fw-semibold">{{ $order->doc_no }}</td>
                        <td>{{ $order->doc_date?->thaiDate() }}</td>
                        <td>{{ $order->finishedProduct?->sku_code }} - {{ $order->finishedProduct?->name_th }}</td>
                        <td>{{ $order->branch?->name_th ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $order->planned_qty, 4) }}</td>
                        <td class="text-end">{{ number_format((float) $order->produced_qty, 4) }}</td>
                        <td class="text-end">
                            <span class="badge {{ $row['fulfillment_percent'] >= 100 ? 'text-bg-success' : ($row['fulfillment_percent'] >= 90 ? 'text-bg-warning' : 'text-bg-danger') }}">
                                {{ number_format($row['fulfillment_percent'], 1) }}%
                            </span>
                        </td>
                        <td class="text-end">{{ $row['planned_cost'] !== null ? '฿'.number_format($row['planned_cost'], 2) : '-' }}</td>
                        <td class="text-end">฿{{ number_format($row['actual_cost'], 2) }}</td>
                        <td class="text-end {{ $row['variance'] !== null && $row['variance'] > 0.0001 ? 'text-danger' : ($row['variance'] !== null && $row['variance'] < -0.0001 ? 'text-success' : '') }}">
                            @if($row['variance'] !== null)
                                {{ $row['variance'] > 0 ? '+' : '' }}฿{{ number_format($row['variance'], 2) }}
                                <span class="text-muted small">({{ number_format($row['variance_percent'], 1) }}%)</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="small text-muted">
                            @if($row['closed_short'])
                                <span class="badge text-bg-warning">ปิดเอง</span> {{ $order->close_note }}
                                <div>โดย {{ $order->closedBy?->name }} &middot; {{ $order->closed_at?->thaiDate() }}</div>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-5">ไม่พบใบสั่งผลิตที่ปิดงานแล้วในช่วงที่เลือก</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ส่วนที่ 2: งานแปรรูปชั่งน้ำหนัก (สรุป yield/ต้นทุน) --}}
<div class="content-card p-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h2 class="h5 fw-bold mb-0">งานแปรรูปชั่งน้ำหนัก</h2>
        <div class="text-muted small">{{ $batchSummary['count'] }} รอบ (แสดงล่าสุดไม่เกิน 200 รอบ)</div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">Yield เฉลี่ย</div>
                <div class="fw-bold fs-5">{{ number_format($batchSummary['avg_yield'], 1) }}%</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">Margin ประมาณการเฉลี่ย</div>
                <div class="fw-bold fs-5">{{ number_format($batchSummary['avg_margin'], 1) }}%</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">ต้นทุนวัตถุดิบเข้ารวม</div>
                <div class="fw-bold fs-5">฿{{ number_format($batchSummary['total_input_cost'], 2) }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="content-card p-3 h-100">
                <div class="text-muted small">มูลค่าส่วนสูญเสียรวม</div>
                <div class="fw-bold fs-5 {{ $batchSummary['total_loss_cost'] > 0 ? 'text-danger' : '' }}">฿{{ number_format($batchSummary['total_loss_cost'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>วันที่</th><th>สาขา</th><th>สินค้าที่ได้</th>
                    <th class="text-end">น้ำหนักเข้า</th><th class="text-end">น้ำหนักออก</th><th class="text-end">Yield</th>
                    <th class="text-end">ต้นทุนเข้า</th><th class="text-end">Margin ประมาณการ</th><th class="text-end">มูลค่าสูญเสีย</th>
                </tr>
            </thead>
            <tbody>
                @forelse($batches as $batch)
                    <tr>
                        <td>{{ $batch->document?->doc_date?->thaiDate() }}</td>
                        <td>{{ $batch->document?->branch?->name_th ?? '-' }}</td>
                        <td>{{ $batch->outputProduct?->sku_code }} - {{ $batch->outputProduct?->name_th }}</td>
                        <td class="text-end">{{ number_format((float) $batch->input_weight_qty, 3) }}</td>
                        <td class="text-end">{{ number_format((float) $batch->output_weight_qty, 3) }}</td>
                        <td class="text-end">{{ number_format((float) $batch->yield_percent, 1) }}%</td>
                        <td class="text-end">฿{{ number_format((float) $batch->total_input_cost, 2) }}</td>
                        <td class="text-end">{{ number_format((float) $batch->estimated_margin_percent, 1) }}%</td>
                        <td class="text-end {{ (float) $batch->loss_cost_amount > 0 ? 'text-danger' : '' }}">฿{{ number_format((float) $batch->loss_cost_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-5">ไม่พบงานแปรรูปในช่วงที่เลือก</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
