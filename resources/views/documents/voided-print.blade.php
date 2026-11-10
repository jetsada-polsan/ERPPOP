{{-- หน้าแจ้งเมื่อเปิด URL พิมพ์ของเอกสารที่ยกเลิกแล้ว (ดู App\Support\VoidedDocumentPrint) --}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เอกสาร {{ $docNumber }} ถูกยกเลิกแล้ว</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7f9;
               font-family: 'IBM Plex Sans Thai', 'Sarabun', Tahoma, sans-serif; color: #111; }
        .voided-card { width: min(520px, 100%); background: #fff; border: 1px solid #e3e8ec; border-top: 4px solid #c62828;
                       border-radius: 10px; padding: 28px 30px; box-shadow: 0 10px 30px rgba(15,35,52,.08); }
        .voided-card h1 { font-size: 20px; font-weight: 700; color: #b71c1c; margin-bottom: 8px; }
        .voided-card p { font-size: 15px; line-height: 1.7; color: #333; margin-bottom: 20px; }
        .voided-card a { display: inline-block; padding: 9px 20px; border-radius: 999px; background: #1585c0; color: #fff;
                         font-weight: 600; text-decoration: none; }
        @page { size: A4 portrait; margin: 15mm; }
        @media print { .voided-card a { display: none; } }
    </style>
</head>
<body>
    <div class="voided-card" role="alert">
        <h1>เอกสาร {{ $docNumber }} ถูกยกเลิกแล้ว พิมพ์ไม่ได้</h1>
        <p>เอกสารที่ยกเลิกแล้วห้ามพิมพ์ใบเสร็จ ใบกำกับภาษี หรือใบส่งของซ้ำ เพื่อไม่ให้มีเอกสารที่ไม่มีผลทางบัญชีออกไปถึงลูกค้า</p>
        <a href="{{ $backUrl }}">← กลับไปหน้าเอกสาร</a>
    </div>
</body>
</html>
