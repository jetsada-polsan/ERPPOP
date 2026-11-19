@extends('layout')
@section('page-title', 'เปิด/ปิดเมนู')
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<form method="post" action="{{ route('settings.menus.save') }}">
@csrf
<p>เลือกเมนูที่ต้องการเปิดให้ใช้งาน หน้าหลัก การตั้งค่า สถานะระบบ และการจัดการบัญชีส่วนตัวต้องเปิดเสมอเพื่อให้กลับเข้าระบบได้</p>
<div class="row g-3">
@foreach($sections as $section)
<div class="col-12 col-lg-6"><fieldset class="card card-body h-100">
@php($keys = collect($section['items'])->map(fn($item) => \App\Support\MenuVisibility::key($item)))
<legend class="h5"><label><input type="checkbox" onchange="this.closest('fieldset').querySelectorAll('input[name=&quot;enabled[]&quot;]:not(:disabled)').forEach(el => el.checked = this.checked)" name="sections[]" value="{{ $section['label'] }}" @checked($keys->diff($hidden)->isNotEmpty())> {{ $section['displayLabel'] }}</label></legend>
@foreach($section['items'] as $item)
@php($key = \App\Support\MenuVisibility::key($item))
<label class="form-check form-switch py-1"><input class="form-check-input" type="checkbox" onchange="if (this.checked) this.closest('fieldset').querySelector('legend input').checked = true" name="enabled[]" value="{{ $key }}" @checked(!in_array($key,$hidden,true)) @disabled(\App\Support\MenuVisibility::protected($item['route']))> {{ $item['label'] }} @if(\App\Support\MenuVisibility::protected($item['route']))<small>· จำเป็นต่อการเข้าระบบ</small>@endif</label>
@endforeach
</fieldset></div>
@endforeach
</div>
<fieldset class="card card-body mt-3"><legend class="h5">บัญชีส่วนตัว</legend>
@foreach(['เข้าสู่ระบบ','ออกจากระบบ','โปรไฟล์และความปลอดภัย'] as $label)
<label class="form-check form-switch"><input class="form-check-input" type="checkbox" checked disabled> {{ $label }} · จำเป็นต่อการเข้าระบบ</label>
@endforeach
</fieldset>
<div class="mt-3 d-flex gap-2"><button class="btn btn-primary" name="action" value="save">บันทึก</button><button class="btn btn-outline-secondary" name="action" value="restore">เปิดทั้งหมด</button></div>
</form>
@endsection
