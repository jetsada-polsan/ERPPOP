@extends('layout')
@section('title', 'การผลิต - POPSTAR ERP')
@section('page-title', 'การผลิต')
@section('page-subtitle', 'สูตรผลิต ใบสั่งผลิต และสถานะงานผลิต')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-12 col-xl-5">
        <div class="content-card p-4 h-100">
            <h2 class="h5 fw-bold mb-3">เพิ่มสูตรผลิต / แปรรูป</h2>
            <form method="post" action="{{ route('production.recipes.store') }}" class="row g-3">
                @csrf
                <div class="col-md-4"><label class="form-label small text-muted">รหัสสูตร</label><input name="code" required class="form-control"></div>
                <div class="col-md-8"><label class="form-label small text-muted">ชื่อสูตร</label><input name="name" required class="form-control"></div>
                <div class="col-12">
                    <label class="form-label small text-muted">สินค้าสำเร็จรูป</label>
                    <select name="finished_product_id" required class="form-select">
                        <option value="">-- เลือกสินค้า --</option>
                        @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label small text-muted">จำนวนที่ได้</label><input type="number" step="0.0001" min="0.0001" name="output_qty" value="1" required class="form-control"></div>
                <div class="col-md-8"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control"></div>
                <div class="col-12 d-flex justify-content-between align-items-center">
                    <label class="form-check"><input type="checkbox" name="is_active" value="1" checked class="form-check-input"> ใช้งาน</label>
                    <button class="btn btn-primary px-4"><i class="bi bi-plus-lg me-1"></i> เพิ่มสูตร</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="content-card p-4 h-100">
            <h2 class="h5 fw-bold mb-3">เปิดใบสั่งผลิต</h2>
            <form method="post" action="{{ route('production.orders.store') }}" class="row g-3">
                @csrf
                <div class="col-md-4"><label class="form-label small text-muted">เลขที่เอกสาร</label><input name="doc_no" value="PD{{ now()->format('ymdHis') }}" required class="form-control"></div>
                <div class="col-md-4"><label class="form-label small text-muted">วันที่</label><input type="date" name="doc_date" value="{{ now()->toDateString() }}" required class="form-control"></div>
                <div class="col-md-4"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="planned">วางแผน</option><option value="released">เปิดงาน</option><option value="completed">เสร็จแล้ว</option></select></div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">สูตรผลิต</label>
                    <select name="production_recipe_id" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($recipes as $recipe)<option value="{{ $recipe->id }}">{{ $recipe->code }} - {{ $recipe->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">สินค้าสำเร็จรูป</label>
                    <select name="finished_product_id" required class="form-select">
                        <option value="">-- เลือกสินค้า --</option>
                        @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">สาขา</label>
                    <select name="branch_id" class="form-select"><option value="">-- ไม่ระบุ --</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">รับเข้าที่เก็บ</label>
                    <select name="warehouse_location_id" class="form-select"><option value="">-- ไม่ระบุ --</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->warehouse?->code }} / {{ $location->code }} - {{ $location->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-2"><label class="form-label small text-muted">แผนผลิต</label><input type="number" step="0.0001" min="0.0001" name="planned_qty" value="1" required class="form-control"></div>
                <div class="col-md-2"><label class="form-label small text-muted">ผลิตแล้ว</label><input type="number" step="0.0001" min="0" name="produced_qty" value="0" class="form-control"></div>
                <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
                <div class="col-12 text-end"><button class="btn btn-primary px-4">เปิดใบสั่งผลิต</button></div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">สูตรผลิต</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>สูตร</th><th>สินค้า</th><th class="text-end">จำนวนที่ได้</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    @forelse($recipes as $recipe)
                        <tr>
                            <td class="fw-semibold">{{ $recipe->code }}</td>
                            <td>{{ $recipe->name }}</td>
                            <td>{{ $recipe->finishedProduct?->sku_code }}</td>
                            <td class="text-end">{{ number_format((float) $recipe->output_qty, 4) }}</td>
                            <td><span class="badge {{ $recipe->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $recipe->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีสูตรผลิต</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $recipes->links() }}
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">ใบสั่งผลิต</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>เลขที่</th><th>วันที่</th><th>สินค้า</th><th class="text-end">แผน</th><th class="text-end">ผลิตแล้ว</th><th>สถานะ</th><th style="width:190px">รับเข้าคลัง (IP)</th></tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td class="fw-semibold">{{ $order->doc_no }}</td>
                            <td>{{ $order->doc_date?->thaiDate() }}</td>
                            <td>{{ $order->finishedProduct?->sku_code }} - {{ $order->finishedProduct?->name_th }}</td>
                            <td class="text-end">{{ number_format((float) $order->planned_qty, 4) }}</td>
                            <td class="text-end">{{ number_format((float) $order->produced_qty, 4) }}</td>
                            <td><span class="badge {{ $order->status === 'completed' ? 'text-bg-success' : 'text-bg-light border' }}">{{ $order->status === 'completed' ? 'ปิดงานแล้ว' : $order->status }}</span></td>
                            <td>
                                @if($order->status !== 'completed')
                                <form method="post" action="{{ route('production.orders.receive', $order) }}" class="d-flex gap-1"
                                    onsubmit="return confirm('รับสินค้าเข้าคลังตามจำนวนที่ระบุ?')">
                                    @csrf
                                    <input type="number" step="0.0001" min="0.0001" name="qty" class="form-control form-control-sm text-end"
                                        value="{{ max(0, (float) $order->planned_qty - (float) $order->produced_qty) }}" style="width:90px" required>
                                    <button class="btn btn-sm btn-success text-nowrap"><i class="bi bi-box-arrow-in-down"></i> รับ</button>
                                </form>
                                @else
                                <span class="text-muted small">ครบแล้ว</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีใบสั่งผลิต</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        </div>
    </div>
</div>
@endsection
