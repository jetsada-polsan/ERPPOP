@extends('layout')
@section('title', 'LINE / Messaging API - PopCentral')
@section('page-title', 'LINE / Messaging API')
@section('page-subtitle', 'ตั้งค่าช่องทางแจ้งเตือนแทน LINE Notify เดิม')
@section('content')
<div class="content-card p-4 mb-3">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <h2 class="h5 fw-bold mb-1">LINE OA · PopstarCenter Member</h2>
            <div class="text-muted small">ศูนย์ควบคุมการเชื่อมต่อสมาชิก แต้ม ยอดซื้อ และ Rich Menu</div>
        </div>
        <span class="badge text-bg-success px-3 py-2">● Webhook พร้อมใช้งาน</span>
    </div>
    <div class="row g-3 mt-2">
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Channel ID</div><strong>{{ $lineChannelId ?: 'ยังไม่ตั้งค่า' }}</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">สมาชิกใช้งาน</div><strong>{{ number_format($activeMembers) }} ราย</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">ผูก LINE แล้ว</div><strong>{{ number_format($linkedMembers) }} ราย</strong></div></div>
        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Rich Menu</div><strong>6 เมนูหลัก</strong></div></div>
    </div>
    <div class="mt-3">
        <label class="form-label small text-muted mb-1">Webhook URL</label>
        <div class="input-group"><input class="form-control" value="{{ $webhookUrl }}" readonly><button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(@js($webhookUrl))">คัดลอก</button></div>
    </div>
    <div class="alert alert-info mt-3 mb-0 py-2 small">การสมัครสมาชิกให้สร้างสมาชิกใน ERP ก่อน แล้วให้ลูกค้ากดผูกสมาชิกและยืนยันด้วยเบอร์โทรศัพท์</div>
</div>
<div class="alert alert-warning border-0 shadow-sm">LINE Notify ปิดบริการแล้ว ช่องนี้ใช้สำหรับเก็บทะเบียนช่องทางแจ้งเตือนเดิม ส่วน Channel Secret และ Access Token ให้เก็บใน Environment ของเซิร์ฟเวอร์และไม่แสดงบนหน้าเว็บ</div>
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">สมาชิกที่ผูก LINE แล้ว</h2>
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>สมาชิก</th><th>ลูกค้า/ลูกหนี้</th><th>LINE User ID</th><th>ผูกเมื่อ</th><th></th></tr></thead>
        <tbody>
        @forelse($linkedAccounts as $account)
            <tr>
                <td><strong>{{ $account->member?->name ?: '-' }}</strong><div class="small text-muted">{{ $account->member?->phone ?: '-' }}</div></td>
                <td>{{ $account->member?->customer?->code ?: '-' }} {{ $account->member?->customer?->name_th }}</td>
                <td><code>{{ \Illuminate\Support\Str::mask($account->line_user_id, '*', 4) }}</code></td>
                <td>{{ $account->linked_at?->format('d/m/Y H:i') ?: '-' }}</td>
                <td class="text-end"><form method="post" action="{{ route('line-integrations.members.unlink', $account) }}" onsubmit="return confirm('ยืนยันปลดผูกสมาชิกคนนี้?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">ปลดผูก</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีสมาชิกที่ผูก LINE</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">เพิ่มช่องทางแจ้งเตือน</h2>
    <form method="post" action="{{ route('line-integrations.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อ</label><input name="name" required class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="channel_type" class="form-select"><option value="messaging_api">Messaging API</option><option value="webhook">Webhook</option><option value="legacy_notify">Legacy Notify</option></select></div>
        <div class="col-md-2"><label class="form-label small text-muted">ชื่อกลุ่ม/ผู้รับ</label><input name="target_name" class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">LINE Target ID</label><input name="target_id" class="form-control" placeholder="U... หรือ C..."></div>
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
                    <td>{{ $item->target_name ?? '-' }}<div class="small text-muted">{{ $item->target_id ? \Illuminate\Support\Str::mask($item->target_id, '*', 4) : 'ยังไม่มี Target ID' }}</div></td>
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
