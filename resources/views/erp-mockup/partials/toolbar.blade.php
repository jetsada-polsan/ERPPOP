{{-- แถบเครื่องมือแบบ ERP: ค้นหา / ตัวกรอง / จัดกลุ่ม / รายการโปรด / สลับมุมมอง --}}
<div class="pop-toolbar">
    <div class="grow"><input placeholder="{{ $placeholder ?? 'ค้นหา...' }}"></div>
    <button class="pop-btn"><i class="bi bi-funnel"></i> ตัวกรอง</button>
    <button class="pop-btn"><i class="bi bi-diagram-3"></i> จัดกลุ่ม</button>
    <button class="pop-btn"><i class="bi bi-star"></i> รายการโปรด</button>
    <div class="pop-viewswitch">
        @foreach(($views ?? ['list' => 'bi-list-ul', 'kanban' => 'bi-kanban', 'graph' => 'bi-bar-chart', 'pivot' => 'bi-table']) as $key => $icon)
            <button class="{{ ($active ?? 'list') === $key ? 'on' : '' }}" title="{{ $key }}"><i class="bi {{ $icon }}"></i></button>
        @endforeach
    </div>
</div>
