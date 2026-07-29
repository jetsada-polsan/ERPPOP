<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#eef2f5">
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
            color-scheme: light;
            --bg: #eef2f5;
            --card: #ffffff;
            --card-line: #dce3e9;
            --ink: #172635;
            --ink-2: #5e7180;
            --ink-3: #8a9aa6;
            --input-bg: #f8fafb;
            --input-line: #d4dee5;
            --brand: #bd2836;
            --brand-ink: #284f73;
            --shadow: 0 22px 50px rgba(25, 47, 64, .13), 0 2px 8px rgba(25, 47, 64, .06);
            --font: 'Noto Sans Thai', 'Leelawadee UI', 'Segoe UI', Tahoma, sans-serif;
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
        .card {
            position: relative;
            z-index: 1;
            width: min(460px, 100%);
            padding: 46px 42px 32px;
            border: 1px solid var(--card-line);
            border-radius: 16px;
            background: var(--card);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: rise .5s cubic-bezier(.22, 1, .36, 1) both;
        }
        .card::before { content: ""; position: absolute; inset: 0 0 auto; height: 5px; background: var(--brand); }
        @keyframes rise { from { opacity: 0; transform: translateY(14px); } }

        .brand { display: flex; align-items: center; gap: 14px; margin-bottom: 34px; }
        .brand-logo {
            display: grid; place-items: center;
            width: 58px; height: 58px; padding: 7px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(10, 30, 50, .14), 0 0 0 1px rgba(10, 30, 50, .05);
        }
        .brand-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .brand-copy { display: flex; flex-direction: column; gap: 2px; }
        .brand-kicker { color: var(--brand); font-size: 11px; font-weight: 900; letter-spacing: .04em; line-height: 1.2; }
        .brand-name { font-size: 13px; font-weight: 700; line-height: 1.4; color: var(--ink-2); }

        h1 { font-size: 29px; font-weight: 850; letter-spacing: 0; line-height: 1.25; }
        .sub { margin-top: 10px; color: var(--ink-2); font-size: 14px; line-height: 1.65; }

        form { margin-top: 30px; }
        label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-2); margin-bottom: 8px; }
        .field { position: relative; margin-bottom: 16px; }
        .field > i.lead {
            position: absolute; left: 15px; top: 41px;
            color: var(--ink-3); font-size: 15px; pointer-events: none;
            transition: color .16s;
        }
        .field:focus-within > i.lead { color: var(--brand); }
        input[type=text], input[type=password] {
            width: 100%; height: 54px;
            padding: 0 48px 0 43px;
            border: 1px solid var(--input-line);
            border-radius: 10px;
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
            border: none; border-radius: 10px;
            background: var(--brand-ink);
            color: #fff;
            font-size: 15px; font-weight: 800; font-family: inherit;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(40, 79, 115, .22);
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
        <span class="brand-copy">
            <span class="brand-kicker">POPSTAR 4M ERP</span>
            <span class="brand-name">{{ $companyTh }}</span>
        </span>
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
