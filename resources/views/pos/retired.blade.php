<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PopCentral POS — ย้ายไปแอปเดสก์ท็อป</title>
    <style>
        :root{ --ink:#1d3b52; --muted:#627481; --brand:#147db5; --brand-dark:#0f4c75;
               --soft:#eef4f9; --line:#dbe7ef; --canvas:#f3f7fb; --surface:#fff; }
        *{box-sizing:border-box} body{margin:0;font-family:'Noto Sans Thai','Sarabun',Tahoma,sans-serif;
            background:var(--canvas);color:var(--ink);min-height:100vh;display:grid;place-items:center;padding:24px}
        .card{background:var(--surface);border:1px solid var(--line);border-radius:14px;max-width:560px;width:100%;
            padding:40px;box-shadow:0 12px 40px rgba(15,76,117,.10);text-align:center}
        .mark{width:64px;height:64px;border-radius:16px;background:var(--brand-dark);color:#fff;display:grid;
            place-items:center;margin:0 auto 20px;font-size:30px;font-weight:800}
        h1{margin:0 0 8px;font-size:24px;color:var(--brand-dark)}
        p{margin:0 0 8px;color:var(--muted);line-height:1.7}
        .steps{text-align:left;background:var(--soft);border-radius:10px;padding:16px 20px;margin:22px 0;color:var(--ink)}
        .steps li{margin:6px 0}
        .btn{display:inline-block;background:var(--brand);color:#fff;text-decoration:none;font-weight:700;
            padding:13px 22px;border-radius:9px;margin-top:6px}
        .btn:hover{background:var(--brand-dark)}
        .back{display:inline-block;margin-top:16px;color:var(--brand);text-decoration:none;font-size:14px}
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">★</div>
        <h1>หน้าขายย้ายไปแอปเดสก์ท็อปแล้ว</h1>
        <p>การขายหน้าร้านใช้ผ่าน <strong>PopCentral POS</strong> (โปรแกรมเดสก์ท็อป Windows)
           ซึ่งขายต่อได้แม้เน็ตหลุด แล้วส่งบิลขึ้น ERP ให้อัตโนมัติ</p>
        <ol class="steps">
            <li>ดาวน์โหลดและติดตั้งแอปบนเครื่องแคชเชียร์</li>
            <li>เปิด <strong>ตั้งค่า → การ Sync และ API</strong> ในแอป</li>
            <li>กรอกที่อยู่ ERP และ device token ที่ได้จากผู้ดูแลระบบ แล้วเปิดโปรแกรมใหม่</li>
        </ol>
        <a class="btn" href="{{ route('python-pos.download') }}">ดาวน์โหลด PopCentral POS</a>
        <div><a class="back" href="{{ url('/dashboard') }}">← กลับหน้าหลัก</a></div>
    </div>
</body>
</html>
