@extends('erp-mockup.layout')
@section('title', 'ใบสั่งซื้อ')
@section('content')
<div class="pop-head">
    <div><h1>ใบสั่งซื้อและการตรวจรับ</h1><p>ติดตามเอกสารตามสถานะ</p></div>
    <div class="pop-head-actions">
        <button class="pop-btn primary"><i class="bi bi-plus-lg"></i> สร้างใบขอซื้อ</button>
    </div>
</div>

@include('erp-mockup.partials.toolbar', ['placeholder' => 'ค้นหาเลขที่เอกสาร, ผู้ขาย...', 'active' => 'kanban'])

@php
    // สีหัวคอลัมน์บอกว่าสถานะไหนต้องรีบตาม สถานะที่จบแล้วใช้สีกลาง
    $tones = ['RFQ' => 'grey', 'PO Confirmed' => 'info', 'Waiting Receipt' => 'amber',
              'Partially Received' => 'amber', 'Done' => 'green', 'Cancelled' => 'grey'];
    $labels = ['RFQ' => 'ใบขอซื้อ', 'PO Confirmed' => 'ยืนยันสั่งซื้อ', 'Waiting Receipt' => 'รอตรวจรับ',
               'Partially Received' => 'รับบางส่วน', 'Done' => 'รับครบแล้ว', 'Cancelled' => 'ยกเลิก'];
@endphp

<div style="display:grid;grid-template-columns:repeat(6,minmax(220px,1fr));gap:12px;overflow-x:auto;padding-bottom:8px">
    @foreach($columns as $status => $cards)
        <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span class="pop-badge badge-{{ $tones[$status] ?? 'grey' }}">{{ $labels[$status] ?? $status }}</span>
                <span class="pop-muted">{{ count($cards) }}</span>
            </div>
            @foreach($cards as $card)
                <section class="pop-card" style="padding:12px;margin-bottom:10px">
                    <div style="font-weight:700;font-variant-numeric:tabular-nums">{{ $card['no'] }}</div>
                    <div class="pop-muted" style="margin:3px 0 8px">{{ $card['supplier'] }}</div>
                    <div style="font-size:16px;font-weight:800;margin-bottom:8px">{{ $card['amount'] }}</div>
                    <div class="pop-muted" style="margin-bottom:10px"><i class="bi bi-calendar3"></i> {{ $card['due'] }}</div>
                    @if(in_array($status, ['Waiting Receipt', 'Partially Received'], true))
                        <button class="pop-btn primary" style="width:100%;justify-content:center"><i class="bi bi-box-arrow-in-down"></i> ตรวจรับสินค้า</button>
                    @endif
                </section>
            @endforeach
            @if($cards === [])<div class="pop-muted" style="padding:14px;text-align:center">ไม่มีเอกสาร</div>@endif
        </div>
    @endforeach
</div>
@endsection
