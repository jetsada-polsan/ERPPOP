@extends('layout')
@section('title', 'Show Price / Queue Buster')
@section('page-title', 'Show Price / Queue Buster')
@section('page-subtitle', 'อุปกรณ์ตรวจราคา โปรโมชั่น และคิวหน้าร้าน')
@section('content')
<div class="content-card p-4 mb-3"><form method="post" action="{{ route('bplus.show-price.store') }}" class="row g-3">@csrf
    <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control"></div>
    <div class="col-md-3"><label class="form-label small text-muted">ชื่ออุปกรณ์</label><input name="name" required class="form-control"></div>
    <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="device_type" class="form-select"><option value="show_price">Show Price</option><option value="check_price">Check Price</option><option value="queue_buster">Queue Buster</option></select></div>
    <div class="col-md-2"><label class="form-label small text-muted">สาขา</label><select name="branch_id" class="form-select"><option value="">--</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label small text-muted">IP/เครื่อง</label><input name="ip_address" class="form-control"></div>
    <div class="col-md-1"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="active">ใช้</option><option value="offline">ปิด</option></select></div>
    <div class="col-md-10"><input name="note" class="form-control" placeholder="หมายเหตุ / ตำแหน่งติดตั้ง"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">เพิ่ม</button></div>
</form></div>
<div class="content-card p-4"><h2 class="h5 fw-bold mb-3">อุปกรณ์หน้าร้าน</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>รหัส</th><th>ชื่อ</th><th>ประเภท</th><th>สาขา</th><th>IP</th><th>สถานะ</th></tr></thead><tbody>@forelse($devices as $device)<tr><td class="fw-semibold">{{ $device->code }}</td><td>{{ $device->name }}</td><td>{{ $device->device_type }}</td><td>{{ $device->branch?->name_th ?? '-' }}</td><td>{{ $device->ip_address ?? '-' }}</td><td><span class="badge text-bg-light border">{{ $device->status }}</span></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีอุปกรณ์</td></tr>@endforelse</tbody></table></div>{{ $devices->links() }}</div>
@endsection
