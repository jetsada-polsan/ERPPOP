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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            /* Sukhumvit Set คือฟอนต์ไทยของ macOS/iOS — ของเดิมตกไปใช้ Tahoma
               บนเครื่องที่ไม่ใช่ Windows ซึ่งตัวไทยดูเก่ากว่าที่ควร */
            font-family: 'Leelawadee UI', 'Noto Sans Thai', 'Sukhumvit Set', Tahoma, 'Segoe UI', sans-serif;
            color: #173247;
            background:
                linear-gradient(120deg, rgba(20, 96, 141, .09), transparent 32%),
                linear-gradient(300deg, rgba(49, 151, 107, .12), transparent 34%),
                #f3f7fb;
            padding: clamp(12px, 2.2vw, 28px);
            display: grid;
            place-items: center;
        }
        .login-shell {
            width: min(1080px, 100%);
            /* ความสูงยืดตามจอ ไม่ล็อก 640px ตายตัว จอเตี้ย (โน้ตบุ๊ก 768px
               หรือมือถือแนวนอน) จะได้ไม่ถูกดันจนต้องเลื่อน */
            min-height: min(640px, calc(100vh - 2 * clamp(12px, 2.2vw, 28px)));
            display: grid;
            /* คอลัมน์ฟอร์มยืดได้ในช่วง 360-430px แทนที่จะตรึง 430px
               ช่วง 880-1150px เดิมจะบีบฝั่งซ้ายจนอึดอัด */
            grid-template-columns: minmax(0, 1.05fr) clamp(360px, 34vw, 430px);
            overflow: hidden;
            border: 1px solid #dbe7ef;
            border-radius: 22px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(25, 58, 84, .15);
        }
        .login-story {
            position: relative;
            padding: clamp(28px, 3.6vw, 46px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                linear-gradient(135deg, rgba(8, 80, 127, .92), rgba(20, 125, 140, .86)),
                #0e5c89;
            color: #fff;
            overflow: hidden;
        }
        .login-story::before {
            content: "";
            position: absolute;
            inset: 20px;
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 18px;
            pointer-events: none;
        }
        .login-story::after {
            content: "";
            position: absolute;
            width: 420px;
            height: 420px;
            right: -170px;
            bottom: -190px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.2), rgba(255,255,255,0) 66%);
            pointer-events: none;
        }
        .story-brand,
        .story-content,
        .story-dashboard,
        .story-foot { position: relative; z-index: 1; }
        .story-brand { display: flex; align-items: center; gap: 12px; font-size: clamp(18px, 1.8vw, 22px); font-weight: 900; }
        .story-brand img { width: clamp(38px, 3.6vw, 46px); height: clamp(38px, 3.6vw, 46px); padding: 6px; border-radius: 14px; background: rgba(255,255,255,.96); box-shadow: 0 10px 28px rgba(0,0,0,.18); }
        .story-kicker { color: #bff3ff; font-size: clamp(10px, .9vw, 11px); font-weight: 900; letter-spacing: .14em; }
        /* พาดหัวย่อ-ขยายตามความกว้างจอ ของเดิมตรึง 40px ทำให้ช่วงจอกลางล้นสามบรรทัด */
        .story-title { max-width: 22ch; margin: 12px 0; font-size: clamp(24px, 3.1vw, 40px); line-height: 1.16; font-weight: 900; }
        .story-copy { max-width: 46ch; color: #d9f0f7; font-size: clamp(13px, 1.15vw, 14px); line-height: 1.75; }
        .story-dashboard {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: clamp(10px, 1vw, 12px);
            margin-top: clamp(18px, 2.4vw, 28px);
        }
        .signal-card {
            min-height: clamp(104px, 11vw, 124px);
            border: 1px solid rgba(255,255,255,.16);
            border-radius: 14px;
            background: rgba(255,255,255,.11);
            padding: clamp(12px, 1.3vw, 16px);
            backdrop-filter: blur(10px);
        }
        .signal-card span { display: block; color: #bde9f3; font-size: clamp(10px, .9vw, 11px); font-weight: 800; margin-bottom: 8px; }
        .signal-card strong { display: block; font-size: clamp(19px, 2vw, 25px); line-height: 1.1; }
        .signal-card small { display: block; margin-top: 10px; color: #d8f4f8; font-size: clamp(11px, 1vw, 12px); }
        .signal-bars { height: clamp(40px, 4.6vw, 58px); display: flex; align-items: end; gap: 6px; margin-top: clamp(12px, 1.4vw, 16px); }
        .signal-bars i { flex: 1; border-radius: 5px 5px 0 0; background: linear-gradient(#ffffff, #95f0d0); opacity: .86; }
        .story-foot { color: #c6eef7; font-size: 12px; }
        .login-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: clamp(30px, 3.4vw, 48px) clamp(24px, 3vw, 42px) clamp(26px, 2.8vw, 38px);
            background: #fff;
        }
        .brand { text-align: center; margin-bottom: 10px; }
        .brand img { max-height: clamp(44px, 4.4vw, 54px); max-width: min(190px, 70%); object-fit: contain; }
        .brand-text { font-size: clamp(23px, 2.5vw, 30px); font-weight: 900; color: #153349; }
        .subtitle { text-align: center; color: #63798a; font-size: clamp(12.5px, 1.1vw, 13px); line-height: 1.55; margin-bottom: clamp(20px, 2.2vw, 26px); }
        label:not(.remember) { display: block; font-size: 12.5px; font-weight: 800; color: #3c5668; margin: 14px 0 6px; }
        .field { position: relative; }
        .field i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #7f99ad; font-size: 15px; }
        input[type=text], input[type=password] {
            width: 100%;
            min-height: clamp(44px, 4.4vw, 48px);
            padding: 11px 13px 11px 40px;
            border: 1px solid #d7e4ed;
            border-radius: 12px;
            /* 16px ห้ามต่ำกว่านี้ ไม่งั้น Safari บน iPhone จะซูมหน้าจอเองตอนแตะช่องกรอก
               แล้ว layout เพี้ยนทั้งหน้า (ของเดิม 14px จึงโดนซูมทุกครั้ง) */
            font-size: 16px;
            font-family: inherit;
            color: #173247;
            outline: none;
            background: #fbfdff;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input:focus { border-color: #1a9bdc; box-shadow: 0 0 0 4px rgba(26,155,220,.12); background: #fff; }
        .remember { display: flex; align-items: center; gap: 8px; margin: 15px 0 20px; font-size: 13px; color: #536b7d; }
        .remember input { width: 16px; height: 16px; accent-color: #1a9bdc; }
        button {
            width: 100%;
            min-height: clamp(46px, 4.6vw, 50px);
            padding: 12px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #1a9bdc, #20a67a);
            color: #fff;
            font-size: 15px;
            font-weight: 900;
            font-family: inherit;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(26,155,220,.26);
            transition: transform .15s, box-shadow .15s;
        }
        button:hover { transform: translateY(-1px); box-shadow: 0 15px 30px rgba(26,155,220,.32); }
        .error {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff5f5;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 12px;
            padding: 11px 13px;
            font-size: 13px;
            margin-bottom: 8px;
        }
        .foot { text-align: center; color: #8aa1b2; font-size: 11.5px; margin-top: 24px; }
        /* จอกลาง: ยังวางการ์ดสองใบได้ แค่แบ่งพื้นที่เท่ากันและลดความกว้างข้อความ
           ของเดิมใช้ 1.1fr/.9fr ซึ่งพอแคบลงใบขวาจะบีบจนกราฟแท่งเบียดกัน */
        @media (max-width: 1150px) {
            .story-dashboard { grid-template-columns: 1fr 1fr; }
            .story-copy { max-width: 40ch; }
        }
        /* แคบกว่านี้วางสองใบไม่ไหวแล้ว ซ้อนลงมาแทนการบีบให้เล็กจนอ่านไม่ออก */
        @media (min-width: 881px) and (max-width: 1000px) {
            .story-dashboard { grid-template-columns: 1fr; }
            .signal-card { min-height: 0; }
        }
        /* จอเตี้ย เช่น โน้ตบุ๊ก 1366x768 หรือมือถือแนวนอน: ซ่อนส่วนประกอบรอง
           ไม่ให้การ์ดสูงเกินจอจนต้องเลื่อน */
        @media (min-width: 881px) and (max-height: 700px) {
            .story-dashboard { display: none; }
            .login-shell { min-height: auto; }
        }
        @media (max-width: 880px) {
            body { padding: 14px; align-items: start; }
            .login-shell { grid-template-columns: 1fr; max-width: 480px; min-height: auto; }
            .login-story { min-height: auto; padding: clamp(22px, 6vw, 28px); }
            .login-story::before { inset: 12px; }
            .story-title { font-size: clamp(21px, 6.2vw, 28px); }
            .story-copy, .story-dashboard, .story-foot { display: none; }
            .login-card { padding: clamp(26px, 7vw, 34px) clamp(20px, 6vw, 28px); }
        }
        /* มือถือจอแคบ (iPhone SE 320px): บีบระยะให้ปุ่มเข้าสู่ระบบอยู่ในจอโดยไม่ต้องเลื่อน
           ของเดิมปุ่มตกใต้ขอบจอไป 27px ต้องเลื่อนก่อนถึงจะกดได้ */
        @media (max-width: 380px) {
            body { padding: 0; }
            .login-shell { border: none; border-radius: 0; box-shadow: none; max-width: none; }
            .story-brand { font-size: 17px; }
            .brand { margin-bottom: 4px; }
            .brand img { max-height: 42px; }
            .subtitle { margin-bottom: 14px; }
            label:not(.remember) { margin: 10px 0 5px; }
            .remember { margin: 10px 0 14px; }
        }

        /* Enterprise skin: restrained, application-first login surface. */
        body {
            background: #eef1f4;
            color: #1f2933;
            padding: 32px;
        }
        .login-shell {
            width: min(1080px, 100%);
            min-height: 620px;
            grid-template-columns: minmax(0, 1fr) minmax(380px, 420px);
            border: 1px solid #cbd3da;
            border-radius: 8px;
            box-shadow: 0 18px 48px rgba(31, 41, 51, .12);
        }
        .login-story {
            padding: 48px;
            background: #16324f;
        }
        .login-story::before { inset: 22px; border-radius: 4px; border-color: rgba(255,255,255,.14); }
        .login-story::after { display: none; }
        .story-brand { font-size: 21px; letter-spacing: 0; }
        .story-brand img { border-radius: 8px; box-shadow: none; }
        .story-kicker { color: #b8d8ee; letter-spacing: .18em; }
        .story-title {
            max-width: 16ch;
            margin: 18px 0 14px;
            color: #fff;
            font-size: clamp(30px, 3.4vw, 46px);
            line-height: 1.08;
            letter-spacing: 0;
        }
        .story-copy { max-width: 43ch; color: #d5e2ec; line-height: 1.7; }
        .story-dashboard { display: none; }
        .story-foot { color: #b8c9d7; font-size: 11px; letter-spacing: .02em; }
        .login-card {
            justify-content: flex-start;
            padding: 72px 48px 42px;
        }
        .brand { text-align: left; margin-bottom: 22px; }
        .brand img { max-height: 48px; max-width: 180px; }
        .brand-text { font-size: 26px; color: #16324f; }
        .subtitle { text-align: left; color: #627384; margin-bottom: 30px; }
        label:not(.remember) { color: #34495e; font-size: 12px; letter-spacing: .01em; margin: 18px 0 7px; }
        .field i { color: #7b8c9b; }
        input[type=text], input[type=password] {
            min-height: 48px;
            border: 1px solid #bfcbd5;
            border-radius: 4px;
            background: #fff;
            color: #1f2933;
        }
        input:focus { border-color: #0a6ed1; box-shadow: 0 0 0 2px rgba(10,110,209,.16); }
        .remember { color: #627384; margin: 18px 0 24px; }
        .remember input { accent-color: #0a6ed1; }
        button {
            min-height: 48px;
            border-radius: 4px;
            background: #0a6ed1;
            box-shadow: none;
            font-size: 15px;
            letter-spacing: .01em;
        }
        button:hover { background: #085caf; box-shadow: none; transform: none; }
        .error { border-radius: 4px; margin-bottom: 10px; }
        .foot { text-align: left; color: #7b8c9b; font-size: 12px; margin-top: 22px; }
        @media (max-width: 880px) {
            body { padding: 16px; }
            .login-shell { grid-template-columns: 1fr; min-height: auto; }
            .login-story { min-height: 210px; padding: 30px; }
            .login-card { padding: 34px 30px 32px; }
        }
        @media (max-width: 380px) {
            body { padding: 0; }
            .login-story { padding: 24px; }
            .login-card { padding: 28px 22px; }
        }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-story">
        <div class="story-brand"><img src="{{ asset('images/logo-jet-erp-mark.svg') }}" alt="PopCentral"> PopCentral</div>
        <div class="story-content">
            <div class="story-kicker">BUSINESS OPERATIONS PLATFORM</div>
            <h1 class="story-title">One platform for every operation.</h1>
            <p class="story-copy">Manage sales, inventory, purchasing and finance across every branch from one secure workspace.</p>
            <div class="story-dashboard">
                <div class="signal-card">
                    <span>POS TODAY</span>
                    <strong>พร้อมขาย</strong>
                    <small>รองรับงานหน้าร้านและการนำเข้ายอดขาย</small>
                </div>
                <div class="signal-card">
                    <span>STOCK CONTROL</span>
                    <strong>Real-time</strong>
                    <div class="signal-bars" aria-hidden="true"><i style="height:36%"></i><i style="height:54%"></i><i style="height:44%"></i><i style="height:72%"></i><i style="height:63%"></i><i style="height:88%"></i></div>
                </div>
            </div>
        </div>
        <div class="story-foot">PopCentral · Built for POPSTAR operations</div>
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
        <div class="subtitle">Sign in to your PopCentral workspace</div>

        @if($errors->any())
            <div class="error"><i class="bi bi-exclamation-triangle-fill"></i>{{ $errors->first() }}</div>
        @endif

        <label for="username">Username, email or phone</label>
        <div class="field">
            <i class="bi bi-person-fill"></i>
            <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
        </div>

        <label for="password">Password</label>
        <div class="field">
            <i class="bi bi-lock-fill"></i>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <label class="remember">
            <input type="checkbox" name="remember" value="1"> Keep me signed in on this device
        </label>

        <button type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>

        <div class="foot">Forgot your password? Contact your system administrator.</div>
    </form>
</main>
</body>
</html>
