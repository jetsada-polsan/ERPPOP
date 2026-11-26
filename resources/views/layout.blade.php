@php
    // เมนูทั้งหมดอยู่ใน App\Support\ErpMenu — เรียงลำดับและกรองสิทธิ์มาให้แล้ว
    $menuSections = \App\Support\ErpMenu::forUser(auth()->user());

    $toneClass = [
        'blue' => 'menu-icon-blue',
        'cyan' => 'menu-icon-cyan',
        'orange' => 'menu-icon-orange',
        'indigo' => 'menu-icon-indigo',
        'brown' => 'menu-icon-brown',
        'pink' => 'menu-icon-pink',
        'red' => 'menu-icon-red',
        'teal' => 'menu-icon-teal',
        'slate' => 'menu-icon-slate',
        'amber' => 'menu-icon-amber',
    ];
    $fallbackFavicon = \App\Support\AssetVersion::url('images/logos/mpm-food-group.jpg');
    $companyName = \App\Models\AppSetting::company('name_th') ?: 'กิจการของคุณ';
    $systemName = 'JET ERP';
    $companyLogo = \App\Models\AppSetting::logoUrl();
    $faviconPath = \App\Models\AppSetting::get('favicon_path');
    $faviconUrl = $faviconPath
        ? \App\Support\AssetVersion::url($faviconPath)
        : ($companyLogo ?: $fallbackFavicon);
    $erpTheme = in_array(\App\Models\AppSetting::get('erp_theme', 'ocean'), ['ocean', 'navy', 'emerald', 'slate', 'clear'], true)
        ? \App\Models\AppSetting::get('erp_theme', 'ocean') : 'ocean';
    $erpLayout = in_array(\App\Models\AppSetting::get('erp_layout', 'classic'), ['classic', 'odoo'], true)
        ? \App\Models\AppSetting::get('erp_layout', 'classic') : 'classic';
@endphp
<!DOCTYPE html>
<html lang="th" data-theme="{{ $erpTheme }}" data-layout="{{ $erpLayout }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="view-transition" content="same-origin">
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>{{ trim($__env->yieldContent('title', 'หน้าหลัก')) }} · JET ERP</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- โหลดฟอนต์แบบไม่บล็อกการวาดหน้า: หน้าแสดงด้วยฟอนต์ระบบก่อน แล้วสลับเมื่อฟอนต์มาถึง --}}
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('vendor/adminlte/css/adminlte.min.css') }}">
    {{-- Tailwind แบบ build นิ่ง (แทน runtime compiler เดิมที่คอมไพล์สดทุกหน้า)
         เพิ่มคลาสใหม่แล้วให้ rebuild: ดูคำสั่งใน tailwind.config.js --}}
    <link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('vendor/tailwindcss/tailwind.min.css') }}">
    <script src="{{ \App\Support\AssetVersion::url('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script defer src="{{ \App\Support\AssetVersion::url('vendor/alpinejs/alpine.min.js') }}"></script>
    <link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/erp-layout.css') }}">
    @stack('head')
    {{-- Page-specific styles must be emitted in the document head. --}}
    @stack('styles')
    {{-- อ่านค่าที่ผู้ใช้ตั้งไว้ก่อนหน้าจอถูกวาด ไม่งั้นจะเห็นขนาดเดิมแวบหนึ่งแล้วค่อยกระโดด --}}
    <script>
    (function () {
        try {
            var p = JSON.parse(localStorage.getItem('erp-display') || '{}');
            var r = document.documentElement;
            if (p.uiScale)   { r.style.setProperty('--ui-scale', p.uiScale); }
            if (p.menuScale) { r.style.setProperty('--menu-scale', p.menuScale); }
            if (p.font)      { r.style.setProperty('--erp-font-family', p.font); }
            if (p.theme)     { r.setAttribute('data-theme', p.theme); }
        } catch (e) { /* localStorage ปิดอยู่ก็ใช้ค่ากลางของบริษัทไป */ }
    })();
    </script>
    <link id="erp-ui-standard" rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/erp-ui-standard.css') }}">
