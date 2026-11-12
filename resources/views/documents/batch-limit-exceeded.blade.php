<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>เกินจำนวนเอกสารที่กำหนด</title>
    <style>
        body { font-family: 'Sarabun', 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 36px 32px; max-width: 480px; width: 100%; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); text-align: center; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 18px; font-weight: 700; margin-bottom: 12px; color: #0f172a; }
        p { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
        .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; border: none; font-family: inherit; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⚠️</div>
        <h1>จำกัดการพิมพ์ไม่เกิน 150 ใบต่อครั้ง</h1>
        <p>{{ $message }}</p>
        <button class="btn" onclick="javascript:history.back()">← กลับไปแบ่งตัวกรองใหม่</button>
    </div>
</body>
</html>
