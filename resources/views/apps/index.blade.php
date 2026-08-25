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
@endpush
