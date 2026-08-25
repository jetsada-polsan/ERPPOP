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
    $faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg'));
    $companyName = \App\Models\AppSetting::company('name_th') ?: 'กิจการของคุณ';
    $companyLogo = \App\Models\AppSetting::logoUrl();
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
        /* ══════════════════════════════════════════════════════════
           LAYOUT: Odoo — โครงการใช้งานแบบ Odoo แต่ใช้สีแบรนด์ JET/POPSTAR
           กติกาของบล็อกนี้: ทุกสีต้องอ้าง design token เท่านั้น ห้าม hardcode
           เพิ่ม เพื่อให้สลับธีม (ocean/navy/emerald/slate/clear) แล้วตามไปด้วย
           แก้เฉพาะการแสดงผล ไม่แตะ markup, route, สิทธิ์ หรือ business logic
           ขนาดตัวอักษรและระยะห่างไม่กำหนดในนี้ ปล่อยให้ media query ของ
           layout เดิมคุม จอ 1366/1920/Retina จึงยังขยายตามเดิมทุกประการ
           ══════════════════════════════════════════════════════════ */

        /* ── พื้นหลัง: ฟ้าอ่อน/เทาอ่อนของระบบเดิม ไม่ใช่เทา #f7f7f7 ของ Odoo ── */
        html[data-layout="odoo"] body,
        html[data-layout="odoo"] .app-main,
        html[data-layout="odoo"] .app-content { background: var(--erp-bg); }

        /* ── Top header: ฟ้าเข้ม JET คาดเส้น accent ฟ้าสด ── */
        html[data-layout="odoo"] .app-header {
            background: var(--erp-primary-dark) !important;
            color: #fff;
            border-bottom: 3px solid var(--erp-primary);
            box-shadow: 0 1px 4px rgba(15, 76, 117, .22);
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }
        html[data-layout="odoo"] .app-header h1 { color: #fff; }
        html[data-layout="odoo"] .app-header .nav-link { color: #fff; }
        html[data-layout="odoo"] .app-header .text-muted { color: rgba(255, 255, 255, .78) !important; }
        html[data-layout="odoo"] .app-header .page-title-icon { background: rgba(255, 255, 255, .18); color: #fff; }
        /* ปุ่มพื้นขาวในหัวต้องคงตัวหนังสือเข้มไว้ ถ้าบังคับเป็นขาวทั้งแถบ
           ปุ่ม "คู่มือ 4M" จะกลายเป็นขาวบนขาวจนอ่านไม่ออก */
        html[data-layout="odoo"] .app-header .btn-light { color: var(--erp-text); }

        /* ── Notification panel: ลอยอยู่ในหัวแต่พื้นขาว ต้องดึงสีตัวหนังสือกลับ ── */
        html[data-layout="odoo"] .notify-panel { color: var(--erp-text); }
        html[data-layout="odoo"] .notify-panel .text-muted { color: var(--erp-muted) !important; }
        html[data-layout="odoo"] .notify-item { color: var(--erp-text); }
        html[data-layout="odoo"] .notify-item:hover { background: var(--erp-primary-soft); }
        html[data-layout="odoo"] .notify-head { background: var(--erp-primary-soft); border-bottom-color: var(--erp-border); }
        html[data-layout="odoo"] .notify-badge { background: var(--erp-brand-red); }

        /* ── Left navigation: rail ทึบสีฟ้าเข้ม (Odoo ใช้แถบทึบ ไม่ใช้ gradient) ── */
        html[data-layout="odoo"] .fa-rail { background: var(--erp-primary-dark); border-right: 0; }
        html[data-layout="odoo"] .fa-rail-logo { background: var(--erp-surface); }
        html[data-layout="odoo"] .fa-rail-btn { color: rgba(255, 255, 255, .82); }
        html[data-layout="odoo"] .fa-rail-btn:hover { background: rgba(255, 255, 255, .15); color: #fff; }
        html[data-layout="odoo"] .fa-rail-btn.active {
            background: var(--erp-surface);
            color: var(--erp-primary);
            box-shadow: 0 6px 16px rgba(15, 76, 117, .28);
        }

        /* ── Menu groups: แผงเมนูย่อยพื้นขาว ตัวที่เลือกมีเส้น accent ฟ้า ── */
        html[data-layout="odoo"] .fa-subnav { background: var(--erp-surface); border-right: 1px solid var(--erp-border); }
        html[data-layout="odoo"] .fa-subnav-title { color: var(--erp-primary-dark); }
        html[data-layout="odoo"] .fa-subnav-link { color: var(--erp-text); border-radius: 4px; }
        html[data-layout="odoo"] .fa-subnav-link i { color: var(--erp-muted); }
        html[data-layout="odoo"] .fa-subnav-link:hover {
            background: var(--erp-primary-soft);
            color: var(--erp-primary);
            transform: none;
        }
        html[data-layout="odoo"] .fa-subnav-link:hover i { color: var(--erp-primary); }
        html[data-layout="odoo"] .fa-subnav-link.active {
            background: var(--erp-primary-soft);
            color: var(--erp-primary-dark);
            box-shadow: inset 3px 0 0 var(--erp-primary);
        }
        html[data-layout="odoo"] .fa-subnav-link.active i { color: var(--erp-primary); }

        /* ── Cards: พื้นขาว ขอบฟ้าอ่อน เงาบาง มุมเหลี่ยมกว่าแบบ Odoo ── */
        html[data-layout="odoo"] .card,
        html[data-layout="odoo"] .content-card,
        html[data-layout="odoo"] .set-card,
        html[data-layout="odoo"] .panel-card {
            background: var(--erp-surface);
            border: 1px solid var(--erp-border);
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(29, 59, 82, .09);
        }
        html[data-layout="odoo"] .card-header {
            background: var(--erp-surface);
            border-bottom: 1px solid var(--erp-border);
            color: var(--erp-primary-dark);
        }
        html[data-layout="odoo"] .panel-title { color: var(--erp-primary-dark); }

        /* ── Dashboard KPI: สีแยกตามความหมาย ไม่ใช่แยกตามตำแหน่งการ์ด ── */
        html[data-layout="odoo"] .metric-value { color: var(--erp-primary-dark); }
        html[data-layout="odoo"] .metric-label { color: var(--erp-muted); }

        /* ── List view: หัวตารางฟ้าอ่อน ตัวหนังสือฟ้าเข้ม ── */
        html[data-layout="odoo"] .table thead th {
            background: var(--erp-primary-soft);
            color: var(--erp-primary-dark);
            border-bottom: 2px solid var(--erp-primary);
        }
        html[data-layout="odoo"] .table tbody tr:hover { background: var(--erp-primary-soft); }

        /* ── Form view ── */
        html[data-layout="odoo"] .form-control:focus,
        html[data-layout="odoo"] .form-select:focus {
            border-color: var(--erp-primary);
            box-shadow: 0 0 0 3px rgba(21, 133, 192, .18);
        }
        html[data-layout="odoo"] .nav-pills .nav-link.active { background: var(--erp-primary); color: #fff; }
        html[data-layout="odoo"] .odn-side { overflow-y: auto; }
        html[data-layout="odoo"] .odn-grp { display: none; }
        html[data-layout="odoo"] .odn-grp.is-visible { display: block; }
        html[data-layout="odoo"] .odn-nav-link { white-space: nowrap; }

        /* ── ปุ่ม: ฟ้า = ทำต่อ, เขียว = ยืนยัน/สำเร็จ, แดง = ลบ/ผิดพลาด ──
           ธีม Odoo เดิมทำ .btn-success ให้เป็นม่วงชุดเดียวกับ .btn-primary
           ทำให้ "ยืนยัน" กับ "ทำต่อ" หน้าตาเหมือนกันจนแยกไม่ออก จึงแยกคืน */
        html[data-layout="odoo"] .btn-primary {
            background: var(--erp-primary-ink) !important;
            border-color: var(--erp-primary-ink) !important;
            color: #fff !important;
        }
        html[data-layout="odoo"] .btn-primary:hover,
        html[data-layout="odoo"] .btn-primary:focus {
            background: var(--erp-primary-dark) !important;
            border-color: var(--erp-primary-dark) !important;
        }
        html[data-layout="odoo"] .btn-success {
            background: var(--erp-success-ink) !important;
            border-color: var(--erp-success-ink) !important;
            color: #fff !important;
        }
        html[data-layout="odoo"] .btn-danger {
            background: var(--erp-danger) !important;
            border-color: var(--erp-danger) !important;
            color: #fff !important;
        }
        html[data-layout="odoo"] .btn-outline-primary { color: var(--erp-primary); border-color: var(--erp-primary); }
        html[data-layout="odoo"] .btn-outline-primary:hover { background: var(--erp-primary-ink); color: #fff; }

        /* ── สถานะ: ความหมายหนึ่งอย่าง = สีหนึ่งสี ทั้งระบบ ── */
        html[data-layout="odoo"] .text-primary { color: var(--erp-primary) !important; }
        html[data-layout="odoo"] .text-success { color: var(--erp-success-ink) !important; }
        html[data-layout="odoo"] .text-danger { color: var(--erp-danger) !important; }
        html[data-layout="odoo"] .text-warning { color: var(--erp-warning-ink) !important; }
        html[data-layout="odoo"] .badge.bg-primary { background: var(--erp-primary) !important; }
        html[data-layout="odoo"] .badge.bg-success { background: var(--erp-success-ink) !important; }
        html[data-layout="odoo"] .badge.bg-danger { background: var(--erp-danger) !important; }
        html[data-layout="odoo"] .badge.bg-warning { background: var(--erp-warning-ink) !important; color: #fff !important; }
        html[data-layout="odoo"] .table-danger { --bs-table-bg: var(--erp-danger-soft); }
        html[data-layout="odoo"] .table-success { --bs-table-bg: var(--erp-success-soft); }
        html[data-layout="odoo"] .table-warning { --bs-table-bg: var(--erp-warning-soft); }
        html[data-layout="odoo"] .alert-danger { background: var(--erp-danger-soft); border-color: var(--erp-danger); color: var(--erp-danger); }

        /* ══════════════════════════════════════════════════════════
           แผงควบคุมแบบ Odoo (dashboard-odoo.blade.php)
           ใช้เฉพาะหน้านี้ ไม่กระทบหน้าอื่นหรือ layout Classic
           ══════════════════════════════════════════════════════════ */
        .od-ctrl { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            background:var(--erp-surface); border:1px solid var(--erp-border); border-radius:8px;
            padding:10px 14px; margin-bottom:12px; box-shadow:0 1px 3px rgba(29,59,82,.06); }
        .od-ctrl h2 { margin:0; font-size:17px; font-weight:700; color:var(--erp-primary-dark); }
        .od-ctrl-right { margin-left:auto; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .od-btn { display:inline-flex; align-items:center; gap:6px; background:var(--erp-surface);
            border:1px solid var(--erp-border); border-radius:7px; padding:5px 11px;
            font-size:12.5px; color:var(--erp-text); text-decoration:none; }
        .od-btn:hover { background:var(--erp-primary-soft); border-color:var(--erp-primary); color:var(--erp-primary-dark); }
        .od-btn-primary { background:var(--erp-primary-ink); border-color:var(--erp-primary-ink); color:#fff; font-weight:600; }
        .od-btn-primary:hover { background:var(--erp-primary-dark); border-color:var(--erp-primary-dark); color:#fff; }
        .od-range { display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--erp-muted); flex-wrap:wrap; }
        .od-range input { border:1px solid var(--erp-border); border-radius:6px; padding:4px 8px; font-size:12.5px; color:var(--erp-text); }
        .od-range input:focus { outline:0; border-color:var(--erp-primary); box-shadow:0 0 0 3px rgba(21,133,192,.18); }
        .od-scope { display:inline-flex; align-items:center; gap:5px; font-size:12px;
            background:var(--erp-primary-soft); color:var(--erp-primary-dark); border-radius:999px; padding:4px 11px; font-weight:600; }

        .od-kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:11px; margin-bottom:12px; }
        .od-kpi { background:var(--erp-surface); border:1px solid var(--erp-border); border-radius:8px;
            padding:13px; display:flex; gap:11px; box-shadow:0 1px 3px rgba(29,59,82,.06); }
        .od-ico { width:38px; height:38px; border-radius:9px; display:grid; place-items:center; flex:none; font-size:17px; color:#fff; }
        .od-ico.t-blue { background:var(--erp-primary-ink); }
        .od-ico.t-green { background:var(--erp-success-ink); }
        .od-ico.t-info { background:#0f766e; }
        .od-ico.t-amber { background:var(--erp-warning-ink); }
        .od-ico.t-red { background:var(--erp-danger); }
        .od-lbl { font-size:12px; color:var(--erp-muted); line-height:1.35; }
        .od-val { font-size:20px; font-weight:700; line-height:1.3; letter-spacing:-.4px; margin:1px 0 2px;
            font-variant-numeric:tabular-nums; color:var(--erp-primary-dark); }
        .od-sub { font-size:11.5px; color:var(--erp-muted); }
        .od-sub b.up { color:var(--erp-success-on-soft); font-weight:600; }
        .od-sub b.dn { color:var(--erp-danger); font-weight:600; }

        .od-card { background:var(--erp-surface); border:1px solid var(--erp-border); border-radius:8px;
            box-shadow:0 1px 3px rgba(29,59,82,.06); overflow:hidden; }
        .od-card > header { display:flex; align-items:center; gap:9px; padding:11px 14px; border-bottom:1px solid var(--erp-border); }
        .od-card > header h3 { margin:0; font-size:14.5px; font-weight:700; color:var(--erp-primary-dark); }
        .od-meta { margin-left:auto; font-size:11.5px; color:var(--erp-muted); }
        .od-pad { padding:13px 14px; }
        .od-empty { color:var(--erp-muted); font-size:12.5px; text-align:center; padding:18px 0; margin:0; }

        /* ── แท็บแบบ segmented ────────────────────────────────────
           ใช้แทนปุ่ม btn-dark ทึบ ๆ ของ Bootstrap ที่ดูเป็นปุ่มกดมากกว่าแท็บ */
        .od-tabs { display:inline-flex; gap:2px; background:var(--erp-surface-2, #f8fbfd);
            border:1px solid var(--erp-border); border-radius:9px; padding:3px; flex-wrap:wrap; }
        .od-tab { border:0; background:none; border-radius:7px; padding:6px 14px;
            font:inherit; font-size:13px; color:var(--erp-muted); cursor:pointer; white-space:nowrap; }
        .od-tab:hover { color:var(--erp-primary-dark); background:var(--erp-primary-soft); }
        .od-tab.on { background:var(--erp-surface); color:var(--erp-primary-dark); font-weight:600;
            box-shadow:0 1px 3px rgba(29,59,82,.14); }
        .od-tab:focus-visible { outline:2px solid var(--erp-primary); outline-offset:1px; }

        /* ── แถวฟอร์มในการ์ด ─────────────────────────────────────── */
        .od-form { display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); align-items:end; }
        .od-field { display:flex; flex-direction:column; gap:5px; min-width:0; }
        .od-field > label { font-size:11.5px; font-weight:600; color:var(--erp-muted); }
        .od-field .form-control, .od-field .form-select { font-size:13px; }
        .od-form-actions { display:flex; align-items:end; }

        /* ── แถวสรุปท้ายตาราง ────────────────────────────────────── */
        .od-total td { border-top:2px solid var(--erp-border); font-weight:700;
            background:var(--erp-surface-2, #f8fbfd); }
        /* empty state ที่อยู่ใน <li> ต้องหลุดจาก grid ของรายการ ไม่งั้นถูกบีบจนขึ้นบรรทัดละคำ */
        .od-top li.od-empty { display:block; grid-template-columns:none; border-bottom:0; }

        .od-row3 { display:grid; grid-template-columns:1.05fr 1fr .95fr; gap:12px; margin-bottom:12px; }
        .od-row2 { display:grid; grid-template-columns:1.55fr 1fr; gap:12px; margin-bottom:12px; }

        .od-bar { display:grid; grid-template-columns:120px 1fr 74px; gap:9px; align-items:center; margin-bottom:9px; font-size:12.5px; }
        .od-bar:last-child { margin-bottom:0; }
        .od-track { background:var(--erp-surface-2, #f8fbfd); border:1px solid var(--erp-border); border-radius:999px; height:11px; overflow:hidden; }
        .od-fill { display:block; height:100%; background:var(--erp-primary-ink); }
        .od-bar-val { text-align:right; font-weight:600; font-variant-numeric:tabular-nums; }
        .od-bar-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        .od-top { list-style:none; margin:0; padding:0; }
        .od-top li { display:grid; grid-template-columns:22px 1fr auto auto; gap:9px; align-items:center;
            padding:8px 14px; border-bottom:1px solid var(--erp-border); font-size:12.5px; }
        .od-top li:last-child { border-bottom:0; }
        .od-rk { color:var(--erp-muted); font-variant-numeric:tabular-nums; }
        .od-nm { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .od-q { color:var(--erp-muted); font-size:11.5px; text-align:right; font-variant-numeric:tabular-nums; }
        .od-m { font-weight:700; text-align:right; min-width:64px; font-variant-numeric:tabular-nums; }

        .od-nts { padding:2px 0; }
        .od-nt { display:flex; gap:10px; padding:10px 14px; border-bottom:1px solid var(--erp-border); align-items:flex-start; }
        .od-nt:last-child { border-bottom:0; }
        .od-ico2 { width:28px; height:28px; border-radius:7px; display:grid; place-items:center; flex:none; font-size:13px; }
        .od-ico2.t-blue { background:var(--erp-primary-soft); color:var(--erp-primary-ink); }
        .od-ico2.t-amber { background:var(--erp-warning-soft); color:var(--erp-warning-ink); }
        .od-ico2.t-red { background:var(--erp-danger-soft); color:var(--erp-danger); }
        .od-av { width:28px; height:28px; border-radius:50%; flex:none; display:grid; place-items:center;
            font-size:11px; font-weight:700; background:var(--erp-primary-soft); color:var(--erp-primary-dark); }
        .od-nt-t { font-size:12.5px; font-weight:600; line-height:1.4; }
        .od-nt-d { font-size:11.5px; color:var(--erp-muted); text-decoration:none; }
        a.od-nt-d { color:var(--erp-primary-ink); font-weight:600; }
        a.od-nt-d:hover { text-decoration:underline; }
        .od-nt-tm { margin-left:auto; font-size:11px; color:var(--erp-muted); white-space:nowrap; flex:none; }

        .od-pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:5px;
            font-size:11px; font-weight:600; white-space:nowrap; }
        .od-pill.ok { background:var(--erp-success-soft); color:var(--erp-success-on-soft); }
        .od-pill.wait { background:var(--erp-warning-soft); color:var(--erp-warning-ink); }
        .od-pill.fail { background:var(--erp-danger-soft); color:var(--erp-danger); box-shadow:inset 0 0 0 1px currentColor; }
        .od-struck { text-decoration:line-through; color:var(--erp-muted); }
        .od-card .table td, .od-card .table th { white-space:nowrap; }
        .od-card .table { font-size:12.5px; }

        /* ══════════════════════════════════════════════════════════
           โครงเปลือกแบบ Odoo — แถบบน + ค้นหาเมนู + เมนูข้างแบบกลุ่ม
           ทุกกฎขึ้นต้นด้วย .odn- หรือ html[data-layout="odoo"]
           layout Classic จึงไม่โดนผลกระทบเลย
           ══════════════════════════════════════════════════════════ */
        /* .app-wrapper ของ AdminLTE เป็น grid ที่ตั้งชื่อ area ไว้แล้ว
           ถ้าไม่เพิ่มแถวให้แถบบน มันจะถูก auto-place ไปแถวล่างสุดของหน้า */
        html[data-layout="odoo"] .app-wrapper {
            grid-template-areas:
                "odn-top odn-top"
                "lte-app-sidebar lte-app-header"
                "lte-app-sidebar lte-app-main"
                "lte-app-sidebar lte-app-footer";
            grid-template-rows: 44px auto 1fr auto;
        }
        .odn-topbar {
            grid-area: odn-top;
            position: sticky; top: 0; z-index: 1200;
            background: var(--erp-primary-dark); color: #fff;
            display: flex; align-items: center; gap: 6px; padding: 0 12px; height: 44px;
        }
        .odn-apps { display:grid; place-items:center; width:30px; height:30px; border-radius:8px;
            background:rgba(255,255,255,.13); color:#fff; flex:none; font-size:14px; text-decoration:none;
            transition:background .13s; }
        .odn-apps:hover { background:rgba(255,255,255,.28); color:#fff; }
        .odn-apps:focus-visible { outline:2px solid #fff; outline-offset:2px; }

        .odn-brand { font-weight:700; font-size:15px; color:#fff; text-decoration:none;
            letter-spacing:.3px; white-space:nowrap; padding:0 4px; }
        .odn-brand:hover { color:#fff; }
        /* เส้นคั่นบาง ๆ แยก "ตัวระบบ" ออกจาก "เมนู" ให้ตาอ่านเป็นสองส่วน */
        .odn-brand::after { content:""; display:inline-block; width:1px; height:16px;
            background:rgba(255,255,255,.22); margin-left:12px; vertical-align:-3px; }

        .odn-nav { display:flex; gap:2px; overflow-x:auto; scrollbar-width:none; min-width:0; margin-left:4px; }
        .odn-nav::-webkit-scrollbar { display:none; }
        .odn-nav-link { color:rgba(255,255,255,.86); text-decoration:none; padding:6px 12px;
            border-radius:7px; font-size:13px; white-space:nowrap; transition:background .12s, color .12s; }
        .odn-nav-link:hover { background:rgba(255,255,255,.14); color:#fff; }
        .odn-nav-link:focus-visible { outline:2px solid #fff; outline-offset:-2px; }
        /* ตัวที่เลือกเป็นพิลขาว ไม่ใช่ฟ้าบนฟ้า — ฟ้า #1585c0 บนแถบ #0f4c75 ได้แค่ 2.23:1
           มองแทบไม่ออกว่าอันไหนถูกเลือก ส่วนพิลขาวได้ 9.09:1 ทั้งพื้นและตัวหนังสือ */
        .odn-nav-link.on { background:#fff; color:var(--erp-primary-dark); font-weight:600;
            box-shadow:0 1px 3px rgba(0,0,0,.18); }
        .odn-nav-link.on:hover { background:#fff; color:var(--erp-primary-dark); }

        .odn-company { margin-left:auto; font-size:12.5px; color:rgba(255,255,255,.82);
            white-space:nowrap; padding-left:14px; border-left:1px solid rgba(255,255,255,.18);
            margin-right:2px; }

        /* header เดิมยังทำหน้าที่แถบชื่อหน้า แต่ไม่ต้องเป็นสีเข้มซ้อนกันสองชั้น */
        html[data-layout="odoo"] .app-header {
            background: var(--erp-surface) !important;
            color: var(--erp-text);
            border-bottom: 1px solid var(--erp-border);
            box-shadow: 0 1px 3px rgba(29,59,82,.07);
        }
        html[data-layout="odoo"] .app-header h1 { color: var(--erp-primary-dark); }
        html[data-layout="odoo"] .app-header .nav-link { color: var(--erp-muted); }
        html[data-layout="odoo"] .app-header .text-muted { color: var(--erp-muted) !important; }
        html[data-layout="odoo"] .app-header .page-title-icon { background: var(--erp-primary-soft); color: var(--erp-primary-ink); }

        .odn-search { position:relative; flex:1; max-width:340px; margin:0 16px; }
        .odn-search input { width:100%; border:1px solid var(--erp-border); background:var(--erp-surface-2, #f8fbfd);
            border-radius:7px; padding:6px 30px 6px 11px; font-size:12.5px; color:var(--erp-text); }
        .odn-search input::placeholder { color:var(--erp-muted); }
        .odn-search input:focus { outline:0; border-color:var(--erp-primary); background:#fff;
            box-shadow:0 0 0 3px rgba(21,133,192,.16); }
        .odn-search i { position:absolute; right:10px; top:50%; transform:translateY(-50%);
            font-size:12px; color:var(--erp-muted); pointer-events:none; }

        /* เมนูข้าง: คอลัมน์เดียว ไม่มี rail ไอคอน */
        html[data-layout="odoo"] { --lte-sidebar-width: calc(216px * var(--menu-scale)); }
        /* กฎ .app-sidebar ของธีมเดิมอยู่ท้ายไฟล์และใช้ !important
           จึงต้องเจาะจงกว่า (0,3,1) ถึงจะเอาชนะได้ ไม่ใช่แค่ !important เท่ากัน */
        html[data-layout="odoo"] .app-sidebar.odn-side {
            display: block !important;
            width: calc(216px * var(--menu-scale)) !important;
            min-width: calc(216px * var(--menu-scale)) !important;
            max-width: calc(216px * var(--menu-scale)) !important;
            background: var(--erp-surface) !important;
            border-right: 1px solid var(--erp-border);
            height: calc(100vh - 44px); top: 44px;
            overflow-y: auto !important; overflow-x: hidden !important;
            scrollbar-width: thin; padding: 0 0 24px;
        }
        /* ── แบรนด์บนสุดของเมนู ───────────────────────────────────── */
        .odn-side-brand { display:flex; align-items:center; justify-content:center; height:56px;
            margin:0; padding:10px 14px; overflow:hidden; text-decoration:none;
            color:var(--erp-primary-dark); font-weight:700; font-size:13px;
            border-bottom:1px solid var(--erp-border); background:var(--erp-surface); }
        .odn-side-brand img { max-width:136px; max-height:36px; width:auto; height:auto; object-fit:contain; }

        /* ── หัวหมวด ──────────────────────────────────────────────── */
        .odn-grp { border-bottom:0; }
        .odn-grp-head { display:flex; align-items:center; gap:8px; width:100%; background:none; border:0;
            padding:14px 14px 7px; font-size:10.5px; font-weight:700; letter-spacing:.7px;
            text-transform:uppercase; color:var(--erp-muted); text-align:left; cursor:default; }
        .odn-grp-head:hover { background:none; color:var(--erp-muted); }
        .odn-grp-head > i:first-child { font-size:12px; opacity:.6; }
        .odn-caret { display:none; }
        .odn-grp-body { padding:0 8px 10px; }

        /* ── รายการเมนู ───────────────────────────────────────────
           ไอคอนใส่กล่องสีประจำโมดูล ใช้ค่า tone ชุดเดียวกับหน้า App Launcher
           เมนูเดิมเป็นไอคอนเทาบางเท่ากันหมด ตากวาดแล้วไม่มีอะไรให้จับ
           ─────────────────────────────────────────────────────── */
        .odn-item { display:flex; align-items:center; gap:10px; padding:6px 8px; margin-bottom:1px;
            color:var(--erp-text); text-decoration:none; font-size:calc(13px * var(--menu-scale));
            border-radius:8px; line-height:1.35; position:relative; }
        /* ตราโมดูลวางบนพื้นขาว ไม่ใช่พื้นสีอ่อน — ตัวทรงมีสีของมันเองอยู่แล้ว
           ถ้าใส่พื้นสีอีกชั้นจะกลายเป็นสีทับสีจนอ่านยาก */
        .odn-item-ico { width:28px; height:28px; border-radius:8px; flex:none;
            display:grid; place-items:center; background:#fff;
            box-shadow:inset 0 0 0 1px var(--erp-border); }
        .odn-item-ico svg { width:19px; height:19px; display:block; }
        /* ชื่อยาวให้ตัดสองบรรทัดแทนการตัดท้ายทิ้ง — "เบิก / คืน / สูญเสีย / แปรรูป"
           ถ้าตัดท้ายจะเหลือ "แปร..." ซึ่งอ่านไม่ออกว่าคืออะไร */
        .odn-item > span:last-child { min-width:0; display:-webkit-box; -webkit-line-clamp:2;
            -webkit-box-orient:vertical; overflow:hidden; }
        .odn-item:hover { background:var(--erp-surface-2, #f8fbfd); }
        .odn-item:hover .odn-item-ico { box-shadow:inset 0 0 0 1px var(--erp-primary); }
        /* ตัวที่เปิดอยู่ใช้ฟ้า JET เสมอ ไม่ใช่สีประจำโมดูล — "คุณอยู่ตรงนี้" ต้องเป็น
           สัญญาณเดียวคงที่ ถ้าเปลี่ยนสีไปตามเมนูจะกลายเป็นสีสุ่มที่อ่านไม่ออกว่าแปลว่าอะไร */
        .odn-item.active { background:var(--erp-primary-soft); font-weight:600;
            color:var(--erp-primary-dark); }
        .odn-item.active .odn-item-ico { box-shadow:inset 0 0 0 2px var(--erp-primary); }
        .odn-item:focus-visible { outline:2px solid var(--erp-primary); outline-offset:1px; }

        /* สีประจำโมดูล 10 โทน — ชุดเดียวกับ App Launcher ผ่าน AA ทั้งไอคอนและตัวอักษร */
        .odn-item.t-blue   { --ti:#1274a8; --ts:#e9f2f9; }
        .odn-item.t-cyan   { --ti:#0e7490; --ts:#e6f2f6; }
        .odn-item.t-teal   { --ti:#0f766e; --ts:#e6f3f1; }
        .odn-item.t-indigo { --ti:#4054a8; --ts:#edeff9; }
        .odn-item.t-slate  { --ti:#52677d; --ts:#eef1f4; }
        .odn-item.t-amber  { --ti:#9b6400; --ts:#fbf3e3; }
        .odn-item.t-orange { --ti:#b0530a; --ts:#fbf0e6; }
        .odn-item.t-red    { --ti:#c62828; --ts:#fdedec; }
        .odn-item.t-pink   { --ti:#a3376b; --ts:#fbeef3; }
        .odn-item.t-brown  { --ti:#7c5233; --ts:#f6efe8; }

        .odn-noresult { color:var(--erp-muted); font-size:12.5px; text-align:center; padding:20px 12px; margin:0; }

        /* ── แผงปรับหน้าจอ (ใช้ได้ทั้ง Classic และ Odoo) ────────────── */
        .erp-display-panel {
            position:absolute; right:0; top:calc(100% + 8px); z-index:3000; width:320px;
            background:#fff; border:1px solid #e2e8f0; border-radius:14px;
            box-shadow:0 18px 48px rgba(15,23,42,.18); overflow:hidden; color:#1d3b52;
            font-size:13px; text-align:left;
        }
        .erp-display-panel[hidden] { display:none; }
        .edp-head { display:flex; align-items:center; justify-content:space-between;
            padding:11px 14px; border-bottom:1px solid #f1f5f9; background:#f8fafc; }
        .edp-note { font-size:11px; color:#64748b; background:#eef4f9; border-radius:999px; padding:2px 9px; }
        .edp-row { padding:11px 14px; border-bottom:1px solid #f1f5f9; }
        .edp-row > label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:7px; }
        .edp-seg { display:flex; gap:4px; flex-wrap:wrap; }
        .edp-seg button {
            flex:1; min-width:52px; font:inherit; font-size:11.5px; cursor:pointer;
            background:#fff; color:#475569; border:1px solid #dbe7ef; border-radius:6px; padding:5px 4px;
        }
        .edp-seg button:hover { border-color:#1585c0; color:#0f4c75; }
        .edp-seg button[aria-pressed="true"] { background:#1585c0; border-color:#1585c0; color:#fff; font-weight:600; }
        .edp-row select {
            width:100%; font:inherit; font-size:12.5px; color:#1d3b52;
            border:1px solid #dbe7ef; border-radius:6px; padding:6px 9px; background:#fff;
        }
        .edp-row select:focus, .edp-seg button:focus-visible {
            outline:0; border-color:#1585c0; box-shadow:0 0 0 3px rgba(21,133,192,.18);
        }
        .edp-foot { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; }
        .edp-reset { font:inherit; font-size:12px; background:none; border:0; color:#c62828; cursor:pointer; padding:0; }
        .edp-reset:hover { text-decoration:underline; }
        .edp-saved { font-size:11.5px; color:#158662; font-weight:600; }
        @media print { .erp-display-panel { display:none !important; } }

        html[data-layout="odoo"] .fa-collapse-btn { display:none; }

        @media (max-width: 991.98px) {
            .odn-nav, .odn-company { display:none; }
            .odn-search { margin:0 8px; max-width:none; }
        }
        @media print { .odn-topbar, .odn-side, .odn-search { display:none !important; } }

        .od-donut { display:flex; align-items:center; gap:16px; flex-wrap:wrap; justify-content:center; }
        .od-ring { position:relative; width:148px; height:148px; flex:none; }
        .od-ring svg { transform:rotate(-90deg); width:148px; height:148px; }
        .od-ring circle.s-ok { stroke:var(--erp-success-ink); }
        .od-ring circle.s-wait { stroke:var(--erp-warning); }
        .od-ring circle.s-fail { stroke:var(--erp-danger); }
        .od-ring-mid { position:absolute; inset:0; display:flex; flex-direction:column;
            align-items:center; justify-content:center; text-align:center; line-height:1.15; }
        .od-ring-mid b { font-size:24px; font-weight:700; display:block; font-variant-numeric:tabular-nums; color:var(--erp-primary-dark); }
        .od-ring-mid span { font-size:11.5px; color:var(--erp-muted); }
        .od-legend { list-style:none; margin:0; padding:0; font-size:12.5px; min-width:168px; }
        .od-legend li { display:flex; align-items:center; gap:8px; padding:5px 0; }
        .od-legend i { width:10px; height:10px; border-radius:3px; flex:none; }
        .od-legend i.d-ok { background:var(--erp-success-ink); }
        .od-legend i.d-wait { background:var(--erp-warning); }
        .od-legend i.d-fail { background:var(--erp-danger); }
        .od-legend .n { margin-left:auto; font-weight:700; font-variant-numeric:tabular-nums; }
        .od-legend .p { color:var(--erp-muted); font-size:11.5px; min-width:50px; text-align:right; font-variant-numeric:tabular-nums; }

        @media (max-width: 1240px) {
            .od-kpis { grid-template-columns:repeat(3,1fr); }
            .od-row3, .od-row2 { grid-template-columns:1fr; }
        }
        @media (max-width: 780px) {
            .od-kpis { grid-template-columns:repeat(2,1fr); }
            .od-ctrl-right { margin-left:0; width:100%; }
        }
        html[data-layout="odoo"] .alert-success { background: var(--erp-success-soft); border-color: var(--erp-success); color: var(--erp-success-on-soft); }

        /* ════════════════════════════════════════
           THEME - FlowAccount-style light (single)
           ════════════════════════════════════════ */
        :root {
            /* ฟอนต์เดียวทั้งระบบ (ใช้แทนการ hardcode font-family กระจายไปทีละหน้า) */
            /* ── ค่าที่ผู้ใช้ปรับเองได้ต่อเครื่อง (เก็บใน localStorage) ──
               จอแต่ละเครื่องขนาดไม่เท่ากันและคนใช้สายตาไม่เท่ากัน
               ค่ากลางของบริษัทยังอยู่ที่ AppSetting เหมือนเดิม
               ตัวนี้เป็นการ "ทับเฉพาะเครื่องนี้" ไม่กระทบคนอื่น */
            --ui-scale: 1;      /* ตัวคูณขนาดตัวอักษรและระยะห่าง */
            --menu-scale: 1;    /* ตัวคูณความกว้างและขนาดตัวอักษรของเมนู */
            --erp-font-family: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif;
            /* rail 64px + subpanel 236px - adminlte ใช้ var นี้คำนวณ margin ของ main */
            --erp-rail-w: calc(68px * var(--menu-scale));
            --erp-subnav-w: calc(176px * var(--menu-scale));
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

            /* ── Design tokens กลาง ─────────────────────────────────
               ชั้น "ความหมาย" (primary/success/danger/warning) วางทับชั้น
               "สีดิบ" (--fa-*) ที่มีอยู่เดิม ตัวไหนอ้าง --fa-* ได้ให้อ้าง
               เพื่อให้ธีมทั้ง 5 (ocean/navy/emerald/slate/clear) ยังเปลี่ยน
               สีได้เหมือนเดิม ส่วน --erp-bg/-surface/-border/-ink มีอยู่แล้ว
               ด้านบนและค่าตรงตามที่กำหนดไว้ จึงไม่ประกาศซ้ำ
               ───────────────────────────────────────────────────── */
            --erp-primary: var(--fa-blue-deep);
            --erp-primary-light: var(--fa-blue);
            --erp-primary-dark: #0f4c75;
            --erp-primary-soft: #eef4f9;
            --erp-success: var(--fa-green-deep);
            --erp-success-soft: #eaf7f1;
            --erp-danger: #c62828;
            --erp-danger-soft: #fff0f0;
            --erp-warning: #d98b00;
            --erp-warning-soft: #fdf4e3;
            --erp-text: var(--erp-ink);
            /* #6b7f8d ที่กำหนดมาได้ 3.87:1 บนพื้น --erp-bg ต่ำกว่าเกณฑ์ AA 4.5:1
               ขยับให้เข้มขึ้นเท่าที่จำเป็นพอดี เฉดเดิม อ่านออกทุกขนาดจอ */
            --erp-muted: #627481;

            /* ── คู่ "-ink": ใช้เฉพาะตอนที่มีตัวหนังสือทับอยู่บนสีนั้น ──
               สีแบรนด์ด้านบนคงค่าตามที่กำหนดไว้ทุกตัว ใช้เป็นพื้น ขอบ และ
               เส้น accent ตามเดิม แต่ถ้าเอาตัวหนังสือขาวไปวางทับจะได้
               4.08:1 (ฟ้า) และ 4.32:1 (เขียว) ซึ่งไม่ผ่าน AA จึงแยกเฉดที่
               เข้มขึ้นนิดเดียวไว้ใช้ตอนมีตัวอักษรโดยเฉพาะ ตาเปล่าแยกไม่ออก */
            --erp-primary-ink: #147db5;   /* ขาวบนฟ้า  4.54:1 */
            --erp-success-ink: #158662;   /* ขาวบนเขียว 4.55:1 */
            --erp-success-on-soft: #147f5d; /* เขียวบนพื้นเขียวอ่อน 4.52:1 */
            --erp-warning-ink: #a56a00;   /* เหลืองอ่านบนขาว 4.51:1 */
            /* แดง POPSTAR: ใช้เฉพาะแบรนด์ แจ้งเตือน และข้อผิดพลาด */
            --erp-brand-red: #c62828;
        }
        html[data-theme="navy"] { --erp-primary-dark:#183049; --erp-primary-soft:#eef2f7; --erp-border:#dce5ef; --erp-ink:#243b53; --erp-bg:#eef2f7; --fa-blue:#315b86; --fa-blue-deep:#244768; --fa-blue-dark:#3f5f7d; --fa-green:#4e9b72; --fa-green-deep:#397b58; --accent-btn:linear-gradient(135deg,#416f9c,#244768); --accent-btn-hover:linear-gradient(135deg,#527fad,#315b86); }
        html[data-theme="emerald"] { --erp-primary-dark:#0f5138; --erp-primary-soft:#eaf5f0; --erp-border:#d8ebe3; --erp-ink:#28483c; --erp-bg:#eef7f3; --fa-blue:#23966c; --fa-blue-deep:#187653; --fa-blue-dark:#397563; --fa-green:#65a30d; --fa-green-deep:#4d7c0f; --accent-btn:linear-gradient(135deg,#34b986,#187653); --accent-btn-hover:linear-gradient(135deg,#49c99a,#23966c); }
        html[data-theme="slate"] { --erp-primary-dark:#334155; --erp-primary-soft:#eef1f4; --erp-border:#e1e5e9; --erp-ink:#374151; --erp-bg:#f1f3f5; --fa-blue:#64748b; --fa-blue-deep:#475569; --fa-blue-dark:#596579; --fa-green:#4f8b72; --fa-green-deep:#3c6f59; --accent-btn:linear-gradient(135deg,#7b8798,#475569); --accent-btn-hover:linear-gradient(135deg,#909aaa,#64748b); }
        html[data-theme="clear"] {
            --erp-font-family:'Noto Sans Thai','Segoe UI','Leelawadee UI',Tahoma,sans-serif;
            --erp-primary-dark:#1e3a8a; --erp-primary-soft:#eef2ff;
            --erp-border:#d7dde5; --erp-ink:#1f2937; --erp-bg:#f4f6f8;
            --fa-blue:#2563eb; --fa-blue-deep:#1d4ed8; --fa-blue-dark:#334155;
            --fa-green:#0f766e; --fa-green-deep:#115e59;
            --erp-surface:#fff; --erp-soft:#f8fafc;
            --erp-shadow:0 8px 22px rgba(31,41,55,.07);
            --accent-btn:linear-gradient(135deg,#2563eb,#0f766e);
            --accent-btn-hover:linear-gradient(135deg,#1d4ed8,#115e59);
        }
        html[data-theme="clear"] body { background:var(--erp-bg); }
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
            font-size: calc(13px * var(--menu-scale));
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
    <style id="erp-ui-standard">
        /* มาตรฐาน UI กลาง: อ่านได้ชัดบนจอ 13–15 นิ้ว และขยายเป็นขั้นบนจอใหญ่ */
        :root {
            --ui-font-xs: calc(13px * var(--ui-scale));
            --ui-font-sm: calc(14px * var(--ui-scale));
            --ui-font-md: calc(15px * var(--ui-scale));
            --ui-font-lg: calc(18px * var(--ui-scale));
            --ui-font-xl: calc(24px * var(--ui-scale));
            --ui-control-h: calc(40px * var(--ui-scale));
            --ui-radius: 7px;
            --ui-card-radius: 10px;
            --ui-space: calc(16px * var(--ui-scale));
        }
        body:not(.erp-popup-page) { font-size:var(--ui-font-md); line-height:1.4; }
        .app-header { min-height:52px; }
        .app-header h1 { font-size:var(--ui-font-xl); line-height:1.2; }
        .app-header .text-muted { font-size:var(--ui-font-sm)!important; }
        .page-title-icon { width:32px; height:32px; margin-right:8px; border-radius:7px; font-size:14px; }
        .app-content { padding:16px 18px; }

        .content-card,
        .card {
            border-color:#dbe7ef;
            border-radius:var(--ui-card-radius);
            box-shadow:0 10px 26px rgba(29,59,82,.07);
        }
        .card-header {
            padding:10px 14px;
            background:linear-gradient(180deg,#fff,#f8fbfd);
            border-bottom-color:#e4edf4;
        }
        .card-body { padding:14px; }

        h1,.h1 { font-size:26px; }
        h2,.h2 { font-size:22px; }
        h3,.h3 { font-size:19px; }
        h4,.h4 { font-size:17px; }
        h5,.h5 { font-size:16px; }
        h6,.h6 { font-size:15px; }
        .small,small { font-size:var(--ui-font-sm)!important; }

        .btn:not(.rounded-circle) { min-height:var(--ui-control-h); padding:5px 10px; border-radius:var(--ui-radius); font-size:var(--ui-font-md); line-height:1.2; font-weight:700; }
        .btn-sm:not(.rounded-circle) { min-height:30px; padding:4px 9px; border-radius:6px; font-size:var(--ui-font-sm); }
        .btn-lg:not(.rounded-circle) { min-height:42px; padding:8px 16px; font-size:var(--ui-font-md); }
        .btn.rounded-pill { padding-left:12px!important; padding-right:12px!important; }

        .form-label { margin-bottom:4px; color:#536b7d; font-size:var(--ui-font-sm); font-weight:700; }
        .form-control,.form-select,.input-group-text { min-height:var(--ui-control-h); padding:5px 8px; border-radius:var(--ui-radius); border-color:#d7e2ea; font-size:var(--ui-font-md); line-height:1.2; }
        textarea.form-control { min-height:64px; }
        .form-control-sm,.form-select-sm { min-height:31px; padding:4px 8px; font-size:var(--ui-font-sm); }
        .form-check-label,.form-text { font-size:var(--ui-font-sm); }
        .input-group > :not(:first-child) { border-top-left-radius:0; border-bottom-left-radius:0; }
        .input-group > :not(:last-child) { border-top-right-radius:0; border-bottom-right-radius:0; }

        .table { margin-bottom:0; font-size:var(--ui-font-md); border-color:#e5edf3; }
        .table-responsive {
            border-radius:10px;
            border:1px solid #e1ebf2;
            background:#fff;
        }
        .table > thead > tr > th { padding:9px 10px; color:#fff; font-size:var(--ui-font-sm); font-weight:900; line-height:1.2; vertical-align:middle; white-space:nowrap; }
        .table > tbody > tr > td { padding:9px 10px; line-height:1.35; vertical-align:middle; }
        .table > tbody > tr:nth-child(even) > td { background:#fbfdff; }
        .table > tbody > tr:hover > td { background:#eef8fd; }
        .table-sm > thead > tr > th,.table-sm > tbody > tr > td { padding:5px 7px; }
        .badge { padding:4px 7px; border-radius:6px; font-size:var(--ui-font-xs); line-height:1; }

        .alert { padding:9px 11px; border-radius:10px; font-size:var(--ui-font-md); border:1px solid rgba(148,163,184,.2); }
        .pagination { --bs-pagination-padding-x:.6rem; --bs-pagination-padding-y:.28rem; --bs-pagination-font-size:11px; }
        .dropdown-menu { padding:5px; border-radius:8px; font-size:var(--ui-font-md); }
        .dropdown-item { padding:6px 8px; border-radius:5px; }

        @media (max-width: 991.98px) {
            :root {
                --ui-font-xs: calc(11px * var(--ui-scale));
                --ui-font-sm: calc(12px * var(--ui-scale));
                --ui-font-md: calc(13px * var(--ui-scale));
                --ui-font-lg: calc(16px * var(--ui-scale));
                --ui-font-xl: calc(20px * var(--ui-scale));
                --ui-control-h: calc(34px * var(--ui-scale));
                --ui-space: calc(12px * var(--ui-scale));
            }
            .app-content { padding:14px; }
            .card-header { padding:9px 12px; }
            .card-body { padding:12px; }
        }

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

        /* ERP web responsive tiers: fixed readable sizes for small, standard and large monitors. */
        @media (min-width: 1366px) and (min-height: 720px) {
            :root {
                --ui-font-xs: calc(13px * var(--ui-scale));
                --ui-font-sm: calc(14px * var(--ui-scale));
                --ui-font-md: calc(15px * var(--ui-scale));
                --ui-font-lg: calc(19px * var(--ui-scale));
                --ui-font-xl: calc(25px * var(--ui-scale));
                --ui-control-h: calc(42px * var(--ui-scale));
                --ui-space: calc(16px * var(--ui-scale));
            }
            .app-content { padding: 20px 24px; }
            .app-header { min-height: 60px; }
            .app-header h1 { font-size: var(--ui-font-xl); }
            .page-title-icon { width: 38px; height: 38px; font-size: 17px; }
            .card-header { padding: 12px 16px; }
            .card-body { padding: 16px; }
            .table > thead > tr > th { padding: 11px 12px; }
            .table > tbody > tr > td { padding: 11px 12px; }
            .pagination { --bs-pagination-font-size: 14px; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) { font-size: 14px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) :is(.form-control,.form-select) { font-size: 14px!important; min-height: 36px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .btn:not(.rounded-circle) { font-size: 14px!important; min-height: 36px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table th { font-size: 13px!important; padding: 8px 9px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table td { font-size: 13px!important; padding: 7px 9px!important; }

            /* Navigation has its own fixed sizing. Keep it readable independently
               from the data-table font scale. */
            :root { --erp-rail-w: 76px; --erp-subnav-w: 218px; }
            .fa-rail { padding: 14px 0; gap: 8px; }
            .fa-rail-btn { width: 66px; min-height: 62px; padding: 8px 3px 7px; }
            .fa-rail-btn i { font-size: 21px; }
            .fa-rail-btn-label { font-size: 11px; max-width: 64px; }
            .fa-subnav { padding: 14px 12px 22px; }
            .fa-subnav-brand { height: 54px; }
            .fa-subnav-brand img { max-width: 124px!important; max-height: 44px!important; }
            .fa-subnav-title { font-size: 17px; padding: 6px 10px 10px; }
            .fa-subnav-link { gap: 11px; padding: 9px 10px; font-size: 15px; margin-bottom: 2px; }
            .fa-subnav-link i { font-size: 15px; width: 18px; }
        }

        @media (min-width: 1600px) and (min-height: 820px) {
            :root {
                --erp-rail-w: 74px;
                --erp-subnav-w: 202px;
                --ui-font-xs: calc(14px * var(--ui-scale));
                --ui-font-sm: calc(15px * var(--ui-scale));
                --ui-font-md: calc(17px * var(--ui-scale));
                --ui-font-lg: calc(21px * var(--ui-scale));
                --ui-font-xl: calc(27px * var(--ui-scale));
                --ui-control-h: calc(46px * var(--ui-scale));
                --ui-space: calc(18px * var(--ui-scale));
            }
            .app-content { padding: 24px 30px; }
            .app-header { min-height: 66px; }
            .card-header { padding: 14px 18px; }
            .card-body { padding: 18px; }
            .table > thead > tr > th { padding: 12px 14px; }
            .table > tbody > tr > td { padding: 12px 14px; }
            :root { --erp-rail-w: 84px; --erp-subnav-w: 242px; }
            .fa-rail-btn { width: 72px; min-height: 68px; }
            .fa-rail-btn i { font-size: 23px; }
            .fa-rail-btn-label { font-size: 12px; max-width: 70px; }
            .fa-subnav-title { font-size: 19px; }
            .fa-subnav-link { font-size: 17px; padding: 10px 12px; }
            .fa-subnav-link i { font-size: 17px; width: 20px; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) { font-size: 15px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) :is(.form-control,.form-select) { font-size: 15px!important; }
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table th,
            body.erp-classic-document-page :is(.booking-modal,.po-modal,.cd-modal) .table td { font-size: 14px!important; }
        }

        @media (min-width: 2200px) {
            :root {
                --erp-rail-w: 80px;
                --erp-subnav-w: 220px;
                --ui-font-xs: calc(14px * var(--ui-scale));
                --ui-font-sm: calc(16px * var(--ui-scale));
                --ui-font-md: calc(18px * var(--ui-scale));
                --ui-font-lg: calc(22px * var(--ui-scale));
                --ui-font-xl: calc(29px * var(--ui-scale));
                --ui-control-h: calc(50px * var(--ui-scale));
            }
            .app-content { padding: 28px 34px; }
            :root { --erp-rail-w: 90px; --erp-subnav-w: 260px; }
            .fa-rail-btn { width: 78px; min-height: 72px; }
            .fa-rail-btn i { font-size: 25px; }
            .fa-rail-btn-label { font-size: 13px; max-width: 76px; }
            .fa-subnav-title { font-size: 20px; }
            .fa-subnav-link { font-size: 18px; padding: 11px 13px; }
            .fa-subnav-link i { font-size: 18px; width: 22px; }
        }

        @media print { .app-content{padding:0}.content-card,.card{box-shadow:none}.table-responsive{overflow:visible!important} }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg {{ request()->boolean('popup') ? 'erp-popup-page' : '' }} {{ request()->routeIs(['bookings.*','cash-sales.*','sales.*','purchases.*','purchase-orders.*','sale-returns.*','credit-debit-notes.*','stock-issues.*','stock-transfers.*','stock-transforms.*','stock-adjustments.*','stock-counts.*']) ? 'erp-classic-document-page' : '' }}">
    <div class="app-wrapper">
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
@if ($erpLayout === 'odoo')
        {{-- แถบบนแบบ Odoo: ปุ่มโมดูล + ชื่อระบบ + เมนูหมวด
             ใช้ $menuSections ชุดเดียวกับเมนูข้าง จะได้ไม่มีวันหลุดจากกัน --}}
        <div class="odn-topbar no-print">
            <a href="{{ route('apps.launcher') }}" class="odn-apps" title="รวมโมดูลทั้งหมด" aria-label="รวมโมดูลทั้งหมด">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </a>
            <a href="{{ route('dashboard') }}" class="odn-brand">JET ERP</a>
            <nav class="odn-nav" aria-label="หมวดเมนู">
                @foreach ($menuSections as $i => $section)
                    <a href="#odn-grp-{{ $i }}" class="odn-nav-link {{ $i === $activeSection ? 'on' : '' }}"
                       data-sec="{{ $i }}">{{ $section['displayLabel'] ?? $section['label'] }}</a>
                @endforeach
            </nav>
            <span class="odn-company">{{ $companyName }}</span>
        </div>
@endif
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
@if ($erpLayout === 'odoo')
                {{-- ค้นหาเมนู: กรองรายการในเมนูข้างแบบสด ๆ ไม่ได้ยิงหลังบ้าน
                     เพราะระบบยังไม่มี endpoint ค้นหารวม การทำช่องที่กดแล้วเงียบ
                     แย่กว่าการบอกตรง ๆ ว่ามันค้นอะไรได้ --}}
                <div class="odn-search">
                    <label for="odn-q" class="visually-hidden">ค้นหาเมนู</label>
                    <input id="odn-q" type="search" autocomplete="off" placeholder="ค้นหาเมนู…"
                           aria-controls="odn-side">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
@endif
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
                    {{-- ปรับหน้าจอเฉพาะเครื่องนี้ --}}
                    <li class="nav-item">
                        <div class="position-relative">
                            <button type="button" class="btn btn-light border rounded-circle" style="width:42px;height:42px"
                                    id="erp-display-btn" title="ปรับขนาดและสีหน้าจอ" aria-expanded="false" aria-controls="erp-display-panel">
                                <i class="bi bi-sliders" style="color:#64748b"></i>
                            </button>
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

@if ($erpLayout === 'odoo')
        {{-- เมนูข้างแบบ Odoo: ทุกหมวดกางพร้อมกัน พับได้ทีละหมวด --}}
        <aside class="app-sidebar odn-side" id="odn-side">
            <a href="{{ route('dashboard') }}" class="odn-side-brand">
                @if($appLogo)<img src="{{ $appLogo }}" alt="{{ $companyName }}">
                @else<span>{{ $companyName }}</span>@endif
            </a>
            @foreach ($menuSections as $i => $section)
                <div class="odn-grp {{ $i === $activeSection ? 'is-visible' : '' }}" id="odn-grp-{{ $i }}" data-sec="{{ $i }}">
                    <button type="button" class="odn-grp-head" aria-expanded="true">
                        <i class="bi {{ $railIcons[$section['label']] ?? 'bi-grid-fill' }}"></i>
                        <span>{{ $section['displayLabel'] ?? $section['label'] }}</span>
                        <i class="bi bi-chevron-down odn-caret" aria-hidden="true"></i>
                    </button>
                    <div class="odn-grp-body">
                        @foreach ($section['items'] as $item)
                            @php
                                $active = request()->routeIs($item['pattern']) || (isset($item['extraPattern']) && request()->routeIs($item['extraPattern']));
                                if ($active && array_key_exists('queryCategory', $item)) {
                                    $active = request('category') === $item['queryCategory'];
                                } elseif ($active && $item['route'] === 'reports.index' && ($item['params'] ?? []) === []) {
                                    $active = !request()->filled('category');
                                }
                                $mark = \App\Support\AppMark::forItem($i, $loop->index, $item['tone'] ?? 'blue');
                            @endphp
                            <a href="{{ route($item['route'], $item['params'] ?? []) }}"
                               class="odn-item t-{{ $item['tone'] ?? 'blue' }} {{ $active ? 'active' : '' }}"
                               data-s="{{ mb_strtolower($item['label']) }}"
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
@else
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
@endif

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

    if (!input) { return; }
    var items = Array.prototype.slice.call(side.querySelectorAll('.odn-item'));
    var groups = Array.prototype.slice.call(side.querySelectorAll('.odn-grp'));
    var empty = side.querySelector('.odn-noresult');

    /* โมดูลที่กำลังเปิดอยู่ — ต้องจำไว้ เพราะระหว่างค้นหาเราเปิดหลายหมวดชั่วคราว
       พอล้างคำค้นต้องกลับมาเหลือหมวดเดียวเหมือนเดิม ไม่งั้นเมนูรกกลับมาอีก */
    var activeGroup = side.querySelector('.odn-grp.is-visible');

    function apply() {
        var q = input.value.trim().toLowerCase();
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

    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { input.value = ''; apply(); return; }
        if (event.key !== 'Enter') { return; }
        var first = side.querySelector('.odn-item:not([hidden])');
        if (first) { event.preventDefault(); first.click(); }
    });

    /* กดหมวดบนแถบบน = เลื่อนไปหมวดนั้นในเมนูข้าง แล้วกางให้ */
    document.querySelectorAll('.odn-nav-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var target = document.getElementById('odn-grp-' + link.dataset.sec);
            if (!target) { return; }
            event.preventDefault();
            document.querySelectorAll('.odn-grp').forEach(function (group) { group.classList.remove('is-visible'); });
            target.classList.add('is-visible');
            activeGroup = target;
            if (input) { input.value = ''; apply(); }
            target.classList.remove('collapsed');
            target.scrollIntoView({ block: 'nearest' });
            document.querySelectorAll('.odn-nav-link').forEach(function (other) { other.classList.remove('on'); });
            link.classList.add('on');
        });
    });
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
</body>
</html>
