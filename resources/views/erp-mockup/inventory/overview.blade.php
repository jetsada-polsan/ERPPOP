@extends('erp-mockup.layout')
@section('title', 'ภาพรวมคลังสินค้า')
@section('content')
<div class="pop-head">
    <div><h1>ภาพรวมคลังสินค้า</h1><p>สถานะแต่ละคลังและงานที่ค้างอยู่</p></div>
    <div class="pop-head-actions">
        <button class="pop-btn primary"><i class="bi bi-plus-lg"></i> เอกสารใหม่</button>
    </div>
</div>

@include('erp-mockup.partials.toolbar', ['placeholder' => 'ค้นหาคลัง...', 'active' => 'kanban'])

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
    @foreach($cards as $card)
        <section class="pop-card">
            <div class="pop-card-head">
                <div class="pop-kpi-icon tone-{{ $card['tone'] }}" style="width:32px;height:32px;font-size:15px"><i class="bi bi-box-seam"></i></div>
                <div><div>{{ $card['title'] }}</div><div class="pop-muted" style="font-weight:400">{{ $card['sub'] }}</div></div>
            </div>
            <div class="pop-card-body">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;margin-bottom:12px">
                    <div><div class="pop-kpi-value" style="font-size:19px">{{ $card['onhand'] }}</div><div class="pop-muted">คงเหลือ</div></div>
                    <div><div class="pop-kpi-value" style="font-size:19px;{{ $card['low'] ? 'color:var(--pop-amber)' : '' }}">{{ $card['low'] }}</div><div class="pop-muted">สต๊อกต่ำ</div></div>
                    <div><div class="pop-kpi-value" style="font-size:19px;{{ $card['pending'] ? 'color:var(--pop-primary)' : '' }}">{{ $card['pending'] }}</div><div class="pop-muted">เอกสารค้าง</div></div>
                </div>
                <button class="pop-btn" style="width:100%;justify-content:center">{{ $card['action'] }} <i class="bi bi-arrow-right"></i></button>
            </div>
        </section>
    @endforeach
</div>
@endsection
