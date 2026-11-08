@extends('layout')
@section('title', 'ตรวจนับสินค้า - MPM Food Group')
@section('page-title', 'ตรวจนับสินค้า')
@section('page-subtitle', 'เปิดใบตรวจนับตามสาขา กรอกหรือ import ยอดนับจริง แล้วปรับปรุงสต๊อกอัตโนมัติ')
@section('content')
@if($staleCounts->isNotEmpty())
<div class="alert alert-warning d-flex gap-2 align-items-start" data-testid="stale-counts">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div>
        <div class="fw-semibold">ใบตรวจนับค้าง "กำลังนับ" เกิน {{ \App\Models\StockCount::STALE_DAYS }} วัน {{ $staleCounts->count() }} ใบ — นับให้จบหรือยกเลิก สต๊อกระหว่างนี้อาจคลาดเคลื่อน</div>
        <div class="small">
            @foreach($staleCounts as $stale)
                <a href="{{ route('stock-counts.show', $stale) }}" class="me-2">{{ $stale->doc_number }}</a><span class="text-muted me-3">{{ $stale->branch?->code }} · เปิด {{ $stale->created_at->thaiDate() }} ({{ (int) $stale->created_at->diffInDays(now()) }} วัน)</span>
            @endforeach
        </div>
    </div>
</div>
@endif
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">เปิดใบตรวจนับใหม่</h2>
    <form method="post" action="{{ route('stock-counts.store') }}" class="row g-3">
        @csrf
        <div class="col-md-3">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" required class="form-select">
                <option value="">-- เลือกสาขา --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">ตำแหน่งเก็บ (ไม่เลือก = คลังหลักของสาขา)</label>
            <select name="warehouse_location_id" class="form-select">
                <option value="">-- คลังหลักของสาขา --</option>
                @foreach($locations as $loc)
                    <option value="{{ $loc->id }}" @selected(old('warehouse_location_id') == $loc->id)>{{ $loc->code }} - {{ $loc->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">โหมดการนับ</label>
            <select name="count_mode" class="form-select" required>
                <option value="partial">นับบางส่วน (ปลอดภัย)</option>
                <option value="full_zero_missing">นับเต็ม — ต้องกรอกครบทุกสินค้า</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">กลุ่มสินค้า</label>
            <select name="product_scope" class="form-select" required>
                <option value="active" @selected(old('product_scope', 'active') === 'active')>สินค้าใช้งาน</option>
                <option value="inactive" @selected(old('product_scope') === 'inactive')>สินค้าไม่ใช้งาน</option>
                <option value="all" @selected(old('product_scope') === 'all')>ทั้งหมด</option>
            </select>
        </div>
        <div class="col-md-1"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control" value="{{ old('note') }}" placeholder="เช่น สิ้นเดือน"></div>
        <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-clipboard-plus me-1"></i>เปิด</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">ระบบจะสร้างรายการจากแคตตาล็อกตามกลุ่มที่เลือก รวมสินค้าที่สต๊อกเป็นศูนย์ พร้อมยอดตามระบบ ณ ตอนเปิดใบ</p>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการใบตรวจนับ</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>เลขที่</th><th>วันที่</th><th>สาขา</th><th>ตำแหน่งเก็บ</th><th class="text-end">รายการ</th><th>สถานะ</th><th>เอกสารปรับปรุง</th><th></th></tr></thead>
            <tbody>
            @forelse($counts as $count)
                <tr>
                    <td class="fw-semibold">{{ $count->doc_number }}</td>
                    <td>{{ $count->created_at->thaiDate() }}</td>
                    <td>{{ $count->branch->name_th }}</td>
                    <td>{{ $count->warehouseLocation->name }}</td>
                    <td class="text-end">{{ number_format($count->items_count) }}</td>
                    <td><span class="badge {{ $count->status === 'posted' ? 'text-bg-success' : ($count->status === 'review' ? 'text-bg-info' : 'text-bg-warning') }}">{{ $count->status === 'posted' ? 'ปรับปรุงแล้ว' : ($count->status === 'review' ? 'รอตรวจยืนยัน' : 'กำลังนับ') }}</span></td>
                    <td class="small">
                        @if($count->postedDocument)
                            <a href="{{ route('stock-adjustments.show', $count->posted_document_id) }}">{{ $count->postedDocument->doc_number }}</a>
                        @else - @endif
                    </td>
                    <td class="text-end"><a href="{{ route('stock-counts.show', $count) }}" class="btn btn-sm btn-light border">{{ $count->isEditable() ? 'กรอกยอดนับ' : 'ดูรายละเอียด' }}</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีใบตรวจนับ — เปิดใบใหม่ด้านบน</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $counts->links() }}
</div>
@endsection
