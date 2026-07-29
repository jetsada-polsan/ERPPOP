<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f4f7fb" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#080f18" media="(prefers-color-scheme: dark)">
    @php($faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg')))
    @php($companyTh = \App\Models\AppSetting::company('name_th'))
    @php($companyLogo = \App\Models\AppSetting::logoUrl())
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ - JET ERP</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f7fb;
            --card: #ffffff;
            --card-line: rgba(17, 45, 71, .09);
            --ink: #0f2439;
            --ink-2: #55708a;
            --ink-3: #8ba3b8;
            --input-bg: #f7fafc;
            --input-line: #e2eaf2;
            --brand: #1a9bdc;
            --brand-ink: #0e7fbb;
            --shadow: 0 1px 2px rgba(12, 38, 61, .04), 0 10px 24px -10px rgba(12, 38, 61, .16), 0 40px 60px -40px rgba(12, 38, 61, .28);
            --glow-1: rgba(64, 178, 245, .30);
            --glow-2: rgba(32, 190, 150, .22);
            --glow-3: rgba(126, 130, 245, .18);
            --font: 'Leelawadee UI', 'Noto Sans Thai', 'Sukhumvit Set', 'Segoe UI', Tahoma, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #080f18;
                --card: rgba(20, 31, 45, .72);
                --card-line: rgba(255, 255, 255, .09);
                --ink: #eaf2f9;
                --ink-2: #93a9be;
                --ink-3: #6b8299;
                --input-bg: rgba(255, 255, 255, .04);
                --input-line: rgba(255, 255, 255, .11);
                --brand: #3fb4ec;
                --brand-ink: #2aa3e0;
                --shadow: 0 20px 60px -20px rgba(0, 0, 0, .8);
                --glow-1: rgba(34, 132, 200, .38);
                --glow-2: rgba(22, 140, 110, .26);
                --glow-3: rgba(86, 92, 200, .24);
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            color: var(--ink);
            background: var(--bg);
            display: grid;
            place-items: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        /* แสงพื้นหลังแบบ mesh — วางเป็นชั้นคงที่ ไม่ scroll ตามเนื้อหา */
        body::before {
            content: "";
            position: fixed; inset: 0; z-index: 0;
            background:
                radial-gradient(46% 38% at 16% 12%, var(--glow-1), transparent 68%),
                radial-gradient(42% 40% at 86% 22%, var(--glow-3), transparent 66%),
                radial-gradient(52% 44% at 72% 92%, var(--glow-2), transparent 70%);
            pointer-events: none;
        }
        /* noise กัน gradient เป็นวงแถบบนจอใหญ่ */
        body::after {
            content: "";
            position: fixed; inset: 0; z-index: 0;
            opacity: .35;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23n)' opacity='.55'/%3E%3C/svg%3E");
            pointer-events: none;
        }

        .card {
            position: relative;
            z-index: 1;
            width: min(430px, 100%);
            padding: 40px 38px 32px;
            border: 1px solid var(--card-line);
            border-radius: 22px;
            background: var(--card);
            box-shadow: var(--shadow);
            backdrop-filter: blur(24px) saturate(140%);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            animation: rise .5s cubic-bezier(.22, 1, .36, 1) both;
        }
        @keyframes rise { from { opacity: 0; transform: translateY(14px); } }

        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; }
        .brand-logo {
            display: grid; place-items: center;
            width: 46px; height: 46px; padding: 6px;
            border-radius: 13px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(10, 30, 50, .14), 0 0 0 1px rgba(10, 30, 50, .05);
        }
        .brand-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .brand-name { font-size: 13px; font-weight: 700; line-height: 1.4; color: var(--ink-2); }

        h1 { font-size: 30px; font-weight: 800; letter-spacing: -.03em; line-height: 1.2; }
        .sub { margin-top: 9px; color: var(--ink-2); font-size: 13.5px; line-height: 1.6; }

        form { margin-top: 28px; }
        label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-2); margin-bottom: 8px; }
        .field { position: relative; margin-bottom: 16px; }
        .field > i.lead {
            position: absolute; left: 15px; top: 41px;
            color: var(--ink-3); font-size: 15px; pointer-events: none;
            transition: color .16s;
        }
        .field:focus-within > i.lead { color: var(--brand); }
        input[type=text], input[type=password] {
            width: 100%; height: 52px;
            padding: 0 48px 0 43px;
            border: 1px solid var(--input-line);
            border-radius: 14px;
            font-size: 15px; font-family: inherit;
            color: var(--ink);
            background: var(--input-bg);
            outline: none;
            transition: border-color .16s, box-shadow .16s, background .16s;
        }
        input::placeholder { color: var(--ink-3); }
        input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 18%, transparent);
        }
        .toggle {
            position: absolute; right: 8px; top: 34px;
            width: 36px; height: 36px;
            display: grid; place-items: center;
            border: none; border-radius: 10px;
            background: none; color: var(--ink-3);
            font-size: 15px; cursor: pointer;
            transition: color .16s, background .16s;
        }
        .toggle:hover { color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, transparent); }
        .toggle:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }

        .hint { display: none; margin-top: 9px; color: #d08700; font-size: 12px; }
        .hint.show { display: flex; align-items: center; gap: 6px; }

        .remember {
            display: inline-flex; align-items: center; gap: 9px;
            margin: 4px 0 22px;
            font-size: 13px; color: var(--ink-2);
            cursor: pointer; user-select: none;
        }
        .remember input { width: 17px; height: 17px; accent-color: var(--brand); cursor: pointer; }

        button[type=submit] {
            width: 100%; height: 52px;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            border: none; border-radius: 14px;
            background: var(--brand-ink);
            color: #fff;
            font-size: 15px; font-weight: 800; font-family: inherit;
            cursor: pointer;
            box-shadow: 0 6px 18px -6px color-mix(in srgb, var(--brand-ink) 75%, transparent);
            transition: filter .16s, transform .07s, box-shadow .16s;
        }
        button[type=submit]:hover { filter: brightness(1.09); box-shadow: 0 10px 24px -7px color-mix(in srgb, var(--brand-ink) 80%, transparent); }
        button[type=submit]:active { transform: translateY(1px); }
        button[type=submit]:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; }
        button[type=submit][disabled] { opacity: .65; cursor: progress; box-shadow: none; }
        .spin { display: none; animation: spin .7s linear infinite; }
        button[disabled] .spin { display: inline-block; }
        button[disabled] .label-idle, button[disabled] > i:not(.spin) { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .error {
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 20px; padding: 13px 15px;
            border: 1px solid color-mix(in srgb, #e5484d 32%, transparent);
            border-radius: 12px;
            background: color-mix(in srgb, #e5484d 9%, transparent);
            color: #d3383d;
            font-size: 13px; line-height: 1.55;
        }
        .error i { margin-top: 1px; }

        .foot {
            margin-top: 26px; padding-top: 18px;
            border-top: 1px solid var(--card-line);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            color: var(--ink-3); font-size: 11.5px;
        }
        .foot span { display: flex; align-items: center; gap: 6px; }

        @media (max-width: 460px) {
            body { padding: 0; place-items: stretch; }
            .card {
                width: 100%; min-height: 100%;
                display: flex; flex-direction: column; justify-content: center;
                padding: 40px 24px;
                border: none; border-radius: 0;
                box-shadow: none; backdrop-filter: none;
                background: var(--bg);
            }
            h1 { font-size: 27px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        @if($companyLogo)
            <span class="brand-logo"><img src="{{ $companyLogo }}" alt=""></span>
        @endif
        <span class="brand-name">{{ $companyTh }}</span>
    </div>

    <h1>เข้าสู่ระบบ</h1>
    <p class="sub">กรอกบัญชีผู้ใช้ที่ได้รับจากผู้ดูแลระบบเพื่อเริ่มใช้งาน</p>

    @if($errors->any())
        <div class="error" role="alert" aria-live="assertive" style="margin-top:24px;margin-bottom:0">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="post" action="{{ route('login.attempt') }}" id="loginForm">
        @csrf

        <div class="field">
            <label for="username">ชื่อผู้ใช้ อีเมล หรือเบอร์โทร</label>
            <i class="bi bi-person lead"></i>
            <input type="text" id="username" name="username" value="{{ old('username') }}"
                   placeholder="เช่น somchai หรือ 0812345678"
                   required autofocus autocomplete="username" spellcheck="false">
        </div>

        <div class="field">
            <label for="password">รหัสผ่าน</label>
            <i class="bi bi-lock lead"></i>
            <input type="password" id="password" name="password"
                   placeholder="รหัสผ่านของคุณ"
                   required autocomplete="current-password">
            <button type="button" class="toggle" id="pwToggle" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                <i class="bi bi-eye" id="pwIcon"></i>
            </button>
            <div class="hint" id="capsHint"><i class="bi bi-capslock"></i> เปิด Caps Lock อยู่</div>
        </div>

        <label class="remember">
            <input type="checkbox" name="remember" value="1"> จดจำการเข้าสู่ระบบในเครื่องนี้
        </label>

        <button type="submit" id="submitBtn">
            <i class="bi bi-arrow-right-short" style="font-size:20px"></i>
            <i class="bi bi-arrow-repeat spin"></i>
            <span class="label-idle">เข้าสู่ระบบ</span>
            <span class="label-busy" hidden>กำลังตรวจสอบ…</span>
        </button>
    </form>

    <div class="foot">
        <span><i class="bi bi-shield-lock"></i> การเข้าใช้งานถูกบันทึกไว้</span>
        <span>ลืมรหัสผ่าน ติดต่อผู้ดูแลระบบ</span>
    </div>
</main>

<script>
    (function () {
        var password = document.getElementById('password');
        var toggle = document.getElementById('pwToggle');
        var icon = document.getElementById('pwIcon');
        var caps = document.getElementById('capsHint');
        var form = document.getElementById('loginForm');
        var button = document.getElementById('submitBtn');

        toggle.addEventListener('click', function () {
            var shown = password.type === 'text';
            password.type = shown ? 'password' : 'text';
            icon.className = shown ? 'bi bi-eye' : 'bi bi-eye-slash';
            toggle.setAttribute('aria-pressed', String(!shown));
            toggle.setAttribute('aria-label', shown ? 'แสดงรหัสผ่าน' : 'ซ่อนรหัสผ่าน');
            password.focus();
        });

        // เตือน Caps Lock เพราะเป็นสาเหตุอันดับต้นๆ ที่รหัสผ่านถูกต้องแต่เข้าไม่ได้
        function checkCaps(event) {
            if (typeof event.getModifierState !== 'function') return;
            caps.classList.toggle('show', event.getModifierState('CapsLock'));
        }
        password.addEventListener('keydown', checkCaps);
        password.addEventListener('keyup', checkCaps);
        password.addEventListener('blur', function () { caps.classList.remove('show'); });

        // กันกดซ้ำระหว่างรอเซิร์ฟเวอร์ตอบ
        form.addEventListener('submit', function () {
            button.disabled = true;
            button.querySelector('.label-busy').hidden = false;
        });
    })();
</script>
</body>
</html>
