@extends('erp-mockup.layout')
@section('title', 'โมดูลทั้งหมด')
@section('content')
<div class="pop-head">
    <div><h1>โมดูลทั้งหมด</h1><p>เลือกโมดูลที่ต้องการเข้าใช้งาน</p></div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(178px,1fr));gap:14px">
    @foreach($apps as $app)
        <a href="{{ $app['route'] ? route($app['route']) : '#' }}" class="pop-card"
           style="padding:20px 16px;text-align:center;display:block;transition:.15s;{{ $app['route'] ? '' : 'opacity:.55' }}"
           onmouseover="this.style.borderColor='var(--pop-primary)';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='var(--pop-line)';this.style.transform='none'">
            <div class="pop-kpi-icon tone-primary" style="margin:0 auto 10px;width:46px;height:46px;font-size:21px"><i class="bi {{ $app['icon'] }}"></i></div>
            <div style="font-weight:700">{{ $app['th'] }}</div>
            <div class="pop-muted">{{ $app['en'] }}</div>
            @unless($app['route'])<div class="pop-muted" style="margin-top:6px;font-size:11.5px">ยังไม่ได้ทำหน้า</div>@endunless
        </a>
    @endforeach
</div>
@endsection
