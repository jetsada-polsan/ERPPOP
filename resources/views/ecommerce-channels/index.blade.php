@extends('layout')
@section('title', 'E-Commerce - POPSTAR ERP')
@section('page-title', 'E-Commerce')
@section('page-subtitle', 'ช่องทางขายออนไลน์ Lazada, Shopee, LINE MyShop, TikTok Shop')
@section('content')
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">เพิ่มช่องทางขายออนไลน์</h2>
    <form method="post" action="{{ route('ecommerce-channels.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อ</label><input name="name" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">แพลตฟอร์ม</label><select name="platform" class="form-select"><option value="lazada">Lazada</option><option value="shopee">Shopee</option><option value="line_myshop">LINE MyShop</option><option value="tiktok_shop">TikTok Shop</option><option value="other">อื่น ๆ</option></select></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อร้าน</label><input name="shop_name" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">สถานะ sync</label><select name="sync_status" class="form-select"><option value="draft">รอตั้งค่า</option><option value="ready">พร้อม sync</option><option value="paused">พัก</option></select></div>
        <div class="col-12"><textarea name="credential_note" rows="2" class="form-control" placeholder="หมายเหตุ credential / token / ขั้นตอนเชื่อมต่อ"></textarea></div>
        <div class="col-12 d-flex justify-content-end gap-3"><label class="form-check mt-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input"> ใช้งาน</label><button class="btn btn-primary px-4">เพิ่มช่องทาง</button></div>
    </form>
</div>
<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">ช่องทางทั้งหมด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อ</th><th>แพลตฟอร์ม</th><th>ร้าน</th><th>Sync</th><th>ล่าสุด</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($channels as $channel)
                <tr>
                    <td class="fw-semibold">{{ $channel->code }}</td>
                    <td>{{ $channel->name }}</td>
                    <td>{{ $channel->platform }}</td>
                    <td>{{ $channel->shop_name ?? '-' }}</td>
                    <td><span class="badge text-bg-light border">{{ $channel->sync_status }}</span></td>
                    <td>{{ $channel->last_synced_at?->thaiDate(true) ?? '-' }}</td>
                    <td><span class="badge {{ $channel->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $channel->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีช่องทาง E-Commerce</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $channels->links() }}
</div>
@endsection
