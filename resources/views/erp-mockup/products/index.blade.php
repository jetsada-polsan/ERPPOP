@extends('erp-mockup.layout')
@section('title', 'สินค้า')
@section('content')
<div class="pop-head">
    <div><h1>สินค้า</h1><p>{{ count($products) }} รายการ</p></div>
    <div class="pop-head-actions">
        <button class="pop-btn primary"><i class="bi bi-plus-lg"></i> สร้าง</button>
        <button class="pop-btn"><i class="bi bi-upload"></i> นำเข้า</button>
    </div>
</div>

@include('erp-mockup.partials.toolbar', ['placeholder' => 'ค้นหาสินค้า, บาร์โค้ด, รหัส...'])

<section class="pop-card">
    <table class="pop-table">
        <thead><tr>
            <th style="width:34px"><input type="checkbox"></th>
            <th>บาร์โค้ด</th><th>รหัสสินค้า</th><th>ชื่อสินค้า</th><th>หมวด</th><th>หน่วย</th>
            <th class="num">ราคาขาย</th><th class="num">ต้นทุน</th><th class="num">คงเหลือ</th><th>สถานะ</th>
        </tr></thead>
        <tbody>
        @foreach($products as $product)
            <tr>
                <td><input type="checkbox"></td>
                <td style="font-variant-numeric:tabular-nums">{{ $product['barcode'] }}</td>
                <td><a href="{{ route('erp-mockup.product-form') }}" style="color:var(--pop-primary);font-weight:600">{{ $product['code'] }}</a></td>
                <td>{{ $product['name'] }}</td>
                <td class="pop-muted">{{ $product['category'] }}</td>
                <td>{{ $product['unit'] }}</td>
                <td class="num">{{ $product['price'] }}</td>
                <td class="num pop-muted">{{ $product['cost'] }}</td>
                <td class="num">{{ $product['stock'] }}</td>
                <td>@include('erp-mockup.partials.status-badge', ['status' => $product['status']])</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endsection
