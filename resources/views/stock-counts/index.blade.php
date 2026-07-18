@extends('layout')
@section('title', 'ตรวจนับสินค้า - POPSTAR ERP')
@section('page-title', 'ตรวจนับสินค้า')
@section('page-subtitle', 'เปิดใบตรวจนับตามสาขา กรอกหรือ import ยอดนับจริง แล้วปรับปรุงสต๊อกอัตโนมัติ')
@section('content')
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
                <option value="full_zero_missing">นับเต็ม — รายการไม่ได้นับเป็น 0</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control" value="{{ old('note') }}" placeholder="เช่น ตรวจนับสิ้นเดือน"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-clipboard-plus me-1"></i>เปิดใบตรวจนับ</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">ระบบจะดึงสินค้าทุกตัวที่มีสต๊อกในตำแหน่งเก็บนั้นมาเป็นรายการตรวจนับ พร้อมยอดตามระบบ ณ ตอนเปิดใบ</p>
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
