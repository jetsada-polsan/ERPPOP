<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fb">
    @php($faviconUrl = \App\Models\AppSetting::logoUrl() ?: asset('images/logos/mpm-food-group.jpg').'?v='.filemtime(public_path('images/logos/mpm-food-group.jpg')))
    @php($companyName = \App\Models\AppSetting::company('name_th') ?: 'MPM Food Group')
    @php($logo = \App\Models\AppSetting::logoUrl() ?: asset('images/logos/mpm-food-group.jpg'))
    <link rel="icon" href="{{ $faviconUrl }}">
    <link rel="shortcut icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <title>เข้าสู่ระบบ JET ERP - {{ $companyName }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --ink: #10233a;
            --muted: #5b7186;
            --line: #d8e2ec;
            --brand: #1769aa;
            --brand-dark: #0f4f84;
            --brand-soft: #e8f2fb;
            --danger: #b3261e;
            --danger-soft: #fdecea;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'IBM Plex Sans Thai', 'Leelawadee UI', Tahoma, sans-serif;
            color: var(--ink);
            background: var(--bg);
            display: grid;
            place-items: center;
            padding: 24px 16px;
        }
        .wrap { width: 100%; max-width: 420px; }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand img { width: 84px; height: 84px; object-fit: contain; border-radius: 18px; background: #fff; border: 1px solid var(--line); padding: 8px; }
        .brand h1 { font-size: 22px; margin: 14px 0 2px; font-weight: 700; }
        .brand p { margin: 0; color: var(--muted); font-size: 15px; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 28px 26px;
            box-shadow: 0 10px 30px rgba(16, 35, 58, .06);
        }
        .card h2 { font-size: 18px; margin: 0 0 18px; font-weight: 600; }
        .alert {
            display: flex; gap: 8px; align-items: flex-start;
            background: var(--danger-soft); color: var(--danger);
            border-radius: 10px; padding: 10px 12px; font-size: 14px; margin-bottom: 16px;
        }
        label.text { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; }
        .input { position: relative; margin-bottom: 16px; }
        .input i.ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 17px; }
        .input input {
            width: 100%; height: 48px; font: inherit; font-size: 16px; color: var(--ink);
            border: 1px solid var(--line); border-radius: 10px; background: #fff;
            padding: 0 44px 0 42px; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .input input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
        .toggle {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            border: 0; background: none; color: var(--muted); width: 36px; height: 36px;
            border-radius: 8px; cursor: pointer; font-size: 17px;
        }
        .toggle:hover { background: var(--bg); }
        .remember { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--muted); margin: 4px 0 20px; cursor: pointer; }
        .remember input { width: 18px; height: 18px; accent-color: var(--brand); }
        .submit {
            width: 100%; height: 48px; border: 0; border-radius: 10px; cursor: pointer;
            background: var(--brand); color: #fff; font: inherit; font-size: 16px; font-weight: 600;
            transition: background .15s;
        }
        .submit:hover { background: var(--brand-dark); }
        .submit:focus-visible, .toggle:focus-visible { outline: 3px solid var(--brand-soft); outline-offset: 2px; }
        .foot { text-align: center; color: var(--muted); font-size: 13px; margin-top: 18px; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1722; --card: #172231; --ink: #e6edf5; --muted: #9fb0c3; --line: #2a3a4e; --brand-soft: #1b3550; --danger-soft: #3a1d1b; --danger: #ffb4ab; }
            .input input { background: #0f1722; }
            .brand img { background: #fff; }
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="brand">
        <img src="{{ $logo }}" alt="{{ $companyName }}">
        <h1>{{ $companyName }}</h1>
        <p>JET ERP</p>
    </div>

    <form class="card" method="post" action="{{ route('login.attempt') }}">
        @csrf
        <h2>เข้าสู่ระบบ</h2>

        @if($errors->any())
            <div class="alert" role="alert"><i class="bi bi-exclamation-circle"></i><span>{{ $errors->first() }}</span></div>
        @endif

        <label class="text" for="username">ชื่อผู้ใช้</label>
        <div class="input">
            <i class="bi bi-person ico"></i>
            <input type="text" id="username" name="username" value="{{ old('username') }}" placeholder="ชื่อผู้ใช้ อีเมล หรือเบอร์โทร" required autofocus autocomplete="username">
        </div>

        <label class="text" for="password">รหัสผ่าน</label>
        <div class="input">
            <i class="bi bi-lock ico"></i>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="toggle" aria-label="แสดงหรือซ่อนรหัสผ่าน" onclick="const p=document.getElementById('password'); const i=this.querySelector('i'); const show=p.type==='password'; p.type = show ? 'text' : 'password'; i.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';">
                <i class="bi bi-eye"></i>
            </button>
        </div>

        <label class="remember">
            <input type="checkbox" name="remember" value="1">
            จดจำการเข้าสู่ระบบบนเครื่องนี้
        </label>

        <button type="submit" class="submit">เข้าสู่ระบบ</button>
    </form>

    <p class="foot">ลืมรหัสผ่าน ติดต่อผู้ดูแลระบบ</p>
</main>
</body>
</html>
