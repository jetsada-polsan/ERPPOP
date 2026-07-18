@extends('layout')
@section('title', 'แคมเปญซื้อครบ - POPSTAR ERP')
@section('page-title', 'แคมเปญซื้อครบ')
@section('page-subtitle', 'ซื้อจำนวนครบได้ของแถม (เช่น ซื้อ 1 แถม 1) หรือได้ส่วนลด (เช่น ซื้อ 2 ลด 50%)')
@section('content')
<div class="content-card p-4 mb-3" x-data="{ type: 'free_item' }">
    <h2 class="h5 fw-bold mb-3">สร้างแคมเปญ</h2>
    <form method="post" action="{{ route('qty-promotions.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
        <div class="col-md-4"><label class="form-label small text-muted">ชื่อแคมเปญ</label><input name="name" required class="form-control" placeholder="เช่น ปลาหมึกซื้อ 1 แถม 1"></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">ประเภทแคมเปญ</label>
            <select name="promo_type" x-model="type" class="form-select">
                <option value="free_item">ซื้อครบได้ของแถม</option>
                <option value="discount">ซื้อครบได้ส่วนลด</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" class="form-select">
                <option value="">-- ทุกสาขา --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-5">
            <label class="form-label small text-muted">สินค้าที่ต้องซื้อ</label>
            <select name="product_id" required class="form-select">
                <option value="">-- เลือกสินค้า --</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">ซื้อครบ (จำนวน)</label><input type="number" step="0.0001" min="0.0001" name="min_qty" required class="form-control" value="1"></div>

        <div class="col-md-5" x-show="type === 'free_item'">
            <label class="form-label small text-muted">ของแถม (เลือกตัวเดียวกัน = ซื้อ 1 แถม 1)</label>
            <div class="row g-2">
                <div class="col-8">
                    <select name="free_product_id" class="form-select">
                        <option value="">-- เลือกของแถม --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4"><input type="number" step="0.0001" min="0.0001" name="free_qty" class="form-control" placeholder="จำนวนแถม" value="1"></div>
            </div>
        </div>
        <div class="col-md-5" x-show="type === 'discount'" x-cloak>
            <label class="form-label small text-muted">ส่วนลดต่อชุดที่ซื้อครบ</label>
            <div class="row g-2">
                <div class="col-6"><input type="number" step="0.01" min="0.01" name="discount_value" class="form-control" placeholder="เช่น 50"></div>
                <div class="col-6">
                    <select name="discount_type" class="form-select">
                        <option value="percent">% ของราคาชุด</option>
                        <option value="baht">บาทต่อชุด</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="col-md-2"><label class="form-label small text-muted">วันที่เริ่ม</label><input type="date" name="starts_date" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">วันที่สิ้นสุด</label><input type="date" name="ends_date" class="form-control"></div>
        <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="promoActive"><label class="form-check-label" for="promoActive">ใช้งาน</label></div></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">สร้างแคมเปญ</button></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ / เงื่อนไขเพิ่มเติม"></div>
    </form>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการแคมเปญ</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อแคมเปญ</th><th>สินค้า</th><th>เงื่อนไข</th><th>ของแถม</th><th>สาขา</th><th>ช่วงเวลา</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($promotions as $promo)
                <tr>
                    <td class="fw-semibold">{{ $promo->code }}</td>
                    <td>{{ $promo->name }}</td>
                    <td class="small">{{ $promo->product?->sku_code }} {{ $promo->product?->name_th }}</td>
                    <td><span class="badge {{ $promo->promo_type === 'free_item' ? 'text-bg-success' : 'text-bg-warning' }}">{{ $promo->label() }}</span></td>
                    <td class="small">{{ $promo->freeProduct ? $promo->freeProduct->sku_code.' '.$promo->freeProduct->name_th : '-' }}</td>
                    <td class="small">{{ $promo->branch?->name_th ?? 'ทุกสาขา' }}</td>
                    <td class="small">{{ $promo->starts_date?->thaiDate() ?? 'ไม่กำหนด' }} - {{ $promo->ends_date?->thaiDate() ?? 'ไม่กำหนด' }}</td>
                    <td><span class="badge {{ $promo->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $promo->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีแคมเปญซื้อครบ</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $promotions->links() }}
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
