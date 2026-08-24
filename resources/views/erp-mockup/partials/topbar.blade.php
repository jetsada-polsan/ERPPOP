<header class="pop-top">
    <a href="{{ route('erp-mockup.launcher') }}" class="pop-grid-btn" title="เมนูโมดูล"><i class="bi bi-grid-3x3-gap-fill"></i></a>
    <span class="pop-brand">Popstar ERP</span>
    <nav class="pop-topnav">
        @foreach([
            ['erp-mockup.dashboard', 'หน้าหลัก'],
            ['erp-mockup.pos-orders', 'ขาย'],
            ['erp-mockup.inventory', 'คลังสินค้า'],
            ['erp-mockup.purchase', 'จัดซื้อ'],
            ['erp-mockup.products', 'สินค้า'],
        ] as [$name, $label])
            <a href="{{ route($name) }}" class="{{ request()->routeIs($name) ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>
    <div class="pop-top-right">
        <span><i class="bi bi-bell"></i> 12</span>
        <span>บริษัท ป๊อบสตาร์ จำกัด</span>
        <span class="pop-avatar">JJ</span>
    </div>
</header>
