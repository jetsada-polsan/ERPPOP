<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b2740">
    @php($faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg')))
    @php($companyTh = \App\Models\AppSetting::company('name_th'))
    @php($companyEn = \App\Models\AppSetting::company('name_en'))
    @php($companyLogo = \App\Models\AppSetting::logoUrl())
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ - JET ERP</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        :root {
            --ink: #14293b;
            --ink-2: #40607a;
            --ink-3: #7d97ab;
            --line: #e3ecf3;
            --blue: #1a9bdc;
            --blue-deep: #1179ae;
            --navy-1: #164a72;
            --navy-2: #0e3355;
            --navy-3: #08203a;
            /* ฟอนต์ไทยของ macOS/iOS (Sukhumvit Set) มาก่อน Tahoma
               ตัวเดิมตกไปใช้ Tahoma บนเครื่องที่ไม่ใช่ Windows ซึ่งดูเก่ากว่าที่ควร */
            --font: 'Leelawadee UI', 'Noto Sans Thai', 'Sukhumvit Set', 'Segoe UI', Tahoma, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: var(--font);
            color: var(--ink);
            background: #eaf0f6;
            padding: 28px;
            display: grid;
            place-items: center;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .shell {
            position: relative;
            width: min(1020px, 100%);
            display: grid;
            /* ฟอร์มกว้างกว่าแผงแบรนด์เล็กน้อย เพราะงานหลักของหน้านี้คือการล็อกอิน */
            grid-template-columns: minmax(0, 1fr) 448px;
            border-radius: 20px;
            overflow: hidden;
            background: #fff;
            box-shadow:
                0 1px 2px rgba(13, 42, 66, .05),
                0 12px 26px -8px rgba(13, 42, 66, .14),
                0 42px 68px -32px rgba(13, 42, 66, .3);
        }
        /* เส้นแสงบางๆ ที่ขอบบน ทำให้การ์ดดูมีมิติแทนที่จะเป็นสี่เหลี่ยมแบนๆ */
        .shell::after {
            content: "";
            position: absolute; inset: 0;
            border-radius: inherit;
            border-top: 1px solid rgba(255,255,255,.5);
            pointer-events: none;
        }

        /* ── แผงแบรนด์ ── */
        .panel {
            position: relative;
            isolation: isolate;
            padding: 46px 44px;
            display: flex;
            flex-direction: column;
            gap: 34px;
            color: #fff;
            background:
                radial-gradient(120% 90% at 88% -6%, rgba(64, 196, 255, .42), transparent 58%),
                radial-gradient(90% 80% at -12% 108%, rgba(32, 166, 122, .30), transparent 62%),
                linear-gradient(155deg, var(--navy-1), var(--navy-2) 46%, var(--navy-3));
        }
        /* noise บางๆ กัน gradient เป็นวงแถบ (banding) บนจอใหญ่ */
        .panel::before {
            content: "";
            position: absolute; inset: 0; z-index: -1;
            opacity: .5;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)' opacity='.5'/%3E%3C/svg%3E");
        }
        .panel::after {
            content: "";
            position: absolute; inset: 0; z-index: -1;
            background-image:
                linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: linear-gradient(200deg, #000 8%, transparent 62%);
            -webkit-mask-image: linear-gradient(200deg, #000 8%, transparent 62%);
        }

        .org { display: flex; align-items: center; gap: 15px; }
        .org-logo {
            flex: 0 0 auto;
            display: grid; place-items: center;
            width: 62px; height: 62px;
            padding: 8px;
            border-radius: 15px;
            background: #fff;
            box-shadow: 0 8px 20px -6px rgba(2, 18, 33, .55), 0 0 0 1px rgba(255,255,255,.5);
        }
        .org-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .org-name { font-size: 17px; font-weight: 800; line-height: 1.4; letter-spacing: -.005em; }
        .org-name small {
            display: block; margin-top: 4px;
            color: #93bedd; font-size: 10.5px; font-weight: 700;
            letter-spacing: .09em;
        }

        .panel-body { display: flex; flex-direction: column; gap: 26px; }
        /* กำหนดจุดขึ้นบรรทัดเองด้วย <br> เพราะภาษาไทยไม่มีเว้นวรรคระหว่างคำ
           ปล่อยให้เบราว์เซอร์ตัดเองจะได้ "เชื่อม|ข้อมูล" ซึ่งอ่านสะดุด
           (บล็อกนี้ซ่อนบนจอเล็ก ความกว้างจึงคาดเดาได้) */
        .panel-lead {
            max-width: 40ch;
            font-size: 21px;
            font-weight: 700;
            line-height: 1.6;
            letter-spacing: -.01em;
            color: #f2f9ff;
        }
        .modules { display: grid; gap: 1px; background: rgba(255,255,255,.11); border-radius: 14px; overflow: hidden; }
        .modules div {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 15px;
            background: rgba(255,255,255,.055);
            font-size: 13px;
            color: #dfeefb;
        }
        .modules i {
            display: grid; place-items: center;
            width: 24px; height: 24px;
            border-radius: 7px;
            background: rgba(120, 205, 255, .17);
            color: #8fd8ff;
            font-size: 12px;
        }

        .panel-foot {
            margin-top: auto;
            display: flex; align-items: center; gap: 9px;
            padding-top: 26px;
            border-top: 1px solid rgba(255,255,255,.12);
            color: #8fb6d4;
            font-size: 11.5px;
        }

        /* ── ฟอร์ม ── */
        .card {
            display: flex; flex-direction: column; justify-content: center;
            padding: 52px 44px;
            background: #fff;
        }
        .card-head { margin-bottom: 30px; }
        .card-eyebrow {
            display: block; margin-bottom: 10px;
            color: var(--blue-deep); font-size: 11px; font-weight: 800; letter-spacing: .12em;
        }
        h1 { font-size: 28px; font-weight: 800; letter-spacing: -.025em; line-height: 1.25; }
        .card-sub { margin-top: 8px; color: var(--ink-2); font-size: 13.5px; line-height: 1.6; }

        label { display: block; font-size: 12px; font-weight: 700; color: var(--ink-2); margin-bottom: 7px; letter-spacing: .01em; }
        .field { position: relative; margin-bottom: 18px; }
        .field > i.lead {
            position: absolute; left: 15px; top: 40px;
            color: #9fb4c5; font-size: 15px; pointer-events: none;
            transition: color .15s;
        }
        .field:focus-within > i.lead { color: var(--blue-deep); }
        input[type=text], input[type=password] {
            width: 100%;
            height: 50px;
            padding: 0 46px 0 43px;
            border: 1px solid var(--line);
            border-radius: 12px;
            font-size: 14.5px;
            font-family: inherit;
            color: var(--ink);
            background: #f9fbfd;
            outline: none;
            transition: border-color .16s, box-shadow .16s, background .16s;
        }
        input::placeholder { color: #a9bccb; }
        input:hover:not(:focus) { border-color: #cfdde8; }
        input:focus {
            border-color: var(--blue);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(26, 155, 220, .13);
        }
        .toggle {
            position: absolute; right: 7px; top: 32px;
            width: 36px; height: 36px;
            display: grid; place-items: center;
            border: none; border-radius: 9px;
            background: none; color: #94a9ba;
            font-size: 15px; cursor: pointer;
            transition: color .15s, background .15s;
        }
        .toggle:hover { color: var(--blue-deep); background: #eff7fc; }
        .toggle:focus-visible { outline: 2px solid var(--blue); outline-offset: 1px; }

        .hint { display: none; margin-top: 8px; color: #b45309; font-size: 12px; }
        .hint.show { display: flex; align-items: center; gap: 6px; }

        .remember {
            display: inline-flex; align-items: center; gap: 9px;
            margin: 2px 0 24px;
            font-size: 13px; color: var(--ink-2);
            cursor: pointer; user-select: none;
        }
        .remember input { width: 17px; height: 17px; accent-color: var(--blue); cursor: pointer; }

        button[type=submit] {
            width: 100%;
            height: 50px;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(180deg, #23a7e6, var(--blue-deep));
            color: #fff;
            font-size: 15px; font-weight: 800; font-family: inherit;
            letter-spacing: .01em;
            cursor: pointer;
            box-shadow: 0 1px 1px rgba(255,255,255,.25) inset, 0 8px 18px -6px rgba(17, 121, 174, .6);
            transition: filter .16s, box-shadow .16s, transform .06s;
        }
        button[type=submit]:hover { filter: brightness(1.07); box-shadow: 0 1px 1px rgba(255,255,255,.25) inset, 0 11px 22px -6px rgba(17, 121, 174, .68); }
        button[type=submit]:active { transform: translateY(1px); }
        button[type=submit]:focus-visible { outline: 2px solid var(--blue-deep); outline-offset: 3px; }
        button[type=submit][disabled] { opacity: .68; cursor: progress; box-shadow: none; }
        .spin { display: none; animation: spin .7s linear infinite; }
        button[disabled] .spin { display: inline-block; }
        button[disabled] .label-idle, button[disabled] > i:not(.spin) { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .error {
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 22px;
            padding: 13px 15px;
            border: 1px solid #f7cccc;
            border-left: 3px solid #d92d2d;
            border-radius: 11px;
            background: #fdf4f4;
            color: #a51c1c;
            font-size: 13px; line-height: 1.55;
        }
        .error i { margin-top: 1px; }

        .card-foot {
            margin-top: 28px; padding-top: 20px;
            border-top: 1px solid #f0f5f9;
            color: var(--ink-3); font-size: 12px; text-align: center;
        }

        @media (max-width: 880px) {
            body { padding: 0; place-items: stretch; background: #fff; }
            .shell {
                width: 100%;
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
                border-radius: 0;
                box-shadow: none;
            }
            .panel { padding: 26px 24px; gap: 0; }
            .panel-body, .panel-foot { display: none; }
            .org-logo { width: 52px; height: 52px; border-radius: 13px; }
            .org-name { font-size: 15.5px; }
            .card { padding: 32px 24px 40px; justify-content: flex-start; }
            h1 { font-size: 23px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<main class="shell">
    <section class="panel">
        <div class="org">
            @if($companyLogo)
                <span class="org-logo"><img src="{{ $companyLogo }}" alt=""></span>
            @endif
            <span class="org-name">
                {{ $companyTh }}
                @if($companyEn)<small>{{ $companyEn }}</small>@endif
            </span>
        </div>

        <div class="panel-body">
            <p class="panel-lead">ระบบบริหารจัดการภายใน<br>เชื่อมข้อมูลทุกสาขาไว้บนฐานข้อมูลเดียว</p>

            <div class="modules">
                <div><i class="bi bi-shop"></i> ขายและงานหน้าร้าน</div>
                <div><i class="bi bi-boxes"></i> สต็อกและคลังสินค้า</div>
                <div><i class="bi bi-cart-check"></i> จัดซื้อและเจ้าหนี้</div>
                <div><i class="bi bi-calculator"></i> บัญชีและภาษี</div>
            </div>
        </div>

        <div class="panel-foot">
            <i class="bi bi-shield-lock"></i>
            <span>ระบบภายในองค์กร · การเข้าใช้งานทุกครั้งถูกบันทึกไว้</span>
        </div>
    </section>

    <div class="card">
        <div class="card-head">
            <span class="card-eyebrow">JET ERP</span>
            <h1>เข้าสู่ระบบ</h1>
            <p class="card-sub">กรอกบัญชีผู้ใช้ที่ได้รับจากผู้ดูแลระบบเพื่อเริ่มใช้งาน</p>
        </div>

        @if($errors->any())
            <div class="error" role="alert" aria-live="assertive">
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
                <i class="bi bi-box-arrow-in-right"></i>
                <i class="bi bi-arrow-repeat spin"></i>
                <span class="label-idle">เข้าสู่ระบบ</span>
                <span class="label-busy" hidden>กำลังตรวจสอบ…</span>
            </button>
        </form>

        <p class="card-foot">ลืมรหัสผ่าน หรือยังไม่มีบัญชี ติดต่อผู้ดูแลระบบ</p>
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
