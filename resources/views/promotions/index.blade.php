@extends('layout')
@section('title', 'ราคา/โปรโมชั่น - PopCentral')
@section('page-title', 'ราคา/โปรโมชั่น')
@section('page-subtitle', 'ตั้งแคมเปญ ส่วนลด คูปอง และเงื่อนไขขายหน้าร้าน')
@section('content')
<div class="content-card p-4 mb-3" x-data="promotionProductPage()">
    <h2 class="h5 fw-bold mb-3">เพิ่มโปรโมชั่น</h2>
    <form method="post" action="{{ route('promotions.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อ</label><input name="name" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="promotion_type" class="form-select"><option value="discount">ส่วนลด</option><option value="coupon">คูปอง</option><option value="free_item">ของแถม</option><option value="member_points">แต้มสมาชิก</option></select></div>
        <div class="col-md-2"><label class="form-label small text-muted">เริ่ม</label><input type="date" name="starts_at" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">สิ้นสุด</label><input type="date" name="ends_at" class="form-control"></div>
        <div class="col-md-1 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="promoActive"><label class="form-check-label" for="promoActive">ใช้</label></div></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สินค้าเฉพาะ (เว้นว่าง = ทุกสินค้า)</label>
            <input type="hidden" name="product_id" x-model="items[0].product_id">
            <button type="button" class="selected-product" :class="{ empty: !items[0].product_id }" @click="openProductPicker(0)">
                <template x-if="!items[0].product_id"><span class="selected-empty"><i class="bi bi-search"></i> ทุกสินค้า หรือค้นหาเพื่อเลือก</span></template>
                <template x-if="items[0].product_id"><span class="selected-product-grid"><b class="selected-code" x-text="items[0].sku_code"></b><span class="selected-copy"><strong x-text="items[0].name_th"></strong><small x-text="items[0].unit_name || 'หน่วยฐาน'"></small></span><span class="selected-change">เปลี่ยน</span></span></template>
            </button>
            <button x-show="items[0].product_id" type="button" class="btn btn-link btn-sm p-0 mt-1" @click="items[0] = emptyProduct()">ล้างสินค้า</button>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">จำนวนขั้นต่ำ</label><input type="number" step="0.0001" name="min_qty" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ยอดขั้นต่ำ</label><input type="number" step="0.0001" name="min_amount" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ลดบาท</label><input type="number" step="0.0001" name="discount_amount" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ลด %</label><input type="number" step="0.0001" name="discount_percent" class="form-control"></div>
        <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ / เงื่อนไขเพิ่มเติม"></div>
    </form>
    @include('partials.product-picker')
</div>
<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการโปรโมชั่น</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อ</th><th>ประเภท</th><th>ช่วงเวลา</th><th>สินค้า</th><th>ส่วนลด</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($promotions as $promotion)
                <tr>
                    <td class="fw-semibold">{{ $promotion->code }}</td>
                    <td>{{ $promotion->name }}</td>
                    <td>{{ $promotion->promotion_type }}</td>
                    <td>{{ $promotion->starts_at?->thaiDate() ?? '-' }} - {{ $promotion->ends_at?->thaiDate() ?? '-' }}</td>
                    <td>{{ $promotion->product?->sku_code ?? 'ทุกสินค้า' }}</td>
                    <td>{{ $promotion->discount_percent ? number_format($promotion->discount_percent, 2).'%' : number_format((float) $promotion->discount_amount, 2).' บาท' }}</td>
                    <td><span class="badge {{ $promotion->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $promotion->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีโปรโมชั่น</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $promotions->links() }}
</div>
@endsection

@push('scripts')
<script>
function promotionProductPage() {
    return {
        ...erpProductPicker(),
        items: [{ product_id: '', sku_code: '', name_th: '', unit_name: '' }],
        emptyProduct() { return { product_id: '', sku_code: '', name_th: '', unit_name: '' }; },
    };
}
</script>
@endpush
