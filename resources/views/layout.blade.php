@php
    $menuSections = [
        [
            'label' => 'ภาพรวม',
            'displayLabel' => 'หน้าหลัก',
            'items' => [
                ['label' => 'ภาพรวมกิจการ', 'route' => 'dashboard', 'pattern' => 'dashboard', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'blue'],
                ['label' => 'รวมเมนูการทำงาน', 'route' => 'features.index', 'pattern' => 'features.*', 'icon' => 'bi-grid-1x2-fill', 'tone' => 'red'],
                ['label' => 'คู่มือ PopStar 4M', 'route' => 'core-modules.index', 'pattern' => 'core-modules.*', 'icon' => 'bi-journal-text', 'tone' => 'amber'],
                ['label' => 'ศูนย์ควบคุมบริหาร', 'route' => 'management-controls.index', 'pattern' => 'management-controls.*', 'icon' => 'bi-speedometer2', 'tone' => 'teal'],
            ],
        ],
        [
            'label' => 'งานประจำวัน',
            'displayLabel' => 'ขาย / เอกสาร',
            'items' => [
                ['label' => 'เปิด POS ขาย', 'route' => 'pos.index', 'pattern' => 'pos.index', 'icon' => 'bi-display', 'tone' => 'cyan', 'target' => '_blank'],
                ['label' => 'เอกสารย้อนหลัง', 'route' => 'documents.browser', 'pattern' => 'documents.browser', 'icon' => 'bi-archive-fill', 'tone' => 'indigo'],
                ['label' => 'นำเข้าข้อมูล POS', 'route' => 'pos-import.page', 'pattern' => 'pos-import.*', 'icon' => 'bi-cart-check-fill', 'tone' => 'cyan'],
                ['label' => 'เครื่องมือ POS', 'route' => 'bplus.pos-workbench', 'pattern' => 'bplus.pos-workbench', 'icon' => 'bi-window-stack', 'tone' => 'blue'],
                ['label' => 'ส่งข้อมูลไป POS', 'route' => 'bplus.pos-preparation', 'pattern' => 'bplus.pos-preparation', 'icon' => 'bi-hdd-network-fill', 'tone' => 'teal'],
                ['label' => 'ใบเสนอราคา', 'route' => 'quotations.index', 'pattern' => 'quotations.*', 'icon' => 'bi-file-earmark-text', 'tone' => 'slate'],
                ['label' => 'ใบจอง / ขายเชื่อ', 'route' => 'bookings.index', 'pattern' => 'bookings.*', 'extraPattern' => 'sales.*', 'icon' => 'bi-receipt-cutoff', 'tone' => 'orange'],
                ['label' => 'ใบขายสด', 'route' => 'cash-sales.index', 'pattern' => 'cash-sales.*', 'icon' => 'bi-cash-stack', 'tone' => 'teal'],
                ['label' => 'ใบรับคืนสินค้า', 'route' => 'sale-returns.index', 'pattern' => 'sale-returns.*', 'icon' => 'bi-arrow-return-left', 'tone' => 'amber'],
                ['label' => 'ใบวางบิล', 'route' => 'billing-notes.index', 'pattern' => 'billing-notes.*', 'icon' => 'bi-receipt', 'tone' => 'pink'],
                ['label' => 'ใบเพิ่ม/ลดหนี้', 'route' => 'credit-debit-notes.index', 'pattern' => 'credit-debit-notes.*', 'icon' => 'bi-plus-slash-minus', 'tone' => 'orange'],
                ['label' => 'QR รับเงิน / จอแสดงราคา', 'route' => 'bplus.qr-payments', 'pattern' => 'bplus.qr-payments', 'extraPattern' => 'bplus.show-price', 'icon' => 'bi-qr-code', 'tone' => 'slate'],
            ],
        ],
        [
            'label' => 'คลัง / ผลิต / ซื้อ',
            'displayLabel' => 'สินค้า / คลัง / จัดซื้อ',
            'items' => [
                ['label' => 'สินค้า / บริการ', 'route' => 'products.index', 'pattern' => 'products.*', 'extraPattern' => 'product-units.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'brown'],
                ['label' => 'โอนย้าย / ปรับยอดสต๊อก', 'route' => 'stock-transfers.index', 'pattern' => 'stock-transfers.index', 'extraPattern' => 'stock-adjustments.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'teal'],
                ['label' => 'ขอโอนสินค้า', 'route' => 'stock-transfers.request', 'pattern' => 'stock-transfers.request*', 'icon' => 'bi-box-arrow-in-down', 'tone' => 'cyan'],
                ['label' => 'ตรวจนับสินค้า', 'route' => 'stock-counts.index', 'pattern' => 'stock-counts.*', 'icon' => 'bi-clipboard-check-fill', 'tone' => 'cyan'],
                ['label' => 'เบิก / คืน / ตัดสินค้าชำรุด', 'route' => 'stock-issues.index', 'pattern' => 'stock-issues.*', 'extraPattern' => 'stock-transforms.*', 'icon' => 'bi-box-arrow-up', 'tone' => 'orange'],
                ['label' => 'การผลิต', 'route' => 'production.index', 'pattern' => 'production.*', 'icon' => 'bi-gear-wide-connected', 'tone' => 'slate'],
                ['label' => 'ใบขอซื้อ / ใบสั่งซื้อ', 'route' => 'purchase-orders.index', 'pattern' => 'purchase-orders.*', 'icon' => 'bi-cart-plus-fill', 'tone' => 'orange'],
                ['label' => 'รับสินค้าเข้าจากผู้ขาย', 'route' => 'purchases.index', 'pattern' => 'purchases.*', 'icon' => 'bi-basket-fill', 'tone' => 'amber'],
                ['label' => 'คลังมือถือ (สแกน)', 'route' => 'wh.index', 'pattern' => 'wh.*', 'icon' => 'bi-phone-fill', 'tone' => 'cyan'],
                ['label' => 'แผนจัดซื้อ', 'route' => 'bplus.purchase-planning', 'pattern' => 'bplus.purchase-planning', 'icon' => 'bi-clipboard2-check-fill', 'tone' => 'teal'],
            ],
        ],
        [
            'label' => 'การเงิน / บัญชี',
            'displayLabel' => 'การเงิน / บัญชี',
            'items' => [
                ['label' => 'ผังบัญชี / บันทึกบัญชี', 'route' => 'chart-of-accounts.index', 'pattern' => 'chart-of-accounts.*', 'extraPattern' => 'gl-journals.*', 'icon' => 'bi-calculator-fill', 'tone' => 'red'],
                ['label' => 'งวดบัญชี / ปิดงวด', 'route' => 'accounting-periods.index', 'pattern' => 'accounting-periods.*', 'icon' => 'bi-calendar2-lock', 'tone' => 'amber'],
                ['label' => 'ปิดบัญชีรายเดือน', 'route' => 'monthly-accounting.index', 'pattern' => 'monthly-accounting.*', 'icon' => 'bi-file-earmark-zip-fill', 'tone' => 'teal'],
                ['label' => 'ภาษีไทย / E-Tax', 'route' => 'tax-compliance.index', 'pattern' => 'tax-compliance.*', 'icon' => 'bi-receipt', 'tone' => 'orange'],
                ['label' => 'งบการเงิน', 'route' => 'financial-statements.index', 'pattern' => 'financial-statements.*', 'icon' => 'bi-graph-up', 'tone' => 'blue'],
                ['label' => 'ทะเบียนทรัพย์สิน', 'route' => 'fixed-assets.index', 'pattern' => 'fixed-assets.*', 'icon' => 'bi-buildings', 'tone' => 'brown'],
                ['label' => 'เงินสด / ภาษี', 'route' => 'bplus.finance', 'pattern' => 'bplus.finance', 'extraPattern' => 'bplus.tax', 'icon' => 'bi-journal-richtext', 'tone' => 'amber'],
                ['label' => 'บัญชีธนาคาร', 'route' => 'bank-accounts.index', 'pattern' => 'bank-accounts.*', 'icon' => 'bi-bank2', 'tone' => 'blue'],
                ['label' => 'ทะเบียนเช็ค', 'route' => 'cheques.index', 'pattern' => 'cheques.*', 'icon' => 'bi-journal-check', 'tone' => 'teal'],
                ['label' => 'อนุมัติเอกสาร', 'route' => 'bplus.approvals', 'pattern' => 'bplus.approvals', 'icon' => 'bi-shield-check', 'tone' => 'indigo'],
            ],
        ],
        [
            'label' => 'ข้อมูลตั้งต้น',
            'displayLabel' => 'ข้อมูลหลัก',
            'items' => [
                ['label' => 'ลูกค้า (รวมลูกหนี้)', 'route' => 'customers.index', 'pattern' => 'customers.*', 'icon' => 'bi-people-fill', 'tone' => 'indigo'],
                ['label' => 'ผู้จำหน่าย / เจ้าหนี้', 'route' => 'suppliers.index', 'pattern' => 'suppliers.*', 'icon' => 'bi-buildings-fill', 'tone' => 'pink'],
                ['label' => 'สมาชิก', 'route' => 'members.index', 'pattern' => 'members.*', 'icon' => 'bi-person-vcard-fill', 'tone' => 'indigo'],
                ['label' => 'พนักงานขาย', 'route' => 'salesmen.index', 'pattern' => 'salesmen.*', 'icon' => 'bi-person-badge-fill', 'tone' => 'slate'],
                ['label' => 'คลังสินค้า', 'route' => 'warehouse-locations.index', 'pattern' => 'warehouse-locations.*', 'icon' => 'bi-archive-fill', 'tone' => 'teal'],
                ['label' => 'ตารางราคา', 'route' => 'price-tables.index', 'pattern' => 'price-tables.*', 'icon' => 'bi-tags-fill', 'tone' => 'red'],
                ['label' => 'ราคาเครื่องชั่ง', 'route' => 'scale-prices.index', 'pattern' => 'scale-prices.*', 'icon' => 'bi-speedometer2', 'tone' => 'teal'],
                ['label' => 'โปรโมชั่น', 'route' => 'promotions.index', 'pattern' => 'promotions.*', 'icon' => 'bi-gift-fill', 'tone' => 'amber'],
                ['label' => 'ราคานาทีทอง', 'route' => 'flash-sales.index', 'pattern' => 'flash-sales.*', 'icon' => 'bi-lightning-charge-fill', 'tone' => 'red'],
                ['label' => 'ป้ายราคา', 'route' => 'price-tags.index', 'pattern' => 'price-tags.*', 'icon' => 'bi-tag-fill', 'tone' => 'cyan'],
                ['label' => 'บัตรส่วนลด', 'route' => 'discount-cards.index', 'pattern' => 'discount-cards.*', 'icon' => 'bi-credit-card-2-front-fill', 'tone' => 'pink'],
                ['label' => 'แต้มสมาชิก', 'route' => 'member-points.index', 'pattern' => 'member-points.*', 'icon' => 'bi-stars', 'tone' => 'amber'],
                ['label' => 'แคมเปญซื้อครบ', 'route' => 'qty-promotions.index', 'pattern' => 'qty-promotions.*', 'icon' => 'bi-gift-fill', 'tone' => 'orange'],
            ],
        ],
        [
            'label' => 'เชื่อมต่อ',
            'displayLabel' => 'เชื่อมต่อภายนอก',
            'items' => [
                ['label' => 'LINE แจ้งเตือน', 'route' => 'line-integrations.index', 'pattern' => 'line-integrations.*', 'icon' => 'bi-broadcast', 'tone' => 'teal'],
                ['label' => 'E-Commerce', 'route' => 'ecommerce-channels.index', 'pattern' => 'ecommerce-channels.*', 'icon' => 'bi-shop-window', 'tone' => 'cyan'],
            ],
        ],
        [
            'label' => 'รายงาน',
            'displayLabel' => 'รายงาน',
            'items' => [
                ['label' => 'ศูนย์รวมรายงาน', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-clipboard-data-fill', 'tone' => 'blue', 'params' => []],
                ['label' => 'รายงานขาย', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-receipt-cutoff', 'tone' => 'teal', 'params' => ['category' => 'sales'], 'queryCategory' => 'sales'],
                ['label' => 'รายงานซื้อ', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-basket-fill', 'tone' => 'amber', 'params' => ['category' => 'purchasing'], 'queryCategory' => 'purchasing'],
                ['label' => 'รายงานคลัง', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-box-seam-fill', 'tone' => 'cyan', 'params' => ['category' => 'inventory'], 'queryCategory' => 'inventory'],
                ['label' => 'รายงานบัญชี', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-calculator-fill', 'tone' => 'red', 'params' => ['category' => 'payment'], 'queryCategory' => 'payment'],
                ['label' => 'รายงานภาษี', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'bi-receipt', 'tone' => 'orange', 'params' => ['category' => 'tax'], 'queryCategory' => 'tax'],
                ['label' => 'รายงาน BPlus เดิม', 'route' => 'legacy-reports.index', 'pattern' => 'legacy-reports.*', 'icon' => 'bi-files', 'tone' => 'indigo'],
            ],
        ],
        [
            'label' => 'ระบบ',
            'displayLabel' => 'ตั้งค่าระบบ',
            'items' => [
                ['label' => 'ตั้งค่าระบบ', 'route' => 'settings.index', 'pattern' => 'settings.*', 'icon' => 'bi-gear-fill', 'tone' => 'slate'],
                ['label' => 'Backup / Security', 'route' => 'operations.index', 'pattern' => 'operations.*', 'icon' => 'bi-shield-lock-fill', 'tone' => 'red'],
                ['label' => 'สมุดเอกสาร', 'route' => 'document-books.index', 'pattern' => 'document-books.*', 'icon' => 'bi-journals', 'tone' => 'indigo'],
                ['label' => 'ผู้ใช้และสิทธิ์', 'route' => 'users.index', 'pattern' => 'users.*', 'icon' => 'bi-people-fill', 'tone' => 'indigo'],
                ['label' => 'แฟ้มพนักงาน', 'route' => 'employees.index', 'pattern' => 'employees.*', 'icon' => 'bi-person-vcard-fill', 'tone' => 'teal'],
                ['label' => 'ผังองค์กร', 'route' => 'organizational-units.index', 'pattern' => 'organizational-units.*', 'icon' => 'bi-diagram-3-fill', 'tone' => 'indigo'],
            ],
        ],
    ];

    $savedMenuOrder = json_decode((string) \App\Models\AppSetting::get('menu_section_order', '[]'), true);
    if (is_array($savedMenuOrder) && $savedMenuOrder !== []) {
        $positions = array_flip($savedMenuOrder);
        usort($menuSections, fn ($a, $b) => ($positions[$a['label']] ?? 999) <=> ($positions[$b['label']] ?? 999));
    }

    // ซ่อนเมนูที่ผู้ใช้ไม่มีสิทธิ์ (mapping เดียวกับ middleware ErpAuthorize)
    if ($authUser = auth()->user()) {
        $menuSections = array_values(array_filter(array_map(function ($section) use ($authUser) {
            $section['items'] = array_values(array_filter($section['items'], function ($item) use ($authUser) {
                $perm = \App\Support\RoutePermissions::resolve($item['route']);

                return $perm === null || $authUser->hasPermission($perm);
            }));

            return $section;
        }, $menuSections), fn ($section) => $section['items'] !== []));
    }

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
    $faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg'));
    $companyName = \App\Models\AppSetting::company('name_th') ?: 'กิจการของคุณ';
    $companyLogo = \App\Models\AppSetting::logoUrl();
    $erpTheme = in_array(\App\Models\AppSetting::get('erp_theme', 'ocean'), ['ocean', 'navy', 'emerald', 'slate'], true)
        ? \App\Models\AppSetting::get('erp_theme', 'ocean') : 'ocean';
@endphp
<!DOCTYPE html>
<html lang="th" data-theme="{{ $erpTheme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>{{ str_replace('POPSTAR ERP', 'JET ERP', trim($__env->yieldContent('title', 'JET ERP'))) }}</title>

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/css/adminlte.min.css') }}">
    {{-- Tailwind แบบ build นิ่ง (แทน runtime compiler เดิมที่คอมไพล์สดทุกหน้า)
         เพิ่มคลาสใหม่แล้วให้ rebuild: ดูคำสั่งใน tailwind.config.js --}}
    <link rel="stylesheet" href="{{ asset('vendor/tailwindcss/tailwind.min.css') }}?v={{ filemtime(public_path('vendor/tailwindcss/tailwind.min.css')) }}">
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script defer src="{{ asset('vendor/alpinejs/alpine.min.js') }}"></script>
    <style>
        /* ════════════════════════════════════════
           THEME - FlowAccount-style light (single)
           ════════════════════════════════════════ */
        :root {
            /* ฟอนต์เดียวทั้งระบบ (ใช้แทนการ hardcode font-family กระจายไปทีละหน้า) */
            --erp-font-family: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif;
            /* rail 64px + subpanel 236px - adminlte ใช้ var นี้คำนวณ margin ของ main */
            --erp-rail-w: 68px;
            --erp-subnav-w: 176px;
            --lte-sidebar-width: calc(var(--erp-rail-w) + var(--erp-subnav-w));
            --erp-border: #dbe7ef;
            --erp-ink: #1d3b52;
            --erp-bg: #f3f7fb;
            --fa-blue: #1a9bdc;
            --fa-blue-deep: #1585c0;
            --fa-blue-dark: #315f80;
            --fa-green: #20a67a;
            --fa-green-deep: #168a65;
            --erp-surface: #ffffff;
            --erp-soft: #f7fbfe;
            --erp-shadow: 0 12px 34px rgba(29, 59, 82, .08);
            --accent-btn: linear-gradient(135deg, #1a9bdc, #20a67a);
            --accent-btn-hover: linear-gradient(135deg, #2bb0ea, #2bbf8e);
        }
        html[data-theme="navy"] { --erp-border:#dce5ef; --erp-ink:#243b53; --erp-bg:#eef2f7; --fa-blue:#315b86; --fa-blue-deep:#244768; --fa-blue-dark:#3f5f7d; --fa-green:#4e9b72; --fa-green-deep:#397b58; --accent-btn:linear-gradient(135deg,#416f9c,#244768); --accent-btn-hover:linear-gradient(135deg,#527fad,#315b86); }
        html[data-theme="emerald"] { --erp-border:#d8ebe3; --erp-ink:#28483c; --erp-bg:#eef7f3; --fa-blue:#23966c; --fa-blue-deep:#187653; --fa-blue-dark:#397563; --fa-green:#65a30d; --fa-green-deep:#4d7c0f; --accent-btn:linear-gradient(135deg,#34b986,#187653); --accent-btn-hover:linear-gradient(135deg,#49c99a,#23966c); }
        html[data-theme="slate"] { --erp-border:#e1e5e9; --erp-ink:#374151; --erp-bg:#f1f3f5; --fa-blue:#64748b; --fa-blue-deep:#475569; --fa-blue-dark:#596579; --fa-green:#4f8b72; --fa-green-deep:#3c6f59; --accent-btn:linear-gradient(135deg,#7b8798,#475569); --accent-btn-hover:linear-gradient(135deg,#909aaa,#64748b); }
        html.subnav-collapsed { --lte-sidebar-width: var(--erp-rail-w); }

        body {
            font-family: var(--erp-font-family);
            background:
                linear-gradient(135deg, rgba(26,155,220,.08), transparent 30%),
                linear-gradient(315deg, rgba(32,166,122,.08), transparent 30%),
                var(--erp-bg);
            color: var(--erp-ink);
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        .app-wrapper { background: transparent; }

        /* ── Header ───────────────────────────── */
        .app-header {
            border-bottom: 1px solid var(--erp-border);
            min-height: 58px;
            background: rgba(255,255,255,.92) !important;
            box-shadow: 0 8px 28px rgba(29,59,82,.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        /* ── Sidebar 2 ชั้นแบบ FlowAccount: rail ไอคอน + แผงเมนูย่อย ── */
        .app-sidebar {
            width: var(--lte-sidebar-width);
            min-width: var(--lte-sidebar-width);
            background: var(--erp-surface) !important;
            border-right: none;
            display: flex !important;
            flex-direction: row;
            overflow: visible !important;
        }

        .fa-rail {
            width: var(--erp-rail-w);
            flex: 0 0 var(--erp-rail-w);
            background: linear-gradient(180deg, #116c9f 0%, #168caa 55%, #177456 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 12px 0;
            gap: 6px;
            height: 100vh;
            /* ต้อง visible ไม่งั้น tooltip ชื่อเมนูที่ยื่นออกขวาถูกตัดทิ้ง */
            overflow: visible;
        }

        .fa-rail-logo {
            width: 44px; height: 44px;
            border-radius: 14px;
            display: grid; place-items: center;
            margin-bottom: 10px;
            overflow: hidden;
            flex: 0 0 44px;
            box-shadow: 0 10px 24px rgba(0,40,80,.22);
            transition: transform .15s;
        }
        .fa-rail-logo:hover { transform: scale(1.06); }
        .fa-rail-logo img { width: 44px; height: 44px; object-fit: contain; }
        .fa-rail-logo span { font-weight: 900; color: var(--fa-blue-deep); font-size: 15px; background: #fff; width: 44px; height: 44px; display: grid; place-items: center; }

        .fa-rail-btn {
            width: 58px;
            min-height: 54px;
            border: 0; border-radius: 14px;
            background: transparent;
            color: rgba(255,255,255,.85);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 7px 2px 6px;
            cursor: pointer;
            position: relative;
            transition: background .13s, color .13s;
        }
        .fa-rail-btn i { font-size: 18px; line-height: 1; }
        .fa-rail-btn-label {
            font-size: 9.5px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: .01em;
            white-space: nowrap;
            max-width: 58px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .fa-rail-btn:hover { background: rgba(255,255,255,.16); color: #fff; }
        .fa-rail-btn.active { background: #fff; color: #126b9b; box-shadow: 0 10px 22px rgba(0,40,80,.22); }

        /* tooltip ชื่อโมดูลเต็มตอน hover (ยื่นออกขวาของ rail) */
        .fa-rail-btn::after {
            content: attr(data-label);
            position: absolute; left: calc(100% + 10px); top: 50%; transform: translateY(-50%);
            background: #123952; color: #fff;
            font-size: 12.5px; font-weight: 700; white-space: nowrap;
            padding: 6px 12px; border-radius: 8px;
            box-shadow: 0 6px 18px rgba(0,30,60,.3);
            opacity: 0; pointer-events: none; transition: opacity .12s;
            z-index: 4000;
        }
        .fa-rail-btn::before {
            content: "";
            position: absolute; left: calc(100% + 4px); top: 50%; transform: translateY(-50%);
            border: 6px solid transparent;
            border-right-color: #123952;
            opacity: 0; pointer-events: none; transition: opacity .12s;
            z-index: 4000;
        }
        .fa-rail-btn:hover::after,
        .fa-rail-btn:hover::before { opacity: 1; }

        .fa-subnav {
            width: var(--erp-subnav-w);
            flex: 0 0 var(--erp-subnav-w);
            background: linear-gradient(180deg, #fff, #f8fbfd);
            border-right: 1px solid var(--erp-border);
            height: 100vh;
            overflow-y: auto;
            scrollbar-width: thin;
            padding: 12px 10px 18px;
            position: relative;
        }
        html.subnav-collapsed .fa-subnav { display: none; }

        .fa-subnav-brand { height:48px; display:flex; align-items:center; justify-content:center; margin:0 4px 6px; padding:4px 6px; overflow:hidden; }
        .fa-subnav-brand img { display:block; width:auto!important; max-width:108px!important; height:auto!important; max-height:38px!important; object-fit:contain; }
        .fa-subnav-brand .brand-logo { font-size: 22px; font-weight: 900; color: #29465b; }
        .fa-subnav-brand .brand-logo span { color: var(--fa-green); }

        .fa-subnav-title {
            font-size: 15px;
            font-weight: 900;
            color: #1b557a;
            letter-spacing: -.01em;
            padding: 4px 8px 8px;
        }

        .fa-subnav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 8px;
            border-radius: 9px;
            color: #526f84;
            font-size: 13px;
            line-height: 1.25;
            font-weight: 700;
            text-decoration: none;
            margin-bottom: 1px;
            transition: background .12s, color .12s, transform .12s;
        }
        .fa-subnav-link i { font-size: 13px; color: #7fa1bd; width: 16px; text-align: center; }
        .fa-subnav-link:hover { background: #edf7fc; color: var(--fa-blue-deep); transform: translateX(1px); }
        .fa-subnav-link:hover i { color: var(--fa-blue); }
        .fa-subnav-link.active {
            background: linear-gradient(90deg, #e1f4fc, #f0fbf6);
            color: var(--fa-blue-deep);
            box-shadow: inset 3px 0 0 var(--fa-blue), 0 6px 16px rgba(26,155,220,.08);
        }
        .fa-subnav-link.active i { color: var(--fa-blue); }

        /* ปุ่มพับแผงเมนูย่อย (ลอยกึ่งกลางขอบขวา) */
        .fa-collapse-btn {
            position: fixed;
            left: calc(var(--lte-sidebar-width) - 13px);
            top: 92px;
            width: 26px; height: 26px;
            border-radius: 50%;
            border: 1px solid var(--erp-border);
            background: #fff;
            color: #7fa1bd;
            font-size: 11px;
            display: grid; place-items: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(18,57,82,.14);
            z-index: 1100;
            transition: left .15s;
        }
        .fa-collapse-btn:hover { color: var(--fa-blue-deep); border-color: var(--fa-blue); }

        .app-main { margin-left: 0; }

        .app-content { padding: 22px 24px; }

        /* ── Page header ──────────────────────── */
        .page-title-icon {
            width: 36px;
            height: 36px;
            display: inline-grid;
            place-items: center;
            border-radius: 11px;
            background: linear-gradient(135deg, #e1f4fc, #e2f7ef);
            color: #1685bc;
            margin-right: 10px;
            font-size: 16px;
        }

        .app-header h1 {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -.03em;
            color: #0f172a;
        }

        .app-header .text-muted {
            font-size: 12px;
        }

        /* ── Profile ──────────────────────────── */
        .profile-pill { display: flex; align-items: center; gap: 9px; }
        [x-cloak] { display: none !important; }
        .notify-badge {
            position: absolute; top: -4px; right: -4px;
            background: #dc2626; color: #fff; font-size: 10.5px; font-weight: 800;
            border-radius: 999px; min-width: 19px; height: 19px; line-height: 19px;
            text-align: center; padding: 0 5px; border: 2px solid #fff;
        }
        .notify-panel {
            position: absolute; right: 0; top: calc(100% + 8px); z-index: 3000;
            width: 330px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            box-shadow: 0 18px 48px rgba(15,23,42,.18); overflow: hidden;
        }
        .notify-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 14px; border-bottom: 1px solid #f1f5f9; background: #f8fafc;
        }
        .notify-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; text-decoration: none; color: #0f172a;
            border-bottom: 1px solid #f8fafc;
        }
        .notify-item:hover { background: #f0f9ff; }
        .notify-icon {
            width: 32px; height: 32px; border-radius: 9px; flex: 0 0 auto;
            display: inline-grid; place-items: center; font-size: 15px;
        }
        .notify-label { font-size: 13px; font-weight: 700; flex: 1; line-height: 1.35; }
        .notify-count {
            color: #fff; font-size: 11.5px; font-weight: 800; border-radius: 999px;
            min-width: 22px; text-align: center; padding: 2px 7px; flex: 0 0 auto;
        }

        .profile-avatar {
            width: 34px; height: 34px;
            border-radius: 11px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, #1a9bdc, #20a67a);
            color: #fff; font-weight: 700; font-size: 13px;
        }

        /* ── Cards ────────────────────────────── */
        .content-card {
            background: #fff;
            border: 1px solid var(--erp-border);
            border-radius: 12px;
            box-shadow: var(--erp-shadow);
        }

        /* ── Forms ────────────────────────────── */
        .form-control, .form-select {
            border-radius: 9px;
            min-height: 34px;
            padding: 5px 10px;
            font-size: 13.5px;
            border-color: #d8e5ed;
            background: #fbfdff;
            color: #0f172a;
            transition: border-color .15s, box-shadow .15s;
        }

        .form-control:focus, .form-select:focus {
            border-color: #1a9bdc;
            box-shadow: 0 0 0 3px rgba(26,155,220,.12);
            background: #fff;
        }

        /* ── Buttons ──────────────────────────── */
        .btn { border-radius: 9px; font-weight: 700; font-size: 13.5px; }

        .btn-primary {
            background: var(--accent-btn, linear-gradient(135deg, #0ea5e9, #0284c7));
            border-color: #0284c7;
            box-shadow: 0 2px 8px rgba(2,132,199,.3);
        }
        .btn-primary:hover {
            background: var(--accent-btn-hover, linear-gradient(135deg, #38bdf8, #0ea5e9));
            border-color: #0ea5e9;
        }

        /* ปุ่มสร้าง/บันทึกหลัก - เขียวสดแบบ FlowAccount */
        .btn-success {
            background: linear-gradient(135deg, #28b983, #179263);
            border-color: #179263;
            box-shadow: 0 8px 18px rgba(23,146,99,.24);
        }
        .btn-success:hover { background: linear-gradient(135deg, #34c891, #20a67a); border-color: #20a67a; }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-color: #d97706; color: #fff;
        }
        .btn-warning:hover { color: #fff; background: linear-gradient(135deg, #fbbf24, #f59e0b); }

        .rounded-pill { border-radius: 100px !important; }

        /* ── Tables: หัวสีทึบแบบ FlowAccount ───── */
        .table > :not(caption) > * > * { padding: .5rem .65rem; }

        .table thead th {
            font-size: 12.5px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: none;
            color: #fff !important;
            border: 0 !important;
            background: linear-gradient(180deg, #2ba7e4, #1b8ecb) !important;
            --bs-table-bg: transparent;
        }
        .table thead th:first-child { border-top-left-radius: 6px; }
        .table thead th:last-child { border-top-right-radius: 6px; }

        .table tbody tr:hover { background: #f2f9fe; }

        /* Empty state แบบเป็นมิตร: จับ cell ว่างที่ span ทั้งแถว (แนวเดิมของทุกหน้า) */
        .table tbody td[colspan].text-center {
            padding: 46px 16px !important;
            color: #8fa7bd;
            font-size: 13.5px;
        }
        .table tbody td[colspan].text-center::before {
            content: "";
            display: block;
            width: 62px; height: 62px;
            margin: 0 auto 10px;
            background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23a8c8e0' stroke-width='1.1' stroke-linecap='round'><rect x='4' y='2.5' width='13' height='17' rx='2'/><path d='M7.5 7h6M7.5 10.5h6M7.5 14h3.5'/><path d='M15.2 20.2l4.6-4.6c.5-.5.5-1.3 0-1.8s-1.3-.5-1.8 0l-4.6 4.6-.6 2.4 2.4-.6z' fill='%23dcecf7'/></svg>") no-repeat center / contain;
        }

        /* ── Badges ───────────────────────────── */
        .badge { border-radius: 6px; font-weight: 600; font-size: 11px; }

        /* ── Nav pills ────────────────────────── */
        .nav-pills .nav-link {
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            color: #64748b;
            padding: 6px 14px;
        }
        .nav-pills .nav-link.active,
        .nav-pills .nav-link span.active {
            background: #0f172a;
            color: #fff;
        }
        .nav-pills .nav-link:not(.active):hover { background: #f1f5f9; color: #0f172a; }

        /* ── Pagination ───────────────────────── */
        .pagination .page-link { border-radius: 6px; font-size: 13px; }

        /* ── Universal list search bar ─────── */
        .erp-search {
            position: relative;
            flex: 0 0 auto;
            min-width: 260px;
        }
        .erp-search .erp-search-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 14px; pointer-events: none;
        }
        .erp-search input[type="text"], .erp-search input[type="search"] {
            width: 100%; padding: 8px 36px 8px 36px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13.5px;
            background: #fafbfc;
            color: #0f172a;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        .erp-search input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,.12);
            background: #fff;
        }
        .erp-search .erp-search-clear {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 13px;
            text-decoration: none; line-height: 1;
            padding: 2px; border-radius: 50%;
        }
        .erp-search .erp-search-clear:hover { color: #475569; background: #f1f5f9; }

        /* list toolbar (search + add button row) */
        .list-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .list-toolbar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        @media (max-width: 991.98px) {
            .app-main { margin-left: 0; }
            .app-header h1 { font-size: 18px; }
            .app-content { padding: 14px; }
            .fa-collapse-btn { display: none; }

            /* จอมือถือ/แท็บเล็ต: sidebar เป็น off-canvas เลื่อนเข้า-ออกแทนการเบียดเนื้อหา
               ต้อง !important เพราะ AdminLTE เติม body.sidebar-collapse อัตโนมัติต่ำกว่า breakpoint
               ของมันเอง แล้วดัน margin-left ลบความกว้าง sidebar ทับ (specificity สูงกว่า .app-sidebar เฉยๆ) */
            .app-sidebar {
                position: fixed !important;
                top: 0; left: 0;
                margin-left: 0 !important;
                min-width: var(--lte-sidebar-width) !important;
                max-width: var(--lte-sidebar-width) !important;
                z-index: 1200;
                height: 100dvh;
                transform: translateX(-100%);
                transition: transform .22s ease;
                box-shadow: 0 20px 60px rgba(15,23,42,.35);
            }
            html.mobile-sidebar-open .app-sidebar { transform: translateX(0); }
            /* subnav ต้องโชว์เต็มเสมอในโหมด off-canvas ไม่ว่าจะเคยพับไว้ตอนใช้จอใหญ่หรือไม่ */
            .fa-subnav { display: block !important; }
            .fa-rail, .fa-subnav { height: 100dvh; }
        }

        .mobile-sidebar-backdrop { display: none; }
        @media (max-width: 991.98px) {
            html.mobile-sidebar-open .mobile-sidebar-backdrop {
                display: block;
                position: fixed; inset: 0;
                background: rgba(15,23,42,.45);
                z-index: 1150;
            }
        }

        /* ── POPSTAR popup system ───────────────────── */
        .erp-swal-popup {
            width: min(420px, calc(100vw - 32px)) !important;
            padding: 0 !important;
            border: 1px solid rgba(148, 163, 184, .22) !important;
            border-radius: 14px !important;
            overflow: hidden !important;
            box-shadow: 0 26px 90px rgba(15, 23, 42, .26) !important;
        }
        .erp-swal-popup .swal2-header { padding: 22px 24px 0 !important; }
        .erp-swal-popup .swal2-icon { margin: 22px auto 10px !important; transform: scale(.86); }
        .erp-swal-title {
            padding: 0 26px !important;
            margin: 0 !important;
            color: #0f172a !important;
            font-size: 21px !important;
            font-weight: 900 !important;
            letter-spacing: 0 !important;
            line-height: 1.25 !important;
        }
        .erp-swal-html, .erp-swal-popup .swal2-html-container {
            padding: 8px 28px 2px !important;
            margin: 0 !important;
            color: #64748b !important;
            font-size: 14px !important;
            line-height: 1.55 !important;
        }
        .erp-swal-actions { gap: 10px !important; padding: 18px 24px 24px !important; margin: 0 !important; }
        .erp-swal-confirm, .erp-swal-cancel {
            min-width: 112px !important;
            min-height: 40px !important;
            padding: 9px 18px !important;
            border: 0 !important;
            border-radius: 10px !important;
            font-weight: 800 !important;
            box-shadow: none !important;
        }
        .erp-swal-confirm { background: linear-gradient(135deg, #10b981, #0ea5e9) !important; color: #fff !important; }
        .erp-swal-cancel { background: #f1f5f9 !important; color: #475569 !important; }
        .erp-swal-toast {
            width: min(380px, calc(100vw - 24px)) !important;
            padding: 12px 14px !important;
            border: 1px solid rgba(148, 163, 184, .2) !important;
            border-radius: 12px !important;
            box-shadow: 0 16px 45px rgba(15, 23, 42, .16) !important;
        }
        .erp-swal-toast .swal2-title { font-size: 14px !important; font-weight: 800 !important; color: #0f172a !important; }
        .erp-swal-toast .swal2-timer-progress-bar { background: linear-gradient(90deg, #10b981, #0ea5e9) !important; }
        [data-theme="midnight"] .erp-swal-popup,
        [data-theme="midnight"] .erp-swal-toast {
            background: #0e1f38 !important;
            border-color: #1e3a5f !important;
        }
        [data-theme="midnight"] .erp-swal-title,
        [data-theme="midnight"] .erp-swal-toast .swal2-title { color: #e2e8f0 !important; }
        [data-theme="midnight"] .erp-swal-html,
        [data-theme="midnight"] .erp-swal-popup .swal2-html-container { color: #9fb4d0 !important; }
        [data-theme="midnight"] .erp-swal-cancel { background: #13233a !important; color: #cbd5e1 !important; }

        .booking-modal-backdrop,
        .doc-modal-backdrop {
            background: rgba(15, 23, 42, .52) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .booking-modal,
        .doc-modal {
            border: 1px solid rgba(148, 163, 184, .28) !important;
            border-radius: 16px !important;
            box-shadow: 0 28px 90px rgba(15, 23, 42, .24), 0 1px 0 rgba(255,255,255,.74) inset !important;
        }
        .booking-modal .modal-header,
        .doc-modal .modal-header {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            border-bottom: 1px solid #e2e8f0 !important;
        }
        .booking-modal .modal-title,
        .doc-modal .modal-title {
            color: #0f172a;
            font-weight: 900;
        }
        .booking-modal .modal-footer,
        .doc-modal .modal-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0 !important;
        }
    </style>
    @stack('head')
    <style id="erp-ui-standard">
        /* มาตรฐาน UI กลาง: compact desktop ERP สำหรับจอ 14–15 นิ้ว */
        :root {
            --ui-font-xs: 11px;
            --ui-font-sm: 12px;
            --ui-font-md: 13px;
            --ui-font-lg: 16px;
            --ui-font-xl: 20px;
            --ui-control-h: 34px;
            --ui-radius: 7px;
            --ui-card-radius: 10px;
            --ui-space: 12px;
        }
        body:not(.erp-popup-page) { font-size:var(--ui-font-md); line-height:1.4; }
        .app-header { min-height:52px; }
        .app-header h1 { font-size:var(--ui-font-xl); line-height:1.2; }
        .app-header .text-muted { font-size:var(--ui-font-sm)!important; }
        .page-title-icon { width:32px; height:32px; margin-right:8px; border-radius:7px; font-size:14px; }
        .app-content { padding:14px 16px; }

        .content-card,
        .card {
            border-color:#dbe7ef;
            border-radius:var(--ui-card-radius);
            box-shadow:0 10px 26px rgba(29,59,82,.07);
        }
        .card-header {
            padding:9px 12px;
            background:linear-gradient(180deg,#fff,#f8fbfd);
            border-bottom-color:#e4edf4;
        }
        .card-body { padding:12px; }

        h1,.h1 { font-size:24px; }
        h2,.h2 { font-size:20px; }
        h3,.h3 { font-size:18px; }
        h4,.h4 { font-size:16px; }
        h5,.h5 { font-size:15px; }
        h6,.h6 { font-size:14px; }
        .small,small { font-size:var(--ui-font-sm)!important; }

        .btn:not(.rounded-circle) { min-height:var(--ui-control-h); padding:5px 10px; border-radius:var(--ui-radius); font-size:var(--ui-font-md); line-height:1.2; font-weight:700; }
        .btn-sm:not(.rounded-circle) { min-height:27px; padding:3px 8px; border-radius:6px; font-size:var(--ui-font-sm); }
        .btn-lg:not(.rounded-circle) { min-height:38px; padding:7px 14px; font-size:13px; }
        .btn.rounded-pill { padding-left:12px!important; padding-right:12px!important; }

        .form-label { margin-bottom:4px; color:#536b7d; font-size:var(--ui-font-sm); font-weight:700; }
        .form-control,.form-select,.input-group-text { min-height:var(--ui-control-h); padding:5px 8px; border-radius:var(--ui-radius); border-color:#d7e2ea; font-size:var(--ui-font-md); line-height:1.2; }
        textarea.form-control { min-height:64px; }
        .form-control-sm,.form-select-sm { min-height:28px; padding:3px 7px; font-size:var(--ui-font-sm); }
        .form-check-label,.form-text { font-size:var(--ui-font-sm); }
        .input-group > :not(:first-child) { border-top-left-radius:0; border-bottom-left-radius:0; }
        .input-group > :not(:last-child) { border-top-right-radius:0; border-bottom-right-radius:0; }

        .table { margin-bottom:0; font-size:var(--ui-font-md); border-color:#e5edf3; }
        .table-responsive {
            border-radius:10px;
            border:1px solid #e1ebf2;
            background:#fff;
        }
        .table > thead > tr > th { padding:8px 9px; color:#fff; font-size:var(--ui-font-sm); font-weight:900; line-height:1.2; vertical-align:middle; white-space:nowrap; }
        .table > tbody > tr > td { padding:8px 9px; line-height:1.3; vertical-align:middle; }
        .table > tbody > tr:nth-child(even) > td { background:#fbfdff; }
        .table > tbody > tr:hover > td { background:#eef8fd; }
        .table-sm > thead > tr > th,.table-sm > tbody > tr > td { padding:5px 7px; }
        .badge { padding:4px 7px; border-radius:6px; font-size:var(--ui-font-xs); line-height:1; }

        .alert { padding:9px 11px; border-radius:10px; font-size:var(--ui-font-md); border:1px solid rgba(148,163,184,.2); }
        .pagination { --bs-pagination-padding-x:.6rem; --bs-pagination-padding-y:.28rem; --bs-pagination-font-size:11px; }
        .dropdown-menu { padding:5px; border-radius:8px; font-size:var(--ui-font-md); }
        .dropdown-item { padding:6px 8px; border-radius:5px; }

        .booking-modal,.doc-modal { border-radius:10px; }
        .booking-modal .modal-header,.doc-modal .modal-header { padding:12px 14px!important; }
        .booking-modal .modal-body,.doc-modal .modal-body { padding:10px 14px!important; }
        .booking-modal .modal-footer,.doc-modal .modal-footer { padding:10px 14px!important; }

        /* หน้าต่างสร้าง/แก้ไขเอกสารทุกหมวด ใช้มาตรฐานคลาสสิกเดียวกัน */
        body.erp-classic-document-page .booking-modal-backdrop,
        body.erp-classic-document-page .po-backdrop,
        body.erp-classic-document-page .cd-backdrop { backdrop-filter:blur(2px)!important; background:rgba(15,23,42,.5)!important; padding:20px!important; }
        body.erp-classic-document-page .booking-modal,
        body.erp-classic-document-page .po-modal,
        body.erp-classic-document-page .cd-modal {
            border:1px solid #aebdca!important; border-radius:12px!important; background:#f1f4f6!important;
            box-shadow:0 24px 72px rgba(15,35,52,.3),0 2px 8px rgba(15,35,52,.12)!important; font-family:Tahoma,"Noto Sans Thai",sans-serif!important;
            font-size:11.5px!important;
        }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-header {
            min-height:40px!important; padding:6px 10px!important; border-bottom:1px solid #c5d1da!important;
            border-radius:11px 11px 0 0!important; background:linear-gradient(180deg,#fff,#f4f7f9)!important;
        }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-header h1,
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-header h2,
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-header h3 { color:#111!important; font-size:14px!important; font-weight:700!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-header .text-muted { display:none; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-body { padding:8px 10px!important; background:#f1f4f6!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .modal-footer {
            min-height:42px!important; padding:5px 10px!important; border-top:1px solid #c5d1da!important; border-radius:0 0 11px 11px!important; background:#f7f9fa!important;
        }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) :is(.form-control,.form-select) { min-height:28px!important; border-radius:5px!important; padding:3px 6px!important; border-color:#aab9c5!important; background-color:#fff!important; box-shadow:inset 0 1px 2px rgba(15,35,52,.04)!important; font-size:11.5px!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) :is(.form-control,.form-select):focus { border-color:#249ed4!important; box-shadow:0 0 0 2px rgba(36,158,212,.14)!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .btn:not(.rounded-circle) { min-height:28px!important; border-radius:6px!important; padding:3px 10px!important; border-color:#b6c3cd!important; font-size:11px!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .rounded-circle { width:29px!important; height:29px!important; border-radius:7px!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) :is(.card,.content-card,.table-responsive) { border-radius:7px!important; border-color:#bcc9d3!important; box-shadow:0 1px 3px rgba(15,35,52,.05)!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table { overflow:hidden;border-radius:6px!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table th { padding:5px 6px!important; border-right:1px solid #bcc7cf; border-bottom-color:#aebac3!important; background:linear-gradient(#f9fbfc,#e6edf1)!important; color:#17212a!important; font-size:10.5px!important; }
        body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table td { padding:4px 6px!important; border-right:1px solid #d7e0e6; border-bottom-color:#d7e0e6!important; background:#fff!important; font-size:11px!important; }

        @media(max-width:991.98px){.app-content{padding:10px}.btn:not(.rounded-circle){min-height:34px}.form-control,.form-select,.input-group-text{min-height:34px}}
        @media print { .app-content{padding:0}.content-card,.card{box-shadow:none}.table-responsive{overflow:visible!important} }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg {{ request()->boolean('popup') ? 'erp-popup-page' : '' }} {{ request()->routeIs(['bookings.*','cash-sales.*','sales.*','purchases.*','purchase-orders.*','sale-returns.*','credit-debit-notes.*','stock-issues.*','stock-transfers.*','stock-transforms.*','stock-adjustments.*','stock-counts.*']) ? 'erp-classic-document-page' : '' }}">
    <div class="app-wrapper">
        <nav class="app-header navbar navbar-expand bg-white">
            <div class="container-fluid px-4">
                <ul class="navbar-nav align-items-center">
                    <li class="nav-item">
                        <a class="nav-link fs-4" href="#" role="button" aria-label="Toggle sidebar"
                            onclick="event.preventDefault(); document.documentElement.classList.toggle('mobile-sidebar-open')">
                            <i class="bi bi-list"></i>
                        </a>
                    </li>
                    <li class="nav-item d-none d-md-block ms-2">
                        <div class="d-flex align-items-center">
                            <span class="page-title-icon"><i class="bi bi-bar-chart-line-fill"></i></span>
                            <div>
                                <h1 class="fw-bold mb-0">@yield('page-title', str_replace('POPSTAR ERP', 'JET ERP', trim($__env->yieldContent('title', 'JET ERP'))))</h1>
                                <div class="text-muted small">@yield('page-subtitle', 'ภาพรวมธุรกิจ สต็อก และงานที่ต้องจัดการ')</div>
                            </div>
                        </div>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center gap-3">
                    {{-- กระดิ่งแจ้งเตือน: แต่ละส่วนงานเห็นเฉพาะเรื่องตามหน้าที่ --}}
                    <li class="nav-item" x-data="notifyBell()" x-init="load()">
                        <div class="position-relative">
                            <button type="button" class="btn btn-light border rounded-circle position-relative" style="width:42px;height:42px" @click="open = !open; if (open) load()">
                                <i class="bi bi-bell-fill" style="color:#64748b"></i>
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
                                    <i class="bi bi-check2-circle d-block fs-4 mb-1" style="color:#10b981"></i>ไม่มีเรื่องค้าง
                                </div>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item d-none d-lg-block">
                        <a href="{{ route('core-modules.index') }}" class="btn btn-light border rounded-pill px-3">
                            <i class="bi bi-book me-2"></i>คู่มือ 4M
                        </a>
                    </li>
                    <li class="nav-item">
                        <div class="profile-pill">
                            <div class="profile-avatar">{{ mb_substr(auth()->user()?->name ?? 'ผู้ใช้', 0, 2) }}</div>
                            <div class="d-none d-md-block">
                                <div class="fw-bold">{{ auth()->user()?->name ?? '-' }}</div>
                                <div class="text-muted small">{{ auth()->user()?->roles?->first()?->name ?? '-' }}</div>
                            </div>
                            <form method="post" action="{{ route('logout') }}" class="ms-1"
                                  data-confirm="ต้องการออกจากระบบใช่หรือไม่?" data-confirm-ok="ออกจากระบบ" data-confirm-icon="question">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-light border" title="ออกจากระบบ">
                                    <i class="bi bi-box-arrow-right"></i>
                                </button>
                            </form>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        @php
            // ไอคอนโมดูลบน rail + หาโมดูลที่ active จาก route ปัจจุบัน
            $railIcons = [
                'ภาพรวม' => 'bi-house-door-fill',
                'งานประจำวัน' => 'bi-cash-coin',
                'คลัง / ผลิต / ซื้อ' => 'bi-box-seam-fill',
                'การเงิน / บัญชี' => 'bi-calculator-fill',
                'ข้อมูลตั้งต้น' => 'bi-people-fill',
                'เชื่อมต่อ' => 'bi-plug-fill',
                'ระบบ' => 'bi-gear-fill',
                'รายงาน' => 'bi-clipboard-data-fill',
            ];
            // ชื่อสั้นใต้ไอคอน (พื้นที่ ~60px) - ชื่อเต็มโชว์ใน tooltip ตอน hover
            $railShort = [
                'ภาพรวม' => 'หน้าหลัก',
                'งานประจำวัน' => 'ขาย/เอกสาร',
                'คลัง / ผลิต / ซื้อ' => 'สินค้า/คลัง',
                'การเงิน / บัญชี' => 'การเงิน',
                'ข้อมูลตั้งต้น' => 'ข้อมูลหลัก',
                'เชื่อมต่อ' => 'เชื่อมต่อ',
                'ระบบ' => 'ตั้งค่า',
                'รายงาน' => 'รายงาน',
            ];
            $activeSection = 0;
            foreach ($menuSections as $i => $section) {
                foreach ($section['items'] as $item) {
                    if (request()->routeIs($item['pattern']) || (isset($item['extraPattern']) && request()->routeIs($item['extraPattern']))) {
                        $activeSection = $i;
                        break 2;
                    }
                }
            }
            $appLogo = $companyLogo;
        @endphp
        <aside class="app-sidebar" x-data="{ sec: {{ $activeSection }} }">
            {{-- ชั้น 1: rail ไอคอนโมดูล --}}
            <div class="fa-rail">
                {{-- โลโก้ = ตรา JET ERP (เครื่องบินเจ็ท) — ตัว J แดงใช้เฉพาะ favicon --}}
                <a href="{{ route('dashboard') }}" class="fa-rail-logo" title="หน้าแรก">
                    @if(is_file(public_path('images/logo-jet-erp-mark.svg')))
                        <img src="{{ asset('images/logo-jet-erp-mark.svg') }}" alt="JET ERP logo">
                    @elseif($appLogo)
                        <img src="{{ $appLogo }}" alt="logo">
                    @else
                        <span>ป</span>
                    @endif
                </a>
                @foreach($menuSections as $i => $section)
                    <button type="button" class="fa-rail-btn" :class="sec === {{ $i }} && 'active'"
                        data-label="{{ $section['label'] }}"
                        @click="sec = {{ $i }}; document.documentElement.classList.remove('subnav-collapsed'); localStorage.setItem('erp-subnav', 'open')">
                        <i class="bi {{ $railIcons[$section['label']] ?? 'bi-grid-fill' }}"></i>
                        <span class="fa-rail-btn-label">{{ $railShort[$section['label']] ?? $section['label'] }}</span>
                    </button>
                @endforeach
            </div>

            {{-- ชั้น 2: แผงเมนูย่อยของโมดูลที่เลือก --}}
            <div class="fa-subnav">
                <a href="{{ route('dashboard') }}" class="fa-subnav-brand text-decoration-none">
                    @if($appLogo)<img src="{{ $appLogo }}" alt="{{ $companyName }}">
                    @else<div class="brand-logo" style="font-size:14px">{{ $companyName }}</div>@endif
                </a>
                @foreach($menuSections as $i => $section)
                    <div x-show="sec === {{ $i }}" @if($i !== $activeSection) x-cloak @endif>
                        <div class="fa-subnav-title">{{ $section['displayLabel'] ?? $section['label'] }}</div>
                        @foreach($section['items'] as $item)
                            @php
                                $active = request()->routeIs($item['pattern']) || (isset($item['extraPattern']) && request()->routeIs($item['extraPattern']));
                                if ($active && array_key_exists('queryCategory', $item)) {
                                    $active = request('category') === $item['queryCategory'];
                                } elseif ($active && $item['route'] === 'reports.index' && ($item['params'] ?? []) === []) {
                                    $active = !request()->filled('category');
                                }
                            @endphp
                            <a href="{{ route($item['route'], $item['params'] ?? []) }}" class="fa-subnav-link {{ $active ? 'active' : '' }}" @if(isset($item['target'])) target="{{ $item['target'] }}" @endif>
                                <i class="bi {{ $item['icon'] }}"></i>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </aside>

        {{-- เลื่อนแตะเพื่อปิด sidebar บนจอมือถือ (แสดงเฉพาะตอน sidebar เปิดอยู่) --}}
        <div class="mobile-sidebar-backdrop" onclick="document.documentElement.classList.remove('mobile-sidebar-open')"></div>

        {{-- ปุ่มพับ/กางแผงเมนูย่อย --}}
        <button type="button" class="fa-collapse-btn no-print" title="พับ/กางเมนู"
            onclick="document.documentElement.classList.toggle('subnav-collapsed'); localStorage.setItem('erp-subnav', document.documentElement.classList.contains('subnav-collapsed') ? 'closed' : 'open')">
            <i class="bi" id="fa-collapse-icon"></i>
        </button>

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid px-0">
                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    <script src="{{ asset('vendor/adminlte/js/adminlte.min.js') }}"></script>
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
                timer: icon === 'error' ? 5200 : 2600,
                showConfirmButton: false,
                ...options,
            });

            window.erpPopup = (icon, title, text, options = {}) => window.Swal.fire({
                icon,
                title,
                text,
                ...options,
            });

            window.erpConfirm = (title, text, options = {}) => window.Swal.fire({
                icon: 'warning',
                title,
                text,
                showCancelButton: true,
                confirmButtonText: options.confirmButtonText || 'ยืนยัน',
                cancelButtonText: options.cancelButtonText || 'ยกเลิก',
                ...options,
            });
        })();

        // ── ยืนยันก่อน submit แบบสวย (Swal) แทน confirm() ของ browser ──
        // ดักได้ทั้ง form[data-confirm="..."] และของเดิม onsubmit="return confirm('...')"
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (!(form instanceof HTMLFormElement) || !window.erpConfirm) return;
            let msg = form.dataset.confirm;
            if (!msg) {
                const os = form.getAttribute('onsubmit');
                if (os && os.indexOf('confirm(') !== -1) {
                    const m = os.match(/confirm\(\s*['"]([\s\S]*?)['"]\s*\)/);
                    if (m) { msg = m[1]; }
                }
            }
            if (!msg) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            window.erpConfirm(msg, form.dataset.confirmText || '', {
                confirmButtonText: form.dataset.confirmOk || 'ยืนยัน',
                icon: form.dataset.confirmIcon || 'warning',
            }).then(function (r) { if (r.isConfirmed) { form.submit(); } });
        }, true);

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
    @stack('scripts')

    @if(isset($errors) && $errors->any())
    <script>
        erpPopup('error', 'บันทึกไม่สำเร็จ', null, {
            icon: 'error',
            html: @json(implode('<br>', $errors->all())),
        });
    </script>
    @endif
    @if(session('success'))
    <script>
        @if(session('success_popup'))
        erpPopup('success', 'สำเร็จ', @json(session('success')), {
            timer: 4200,
            timerProgressBar: true,
        });
        @else
        erpToast('success', @json(session('success')), { timer: 3000 });
        @endif
    </script>
    @endif
    @if(session('error'))
    <script>
        erpPopup('error', 'ทำรายการไม่สำเร็จ', @json(session('error')));
    </script>
    @endif
</body>
</html>
