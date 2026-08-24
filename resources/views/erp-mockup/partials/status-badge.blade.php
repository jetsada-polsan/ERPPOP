@php
    // สถานะซิงค์บิล — สีต้องอ่านออกในแวบเดียวว่าอันไหนต้องตาม
    $map = [
        'synced' => ['badge-green', 'ซิงค์แล้ว', 'bi-check-circle-fill'],
        'pending' => ['badge-amber', 'รอซิงค์', 'bi-clock-fill'],
        'failed' => ['badge-red', 'ซิงค์ล้มเหลว', 'bi-exclamation-triangle-fill'],
        'voided' => ['badge-grey', 'ยกเลิกแล้ว', 'bi-slash-circle'],
        'active' => ['badge-green', 'ใช้งาน', 'bi-check-circle-fill'],
        'low' => ['badge-amber', 'สต๊อกต่ำ', 'bi-exclamation-circle-fill'],
        'out' => ['badge-red', 'หมดสต๊อก', 'bi-x-circle-fill'],
    ];
    [$class, $text, $icon] = $map[$status] ?? ['badge-grey', $status, 'bi-dash-circle'];
@endphp
<span class="pop-badge {{ $class }}"><i class="bi {{ $icon }}"></i> {{ $text }}</span>
