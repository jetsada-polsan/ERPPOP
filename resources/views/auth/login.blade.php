<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg')))
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ - PopCentral</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700;800&display=swap');

        :root {
            --ink: #10233a;
            --ink-soft: #5b7186;
            --ink-faint: #93a5b7;
            --line: #e2eaf2;
            --surface: #ffffff;
            --bg: #eef3f9;
            --primary: #1467c7;
            --primary-dark: #0e4c96;
            --teal: #16a893;
            --amber: #f2994a;
            --danger-bg: #fef2f2;
            --danger-border: #f8caca;
            --danger-text: #b3261e;
            --radius-lg: 26px;
            --radius-md: 14px;
            --radius-sm: 10px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: 'IBM Plex Sans Thai', 'Noto Sans Thai', 'Leelawadee UI', Tahoma, 'Segoe UI', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1100px 620px at 14% -8%, rgba(20, 103, 199, .10), transparent 60%),
                radial-gradient(900px 560px at 100% 108%, rgba(22, 168, 147, .12), transparent 55%),
                var(--bg);
            padding: clamp(14px, 3vw, 32px);
            display: grid;
            place-items: center;
        }

        .login-shell {
            width: min(1040px, 100%);
            min-height: min(620px, calc(100vh - 2 * clamp(14px, 3vw, 32px)));
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) clamp(360px, 36vw, 420px);
            overflow: hidden;
            border-radius: var(--radius-lg);
            background: var(--surface);
            box-shadow:
                0 1px 2px rgba(16, 35, 58, .04),
                0 30px 70px -20px rgba(16, 35, 58, .28);
            animation: rise .55s cubic-bezier(.2, .7, .2, 1) both;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(14px) scale(.99); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ---------- Story / brand panel ---------- */
        .login-story {
            position: relative;
            padding: clamp(30px, 3.8vw, 48px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #fff;
            overflow: hidden;
            background:
                linear-gradient(155deg, #0b2d55 0%, #0e4c96 46%, #127a86 100%);
        }
        .login-story .grid-tex {
            position: absolute;
            inset: 0;
            opacity: .5;
            background-image: radial-gradient(rgba(255,255,255,.16) 1px, transparent 1px);
            background-size: 22px 22px;
            mask-image: radial-gradient(circle at 30% 20%, #000 0%, transparent 72%);
            pointer-events: none;
        }
        .login-story .glow-a {
            position: absolute;
            width: 460px;
            height: 460px;
            right: -180px;
            top: -160px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.18), transparent 68%);
            pointer-events: none;
        }
        .login-story .glow-b {
            position: absolute;
            width: 380px;
            height: 380px;
            left: -140px;
            bottom: -160px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(242,153,74,.20), transparent 70%);
            pointer-events: none;
        }
        .story-brand, .story-content, .story-panel, .story-foot { position: relative; z-index: 1; }
        .story-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: clamp(17px, 1.7vw, 20px);
            font-weight: 800;
            letter-spacing: .01em;
        }
        .story-brand img {
            width: clamp(36px, 3.4vw, 44px);
            height: clamp(36px, 3.4vw, 44px);
            padding: 7px;
            border-radius: 13px;
            background: rgba(255,255,255,.97);
            box-shadow: 0 10px 26px rgba(0,0,0,.2);
        }
        .story-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #ffd9ad;
            font-size: clamp(10.5px, .95vw, 11.5px);
            font-weight: 800;
            letter-spacing: .12em;
            margin-top: clamp(20px, 3vw, 30px);
        }
        .story-kicker::before {
            content: "";
            width: 22px;
            height: 2px;
            border-radius: 2px;
            background: var(--amber);
        }
        .story-title {
            max-width: 19ch;
            margin: 14px 0 12px;
            font-size: clamp(25px, 2.9vw, 38px);
            line-height: 1.18;
            font-weight: 800;
            letter-spacing: -.01em;
        }
        .story-copy {
            max-width: 42ch;
            color: #d7ecf5;
            font-size: clamp(13px, 1.05vw, 14.5px);
            line-height: 1.75;
        }
        .story-panel {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: clamp(10px, 1.1vw, 14px);
            margin-top: clamp(20px, 2.8vw, 30px);
        }
        .stat-card {
            border: 1px solid rgba(255,255,255,.18);
            border-radius: var(--radius-md);
            background: rgba(255,255,255,.08);
            backdrop-filter: blur(14px);
            padding: clamp(13px, 1.4vw, 16px);
        }
        .stat-card .icon {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            background: rgba(255,255,255,.16);
            font-size: 14px;
            margin-bottom: 12px;
        }
        .stat-card .label { display: block; color: #bfe6ee; font-size: clamp(10px, .9vw, 11px); font-weight: 700; letter-spacing: .02em; }
        .stat-card .value { display: block; margin-top: 6px; font-size: clamp(18px, 1.9vw, 23px); font-weight: 800; line-height: 1.1; }
        .stat-card .bars { display: flex; align-items: end; gap: 4px; height: 30px; margin-top: 10px; }
        .stat-card .bars i { flex: 1; border-radius: 3px 3px 0 0; background: linear-gradient(#fff, #9fe8d6); opacity: .9; }
        .story-foot {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #bfe1ea;
            font-size: 11.5px;
            padding-top: clamp(18px, 2.4vw, 24px);
            border-top: 1px solid rgba(255,255,255,.14);
        }

        /* ---------- Form panel ---------- */
        .login-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: clamp(32px, 3.6vw, 50px) clamp(28px, 3.2vw, 44px);
            background: var(--surface);
        }
        .brand { text-align: center; margin-bottom: 6px; }
        .brand img { max-height: clamp(42px, 4vw, 50px); max-width: min(180px, 70%); object-fit: contain; }
        .brand-text { font-size: clamp(21px, 2.2vw, 27px); font-weight: 800; color: var(--ink); }
        .subtitle {
            text-align: center;
            color: var(--ink-soft);
            font-size: clamp(12.5px, 1.05vw, 13.5px);
            line-height: 1.55;
            margin: 6px 0 clamp(22px, 2.6vw, 28px);
        }

        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            border-radius: var(--radius-sm);
            padding: 11px 13px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .alert-error i { margin-top: 1px; }

        label.field-label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #33495d;
            margin: 14px 0 6px;
        }
        .field { position: relative; }
        .field i.field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-faint);
            font-size: 15px;
            transition: color .15s;
        }
        .field input[type=text],
        .field input[type=password] {
            width: 100%;
            min-height: 48px;
            padding: 11px 14px 11px 42px;
            border: 1.5px solid var(--line);
            border-radius: var(--radius-sm);
            font-size: 16px;
            font-family: inherit;
            color: var(--ink);
            outline: none;
            background: #f9fbfd;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .field input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(20,103,199,.13); background: #fff; }
        .field input:focus + i.field-icon,
        .field input:focus ~ i.field-icon { color: var(--primary); }
        .field-toggle {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--ink-faint);
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: grid;
            place-items: center;
            cursor: pointer;
            font-size: 15px;
            transition: background .15s, color .15s;
        }
        .field-toggle:hover { background: #eef3f9; color: var(--ink-soft); }

        .row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 17px 0 22px;
        }
        .remember { display: flex; align-items: center; gap: 9px; font-size: 13px; color: var(--ink-soft); cursor: pointer; user-select: none; }
        .switch { position: relative; width: 34px; height: 20px; flex: none; }
        .switch input { position: absolute; inset: 0; opacity: 0; cursor: pointer; margin: 0; }
        .switch .track {
            position: absolute; inset: 0;
            background: #d7e1ea;
            border-radius: 20px;
            transition: background .18s;
        }
        .switch .track::after {
            content: "";
            position: absolute;
            top: 2px; left: 2px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.25);
            transition: transform .18s;
        }
        .switch input:checked + .track { background: var(--primary); }
        .switch input:checked + .track::after { transform: translateX(14px); }
        .switch input:focus-visible + .track { box-shadow: 0 0 0 3px rgba(20,103,199,.25); }

        button.submit {
            width: 100%;
            min-height: 50px;
            border: none;
            border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--primary), var(--teal));
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            cursor: pointer;
            box-shadow: 0 14px 26px -8px rgba(20,103,199,.45);
            transition: transform .15s, box-shadow .15s, filter .15s;
        }
        button.submit:hover { transform: translateY(-1px); box-shadow: 0 18px 32px -8px rgba(20,103,199,.5); }
        button.submit:active { transform: translateY(0); filter: brightness(.97); }

        .foot-note {
            text-align: center;
            color: var(--ink-faint);
            font-size: 11.5px;
            margin-top: 22px;
        }
        .secure-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #6a8b7c;
            font-size: 11px;
            font-weight: 700;
            margin-top: 12px;
        }
        .secure-badge i { color: var(--teal); }

        /* ---------- Breakpoints ---------- */
        @media (max-width: 1150px) {
            .story-title { max-width: 17ch; }
            .story-copy { max-width: 38ch; }
        }
        @media (min-width: 881px) and (max-width: 1000px) {
            .story-panel { display: none; }
        }
        @media (min-width: 881px) and (max-height: 700px) {
            .story-panel { display: none; }
            .login-shell { min-height: auto; }
        }
        @media (max-width: 880px) {
            body { padding: 14px; align-items: start; }
            .login-shell { grid-template-columns: 1fr; max-width: 460px; min-height: auto; }
            .login-story { padding: clamp(24px, 6.5vw, 30px); }
            .story-title { font-size: clamp(21px, 6vw, 27px); max-width: 20ch; }
            .story-copy, .story-panel, .story-foot { display: none; }
            .login-card { padding: clamp(28px, 7vw, 36px) clamp(22px, 6vw, 28px); }
        }
        @media (max-width: 380px) {
            body { padding: 0; }
            .login-shell { border-radius: 0; box-shadow: none; max-width: none; }
            .brand { margin-bottom: 4px; }
            .subtitle { margin-bottom: 16px; }
            label.field-label { margin: 10px 0 5px; }
            .row-between { margin: 14px 0 18px; }
        }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-story">
        <div class="grid-tex" aria-hidden="true"></div>
        <div class="glow-a" aria-hidden="true"></div>
        <div class="glow-b" aria-hidden="true"></div>
        <div class="story-brand">
            <img src="{{ asset('images/logo-jet-erp-mark.svg') }}" alt="PopCentral">
            PopCentral
        </div>
        <div class="story-content">
            <div class="story-kicker">แพลตฟอร์มบริหารงานธุรกิจ</div>
            <h1 class="story-title">บริหารยอดขาย สต๊อก และต้นทุน ในที่เดียว แบบเรียลไทม์</h1>
            <p class="story-copy">เชื่อมทุกสาขา หน้าร้าน และคลังสินค้าไว้ในระบบเดียว พร้อมข้อมูลที่แม่นยำสำหรับการตัดสินใจทุกวัน</p>
            <div class="story-panel">
                <div class="stat-card">
                    <div class="icon"><i class="bi bi-shop"></i></div>
                    <span class="label">หลายสาขา</span>
                    <strong class="value">เชื่อมต่อกัน</strong>
                </div>
                <div class="stat-card">
                    <div class="icon"><i class="bi bi-boxes"></i></div>
                    <span class="label">ควบคุมสต๊อก</span>
                    <strong class="value">เรียลไทม์</strong>
                    <div class="bars" aria-hidden="true"><i style="height:38%"></i><i style="height:58%"></i><i style="height:46%"></i><i style="height:74%"></i><i style="height:64%"></i><i style="height:90%"></i></div>
                </div>
            </div>
        </div>
        <div class="story-foot">
            <i class="bi bi-shield-check"></i>
            PopCentral · สร้างเพื่อการดำเนินงานของ POPSTAR
        </div>
    </section>

    <form class="login-card" method="post" action="{{ route('login.attempt') }}">
        @csrf
        <div class="brand">
            @if($logo = \App\Models\AppSetting::logoUrl())
                <img src="{{ $logo }}" alt="logo">
            @else
                <div class="brand-text">{{ \App\Models\AppSetting::company('name_th') }}</div>
            @endif
        </div>
        <div class="subtitle">เข้าสู่ระบบศูนย์กลางการทำงานของ PopCentral</div>

        @if($errors->any())
            <div class="alert-error"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ $errors->first() }}</span></div>
        @endif

        <label class="field-label" for="username">ชื่อผู้ใช้ อีเมล หรือเบอร์โทรศัพท์</label>
        <div class="field">
            <i class="bi bi-person-fill field-icon"></i>
            <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
        </div>

        <label class="field-label" for="password">รหัสผ่าน</label>
        <div class="field">
            <i class="bi bi-lock-fill field-icon"></i>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="field-toggle" aria-label="แสดง/ซ่อนรหัสผ่าน" onclick="const p=document.getElementById('password'); const i=this.querySelector('i'); const show=p.type==='password'; p.type = show ? 'text' : 'password'; i.className = show ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';">
                <i class="bi bi-eye-fill"></i>
            </button>
        </div>

        <div class="row-between">
            <label class="remember">
                <span class="switch">
                    <input type="checkbox" name="remember" value="1">
                    <span class="track" aria-hidden="true"></span>
                </span>
                จดจำการเข้าสู่ระบบบนอุปกรณ์นี้
            </label>
        </div>

        <button type="submit" class="submit"><i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ</button>

        <div class="secure-badge"><i class="bi bi-shield-lock-fill"></i> การเชื่อมต่อได้รับการป้องกัน</div>
        <div class="foot-note">ลืมรหัสผ่าน? กรุณาติดต่อผู้ดูแลระบบ</div>
    </form>
</main>
</body>
</html>
