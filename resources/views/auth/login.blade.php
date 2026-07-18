<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg')))
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ - JET ERP</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family:'Segoe UI','Tahoma',sans-serif; min-height:100vh; display:grid; place-items:center; overflow:hidden; background:#071426; padding:22px; }
        body::before { content:"";position:fixed;inset:0;pointer-events:none;background:radial-gradient(circle at 15% 18%,rgba(14,165,233,.22),transparent 31%),radial-gradient(circle at 84% 85%,rgba(16,185,129,.15),transparent 28%),linear-gradient(rgba(56,189,248,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(56,189,248,.045) 1px,transparent 1px);background-size:auto,auto,34px 34px,34px 34px; }
        .login-shell { position:relative;z-index:1;width:min(1040px,100%);min-height:610px;display:grid;grid-template-columns:minmax(0,1.08fr) 440px;overflow:hidden;border:1px solid rgba(125,211,252,.2);border-radius:24px;background:rgba(8,24,43,.72);box-shadow:0 38px 100px rgba(0,0,0,.46);backdrop-filter:blur(16px); }
        .login-story { position:relative;overflow:hidden;padding:52px 48px;display:flex;flex-direction:column;justify-content:space-between;color:#eaf8ff;background:linear-gradient(145deg,rgba(8,29,53,.78),rgba(7,58,75,.5)); }
        .login-story::after { content:"";position:absolute;width:420px;height:420px;right:-190px;top:55px;border:1px solid rgba(103,232,249,.22);border-radius:50%;box-shadow:0 0 0 55px rgba(56,189,248,.035),0 0 0 110px rgba(56,189,248,.025); }
        .story-brand { position:relative;z-index:2;display:flex;align-items:center;gap:12px;font-size:24px;font-weight:900;letter-spacing:.02em; }
        .story-brand img { width:48px;height:48px;padding:6px;border-radius:13px;background:rgba(255,255,255,.94); }
        .story-kicker { position:relative;z-index:2;margin-top:auto;color:#67e8f9;font-size:10px;font-weight:900;letter-spacing:.18em; }
        .story-title { position:relative;z-index:2;max-width:480px;margin:11px 0 12px;font-size:38px;line-height:1.14;font-weight:850;letter-spacing:-.035em; }
        .story-copy { position:relative;z-index:2;max-width:465px;color:#9fc4d5;font-size:13px;line-height:1.7; }
        .signal-board { position:relative;z-index:2;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:30px; }
        .signal-board div { padding:11px;border:1px solid rgba(125,211,252,.18);border-radius:9px;background:rgba(255,255,255,.045); }
        .signal-board span { display:block;color:#79a9bd;font-size:9px;margin-bottom:5px; }
        .signal-board strong { font-size:12px;color:#f0fbff; }
        .signal-bars { position:relative;z-index:2;height:54px;display:flex;align-items:end;gap:5px;margin-top:26px;opacity:.72; }
        .signal-bars i { flex:1;min-width:8px;border-radius:3px 3px 0 0;background:linear-gradient(#22d3ee,#2563eb); }
        .story-foot { position:relative;z-index:2;margin-top:18px;color:#6f9aae;font-size:10px; }
        .login-card {
            width:100%;
            background: #fff;
            border-radius:0;
            padding:48px 42px 34px;
            box-shadow:-18px 0 50px rgba(2,8,23,.18);
            display:flex;flex-direction:column;justify-content:center;
        }
        .brand { text-align: center; margin-bottom: 8px; }
        .brand img { max-height: 48px; max-width: 180px; object-fit: contain; }
        .product-brand { display:none; }
        .brand-text { font-size: 30px; font-weight: 900; color: #0f172a; letter-spacing: -.8px; }
        .brand-text span { color: #10b981; }
        .subtitle { text-align: center; color: #64748b; font-size: 13px; margin-bottom: 24px; }
        label { display: block; font-size: 12.5px; font-weight: 700; color: #475569; margin: 14px 0 5px; }
        .field { position: relative; }
        .field i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        input[type=text], input[type=password] {
            width: 100%; padding: 11px 12px 11px 38px;
            border: 1.5px solid #e2e8f0; border-radius: 10px;
            font-size: 14.5px; font-family: inherit; outline: none;
            transition: border-color .15s;
        }
        input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 4px rgba(14,165,233,.12); }
        .remember { display: flex; align-items: center; gap: 7px; margin: 14px 0 18px; font-size: 13px; color: #475569; }
        button {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none; border-radius: 12px;
            color: #fff; font-size: 16px; font-weight: 800; font-family: inherit;
            cursor: pointer; box-shadow: 0 10px 24px rgba(16,185,129,.35);
            transition: opacity .15s;
        }
        button:hover { opacity:.94;transform:translateY(-1px); }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
            border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 6px;
        }
        .foot { text-align: center; color: #94a3b8; font-size: 11.5px; margin-top: 22px; }
        @media(max-width:820px){ body{overflow:auto;padding:14px}.login-shell{grid-template-columns:1fr;max-width:470px}.login-story{min-height:210px;padding:26px 28px}.story-title{font-size:25px;margin:18px 0 7px}.story-copy,.signal-board,.signal-bars,.story-foot{display:none}.login-card{padding:32px 28px;border-radius:0}.product-brand{display:none} }
    </style>
</head>
<body>
<main class="login-shell">
    <section class="login-story">
        <div class="story-brand"><img src="{{ asset('images/logo-jet-erp-mark.svg') }}" alt="JET ERP"> JET ERP</div>
        <div>
            <div class="story-kicker">INTELLIGENT BUSINESS OPERATING SYSTEM</div>
            <h1 class="story-title">เห็นภาพธุรกิจชัด<br>ตัดสินใจได้เร็วกว่า</h1>
            <p class="story-copy">รวมยอดขาย POS สต็อก จัดซื้อ การเงิน และรายงานไว้ในระบบเดียว พร้อมข้อมูลที่เชื่อมต่อกันทุกสาขา</p>
            <div class="signal-board"><div><span>OPERATIONS</span><strong>Real-time</strong></div><div><span>DATA</span><strong>Connected</strong></div><div><span>CONTROL</span><strong>Secure</strong></div></div>
            <div class="signal-bars" aria-hidden="true"><i style="height:28%"></i><i style="height:44%"></i><i style="height:37%"></i><i style="height:68%"></i><i style="height:55%"></i><i style="height:82%"></i><i style="height:66%"></i><i style="height:94%"></i><i style="height:76%"></i><i style="height:88%"></i><i style="height:100%"></i><i style="height:92%"></i></div>
        </div>
        <div class="story-foot">JET ERP · Enterprise platform for modern operations</div>
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
        <div class="subtitle">ระบบบริหารจัดการสำหรับ {{ \App\Models\AppSetting::company('name_th') }}</div>

        @if($errors->any())
            <div class="error"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $errors->first() }}</div>
        @endif

        <label for="username">ชื่อผู้ใช้ / อีเมล / เบอร์โทร</label>
        <div class="field">
            <i class="bi bi-person-fill"></i>
            <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
        </div>

        <label for="password">รหัสผ่าน</label>
        <div class="field">
            <i class="bi bi-lock-fill"></i>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <label class="remember">
            <input type="checkbox" name="remember" value="1"> จดจำการเข้าสู่ระบบในเครื่องนี้
        </label>

        <button type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ</button>

        <div class="foot">JET ERP &middot; ลืมรหัสผ่านติดต่อผู้ดูแลระบบ</div>
    </form>
</main>
</body>
</html>
