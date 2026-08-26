<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เปรียบเทียบ POS · PopCentral</title>
    <style>
        :root { color-scheme: light; font-family: "Noto Sans Thai", "Leelawadee UI", Tahoma, sans-serif; background: #eef2f5; color: #162331; }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; }
        body { background: #eef2f5; }
        .compare-shell { min-height: 100vh; display: flex; flex-direction: column; }
        .compare-head { display: flex; align-items: center; gap: 18px; padding: 14px 20px; background: #fff; border-bottom: 1px solid #d8e0e7; }
        .compare-head h1 { margin: 0; font-size: 18px; line-height: 1.2; }
        .compare-head p { margin: 3px 0 0; color: #667585; font-size: 12px; }
        .compare-head a { margin-left: auto; color: #9f1f2b; font-size: 12px; font-weight: 800; text-decoration: none; }
        .compare-toolbar { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #f7f9fb; border-bottom: 1px solid #d8e0e7; }
        .compare-toolbar button { border: 1px solid #cbd5dc; border-radius: 6px; padding: 7px 11px; background: #fff; color: #344654; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
        .compare-toolbar button:hover { border-color: #bd2836; color: #9f1f2b; }
        .compare-status { margin-left: auto; color: #667585; font-size: 11px; }
        .compare-grid { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px; }
        .compare-pane { min-width: 0; min-height: 640px; display: flex; flex-direction: column; overflow: hidden; border: 1px solid #d8e0e7; border-radius: 8px; background: #fff; box-shadow: 0 6px 22px rgba(28,48,62,.07); }
        .compare-pane h2 { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0; padding: 10px 13px; border-bottom: 1px solid #d8e0e7; font-size: 13px; }
        .compare-pane h2 span { color: #667585; font-size: 10px; font-weight: 600; }
        .compare-pane iframe { width: 100%; flex: 1; min-height: 0; border: 0; background: #eef2f5; }
        @media (max-width: 900px) { .compare-grid { grid-template-columns: 1fr; } .compare-pane { min-height: 720px; } .compare-status { display: none; } }
    </style>
</head>
<body>
<main class="compare-shell">
    <header class="compare-head">
        <div><h1>เปรียบเทียบหน้าขาย POS</h1><p>ตรวจความต่างระหว่าง Web POS และ Vue POS build จากพื้นที่เดียวกัน</p></div>
        <a href="{{ route('pos.index') }}">เปิดหน้าขายจริง</a>
    </header>
    <div class="compare-toolbar">
        <button type="button" onclick="reloadFrames()">รีเฟรชทั้งสองฝั่ง</button>
        <button type="button" onclick="toggleFullscreen()">ขยายพื้นที่เปรียบเทียบ</button>
        <span class="compare-status">โหมด Vue เป็นข้อมูลตัวอย่างเพื่อเทียบ UI เท่านั้น ไม่ออกบิล</span>
    </div>
    <section class="compare-grid" id="compareGrid">
        <article class="compare-pane"><h2>Web POS <span>/pos</span></h2><iframe id="webPos" src="{{ route('pos.index') }}?compare=1" title="Web POS"></iframe></article>
        <article class="compare-pane"><h2>Vue POS build <span>desktop browser preview</span></h2><iframe id="vuePos" src="{{ asset('pos-desktop-preview/') }}" title="Vue POS build"></iframe></article>
    </section>
</main>
<script>
    function reloadFrames() { document.querySelectorAll('iframe').forEach((frame) => { frame.contentWindow.location.reload(); }); }
    function toggleFullscreen() { document.getElementById('compareGrid').requestFullscreen?.(); }
</script>
</body>
</html>
