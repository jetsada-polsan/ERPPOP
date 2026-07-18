@extends('layout')
@section('title', 'LINE / Messaging API - POPSTAR ERP')
@section('page-title', 'LINE / Messaging API')
@section('page-subtitle', 'ตั้งค่าช่องทางแจ้งเตือนแทน LINE Notify เดิม')
@section('content')
<div class="alert alert-warning border-0 shadow-sm">LINE Notify ปิดบริการแล้ว ให้เก็บค่า Messaging API channel token หรือ note การเชื่อมต่อใหม่ไว้ในหน้านี้</div>
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">เพิ่มช่องทางแจ้งเตือน</h2>
    <form method="post" action="{{ route('line-integrations.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อ</label><input name="name" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="channel_type" class="form-select"><option value="messaging_api">Messaging API</option><option value="webhook">Webhook</option><option value="legacy_notify">Legacy Notify</option></select></div>
        <div class="col-md-3"><label class="form-label small text-muted">กลุ่ม/ผู้รับ</label><input name="target_name" class="form-control"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
        <div class="col-12"><textarea name="token" rows="2" class="form-control" placeholder="Channel access token / webhook secret / note"></textarea></div>
        <div class="col-12 d-flex flex-wrap gap-3">
            <label class="form-check"><input type="checkbox" name="notify_sales" value="1" checked class="form-check-input"> แจ้งยอดขาย</label>
            <label class="form-check"><input type="checkbox" name="notify_qr_payment" value="1" checked class="form-check-input"> แจ้ง QR</label>
            <label class="form-check"><input type="checkbox" name="notify_void_bill" value="1" checked class="form-check-input"> แจ้งยกเลิกบิล</label>
            <label class="form-check"><input type="checkbox" name="notify_stock_alert" value="1" class="form-check-input"> แจ้งสต็อกต่ำ</label>
            <label class="form-check"><input type="checkbox" name="is_active" value="1" checked class="form-check-input"> ใช้งาน</label>
        </div>
    </form>
</div>
<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">ช่องทางที่ตั้งไว้</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อ</th><th>ประเภท</th><th>ผู้รับ</th><th>แจ้งเตือน</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($integrations as $item)
                <tr>
                    <td class="fw-semibold">{{ $item->code }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->channel_type }}</td>
                    <td>{{ $item->target_name ?? '-' }}</td>
                    <td class="small">
                        @if($item->notify_sales) <span class="badge text-bg-primary">ขาย</span> @endif
                        @if($item->notify_qr_payment) <span class="badge text-bg-info">QR</span> @endif
                        @if($item->notify_void_bill) <span class="badge text-bg-warning">ยกเลิก</span> @endif
                        @if($item->notify_stock_alert) <span class="badge text-bg-danger">สต็อก</span> @endif
                    </td>
                    <td><span class="badge {{ $item->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $item->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีช่องทาง LINE</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $integrations->links() }}
</div>
@endsection
