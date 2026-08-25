@extends('layout')

@section('title', 'รวมโมดูลทั้งหมด')
@section('page-title', 'รวมโมดูลทั้งหมด')
@section('page-subtitle', 'เลือกโมดูลที่ต้องการเข้าใช้งาน')

@section('content')
    {{-- เมนูส่งเข้าไปเป็น prop ไม่ต้องมี endpoint ใหม่ และกรองสิทธิ์มาจาก ErpMenu แล้ว --}}
    <div id="erp-app-launcher" data-sections="{{ json_encode($sections, JSON_UNESCAPED_UNICODE) }}">
        {{-- สิ่งที่เห็นก่อน Vue จะ mount และเป็นทางสำรองถ้า JS ใช้ไม่ได้ --}}
        <noscript>
            @foreach ($sections as $section)
                <h2>{{ $section['title'] }}</h2>
                <ul>
                    @foreach ($section['items'] as $item)
                        <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            @endforeach
        </noscript>
    </div>
@endsection

@push('head')
    @vite('resources/js/app-launcher.ts')
    <style>
        /* หน้านี้คือการ "เลือกโมดูล" เมนูข้างของโมดูลเดิมจึงไม่ควรอยู่
           ต้องเจาะจงเท่ากฎ html[data-layout="odoo"] .app-sidebar.odn-side (0,3,1)
           ไม่งั้น !important เฉย ๆ แพ้ แล้วเมนูจะถูกดันไปอยู่ขวาจอแทนที่จะหาย
           ปล่อยให้คอลัมน์ grid ยุบเองเพราะ AdminLTE ใช้ auto ไม่ต้องแก้ template-areas */
        html[data-layout="odoo"] .app-sidebar.odn-side,
        html[data-layout] .app-sidebar,
        .app-sidebar,
        .fa-collapse-btn { display: none !important; }
        .app-content { padding-top: 18px; }
    </style>
@endpush
