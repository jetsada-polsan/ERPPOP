<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#12395a">
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
        /* สีชุดเดียวกับ layout.blade.php เพื่อให้หน้า login ต่อเนื่องกับหน้าจอที่ผู้ใช้เข้าไปเจอ */
        :root {
            --ink: #1d3b52;
            --ink-soft: #5b7488;
            --line: #dbe7ef;
            --blue: #1a9bdc;
            --blue-deep: #1585c0;
            --navy: #12395a;
            --navy-deep: #0d2c47;
            --accent: linear-gradient(135deg, #1a9bdc, #20a67a);
            --font: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: var(--font);
            color: var(--ink);
            background: #eef3f8;
            padding: 24px;
            display: grid;
            place-items: center;
            -webkit-font-smoothing: antialiased;
        }
        .shell {
            width: min(960px, 100%);
            display: grid;
            grid-template-columns: minmax(0, 1fr) 400px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 18px 50px rgba(20, 48, 71, .12);
        }

        /* ── ฝั่งซ้าย: บอกว่านี่คือระบบของใคร และครอบคลุมงานอะไร ── */
        .panel {
            position: relative;
            padding: 44px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 28px;
            background: linear-gradient(158deg, var(--navy), var(--navy-deep));
            color: #fff;
            overflow: hidden;
        }
        .panel-body { display: flex; flex-direction: column; gap: 22px; }
        /* ลายเส้นบางๆ แทนกราฟหลอก ให้พื้นหลังไม่ตายแต่ไม่แย่งความสนใจ */
        .panel::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: radial-gradient(circle at 78% 12%, #000, transparent 72%);
            -webkit-mask-image: radial-gradient(circle at 78% 12%, #000, transparent 72%);
            pointer-events: none;
        }
        .panel > * { position: relative; z-index: 1; }
        .org { display: flex; align-items: center; gap: 14px; }
        .org-logo {
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            width: 58px;
            height: 58px;
            padding: 7px;
            border-radius: 12px;
            background: #fff;
        }
        .org-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .org-name { font-size: 16px; font-weight: 800; line-height: 1.35; }
        .org-name small { display: block; margin-top: 3px; color: #9fc4dd; font-size: 11px; font-weight: 600; letter-spacing: .04em; }
        /* text-wrap: balance ช่วยเกลี่ยบรรทัด — สำคัญกับภาษาไทยที่ไม่มีเว้นวรรคระหว่างคำ
           เบราว์เซอร์จึงตัดกลางคำได้ถ้าปล่อยให้บรรทัดยาวเกินไป */
        .panel-lead {
            font-size: 13.5px;
            line-height: 1.75;
            color: #cfe3f1;
            max-width: 34ch;
            text-wrap: balance;
        }
        .modules { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .modules div { display: flex; align-items: center; gap: 9px; font-size: 13px; color: #e2eef7; }
        .modules i { color: #6fc6f0; font-size: 15px; }
        .panel-foot {
            padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,.13);
            display: flex;
            align-items: center;
            gap: 9px;
            color: #9fc4dd;
            font-size: 11.5px;
        }
        .panel-foot i { font-size: 13px; }

        /* ── ฝั่งขวา: ฟอร์ม ── */
        .card { display: flex; flex-direction: column; justify-content: center; padding: 48px 40px; }
        h1 { font-size: 23px; font-weight: 800; letter-spacing: -.01em; }
        .card-sub { margin-top: 6px; color: var(--ink-soft); font-size: 13px; line-height: 1.6; }
        form { margin-top: 26px; }
        label { display: block; font-size: 12.5px; font-weight: 700; color: #3c5668; margin-bottom: 6px; }
        .field { position: relative; margin-bottom: 16px; }
        .field > i {
            position: absolute; left: 13px; top: 37px;
            color: #90a8ba; font-size: 15px; pointer-events: none;
        }
        input[type=text], input[type=password] {
            width: 100%;
            min-height: 46px;
            padding: 12px 14px 12px 40px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font-size: 14.5px;
            font-family: inherit;
            color: var(--ink);
            background: #fbfdff;
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input::placeholder { color: #a8bccc; }
        input:focus {
            border-color: var(--blue);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(26,155,220,.15);
        }
        .toggle {
            position: absolute; right: 6px; top: 30px;
            width: 34px; height: 34px;
            display: grid; place-items: center;
            border: none; border-radius: 8px;
            background: none; color: #8ba3b6;
            font-size: 15px; cursor: pointer;
        }
        .toggle:hover { color: var(--blue-deep); background: #eef6fc; }
        .toggle:focus-visible { outline: 2px solid var(--blue); outline-offset: 1px; }
        .hint {
            display: none;
            margin-top: 7px;
            color: #a16207;
            font-size: 12px;
        }
        .hint.show { display: flex; align-items: center; gap: 6px; }
        .remember {
            display: flex; align-items: center; gap: 8px;
            margin: 2px 0 22px;
            font-size: 13px; font-weight: 400; color: #52697b;
            cursor: pointer;
        }
        .remember input { width: 16px; height: 16px; accent-color: var(--blue); cursor: pointer; }
        button[type=submit] {
            width: 100%;
            min-height: 46px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            border: none;
            border-radius: 10px;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            font-family: inherit;
            cursor: pointer;
            transition: filter .15s, opacity .15s;
        }
        button[type=submit]:hover { filter: brightness(1.06); }
        button[type=submit]:focus-visible { outline: 2px solid var(--blue-deep); outline-offset: 2px; }
        button[type=submit][disabled] { opacity: .72; cursor: progress; }
        .spin { display: none; animation: spin .7s linear infinite; }
        button[disabled] .spin { display: inline-block; }
        button[disabled] .label-idle, button[disabled] > i:not(.spin) { display: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .error {
            display: flex; align-items: flex-start; gap: 9px;
            margin-bottom: 20px;
            padding: 12px 14px;
            border: 1px solid #f6c9c9;
            border-left: 3px solid #dc2626;
            border-radius: 10px;
            background: #fef5f5;
            color: #a91d1d;
            font-size: 13px;
            line-height: 1.55;
        }
        .error i { margin-top: 1px; font-size: 14px; }
        .card-foot {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #eef3f7;
            color: #8aa1b2;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 860px) {
            body { padding: 0; place-items: stretch; background: #fff; }
            .shell {
                width: 100%;
                grid-template-columns: 1fr;
                /* แถบแบรนด์สูงเท่าเนื้อหา ที่เหลือยกให้ฟอร์ม ไม่งั้น grid แบ่งครึ่งจอ
                   แล้วเหลือพื้นที่น้ำเงินว่างๆ ครึ่งหน้า */
                grid-template-rows: auto 1fr;
                border: none; border-radius: 0; box-shadow: none;
            }
            .panel { padding: 22px 24px; justify-content: flex-start; }
            .panel-body, .panel-foot { display: none; }
            .org-logo { width: 48px; height: 48px; }
            .org-name { font-size: 15px; }
            .card { padding: 30px 24px 40px; justify-content: flex-start; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
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
            <p class="panel-lead">ระบบบริหารจัดการภายใน เชื่อมข้อมูลทุกสาขาไว้บนฐานข้อมูลเดียว</p>

            <div class="modules">
                <div><i class="bi bi-shop"></i> ขายและหน้าร้าน</div>
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
        <h1>เข้าสู่ระบบ</h1>
        <p class="card-sub">กรอกบัญชีผู้ใช้ที่ได้รับจากผู้ดูแลระบบเพื่อเริ่มใช้งาน</p>

        @if($errors->any())
            <div class="error" role="alert" aria-live="assertive" style="margin-top:22px;margin-bottom:0">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="post" action="{{ route('login.attempt') }}" id="loginForm">
            @csrf

            <div class="field">
                <label for="username">ชื่อผู้ใช้ อีเมล หรือเบอร์โทร</label>
                <i class="bi bi-person"></i>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       placeholder="เช่น somchai หรือ 0812345678"
                       required autofocus autocomplete="username" spellcheck="false">
            </div>

            <div class="field">
                <label for="password">รหัสผ่าน</label>
                <i class="bi bi-lock"></i>
                <input type="password" id="password" name="password"
                       placeholder="รหัสผ่านของคุณ"
                       required autocomplete="current-password">
                <button type="button" class="toggle" id="pwToggle"
                        aria-label="แสดงรหัสผ่าน" aria-pressed="false" tabindex="0">
                    <i class="bi bi-eye" id="pwIcon"></i>
                </button>
                <div class="hint" id="capsHint">
                    <i class="bi bi-capslock"></i> เปิด Caps Lock อยู่
                </div>
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
