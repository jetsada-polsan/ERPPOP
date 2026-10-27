@extends('layout')
@section('title', 'ป้ายราคา - PopCentral')
@section('page-title', 'ป้ายราคา')
@section('page-subtitle', 'กำหนดรูปแบบป้ายราคาและพิมพ์ป้ายสำหรับสินค้า')
@section('content')
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('price-tags.print') }}" class="btn btn-primary">
        <i class="bi bi-printer-fill me-1"></i> พิมพ์ป้ายราคา
    </a>
</div>

<div class="content-card p-4 mb-3" x-data="{ source: 'default' }">
    <h2 class="h5 fw-bold mb-3">เพิ่มรูปแบบป้ายราคา</h2>
    @include('price-tags.form')
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการรูปแบบป้ายราคา</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อป้ายราคา</th><th>แหล่งราคา</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
            <tbody>
            @forelse($templates as $tpl)
                <tr>
                    <td class="fw-semibold">{{ $tpl->code }}</td>
                    <td>{{ $tpl->name }}</td>
                    <td>
                        @switch($tpl->price_source)
                            @case('price_table') ตารางราคา: {{ $tpl->priceTable?->name ?? '-' }} @break
                            @case('flash_sale') ราคานาทีทอง @break
                            @case('no_price') ไม่แสดงราคา @break
                            @default ราคาขายทั่วไป
                        @endswitch
                    </td>
                    <td><span class="badge {{ $tpl->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $tpl->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-light border" href="{{ route('price-tags.edit', $tpl) }}">แก้ไข</a>
                        <form class="d-inline" method="post" action="{{ route('price-tags.destroy', $tpl) }}" data-confirm="ยืนยันลบรูปแบบป้ายราคา {{ $tpl->code }}?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">ลบ</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีรูปแบบป้ายราคา</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $templates->links() }}
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
