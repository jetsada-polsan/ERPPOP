@extends('layout')
@section('title','ตั้งค่า MFA - POPSTAR ERP')
@section('page-title','ตั้งค่าการยืนยันตัวตนสองขั้นตอน')
@section('page-subtitle','ใช้ Google Authenticator, Microsoft Authenticator หรือแอป TOTP มาตรฐาน')
@section('content')
<div class="row g-3"><div class="col-lg-7"><section class="card"><div class="card-body p-4">
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if(auth()->user()->mfa_enabled_at)
        <h2 class="h5 fw-bold text-success"><i class="bi bi-shield-check me-2"></i>MFA เปิดใช้งานแล้ว</h2><p class="text-muted">เปิดใช้งานเมื่อ {{ auth()->user()->mfa_enabled_at->thaiDate(true) }}</p>
        <form method="post" action="{{ route('mfa.disable') }}" class="row g-2">@csrf @method('DELETE')<div class="col-md-5"><label class="form-label">รหัสผ่านปัจจุบัน</label><input type="password" name="current_password" class="form-control" required></div><div class="col-md-4"><label class="form-label">รหัส Authenticator</label><input name="code" class="form-control" inputmode="numeric" maxlength="6" required></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-danger w-100">ปิด MFA</button></div></form>
    @else
        <ol class="ps-3 text-muted"><li class="mb-2">เปิดแอป Authenticator แล้วเลือกเพิ่มบัญชีด้วย Setup key</li><li class="mb-2">ชื่อบัญชีใช้ <strong>{{ auth()->user()->username }}</strong> และชนิดรหัสเป็น Time based</li><li>กรอกรหัส 6 หลักเพื่อยืนยันว่าตั้งค่าสำเร็จ</li></ol>
        <div class="p-3 bg-light border rounded mb-3"><div class="small text-muted mb-1">Setup key</div><code class="fs-5 text-break">{{ $secret }}</code><div class="small text-muted mt-2 text-break">{{ $uri }}</div></div>
        <form method="post" action="{{ route('mfa.enable') }}" class="row g-2">@csrf<div class="col-md-5"><label class="form-label">รหัสผ่านปัจจุบัน</label><input type="password" name="current_password" class="form-control" required></div><div class="col-md-4"><label class="form-label">รหัส 6 หลัก</label><input name="code" class="form-control" inputmode="numeric" maxlength="6" required></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">เปิด MFA</button></div></form>
    @endif
</div></section></div><div class="col-lg-5"><div class="alert alert-warning"><strong>ข้อควรระวัง</strong><br>ห้ามส่ง Setup key ใน LINE หรือแชต เก็บไว้ใน Password Manager และต้องติดต่อผู้ดูแลระบบหากโทรศัพท์สูญหาย</div><a class="btn btn-light border" href="{{ route('operations.index') }}"><i class="bi bi-arrow-left me-1"></i>กลับศูนย์ความปลอดภัย</a></div></div>
@endsection
