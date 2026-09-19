<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PopStar Center</title>
    <style>
        :root { color-scheme: light; --ink:#17212b; --muted:#667482; --line:#dfe6eb; --brand:#0d6f79; --brand-dark:#07515b; --surface:#fff; --page:#f4f7f8; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--page); color:var(--ink); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .shell { max-width:1120px; margin:auto; padding:28px 20px 36px; }
        header { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:10px 0 34px; }
        .brand { display:flex; align-items:center; gap:12px; font-weight:800; font-size:1.2rem; }
        .mark { display:grid; place-items:center; width:42px; height:42px; border-radius:10px; background:var(--brand); color:#fff; font-weight:900; }
        .login { color:var(--brand-dark); text-decoration:none; font-weight:700; border:1px solid var(--line); background:var(--surface); border-radius:7px; padding:10px 15px; }
        .intro { max-width:720px; margin-bottom:28px; }
        h1 { margin:0 0 10px; font-size:clamp(2rem,4vw,3.2rem); letter-spacing:0; }
        .intro p { margin:0; color:var(--muted); line-height:1.7; }
        .pos-download { display:flex; align-items:center; justify-content:space-between; gap:20px; padding:0 0 24px; margin-bottom:26px; border-bottom:1px solid var(--line); }
        .pos-download strong { display:block; margin-bottom:4px; font-size:1.05rem; }
        .pos-download span { color:var(--muted); line-height:1.55; font-size:.95rem; }
        .pos-download a { flex:none; color:#fff; background:var(--brand); border-radius:7px; padding:11px 16px; font-weight:800; text-decoration:none; }
        .grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        .card { display:flex; flex-direction:column; min-height:190px; padding:22px; border:1px solid var(--line); border-radius:8px; background:var(--surface); box-shadow:0 8px 24px rgba(23,33,43,.05); }
        .card h2 { margin:0 0 8px; font-size:1.15rem; }
        .card p { flex:1; margin:0 0 20px; color:var(--muted); line-height:1.55; font-size:.95rem; }
        .card a { align-self:flex-start; color:var(--brand-dark); font-weight:800; text-decoration:none; }
        footer { margin-top:34px; color:var(--muted); font-size:.85rem; }
        @media (max-width:760px) { .shell { padding:18px 14px 28px; } header { padding-bottom:26px; } .pos-download { align-items:flex-start; flex-direction:column; gap:12px; } .pos-download a { width:100%; text-align:center; } .grid { grid-template-columns:1fr; } .card { min-height:150px; } }
    </style>
</head>
<body>
<main class="shell">
    <header>
        <div class="brand"><span class="mark">P</span><span>PopStar Center</span></div>
        <a class="login" href="https://erp.popstarcenter.com/login">เข้าสู่ ERP</a>
    </header>
    <section class="intro">
        <h1>ศูนย์รวมระบบงาน</h1>
        <p>เลือกพื้นที่ทำงานที่ต้องการ ระบบแต่ละส่วนจะแยกสิทธิ์และหน้าที่ออกจากกันอย่างชัดเจน</p>
    </section>
    <section class="pos-download" aria-label="ดาวน์โหลด PopCentral POS">
        <div><strong>PopCentral POS</strong><span>โปรแกรม POS สำหรับเครื่องแคชเชียร์ เปิดกะ ขายสินค้า รับชำระ และทำงานออฟไลน์</span></div>
        <a href="https://erp.popstarcenter.com/download/python-pos">ดาวน์โหลด POS →</a>
    </section>
    <section class="grid" aria-label="ระบบที่ใช้งาน">
        <article class="card"><h2>ERP กลาง</h2><p>ขาย คลัง จัดซื้อ บัญชี รายงาน ขนส่ง และข้อมูลหลัก</p><a href="https://erp.popstarcenter.com/login">เข้าสู่ ERP →</a></article>
        <article class="card"><h2>ระบบสมาชิก</h2><p>จัดการสมาชิกและข้อมูลลูกค้าสำหรับช่องทางบริการ</p><a href="https://popstarmember.com/">เปิดระบบสมาชิก →</a></article>
        <article class="card"><h2>PopSpend</h2><p>บันทึกและวิเคราะห์รายจ่าย งบประมาณ และเอกสารค่าใช้จ่าย</p><a href="https://popstarcenter.com/popspend/">เปิด PopSpend →</a></article>
        <article class="card"><h2>TempLog Pro</h2><p>บันทึกและตรวจสอบ Log สำหรับงานหลังบ้าน</p><a href="https://popstarcenter.com/templog/login.php">เข้าสู่ TempLog →</a></article>
        <article class="card"><h2>เว็บไซต์สินค้า</h2><p>รายการอาหารแช่แข็งและข้อมูลหน้าร้าน PopStar Foods</p><a href="https://popstarshops.com/">เปิดเว็บไซต์สินค้า →</a></article>
    </section>
    <footer>PopStar Center © {{ now()->year }}</footer>
</main>
</body>
</html>
