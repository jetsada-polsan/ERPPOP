<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070b1a">
    @php($faviconUrl = \App\Models\AppSetting::logoUrl() ?: asset('images/logos/mpm-food-group.jpg').'?v='.filemtime(public_path('images/logos/mpm-food-group.jpg')))
    @php($companyName = \App\Models\AppSetting::company('name_th') ?: 'MPM Food Group')
    @php($logo = \App\Models\AppSetting::logoUrl() ?: asset('images/logos/mpm-food-group.jpg'))
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ JET ERP - {{ $companyName }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap');

        :root {
            --bg: #070b1a;
            --ink: #eef3ff;
            --ink-soft: #a9b6d3;
            --ink-faint: #6f7d9c;
            --glass: rgba(255, 255, 255, .06);
            --glass-strong: rgba(255, 255, 255, .09);
            --glass-line: rgba(255, 255, 255, .12);
            --cyan: #38e1ff;
            --violet: #8b5cf6;
            --blue: #3b82f6;
            --mint: #34f5c5;
            --danger: #ff8a8a;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; }
        body {
            min-height: 100vh;
            font-family: 'IBM Plex Sans Thai', 'Noto Sans Thai', 'Leelawadee UI', Tahoma, sans-serif;
            color: var(--ink);
            background: var(--bg);
            overflow-x: hidden;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        /* ---------- พื้นหลัง: แสงออโรร่า + ตาราง + ดาว ---------- */
        .aurora { position: fixed; inset: 0; overflow: hidden; z-index: 0; pointer-events: none; }
        .aurora .blob {
            position: absolute;
            width: 62vmax; height: 62vmax;
            border-radius: 50%;
            filter: blur(90px);
            opacity: .55;
            mix-blend-mode: screen;
            animation: drift 22s ease-in-out infinite alternate;
        }
        .aurora .b1 { background: radial-gradient(circle, var(--violet), transparent 65%); top: -28vmax; left: -18vmax; }
        .aurora .b2 { background: radial-gradient(circle, var(--cyan), transparent 65%); bottom: -30vmax; right: -20vmax; animation-delay: -7s; }
        .aurora .b3 { background: radial-gradient(circle, var(--blue), transparent 65%); top: 30%; left: 40%; width: 40vmax; height: 40vmax; opacity: .35; animation-delay: -13s; }
        @keyframes drift {
            0%   { transform: translate(0, 0) scale(1); }
            50%  { transform: translate(6vmax, 4vmax) scale(1.08); }
            100% { transform: translate(-4vmax, 7vmax) scale(.95); }
        }
        .grid-floor {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(140, 170, 255, .07) 1px, transparent 1px),
                linear-gradient(90deg, rgba(140, 170, 255, .07) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse at 50% 45%, #000 0%, transparent 70%);
        }
        .noise {
            position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: .05;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
        }

        /* ---------- โครงหลัก ---------- */
        .shell {
            position: relative; z-index: 1;
            width: min(1080px, 100%);
            display: grid;
            grid-template-columns: 1.1fr minmax(340px, 420px);
            gap: clamp(28px, 5vw, 72px);
            align-items: center;
        }

        /* ---------- ฝั่งซ้าย: แบรนด์ ---------- */
        .hero { animation: fadeUp .8s cubic-bezier(.2, .7, .2, 1) both; }
        .brand-row { display: flex; align-items: center; gap: 12px; margin-bottom: 36px; }
        .brand-row img {
            width: 46px; height: 46px; object-fit: contain;
            padding: 6px; border-radius: 14px; background: #fff;
            box-shadow: 0 0 0 1px rgba(255,255,255,.2), 0 10px 30px rgba(56, 225, 255, .25);
        }
        .brand-row span { font-weight: 700; font-size: 17px; letter-spacing: .01em; }
        .chip {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 12px 6px 8px; border-radius: 999px;
            background: var(--glass); border: 1px solid var(--glass-line);
            font-size: 12px; color: var(--ink-soft); backdrop-filter: blur(10px);
        }
        .chip .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--mint); box-shadow: 0 0 12px var(--mint); animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse { 50% { opacity: .35; } }
        .hero h1 {
            margin: 18px 0 16px;
            font-size: clamp(32px, 4.6vw, 58px);
            line-height: 1.1;
            font-weight: 700;
            letter-spacing: -.02em;
        }
        .hero h1 .grad {
            background: linear-gradient(100deg, var(--cyan), #a78bfa 45%, var(--mint));
            background-size: 200% auto;
            -webkit-background-clip: text; background-clip: text; color: transparent;
            animation: shine 6s linear infinite;
        }
        @keyframes shine { to { background-position: 200% center; } }
        .hero p { max-width: 44ch; color: var(--ink-soft); font-size: clamp(14px, 1.2vw, 16px); line-height: 1.8; }

        .metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 34px; max-width: 520px; }
        .metric {
            padding: 14px 14px 12px; border-radius: 16px;
            background: var(--glass); border: 1px solid var(--glass-line);
            backdrop-filter: blur(14px);
            transition: transform .25s, border-color .25s;
        }
        .metric:hover { transform: translateY(-3px); border-color: rgba(56, 225, 255, .4); }
        .metric i { font-size: 18px; background: linear-gradient(135deg, var(--cyan), var(--violet)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .metric b { display: block; margin-top: 8px; font-family: 'Space Grotesk', 'IBM Plex Sans Thai', sans-serif; font-size: 15px; }
        .metric small { color: var(--ink-faint); font-size: 11.5px; }

        /* ---------- การ์ดเข้าสู่ระบบ ---------- */
        .card-wrap { position: relative; animation: fadeUp .8s .12s cubic-bezier(.2, .7, .2, 1) both; }
        .card-wrap::before {
            content: ""; position: absolute; inset: -1px; border-radius: 28px; padding: 1px;
            background: conic-gradient(from var(--angle, 0deg), transparent 0 60%, var(--cyan), var(--violet), transparent 90%);
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude;
            animation: spin 6s linear infinite;
        }
        @property --angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
        @keyframes spin { to { --angle: 360deg; } }
        .card {
            position: relative;
            padding: clamp(28px, 3.4vw, 40px);
            border-radius: 28px;
            background: linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.03));
            border: 1px solid var(--glass-line);
            backdrop-filter: blur(24px) saturate(140%);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            box-shadow: 0 30px 80px -20px rgba(0, 0, 0, .6), inset 0 1px 0 rgba(255,255,255,.12);
        }
        .card-head { text-align: center; margin-bottom: 26px; }
        .card-head .logo {
            width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 20px;
            display: grid; place-items: center; background: #fff; overflow: hidden;
            box-shadow: 0 0 0 6px rgba(255,255,255,.05), 0 14px 40px rgba(139, 92, 246, .35);
        }
        .card-head .logo img { width: 80%; height: 80%; object-fit: contain; }
        .card-head h2 { font-size: 22px; font-weight: 700; }
        .card-head p { margin-top: 6px; color: var(--ink-soft); font-size: 13px; }

        .alert-error {
            display: flex; align-items: flex-start; gap: 9px;
            margin-bottom: 16px; padding: 11px 13px; border-radius: 12px;
            background: rgba(255, 90, 90, .1); border: 1px solid rgba(255, 120, 120, .35);
            color: var(--danger); font-size: 13px; line-height: 1.5;
        }

        .field { position: relative; margin-bottom: 16px; }
        .field input {
            width: 100%; height: 56px;
            padding: 22px 44px 6px 46px;
            border-radius: 14px;
            border: 1px solid var(--glass-line);
            background: rgba(7, 11, 26, .45);
            color: var(--ink); font-size: 16px; font-family: inherit;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .field input:-webkit-autofill { -webkit-text-fill-color: var(--ink); -webkit-box-shadow: 0 0 0 100px #121a33 inset; caret-color: var(--ink); }
        .field label {
            position: absolute; left: 46px; top: 18px;
            color: var(--ink-faint); font-size: 14px; pointer-events: none;
            transition: top .18s, font-size .18s, color .18s;
        }
        .field input:focus + label,
        .field input:not(:placeholder-shown) + label { top: 8px; font-size: 11px; color: var(--cyan); }
        .field .ico { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--ink-faint); font-size: 16px; transition: color .2s; }
        .field input:focus { border-color: rgba(56, 225, 255, .7); background: rgba(7, 11, 26, .65); box-shadow: 0 0 0 4px rgba(56, 225, 255, .12), 0 0 24px rgba(56, 225, 255, .15); }
        .field input:focus ~ .ico { color: var(--cyan); }
        .toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            width: 38px; height: 38px; border: 0; border-radius: 10px;
            background: transparent; color: var(--ink-faint); cursor: pointer; font-size: 16px;
            transition: background .2s, color .2s;
        }
        .toggle:hover { background: rgba(255,255,255,.07); color: var(--ink); }

        .remember { display: flex; align-items: center; gap: 10px; margin: 6px 0 22px; font-size: 13px; color: var(--ink-soft); cursor: pointer; user-select: none; }
        .switch { position: relative; width: 38px; height: 22px; flex: none; }
        .switch input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; }
        .switch .track { position: absolute; inset: 0; border-radius: 22px; background: rgba(255,255,255,.14); transition: background .2s; }
        .switch .track::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .2s; }
        .switch input:checked + .track { background: linear-gradient(90deg, var(--blue), var(--cyan)); }
        .switch input:checked + .track::after { transform: translateX(16px); }
        .switch input:focus-visible + .track { box-shadow: 0 0 0 3px rgba(56, 225, 255, .35); }

        .submit {
            position: relative; overflow: hidden;
            width: 100%; height: 54px; border: 0; border-radius: 14px;
            background: linear-gradient(100deg, var(--blue), var(--violet) 55%, var(--cyan));
            background-size: 180% auto;
            color: #fff; font-family: inherit; font-size: 15.5px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer;
            box-shadow: 0 16px 36px -10px rgba(139, 92, 246, .65);
            transition: background-position .5s, transform .15s, box-shadow .25s;
        }
        .submit:hover { background-position: right center; transform: translateY(-1px); box-shadow: 0 20px 44px -10px rgba(56, 225, 255, .55); }
        .submit:active { transform: translateY(0); }
        .submit::after {
            content: ""; position: absolute; top: 0; left: -60%; width: 40%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
            transform: skewX(-20deg); animation: sweep 3.2s ease-in-out infinite;
        }
        @keyframes sweep { 60%, 100% { left: 130%; } }
        .submit i { transition: transform .2s; }
        .submit:hover i { transform: translateX(3px); }

        .meta { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 20px; font-size: 11.5px; color: var(--ink-faint); }
        .meta .secure { display: inline-flex; align-items: center; gap: 6px; color: #8fe9cf; }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }

        /* ---------- จอเล็ก ---------- */
        @media (max-width: 900px) {
            body { padding: 18px; align-items: start; }
            .shell { grid-template-columns: 1fr; max-width: 440px; gap: 22px; }
            .hero { text-align: center; }
            .brand-row { justify-content: center; margin-bottom: 18px; }
            .hero h1 { font-size: clamp(26px, 7vw, 34px); margin: 14px 0 0; }
            .hero p, .metrics { display: none; }
        }
        @media (max-width: 380px) {
            body { padding: 12px; }
            .card { padding: 24px 18px; border-radius: 22px; }
            .card-wrap::before { border-radius: 22px; }
            .meta { flex-direction: column; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<div class="aurora" aria-hidden="true"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>
<div class="grid-floor" aria-hidden="true"></div>
<div class="noise" aria-hidden="true"></div>

<main class="shell">
    <section class="hero">
        <div class="brand-row">
            <img src="{{ $logo }}" alt="{{ $companyName }}">
            <span>{{ $companyName }}</span>
        </div>
        <span class="chip"><span class="dot"></span> JET ERP · ระบบพร้อมใช้งาน</span>
        <h1>ขาย สต๊อก บัญชี<br><span class="grad">ครบจบในที่เดียว</span></h1>
        <p>เชื่อมทุกสาขา หน้าร้าน POS และคลังสินค้าเข้าด้วยกัน เห็นตัวเลขจริงแบบเรียลไทม์ ตัดสินใจได้เร็วขึ้นทุกวัน</p>
        <div class="metrics">
            <div class="metric"><i class="bi bi-shop-window"></i><b>หลายสาขา</b><small>ข้อมูลเชื่อมกัน</small></div>
            <div class="metric"><i class="bi bi-lightning-charge-fill"></i><b>เรียลไทม์</b><small>สต๊อกและยอดขาย</small></div>
            <div class="metric"><i class="bi bi-shield-check"></i><b>ปลอดภัย</b><small>สิทธิ์ตามตำแหน่ง</small></div>
        </div>
    </section>

    <div class="card-wrap">
        <form class="card" method="post" action="{{ route('login.attempt') }}">
            @csrf
            <div class="card-head">
                <div class="logo"><img src="{{ $logo }}" alt="logo"></div>
                <h2>ยินดีต้อนรับกลับ</h2>
                <p>เข้าสู่ระบบ {{ $companyName }}</p>
            </div>

            @if($errors->any())
                <div class="alert-error" role="alert"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ $errors->first() }}</span></div>
            @endif

            <div class="field">
                <input type="text" id="username" name="username" value="{{ old('username') }}" placeholder=" " required autofocus autocomplete="username">
                <label for="username">ชื่อผู้ใช้ อีเมล หรือเบอร์โทรศัพท์</label>
                <i class="bi bi-person ico"></i>
            </div>

            <div class="field">
                <input type="password" id="password" name="password" placeholder=" " required autocomplete="current-password">
                <label for="password">รหัสผ่าน</label>
                <i class="bi bi-lock ico"></i>
                <button type="button" class="toggle" aria-label="แสดง/ซ่อนรหัสผ่าน" onclick="const p=document.getElementById('password'); const i=this.querySelector('i'); const show=p.type==='password'; p.type = show ? 'text' : 'password'; i.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';">
                    <i class="bi bi-eye"></i>
                </button>
            </div>

            <label class="remember">
                <span class="switch">
                    <input type="checkbox" name="remember" value="1">
                    <span class="track" aria-hidden="true"></span>
                </span>
                จดจำการเข้าสู่ระบบบนอุปกรณ์นี้
            </label>

            <button type="submit" class="submit">เข้าสู่ระบบ <i class="bi bi-arrow-right"></i></button>

            <div class="meta">
                <span class="secure"><i class="bi bi-shield-lock-fill"></i> เชื่อมต่อแบบเข้ารหัส</span>
                <span>ลืมรหัสผ่าน? ติดต่อผู้ดูแลระบบ</span>
            </div>
        </form>
    </div>
</main>
</body>
</html>
