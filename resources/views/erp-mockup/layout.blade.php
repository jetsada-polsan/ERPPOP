{{--
    โครงหน้า mockup ระบบหลังบ้าน — แยกขาดจาก layout จริงของระบบโดยตั้งใจ

    ใช้ CSS ของตัวเองทั้งหมด ไม่พึ่ง tailwind.min.css ที่ build ไว้แล้ว เพราะไฟล์นั้น
    มีเฉพาะคลาสที่ใช้ตอน build คลาสใหม่จะไม่ render และเครื่องพัฒนาไม่มี node ให้ build ใหม่
    ผลพลอยได้คือหน้านี้แตะ CSS ของระบบจริงเป็นศูนย์ ลองดีไซน์ได้โดยไม่กระทบของที่ใช้งานอยู่
--}}
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Popstar ERP') · Mockup</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        :root {
            --pop-primary: #6d5a86;      /* ม่วงอมเทา — สีหลักของ Popstar ERP */
            --pop-primary-dark: #4e3f63;
            --pop-primary-soft: #f2eef7;
            --pop-secondary: #1e2a44;    /* น้ำเงินเข้ม */
            --pop-bg: #f4f5f8;
            --pop-line: #e3e6ec;
            --pop-ink: #232733;
            --pop-muted: #6f7787;
            --pop-green: #15803d;
            --pop-green-soft: #e7f6ec;
            --pop-amber: #b45309;
            --pop-amber-soft: #fdf3e3;
            --pop-red: #b91c1c;
            --pop-red-soft: #fdecec;
            --pop-grey-soft: #eef0f4;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--pop-bg); color: var(--pop-ink);
            font-family: 'Sarabun', 'Noto Sans Thai', 'Tahoma', system-ui, sans-serif; font-size: 14px;
        }
        a { color: inherit; text-decoration: none; }

        .pop-top { background: var(--pop-primary); color: #fff; display: flex; align-items: center; gap: 18px; padding: 0 18px; height: 52px; }
        .pop-grid-btn { font-size: 19px; opacity: .9; }
        .pop-brand { font-weight: 800; font-size: 16px; letter-spacing: .2px; }
        .pop-topnav { display: flex; gap: 4px; margin-left: 10px; flex-wrap: wrap; }
        .pop-topnav a { padding: 7px 11px; border-radius: 6px; font-size: 13.5px; opacity: .92; }
        .pop-topnav a:hover, .pop-topnav a.on { background: rgba(255,255,255,.16); opacity: 1; }
        .pop-top-right { margin-left: auto; display: flex; align-items: center; gap: 16px; font-size: 13px; }
        .pop-avatar { width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,.22); display: grid; place-items: center; font-weight: 700; font-size: 12px; }

        .pop-search-bar { background: #fff; border-bottom: 1px solid var(--pop-line); padding: 10px 18px; }
        .pop-search { max-width: 520px; margin: 0 auto; position: relative; }
        .pop-search input { width: 100%; border: 1px solid var(--pop-line); border-radius: 8px; padding: 9px 34px 9px 12px; font: inherit; }
        .pop-search i { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: var(--pop-muted); }

        .pop-shell { display: grid; grid-template-columns: 232px 1fr; min-height: calc(100vh - 96px); }
        .pop-side { background: #fff; border-right: 1px solid var(--pop-line); padding: 14px 0; }
        .pop-side-group { color: var(--pop-muted); font-size: 11.5px; font-weight: 700; letter-spacing: .4px; padding: 14px 18px 6px; text-transform: uppercase; }
        .pop-side a { display: flex; align-items: center; gap: 10px; padding: 9px 18px; border-left: 3px solid transparent; color: var(--pop-ink); }
        .pop-side a:hover { background: #f7f8fa; }
        .pop-side a.on { background: var(--pop-primary-soft); color: var(--pop-primary-dark); border-left-color: var(--pop-primary); font-weight: 700; }
        .pop-side i { width: 18px; text-align: center; color: var(--pop-muted); }
        .pop-side a.on i { color: var(--pop-primary); }

        .pop-main { padding: 18px 22px 40px; }
        .pop-head { display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
        .pop-head h1 { font-size: 21px; font-weight: 800; margin: 0; }
        .pop-head p { color: var(--pop-muted); margin: 3px 0 0; font-size: 13px; }
        .pop-head-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

        .pop-btn { border: 1px solid var(--pop-line); background: #fff; border-radius: 7px; padding: 7px 13px; font: inherit; font-size: 13.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .pop-btn:hover { background: #f7f8fa; }
        .pop-btn.primary { background: var(--pop-primary); border-color: var(--pop-primary); color: #fff; font-weight: 600; }
        .pop-btn.primary:hover { background: var(--pop-primary-dark); }
        .pop-btn.on { background: var(--pop-primary-soft); border-color: var(--pop-primary); color: var(--pop-primary-dark); }

        .pop-toolbar { background: #fff; border: 1px solid var(--pop-line); border-radius: 10px; padding: 10px 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
        .pop-toolbar .grow { flex: 1; min-width: 200px; }
        .pop-toolbar input { width: 100%; border: 1px solid var(--pop-line); border-radius: 7px; padding: 7px 11px; font: inherit; }
        .pop-viewswitch { display: flex; border: 1px solid var(--pop-line); border-radius: 7px; overflow: hidden; }
        .pop-viewswitch button { border: 0; background: #fff; padding: 7px 11px; cursor: pointer; font: inherit; color: var(--pop-muted); }
        .pop-viewswitch button.on { background: var(--pop-primary); color: #fff; }

        .pop-card { background: #fff; border: 1px solid var(--pop-line); border-radius: 10px; box-shadow: 0 1px 2px rgba(30,42,68,.04); }
        .pop-card-head { padding: 13px 16px; border-bottom: 1px solid var(--pop-line); font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .pop-card-head .spacer { margin-left: auto; font-weight: 400; color: var(--pop-muted); font-size: 12.5px; }
        .pop-card-body { padding: 14px 16px; }

        .pop-kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
        .pop-kpi { background: #fff; border: 1px solid var(--pop-line); border-radius: 10px; padding: 14px; display: flex; gap: 12px; align-items: flex-start; }
        .pop-kpi-icon { width: 38px; height: 38px; border-radius: 9px; display: grid; place-items: center; font-size: 17px; flex: none; }
        .pop-kpi-label { color: var(--pop-muted); font-size: 12.5px; }
        .pop-kpi-value { font-size: 22px; font-weight: 800; line-height: 1.25; }
        .pop-kpi-sub { color: var(--pop-muted); font-size: 12px; }
        .tone-up { background: var(--pop-green-soft); color: var(--pop-green); }
        .tone-warn { background: var(--pop-amber-soft); color: var(--pop-amber); }
        .tone-info { background: var(--pop-primary-soft); color: var(--pop-primary); }
        .tone-danger { background: var(--pop-red-soft); color: var(--pop-red); }
        .tone-primary { background: var(--pop-primary-soft); color: var(--pop-primary); }
        .delta-up { color: var(--pop-green); font-weight: 700; font-size: 12px; }

        table.pop-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .pop-table th { text-align: left; background: #fafbfc; border-bottom: 1px solid var(--pop-line); padding: 10px 12px; font-weight: 700; color: var(--pop-muted); white-space: nowrap; }
        .pop-table td { padding: 10px 12px; border-bottom: 1px solid #f2f4f7; }
        .pop-table tbody tr:hover { background: #fafbfc; }
        .pop-table .num { text-align: right; font-variant-numeric: tabular-nums; }

        .pop-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-green { background: var(--pop-green-soft); color: var(--pop-green); }
        .badge-amber { background: var(--pop-amber-soft); color: var(--pop-amber); }
        .badge-red { background: var(--pop-red-soft); color: var(--pop-red); }
        .badge-grey { background: var(--pop-grey-soft); color: var(--pop-muted); }

        .pop-bar-row { display: grid; grid-template-columns: 150px 1fr 92px; gap: 10px; align-items: center; margin-bottom: 10px; font-size: 13px; }
        .pop-bar-track { background: #f1f3f7; border-radius: 999px; height: 12px; overflow: hidden; }
        .pop-bar-fill { display: block; height: 100%; background: linear-gradient(90deg, #8f79ab, var(--pop-primary)); }

        .pop-note { background: var(--pop-amber-soft); border-left: 4px solid var(--pop-amber); color: #7a4a0b; padding: 11px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
        .pop-muted { color: var(--pop-muted); font-size: 12.5px; }

        @media (max-width: 1200px) { .pop-kpis { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 900px) { .pop-shell { grid-template-columns: 1fr; } .pop-side { display: none; } .pop-kpis { grid-template-columns: repeat(2, 1fr); } }
    </style>
    @stack('styles')
</head>
<body>
    @include('erp-mockup.partials.topbar')
    <div class="pop-search-bar"><div class="pop-search"><input placeholder="ค้นหาเมนู, เอกสาร, รายงาน..."><i class="bi bi-search"></i></div></div>
    <div class="pop-shell">
        @include('erp-mockup.partials.sidebar')
        <main class="pop-main">
            <div class="pop-note">
                <strong>หน้าตัวอย่างการออกแบบ</strong> — ข้อมูลทั้งหมดเป็นข้อมูลจำลอง ปุ่มยังไม่ทำงานจริง และไม่มีการอ่านหรือเขียนฐานข้อมูล
            </div>
            @yield('content')
        </main>
    </div>
</body>
</html>
