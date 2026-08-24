@extends('erp-mockup.layout')
@section('title', 'แผงควบคุม')
@section('content')
<div class="pop-head">
    <div><h1>แผงควบคุม</h1><p>ภาพรวมวันนี้ · {{ now()->format('j M Y') }}</p></div>
    <div class="pop-head-actions">
        <button class="pop-btn"><i class="bi bi-arrow-clockwise"></i> รีเฟรช</button>
        <button class="pop-btn"><i class="bi bi-calendar3"></i> ช่วงเวลา: วันนี้</button>
        <button class="pop-btn on"><i class="bi bi-star-fill"></i> ค้นหน้า</button>
    </div>
</div>

@include('erp-mockup.partials.kpi-cards', ['items' => $kpis])

<div style="display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:14px;margin-bottom:14px">
    <section class="pop-card">
        <div class="pop-card-head">ยอดขายแยกตามสาขา<span class="spacer">วันนี้</span></div>
        <div class="pop-card-body">
            @php $max = max(array_column($branches, 'sales')); @endphp
            @foreach($branches as $branch)
                <div class="pop-bar-row">
                    <span>{{ $branch['name'] }}</span>
                    <span class="pop-bar-track"><span class="pop-bar-fill" style="width:{{ round($branch['sales'] / $max * 100) }}%"></span></span>
                    <span class="num" style="font-weight:700">{{ number_format($branch['sales']) }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="pop-card">
        <div class="pop-card-head">10 สินค้าขายดี<span class="spacer">วันนี้</span></div>
        <div class="pop-card-body" style="padding:0">
            <table class="pop-table">
                <tbody>
                @foreach($topProducts as $index => $product)
                    <tr>
                        <td style="width:26px;color:var(--pop-muted)">{{ $index + 1 }}.</td>
                        <td>{{ $product['name'] }}</td>
                        <td class="num pop-muted">{{ $product['qty'] }}</td>
                        <td class="num" style="font-weight:700">{{ $product['amount'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="pop-card">
        <div class="pop-card-head">การแจ้งเตือน</div>
        <div class="pop-card-body">
            @foreach($warnings as $warning)
                <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f2f4f7">
                    <div class="pop-kpi-icon tone-{{ $warning['tone'] }}" style="width:30px;height:30px;font-size:14px"><i class="bi bi-info-circle-fill"></i></div>
                    <div style="flex:1">
                        <div style="font-weight:600">{{ $warning['title'] }}</div>
                        <div class="pop-muted">{{ $warning['sub'] }}</div>
                    </div>
                    <div class="pop-muted" style="white-space:nowrap">{{ $warning['time'] }}</div>
                </div>
            @endforeach
        </div>
    </section>
</div>

<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:14px">
    <section class="pop-card">
        <div class="pop-card-head">บิลขายล่าสุด<span class="spacer">POS ทุกสาขา</span></div>
        <table class="pop-table">
            <thead><tr><th>เลขที่บิล</th><th>สาขา</th><th>POS</th><th>แคชเชียร์</th><th class="num">ยอดรวม</th><th>ชำระ</th><th>ซิงค์</th><th>เวลา</th></tr></thead>
            <tbody>
            @foreach($posOrders as $order)
                <tr>
                    <td style="font-variant-numeric:tabular-nums">{{ $order['receipt'] }}</td>
                    <td>{{ $order['branch'] }}</td><td>{{ $order['terminal'] }}</td><td>{{ $order['cashier'] }}</td>
                    <td class="num">{{ $order['total'] }}</td><td>{{ $order['method'] }}</td>
                    <td>@include('erp-mockup.partials.status-badge', ['status' => $order['sync']])</td>
                    <td class="pop-muted">{{ $order['time'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="pop-card">
        <div class="pop-card-head">กิจกรรมล่าสุด</div>
        <div class="pop-card-body">
            @foreach($activities as $activity)
                <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f2f4f7">
                    <div class="pop-avatar" style="background:var(--pop-primary-soft);color:var(--pop-primary)">{{ mb_substr($activity['who'], 0, 2) }}</div>
                    <div style="flex:1">
                        <div><strong>{{ $activity['who'] }}</strong> {{ $activity['what'] }}</div>
                        <div class="pop-muted">{{ $activity['detail'] }} · {{ $activity['time'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