@include('partials.select-arrow')
@include('partials.peak-style-ui')
</head>
<body class="layout-fixed sidebar-expand-lg {{ request()->boolean('popup') ? 'erp-popup-page' : '' }} {{ request()->routeIs(['bookings.*','cash-sales.*','sales.*','purchases.*','purchase-orders.*','sale-returns.*','credit-debit-notes.*','stock-issues.*','stock-transfers.*','stock-transforms.*','stock-adjustments.*','stock-counts.*']) ? 'erp-classic-document-page' : '' }}">
    <div class="app-wrapper">
        @php
            $railIcons = array_merge([
                'ภาพรวม' => 'bi-house-door-fill',
                'งานประจำวัน' => 'bi-cash-coin',
                'POS / หน้าร้าน' => 'bi-shop-window',
                'คลัง / ผลิต / ซื้อ' => 'bi-box-seam-fill',
                'การเงิน / บัญชี' => 'bi-calculator-fill',
                'ข้อมูลตั้งต้น' => 'bi-people-fill',
                'เชื่อมต่อ' => 'bi-plug-fill',
                'ระบบ' => 'bi-gear-fill',
                'รายงาน' => 'bi-clipboard-data-fill',
            ], \App\Support\ErpMenu::SECTION_ICONS);
            $activeSection = 0;
            foreach ($menuSections as $i => $section) {
                foreach ($section['items'] as $item) {
                    if (request()->routeIs($item['pattern']) || (isset($item['extraPattern']) && request()->routeIs($item['extraPattern']))) {
                        $activeSection = $i;
                        break 2;
                    }
                }
            }
            $appLogo = $companyLogo ?: (is_file(public_path('images/logos/mpm-food-group.jpg')) ? \App\Support\AssetVersion::url('images/logos/mpm-food-group.jpg') : null);
        @endphp
@if (!request()->boolean('popup'))
        {{-- แถบบนชุดเดียวของระบบ: ล็อกโลโก้มุมซ้ายบน + หมวดเมนูหลัก --}}
        <div class="odn-topbar no-print">
            <div class="odn-brand-slot">
                <a href="{{ route('apps.launcher') }}" class="odn-apps" title="รวมโมดูลทั้งหมด" aria-label="รวมโมดูลทั้งหมด">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </a>
                <a href="{{ route('dashboard') }}" class="odn-brand" id="erp-main-logo">
                    @if($appLogo)
                        <span class="odn-logo-box"><img src="{{ $appLogo }}" alt="{{ $systemName }}"></span>
                    @endif
                </a>
            </div>
            <nav class="odn-nav" aria-label="หมวดเมนู">
                @foreach ($menuSections as $i => $section)
                    <a href="#odn-grp-{{ $i }}" class="odn-nav-link {{ $i === $activeSection ? 'on' : '' }}"
                       data-sec="{{ $i }}"><i class="bi {{ $railIcons[$section['label']] ?? 'bi-grid-fill' }}"></i><span>{{ $section['displayLabel'] ?? $section['label'] }}</span></a>
                @endforeach
            </nav>
            <span class="odn-sysname" aria-hidden="true">{{ $systemName }}</span>
        </div>
