<aside class="pop-side">
    @foreach([
        'เมนูหลัก' => [
            ['erp-mockup.dashboard', 'bi-speedometer2', 'แผงควบคุม'],
            ['erp-mockup.launcher', 'bi-grid-3x3-gap', 'โมดูลทั้งหมด'],
        ],
        'ขาย' => [
            ['erp-mockup.pos-orders', 'bi-shop', 'บิลขายหน้าร้าน'],
            [null, 'bi-receipt', 'คำสั่งขาย'],
            [null, 'bi-people', 'ลูกค้า'],
        ],
        'คลังสินค้า' => [
            ['erp-mockup.inventory', 'bi-boxes', 'ภาพรวมคลัง'],
            ['erp-mockup.products', 'bi-box-seam', 'สินค้า'],
            [null, 'bi-clipboard-data', 'นับสต๊อก'],
            [null, 'bi-truck', 'จัดส่ง / TMS'],
        ],
        'จัดซื้อ' => [
            ['erp-mockup.purchase', 'bi-cart3', 'ใบสั่งซื้อ'],
            [null, 'bi-truck-front', 'ผู้ขาย'],
        ],
        'อื่น ๆ' => [
            [null, 'bi-journal-text', 'สะพานบัญชี'],
            [null, 'bi-bar-chart', 'รายงาน'],
            [null, 'bi-person-badge', 'พนักงาน'],
            [null, 'bi-gear', 'ตั้งค่า'],
        ],
    ] as $group => $items)
        <div class="pop-side-group">{{ $group }}</div>
        @foreach($items as [$route, $icon, $label])
            <a href="{{ $route ? route($route) : '#' }}" class="{{ $route && request()->routeIs($route) ? 'on' : '' }}">
                <i class="bi {{ $icon }}"></i> {{ $label }}
            </a>
        @endforeach
    @endforeach
</aside>
