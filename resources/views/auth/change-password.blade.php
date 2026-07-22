<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($faviconUrl = asset('images/logo-jet-erp-mark.svg').'?v='.filemtime(public_path('images/logo-jet-erp-mark.svg')))
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เปลี่ยนรหัสผ่าน - JET ERP</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
            font-family: 'Leelawadee UI', 'Noto Sans Thai', Tahoma, 'Segoe UI', sans-serif;
            color: #0f172a;
            background:
                radial-gradient(circle at 12% 18%, rgba(20,184,166,.18), transparent 30%),
                radial-gradient(circle at 86% 78%, rgba(59,130,246,.16), transparent 32%),
                linear-gradient(135deg, #07111f 0%, #102033 55%, #07111f 100%);
        }
        .card {
            width: min(430px, 100%);
            padding: 34px;
            background: #fff;
            border: 1px solid rgba(148,163,184,.24);
            border-radius: 18px;
            box-shadow: 0 30px 90px rgba(2,8,23,.42);
        }
        .brand { text-align: center; margin-bottom: 18px; }
        .brand-text { font-size: 31px; font-weight: 900; letter-spacing: -.5px; }
        .brand-text span { color: #10b981; }
        h1 { font-size: 23px; line-height: 1.25; text-align: center; margin-bottom: 8px; }
        .lead { color: #64748b; text-align: center; font-size: 13.5px; line-height: 1.55; margin-bottom: 22px; }
        label { display: block; margin: 14px 0 6px; font-size: 12.5px; font-weight: 800; color: #475569; }
        .field { position: relative; }
        .field i { position: absolute; top: 50%; left: 13px; color: #94a3b8; transform: translateY(-50%); }
        input {
            width: 100%;
            height: 46px;
            padding: 11px 12px 11px 40px;
            font: inherit;
            border: 1.5px solid #dbe4ef;
            border-radius: 11px;
            outline: none;
        }
        input:focus { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,.13); }
        .error {
            margin-bottom: 12px;
            padding: 11px 13px;
            color: #b91c1c;
            font-size: 13px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 11px;
        }
        button {
            width: 100%;
            height: 48px;
            margin-top: 20px;
            color: #fff;
            font: inherit;
            font-weight: 900;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #10b981, #0284c7);
            box-shadow: 0 14px 28px rgba(16,185,129,.28);
            cursor: pointer;
        }
        .hint { margin-top: 14px; color: #64748b; font-size: 12px; line-height: 1.5; text-align: center; }
        .logout { margin-top: 16px; text-align: center; }
        .logout button {
            width: auto;
            height: auto;
            margin: 0;
            padding: 0;
            color: #64748b;
            font-size: 12.5px;
            font-weight: 700;
            background: transparent;
            border: 0;
            box-shadow: none;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <div class="brand-text">pop<span>star</span></div>
        </div>
        <h1>ตั้งรหัสผ่านใหม่ก่อนใช้งาน</h1>
        <p class="lead">บัญชีนี้ถูกตั้งรหัสผ่านโดยผู้ดูแลระบบ เพื่อความปลอดภัยให้เปลี่ยนเป็นรหัสของคุณเองก่อนเข้า ERP</p>

        @if($errors->any())
            <div class="error"><i class="bi bi-exclamation-triangle-fill"></i> {{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('password.update') }}">
            @csrf
            <label for="current_password">รหัสผ่านปัจจุบัน</label>
            <div class="field">
                <i class="bi bi-lock-fill"></i>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password" autofocus>
            </div>

            <label for="password">รหัสผ่านใหม่</label>
            <div class="field">
                <i class="bi bi-shield-lock-fill"></i>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>

            <label for="password_confirmation">ยืนยันรหัสผ่านใหม่</label>
            <div class="field">
                <i class="bi bi-check2-circle"></i>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit"><i class="bi bi-check-lg"></i> บันทึกรหัสผ่านใหม่</button>
        </form>

        <p class="hint">รหัสผ่านต้องยาวอย่างน้อย 8 ตัว มีตัวอักษรพิมพ์เล็ก พิมพ์ใหญ่ และตัวเลข</p>

        <form class="logout" method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit">ออกจากระบบ</button>
        </form>
    </main>
</body>
</html>