@endif
        <nav class="app-header navbar navbar-expand bg-white">
            <div class="container-fluid px-3">
                <ul class="navbar-nav align-items-center erp-page-heading">
                    <li class="nav-item">
                        <a class="nav-link fs-5 px-2" href="#" role="button" aria-label="Toggle sidebar"
                            onclick="event.preventDefault(); document.documentElement.classList.toggle('mobile-sidebar-open')">
                            <i class="bi bi-list"></i>
                        </a>
                    </li>
                    <li class="nav-item ms-1 erp-page-title">
                        <div class="d-flex align-items-center">
                            <span class="page-title-icon"><i class="bi bi-bar-chart-line-fill"></i></span>
                            <div>
                                <h1 class="fw-bold mb-0">@yield('page-title', trim($__env->yieldContent('title', 'MPM Food Group')))</h1>
                                <div class="text-muted small">@yield('page-subtitle', 'ภาพรวมธุรกิจ สต็อก และงานที่ต้องจัดการ')</div>
                            </div>
                        </div>
                    </li>
                </ul>

                {{-- ค้นหาเมนู: กรองรายการในเมนูข้างแบบสด ๆ --}}
                <div class="odn-search">
                    <label for="odn-q" class="visually-hidden">ค้นหาเมนู</label>
                    <input id="odn-q" type="search" autocomplete="off" placeholder="ค้นหาเมนู…"
                           aria-controls="odn-side">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>

                <ul class="navbar-nav align-items-center gap-2 ms-2">
                    {{-- กระดิ่งแจ้งเตือน --}}
                    <li class="nav-item" x-data="notifyBell()" x-init="load()">
                        <div class="position-relative">
                            <button type="button" class="btn btn-light border rounded-circle position-relative" style="width:38px;height:38px;padding:0" @click="open = !open; if (open) load()" aria-label="แจ้งเตือน">
                                <i class="bi bi-bell-fill" style="color:var(--erp-muted)"></i>
                                <span x-show="total > 0" x-cloak class="notify-badge" x-text="total > 99 ? '99+' : total"></span>
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false" class="notify-panel">
                                <div class="notify-head">
                                    <span class="fw-bold">แจ้งเตือนของฉัน</span>
                                    <span class="text-muted small" x-show="items.length" x-text="total + ' เรื่อง'"></span>
                                </div>
                                <template x-for="item in items" :key="item.label">
                                    <a :href="item.url" class="notify-item">
                                        <span class="notify-icon" :style="'background:' + item.color + '1a; color:' + item.color"><i class="bi" :class="item.icon"></i></span>
                                        <span class="notify-label" x-text="item.label"></span>
                                        <span class="notify-count" :style="'background:' + item.color" x-text="item.count"></span>
                                    </a>
                                </template>
                                <div x-show="!items.length" class="text-center text-muted small py-4">
                                    <i class="bi bi-check2-circle d-block fs-4 mb-1" style="color:var(--erp-success-ink)"></i>ไม่มีเรื่องค้าง
                                </div>
                            </div>
                        </div>
                    </li>

                    {{-- ไอคอนผู้ใช้ + Dropdown รวมข้อมูลผู้ใช้ บริษัท คู่มือ 4M ปรับหน้าจอ และออกจากระบบ --}}
                    <li class="nav-item" x-data="{ userOpen: false }">
                        <div class="position-relative">
                            <button type="button" class="erp-user-trigger" id="erp-user-menu-btn"
                                    @click="userOpen = !userOpen" :aria-expanded="userOpen.toString()" aria-label="เมนูผู้ใช้">
                                <span class="profile-avatar" style="width:30px;height:30px;border-radius:50%;font-size:12px">{{ mb_substr(auth()->user()?->name ?? 'ผู้ใช้', 0, 2) }}</span>
                                <i class="bi bi-chevron-down small text-muted"></i>
                            </button>

                            <div x-show="userOpen" x-cloak @click.outside="userOpen = false" class="erp-user-dropdown" id="erp-user-dropdown">
                                <div class="erp-user-dropdown-head">
                                    <div class="erp-user-dropdown-name">{{ auth()->user()?->name ?? '-' }}</div>
                                    <div class="erp-user-dropdown-role">{{ auth()->user()?->roles?->first()?->name ?? 'ผู้ใช้งาน' }}</div>
                                    <div class="erp-user-dropdown-company">
                                        <i class="bi bi-building"></i>
                                        <span>{{ $companyName }}</span>
                                    </div>
                                </div>
                                <div class="py-1">
                                    @if(!\App\Support\MenuVisibility::blocked('core-modules.index') && auth()->user()?->accountingFirmCanOpen('core-modules.index'))
                                    <a href="{{ route('core-modules.index') }}" class="erp-user-dropdown-item">
                                        <i class="bi bi-book"></i>
                                        <span>คู่มือการใช้งาน</span>
                                    </a>
                                    @endif
                                    <button type="button" class="erp-user-dropdown-item"
                                            id="erp-display-btn" title="ปรับขนาดและสีหน้าจอ" aria-expanded="false" aria-controls="erp-display-panel"
                                            @click="userOpen = false">
                                        <i class="bi bi-sliders"></i>
                                        <span>ปรับขนาดและสีหน้าจอ</span>
                                    </button>
                                </div>
                                <div class="erp-user-dropdown-divider"></div>
                                <div class="py-1">
                                    <form method="post" action="{{ route('logout') }}" class="m-0"
                                          data-confirm="ต้องการออกจากระบบใช่หรือไม่?" data-confirm-ok="ออกจากระบบ" data-confirm-icon="question">
                                        @csrf
                                        <button type="submit" class="erp-user-dropdown-item text-danger">
                                            <i class="bi bi-box-arrow-right"></i>
                                            <span>ออกจากระบบ</span>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            {{-- แผงปรับหน้าจอเฉพาะเครื่องนี้ --}}
                            <div class="erp-display-panel" id="erp-display-panel" hidden>
                                <div class="edp-head">
                                    <span class="fw-bold">ปรับหน้าจอ</span>
                                    <span class="edp-note">เฉพาะเครื่องนี้</span>
                                </div>

                                <div class="edp-row">
                                    <label for="edp-ui">ขนาดตัวอักษร</label>
                                    <div class="edp-seg" role="group" data-pref="uiScale">
                                        <button type="button" data-v="0.85">เล็กมาก</button>
                                        <button type="button" data-v="0.92">เล็ก</button>
                                        <button type="button" data-v="1">ปกติ</button>
                                        <button type="button" data-v="1.12">ใหญ่</button>
                                        <button type="button" data-v="1.25">ใหญ่มาก</button>
                                    </div>
                                </div>

                                <div class="edp-row">
                                    <label>ขนาดเมนู</label>
                                    <div class="edp-seg" role="group" data-pref="menuScale">
                                        <button type="button" data-v="0.85">แคบ</button>
                                        <button type="button" data-v="1">ปกติ</button>
                                        <button type="button" data-v="1.15">กว้าง</button>
                                        <button type="button" data-v="1.3">กว้างมาก</button>
                                    </div>
                                </div>

                                <div class="edp-row">
                                    <label for="edp-font">แบบอักษร</label>
                                    <select id="edp-font" data-pref="font">
                                        <option value="">ค่ากลางของบริษัท</option>
                                        <option value="'Sarabun','Noto Sans Thai',sans-serif">Sarabun</option>
                                        <option value="'Noto Sans Thai','Segoe UI',sans-serif">Noto Sans Thai</option>
                                        <option value="'Leelawadee UI',Tahoma,sans-serif">Leelawadee UI</option>
                                        <option value="'IBM Plex Sans Thai',sans-serif">IBM Plex Sans Thai</option>
                                    </select>
                                </div>

                                <div class="edp-row">
                                    <label for="edp-theme">โทนสี</label>
                                    <select id="edp-theme" data-pref="theme">
                                        <option value="">ค่ากลางของบริษัท ({{ $erpTheme }})</option>
                                        <option value="ocean">ฟ้า JET</option>
                                        <option value="clear">ฟ้าสด</option>
                                        <option value="navy">น้ำเงินเข้ม</option>
                                        <option value="emerald">เขียว</option>
                                        <option value="slate">เทา</option>
                                    </select>
                                </div>

                                <div class="edp-foot">
                                    <button type="button" id="edp-reset" class="edp-reset">คืนค่าเริ่มต้น</button>
                                    <span class="edp-saved" id="edp-saved" hidden>บันทึกแล้ว</span>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        {{-- เมนูข้างชุดเดียว: แสดงเฉพาะเมนูย่อยของหมวดที่เลือก (ไม่มีโลโก้ซ้ำและไม่มี rail ซ้อน) --}}
        <aside class="app-sidebar odn-side" id="odn-side">
            @foreach ($menuSections as $i => $section)
                <div class="odn-grp {{ $i === $activeSection ? 'is-visible' : '' }}" id="odn-grp-{{ $i }}" data-sec="{{ $i }}">
                    <button type="button" class="odn-grp-head" aria-expanded="true">
                        <i class="bi {{ $railIcons[$section['label']] ?? 'bi-grid-fill' }}"></i>
                        <span>{{ $section['displayLabel'] ?? $section['label'] }}</span>
                        <i class="bi bi-chevron-down odn-caret" aria-hidden="true"></i>
                    </button>
                    <div class="odn-grp-body">
                        @php $lastGroup = null; @endphp
                        @foreach ($section['items'] as $item)
                            @php
                                $itemGroup = $item['group'] ?? null;
                                $showGroupHead = $itemGroup !== null && $itemGroup !== $lastGroup;
                                $lastGroup = $itemGroup;
                                $active = request()->routeIs($item['pattern']) || (isset($item['extraPattern']) && request()->routeIs($item['extraPattern']));
                                if ($active && $item['route'] === 'products.index' && request()->routeIs('products.scheduled-prices.*')) {
                                    $active = false;
                                }
                                if ($active && array_key_exists('queryCategory', $item)) {
                                    $active = request('category') === $item['queryCategory'];
                                } elseif ($active && $item['route'] === 'reports.index' && ($item['params'] ?? []) === []) {
                                    $active = !request()->filled('category');
                                }
                                $mark = \App\Support\AppMark::forItem($i, $loop->index, $item['tone'] ?? 'blue');
                            @endphp
                            @if ($showGroupHead)
                                <div class="odn-subgrp-head">{{ $itemGroup }}</div>
                            @endif
                            <a href="{{ route($item['route'], $item['params'] ?? []) }}"
                               class="odn-item t-{{ $item['tone'] ?? 'blue' }} {{ $active ? 'active' : '' }}"
                               data-s="{{ mb_strtolower($item['label'].' '.($itemGroup ?? '')) }}"
                               @if(isset($item['target'])) target="{{ $item['target'] }}" @endif>
                                <span class="odn-item-ico" style="--m1:{{ $mark['m1'] }};--m2:{{ $mark['m2'] }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">{!! $mark['svg'] !!}</svg>
                                </span><span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <p class="odn-noresult" hidden>ไม่พบเมนูที่ตรงกับคำค้น</p>
        </aside>

        {{-- เลื่อนแตะเพื่อปิด sidebar บนจอมือถือ (แสดงเฉพาะตอน sidebar เปิดอยู่) --}}
        <div class="mobile-sidebar-backdrop" onclick="document.documentElement.classList.remove('mobile-sidebar-open')"></div>

        {{-- ปุ่มพับ/กางแผงเมนูย่อย --}}
        <button type="button" class="fa-collapse-btn no-print" title="พับ/กางเมนู"
            onclick="document.documentElement.classList.toggle('subnav-collapsed'); localStorage.setItem('erp-subnav', document.documentElement.classList.contains('subnav-collapsed') ? 'closed' : 'open')">
            <i class="bi" id="fa-collapse-icon"></i>
        </button>

        <main class="app-main">
            <div class="erp-print-head">
                <div>@if(\App\Models\AppSetting::logoUrl())<img src="{{ \App\Models\AppSetting::logoUrl() }}" alt="">@endif</div>
                <div><strong>{{ \App\Models\AppSetting::company('name_th') }}</strong> · @yield('page-title') · {{ now()->format('d/m/Y H:i') }}</div>
            </div>
            <div class="app-content">
                <div class="container-fluid px-0">
                    @if(request()->attributes->get('closed_menu'))<div class="alert alert-warning" role="status">เมนูนี้ปิดอยู่</div>@endif
                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    <script src="{{ \App\Support\AssetVersion::url('vendor/adminlte/js/adminlte.min.js') }}"></script>
    <script>
        // ── จำสถานะพับ/กางแผงเมนูย่อย + อัปเดตทิศลูกศร ─────────────────
        (function() {
            const root = document.documentElement;
            if (localStorage.getItem('erp-subnav') === 'closed') root.classList.add('subnav-collapsed');
            const icon = document.getElementById('fa-collapse-icon');
            const sync = () => icon.className = 'bi ' + (root.classList.contains('subnav-collapsed') ? 'bi-chevron-right' : 'bi-chevron-left');
            sync();
            new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class'] });
        })();

        // ── POPSTAR popup helpers. Existing Swal.fire() calls inherit this skin. ──
        (function () {
            if (!window.Swal || window.Swal.__popstarPatched) return;

            const baseFire = window.Swal.fire.bind(window.Swal);
            const classes = {
                popup: 'erp-swal-popup',
                title: 'erp-swal-title',
                htmlContainer: 'erp-swal-html',
                actions: 'erp-swal-actions',
                confirmButton: 'erp-swal-confirm',
                cancelButton: 'erp-swal-cancel',
            };

            function normalize(options) {
                const config = typeof options === 'object' && options !== null ? { ...options } : options;
                if (typeof config !== 'object' || config === null) return config;

                const customClass = { ...classes, ...(config.customClass || {}) };
                if (config.toast) {
                    customClass.popup = [classes.popup, 'erp-swal-toast', config.customClass?.popup].filter(Boolean).join(' ');
                }

                return {
                    buttonsStyling: false,
                    confirmButtonText: 'ตกลง',
                    cancelButtonText: 'ยกเลิก',
                    showClass: { popup: 'swal2-show' },
                    hideClass: { popup: 'swal2-hide' },
                    timerProgressBar: config.toast ? true : config.timerProgressBar,
                    ...config,
                    customClass,
                };
            }

            window.Swal.fire = function (...args) {
                if (args.length === 1) return baseFire(normalize(args[0]));
                if (args.length > 1) {
                    return baseFire(normalize({
                        title: args[0],
                        html: args[1],
                        icon: args[2],
                    }));
                }
                return baseFire();
            };
            window.Swal.__popstarPatched = true;

            window.erpToast = (icon, title, options = {}) => window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon,
                title,
                timer: icon === 'error' ? 4000 : 1200,
                showConfirmButton: false,
                ...options,
            });

            window.erpPopup = (icon, title, text, options = {}) => window.Swal.fire({
                icon,
                title,
                text,
                ...options,
            });

            function detectTone(title, text, okText) {
                const combined = `${title || ''} ${text || ''} ${okText || ''}`;
                if (/ลบ|ยกเลิก|void|คืนเงิน|ปิดงวด|ล้างข้อมูล|ทำลาย|ปฏิเสธ|delete|destroy|cancel|remove|reject/i.test(combined)) {
                    return 'danger';
                }
                if (/อนุมัติ|ปรับสต็อก|โอน|ปิดกะ|แทนที่|ยืนยันใบจอง|approve|transfer/i.test(combined)) {
                    return 'warning';
                }
                if (/รับเงิน|รับของ|ตรวจรับ|complete|receive/i.test(combined)) {
                    return 'success';
                }
                return 'primary';
            }

            window.erpConfirm = (title, text, options = {}) => {
                if (typeof text === 'object' && text !== null && !Array.isArray(text)) {
                    options = text;
                    text = options.text || '';
                }
                const okText = options.confirmButtonText || 'ยืนยัน';
                const tone = options.tone || detectTone(title, text, okText);
                const defaultIcons = {
                    danger: 'warning',
                    warning: 'warning',
                    primary: 'question',
                    success: 'success',
                };
                const icon = options.icon || defaultIcons[tone] || 'question';
                const isDanger = tone === 'danger';

                return window.Swal.fire({
                    title,
                    text,
                    icon,
                    showCancelButton: true,
                    confirmButtonText: okText,
                    cancelButtonText: options.cancelButtonText || 'ยกเลิก',
                    reverseButtons: true, // Cancel on left, Confirm on right
                    allowOutsideClick: true,
                    allowEscapeKey: true,
                    focusCancel: options.focusCancel !== undefined ? options.focusCancel : isDanger,
                    customClass: {
                        popup: `erp-swal-popup erp-swal-tone-${tone}`,
                        confirmButton: `erp-swal-confirm erp-swal-confirm-${tone}`,
                        cancelButton: 'erp-swal-cancel',
                        ...(options.customClass || {}),
                    },
                    ...options,
                });
            };
        })();

        // ── แจ้ง validation ฝั่งหน้าเว็บ ──
        // ใช้แทน HTML5 required: ถ้า required ว่าง เบราว์เซอร์จะบล็อก submit ตั้งแต่ก่อน
        // เกิด submit event ทำให้ handler ของหน้าไม่ทำงานและผู้ใช้ไม่เห็นข้อความอะไรเลย.
        // ประกาศไว้นอก IIFE ข้างบนเพื่อให้มีเสมอ แม้ SweetAlert โหลดไม่สำเร็จ.
        window.erpStopSubmit = function (event, problems) {
            if (! Array.isArray(problems) || problems.length === 0) return false;

            event.preventDefault();
            try {
                if (window.erpPopup) {
                    window.erpPopup('warning', 'ยังบันทึกไม่ได้', null, {
                        icon: 'warning',
                        html: problems.map((problem) => '• ' + problem).join('<br>'),
                    });
                } else {
                    window.alert('ยังบันทึกไม่ได้\n\n' + problems.join('\n'));
                }
            } catch (error) {
                window.alert('ยังบันทึกไม่ได้\n\n' + problems.join('\n'));
            }

            return true;
        };

        // กระดิ่งแจ้งเตือนตามหน้าที่ (header) - โหลดตอนเปิดหน้า + รีเฟรชตอนกดกระดิ่ง
        function notifyBell() {
            return {
                open: false,
                items: [],
                total: 0,
                async load() {
                    try {
                        const res = await fetch('{{ route('notifications.index') }}', { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const data = await res.json();
                        this.items = data.items || [];
                        this.total = data.total || 0;
                    } catch (e) { /* เงียบไว้ - แจ้งเตือนไม่ใช่งานหลัก */ }
                },
            };
        }
    </script>
    <script src="{{ \App\Support\AssetVersion::url('stock-preview.js') }}"></script>
    <x-confirm-action />
    @stack('scripts')

    @if(isset($errors) && $errors->any())
    <script>
        erpPopup('error', 'บันทึกไม่สำเร็จ', null, {
            icon: 'error',
            html: @json(implode('<br>', $errors->all())),
        });
    </script>
    @endif
    @if(session('document_draft_saved'))
    <script>
        try {
            const pendingKey = 'erp:draft:pending:' + @js((string) auth()->id());
            const raw = localStorage.getItem(pendingKey) || sessionStorage.getItem(pendingKey);
            const draft = JSON.parse(raw || 'null');
            if (draft?.action === @js(session('document_draft_saved'))) {
                localStorage.removeItem(draft.key);
                sessionStorage.removeItem(draft.key);
                localStorage.removeItem(pendingKey);
                sessionStorage.removeItem(pendingKey);
            }
        } catch (_) {}
    </script>
    @endif
    @if(session('success'))
    <script>
        @if(session('success_popup'))
        erpPopup('success', 'สำเร็จ', @json(session('success')), {
            timer: 1500,
            timerProgressBar: true,
        });
        @else
        erpToast('success', @json(session('success')), { timer: 1200 });
        @endif
    </script>
    @endif
    @if(session('error'))
    <script>
        erpPopup('error', 'ทำรายการไม่สำเร็จ', @json(session('error')));
    </script>
    @endif
<script>
/* ค้นหาเมนูและพับหมวด — ทำงานเฉพาะ layout Odoo ที่มี #odn-side อยู่จริง */
(function () {
    var side = document.getElementById('odn-side');
    var input = document.getElementById('odn-q');
    if (!side) { return; }

    side.querySelectorAll('.odn-grp-head').forEach(function (head) {
        head.addEventListener('click', function () {
            var grp = head.closest('.odn-grp');
            var collapsed = grp.classList.toggle('collapsed');
            head.setAttribute('aria-expanded', String(!collapsed));
        });
    });

    var items = Array.prototype.slice.call(side.querySelectorAll('.odn-item'));
    var groups = Array.prototype.slice.call(side.querySelectorAll('.odn-grp'));
    var empty = side.querySelector('.odn-noresult');
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('.odn-nav-link'));
    var launcher = document.getElementById('erp-app-launcher');

    /* โมดูลที่กำลังเปิดอยู่ — ต้องจำไว้ เพราะระหว่างค้นหาเราเปิดหลายหมวดชั่วคราว
       พอล้างคำค้นต้องกลับมาเหลือหมวดเดียวเหมือนเดิม ไม่งั้นเมนูรกกลับมาอีก */
    var activeGroup = side.querySelector('.odn-grp.is-visible') || groups[0] || null;

    function markActiveNav(link) {
        navLinks.forEach(function (other) {
            var selected = other === link;
            other.classList.toggle('on', selected);
            if (selected) {
                other.setAttribute('aria-current', 'page');
            } else {
                other.removeAttribute('aria-current');
            }
        });
    }

    function apply() {
        var q = input ? input.value.trim().toLowerCase() : '';
        var shown = 0;
        items.forEach(function (a) {
            var hit = !q || a.dataset.s.indexOf(q) !== -1;
            a.hidden = !hit;
            a.classList.remove('hit');
            if (hit) { shown++; }
        });
        groups.forEach(function (g) {
            var any = g.querySelector('.odn-item:not([hidden])');
            g.hidden = !any;
            if (q) {
                /* ระหว่างค้นหา เปิดทุกหมวดที่มีผลลัพธ์ เพื่อให้ค้นข้ามโมดูลได้ */
                if (any) { g.classList.add('is-visible'); }
                g.classList.remove('collapsed');
            } else {
                /* ล้างคำค้นแล้ว กลับไปเหลือเฉพาะโมดูลที่เลือกอยู่ */
                g.classList.toggle('is-visible', g === activeGroup);
                g.hidden = false;
            }
        });
        if (empty) { empty.hidden = shown !== 0; }
        var first = side.querySelector('.odn-item:not([hidden])');
        if (q && first) { first.classList.add('hit'); }
    }

    function showGroup(target, link) {
        if (!target) { return; }
        groups.forEach(function (group) {
            group.classList.toggle('is-visible', group === target);
            group.hidden = false;
        });
        target.classList.remove('collapsed');
        activeGroup = target;
        if (input) { input.value = ''; }
        apply();
        markActiveNav(link || null);

        /* หน้า App Launcher ซ่อน sidebar ของโมดูลเดิมไว้ — ส่ง event กลาง
           ให้ Vue เปลี่ยนหมวดเอง ไม่จำลองการคลิก DOM ของ component */
        if (launcher) {
            launcher.dataset.selectedSection = target.dataset.sec;
            window.dispatchEvent(new CustomEvent('erp:select-section', {
                detail: { index: Number(target.dataset.sec) },
            }));
        } else {
            side.scrollTop = 0;
        }
    }

    if (input) {
        input.addEventListener('input', apply);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { input.value = ''; apply(); return; }
            if (event.key !== 'Enter') { return; }
            var first = side.querySelector('.odn-item:not([hidden])');
            if (first) { event.preventDefault(); first.click(); }
        });
    }

    /* กดหมวดบนแถบบน = สลับหมวดในเมนูข้างโดยไม่เลื่อนหน้าจอ */
    navLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var target = document.getElementById('odn-grp-' + link.dataset.sec);
            if (!target) { return; }
            event.preventDefault();
            document.documentElement.classList.remove('subnav-collapsed');
            try { localStorage.setItem('erp-subnav', 'open'); } catch (e) {}
            showGroup(target, link);
        });
    });

    /* ทำให้สถานะเริ่มต้นสอดคล้องกัน แม้หน้าเว็บจะไม่มีช่องค้นหา */
    if (launcher) {
        markActiveNav(null);
        window.addEventListener('erp:section-changed', function (event) {
            var index = event.detail && event.detail.index;
            markActiveNav(Number.isInteger(index) ? navLinks.find(function (link) {
                return Number(link.dataset.sec) === index;
            }) : null);
        });
    } else {
        showGroup(activeGroup, navLinks.filter(function (link) {
            return activeGroup && link.dataset.sec === activeGroup.dataset.sec;
        })[0] || null);
    }
})();
</script>
<script>
/* ปรับหน้าจอต่อเครื่อง — เก็บใน localStorage ไม่แตะฐานข้อมูลและไม่กระทบผู้ใช้คนอื่น */
(function () {
    var KEY = 'erp-display';
    var btn = document.getElementById('erp-display-btn');
    var panel = document.getElementById('erp-display-panel');
    if (!btn || !panel) { return; }

    var saved = document.getElementById('edp-saved');
    var root = document.documentElement;
    var serverTheme = root.getAttribute('data-theme');
    var savedTimer = null;

    function read() {
        try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; }
    }
    function write(prefs) {
        try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch (e) { /* โหมดส่วนตัวเขียนไม่ได้ */ }
        if (!saved) { return; }
        saved.hidden = false;
        clearTimeout(savedTimer);
        savedTimer = setTimeout(function () { saved.hidden = true; }, 1600);
    }
    function apply(prefs) {
        root.style.setProperty('--ui-scale', prefs.uiScale || 1);
        root.style.setProperty('--menu-scale', prefs.menuScale || 1);
        if (prefs.font) { root.style.setProperty('--erp-font-family', prefs.font); }
        else { root.style.removeProperty('--erp-font-family'); }
        root.setAttribute('data-theme', prefs.theme || serverTheme);
    }
    function paint(prefs) {
        panel.querySelectorAll('.edp-seg').forEach(function (seg) {
            var current = String(prefs[seg.dataset.pref] || 1);
            seg.querySelectorAll('button').forEach(function (b) {
                b.setAttribute('aria-pressed', String(b.dataset.v === current));
            });
        });
        panel.querySelectorAll('select[data-pref]').forEach(function (sel) {
            sel.value = prefs[sel.dataset.pref] || '';
        });
    }

    var prefs = read();
    apply(prefs);
    paint(prefs);

    btn.addEventListener('click', function () {
        var open = panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', function (event) {
        if (!panel.hidden && !panel.contains(event.target) && !btn.contains(event.target)) {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) { panel.hidden = true; btn.focus(); }
    });

    panel.querySelectorAll('.edp-seg button').forEach(function (b) {
        b.addEventListener('click', function () {
            prefs[b.closest('.edp-seg').dataset.pref] = parseFloat(b.dataset.v);
            apply(prefs); paint(prefs); write(prefs);
        });
    });
    panel.querySelectorAll('select[data-pref]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel.value) { prefs[sel.dataset.pref] = sel.value; } else { delete prefs[sel.dataset.pref]; }
            apply(prefs); paint(prefs); write(prefs);
        });
    });
    document.getElementById('edp-reset').addEventListener('click', function () {
        prefs = {};
        try { localStorage.removeItem(KEY); } catch (e) { /* ไม่เป็นไร */ }
        apply(prefs); paint(prefs);
        if (saved) { saved.hidden = false; clearTimeout(savedTimer);
            savedTimer = setTimeout(function () { saved.hidden = true; }, 1600); }
    });
})();
</script>
@include('partials.camera-scan')
</body>
</html>
