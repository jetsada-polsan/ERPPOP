{{-- ลูกศรของ dropdown: สไตล์หลายหน้าตั้ง background แบบ shorthand ซึ่งลบลูกศรของ Bootstrap ทิ้ง --}}
<style>
    select:not([multiple]):not([size]) {
        -webkit-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%235b6b7a' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        background-repeat: no-repeat !important;
        background-position: right 8px center !important;
        background-size: 12px 10px !important;
        padding-right: 28px !important;
    }
    select.topbar-select:not([multiple]):not([size]) {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
    }
    select:not([multiple]):not([size]) option { color: #1f2d3a; background: #fff; }
</style>
