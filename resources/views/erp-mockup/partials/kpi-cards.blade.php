<div class="pop-kpis">
    @foreach($items as $kpi)
        <div class="pop-kpi">
            <div class="pop-kpi-icon tone-{{ $kpi['tone'] }}"><i class="bi {{ $kpi['icon'] }}"></i></div>
            <div>
                <div class="pop-kpi-label">{{ $kpi['label'] }}</div>
                <div class="pop-kpi-value">{{ $kpi['value'] }}</div>
                <div class="pop-kpi-sub">
                    {{ $kpi['sub'] }}
                    @if($kpi['delta'])<span class="delta-up">▲ {{ $kpi['delta'] }}</span>@endif
                </div>
            </div>
        </div>
    @endforeach
</div>
