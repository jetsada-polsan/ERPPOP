@extends('layout')
@section('title', 'ศูนย์ตั้งต้นระบบ - PopCentral')
@section('page-title', 'ศูนย์ตั้งต้นระบบ')
@section('page-subtitle', 'ส่งออก Excel CSV, แก้ไข และนำเข้ารายการใหม่โดยไม่แก้ข้อมูลเดิม')
@section('content')
<div class="container-fluid py-2" style="max-width:1320px">
    <div class="alert alert-primary border-0 shadow-sm"><strong>ลำดับเริ่มระบบ:</strong> ดาวน์โหลด template → แก้ใน Excel → Save As <strong>CSV UTF-8</strong> → อัปโหลดตรวจ → ยืนยันเพิ่มเฉพาะรายการใหม่. รหัสสินค้าและรหัสพนักงานถูกสร้างโดยระบบ ไม่รับให้พิมพ์เอง.</div>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="row g-3">
        @foreach(['categories'=>'ประเภทสินค้า','products'=>'สินค้า','employees'=>'พนักงาน'] as $type=>$label)
        <div class="col-lg-4"><section class="card h-100 shadow-sm border-0"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start"><div><h4 class="mb-1">{{ $label }}</h4><p class="text-muted mb-3">ในระบบ {{ number_format($counts[$type]) }} รายการ</p></div><i class="bi bi-file-earmark-arrow-up fs-2 text-primary"></i></div>
            <a href="{{ route('master-data-setup.template',$type) }}" class="btn btn-outline-primary w-100 mb-3"><i class="bi bi-download"></i> ดาวน์โหลด Template CSV</a>
            <form method="post" action="{{ route('master-data-setup.preview',$type) }}" enctype="multipart/form-data">
                @csrf <input class="form-control mb-2" type="file" name="file" accept=".csv,text/csv" required>
                <button class="btn btn-primary w-100"><i class="bi bi-search"></i> ตรวจไฟล์ก่อนนำเข้า</button>
            </form>
        </div></section></div>
        @endforeach
    </div>
    @if($pending)
    <section class="card shadow-sm border-warning mt-4"><div class="card-body">
        <h4><i class="bi bi-shield-check text-warning"></i> ผลตรวจ: {{ ['categories'=>'ประเภทสินค้า','products'=>'สินค้า','employees'=>'พนักงาน'][$pending['type']] }}</h4>
        <div class="row text-center my-3"><div class="col"><strong class="fs-3 text-success">{{ $pending['new'] }}</strong><br>เพิ่มใหม่</div><div class="col"><strong class="fs-3 text-secondary">{{ $pending['skip'] }}</strong><br>ข้ามของเดิม</div><div class="col"><strong class="fs-3 text-danger">{{ $pending['error'] }}</strong><br>ข้อผิดพลาด</div></div>
        @foreach($pending['examples'] as $row)<div class="small border-top py-1"><strong>บรรทัด {{ $row['line'] }}</strong> · <span class="badge text-bg-{{ $row['action']==='new'?'success':($row['action']==='skip'?'secondary':'danger') }}">{{ $row['action'] }}</span> · {{ $row['message'] }}</div>@endforeach
        @if($pending['examples_capped'] ?? false)<div class="small text-muted pt-2">แสดงตัวอย่าง {{ count($pending['examples']) }} บรรทัด (แถวที่ผิดขึ้นก่อน) — แก้ตามเลขบรรทัดใน Excel แล้วอัปโหลดตรวจใหม่</div>@endif
        @if($pending['error'] === 0)<form method="post" action="{{ route('master-data-setup.apply',$pending['type']) }}" class="mt-3">@csrf<input type="hidden" name="token" value="{{ $pending['token'] }}"><button class="btn btn-success"><i class="bi bi-check2-circle"></i> ยืนยันเพิ่ม {{ $pending['new'] }} รายการ</button></form>@else <div class="alert alert-danger mb-0 mt-3">แก้ไฟล์ให้ไม่มี error ก่อน ระบบจะไม่เขียนข้อมูลบางส่วน</div>@endif
    </div></section>
    @endif
    <section class="card mt-4 border-0 bg-light"><div class="card-body"><h5>การจัดหมวดและรัน SKU ใหม่</h5><p class="mb-0 text-muted">เป็นขั้น cutover แยกต่างหาก ไม่ทำจากการ import ปกติ เพราะมีผลกับ SKU ทั้งระบบ. ตอนนี้ SKU ของหมวดตัวเลขถูกจัดให้ตรงหมวดแล้ว และระบบยังเก็บ legacy SKU ไว้สำหรับเทียบข้อมูลเก่า.</p></div></section>
</div>
@endsection
