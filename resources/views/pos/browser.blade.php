<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f4c75">
    <title>PopCentral Web POS</title>
    <style>
        :root {
            --navy: #0f4c75;
            --navy-dark: #082f49;
            --blue: #1585c0;
            --blue-soft: #eaf6fd;
            --green: #138a5b;
            --red: #bd2f45;
            --ink: #17212b;
            --muted: #64748b;
            --line: #d7e2ea;
            --canvas: #f1f6f9;
            --panel: #ffffff;
            --shadow: 0 8px 25px rgba(15, 76, 117, .10);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body { font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); background: var(--canvas); }
        body.pos-active { overflow: hidden; }
        body.pos-active .page { position: fixed; inset: 56px 0 0; width: 100%; max-width: none; height: auto; margin: 0; padding: 8px 14px; overflow: hidden; }
        button, input, select { font: inherit; }
        button { cursor: pointer; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        .topbar { min-height: 56px; padding: 7px 15px; display: flex; align-items: center; gap: 12px; color: #fff; background: linear-gradient(110deg, var(--navy-dark), var(--navy)); box-shadow: 0 3px 14px rgba(8, 47, 73, .25); }
        .brand { font-size: 18px; font-weight: 900; letter-spacing: -.03em; white-space: nowrap; }
        .brand small { display: inline; margin-left: 7px; color: #bde5f7; font-size: 10px; font-weight: 600; letter-spacing: 0; }
        .top-context { display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1 1 auto; overflow: hidden; }
        .context-item { min-width: 0; padding: 4px 8px; border: 1px solid rgba(255,255,255,.18); border-radius: 7px; background: rgba(255,255,255,.08); }
        .context-item .label { display: inline; margin-right: 5px; color: #a9d3e8; font-size: 9px; font-weight: 700; }
        .context-item .value { display: inline-block; max-width: 190px; overflow: hidden; color: #fff; font-size: 11px; font-weight: 900; vertical-align: bottom; text-overflow: ellipsis; white-space: nowrap; }
        .context-item.shift-open { border-color: rgba(187,247,208,.65); background: rgba(20,122,85,.35); }
        .context-item.shift-open .value { color: #bbf7d0; }
        .top-meta { display: flex; align-items: center; gap: 8px; margin-left: auto; color: #d9effb; font-size: 13px; }
        .status { padding: 5px 10px; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; background: rgba(255,255,255,.09); }
        .status.online { color: #bbf7d0; background: rgba(20, 122, 85, .35); }
        .icon-btn { width: 38px; height: 38px; padding: 0; border: 1px solid rgba(255,255,255,.3); border-radius: 8px; color: #fff; background: rgba(255,255,255,.1); }
        .icon-btn:hover { background: rgba(255,255,255,.2); }
        .page { max-width: 1600px; margin: 0 auto; padding: 14px; }
        .panel { border: 1px solid var(--line); border-radius: 9px; background: var(--panel); box-shadow: 0 3px 12px rgba(15, 76, 117, .08); }
        .connect-panel { max-width: 580px; margin: 8vh auto; padding: 34px; text-align: center; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-size: clamp(26px, 4vw, 38px); letter-spacing: -.04em; }
        h2 { margin-bottom: 12px; font-size: 22px; }
        h3 { margin-bottom: 7px; font-size: 16px; }
        .lead { color: var(--muted); line-height: 1.65; }
        .field { display: flex; flex-direction: column; gap: 6px; text-align: left; }
        .field label { color: #405364; font-size: 13px; font-weight: 800; }
        .input, .select { width: 100%; min-height: 43px; padding: 9px 12px; border: 1px solid #b9ccda; border-radius: 8px; color: var(--ink); background: #fff; outline: none; }
        .input:focus, .select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(21,133,192,.12); }
        .token-input { letter-spacing: .08em; }
        .button { min-height: 42px; padding: 9px 16px; border: 1px solid transparent; border-radius: 8px; font-weight: 800; transition: transform .08s, filter .12s; }
        .button:active { transform: translateY(1px); }
        .button:hover:not(:disabled) { filter: brightness(.97); }
        .button.primary { color: #fff; background: var(--blue); }
        .button.success { color: #fff; background: var(--green); }
        .button.danger { color: #fff; background: var(--red); }
        .button.light { color: var(--navy); border-color: #b9ccda; background: #fff; }
        .button.small { min-height: 34px; padding: 6px 10px; font-size: 12px; }
        .button.full { width: 100%; }
        .hint { margin: 14px 0 0; color: var(--muted); font-size: 12px; line-height: 1.6; }
        .notice { margin: 14px 0; padding: 11px 13px; border-radius: 8px; color: #6a4a00; background: #fff7da; font-size: 13px; line-height: 1.55; text-align: left; }
        .error { margin: 12px 0; padding: 10px 12px; border-radius: 8px; color: #9d2439; background: #fff0f2; font-size: 13px; text-align: left; }
        .hidden { display: none !important; }
        .workspace { height: 100%; min-height: 0; }
        .sale-grid { display: grid; grid-template-columns: minmax(0, 55fr) minmax(360px, 45fr); gap: 10px; align-items: stretch; min-height: 0; height: 100%; }
        .catalog, .cart { min-width: 0; min-height: 0; height: 100%; overflow: hidden; }
        .catalog { display: flex; flex-direction: column; }
        .section-head { padding: 8px 11px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 8px; }
        .section-head h2 { margin: 0; font-size: 18px; }
        .section-head .grow { flex: 1; }
        .catalog .section-head small { color: var(--muted); font-size: 11px; font-weight: 600; }
        .catalog .section-head { color: var(--navy); background: var(--blue-soft); }
        .cart .section-head { color: #fff; background: var(--navy); }
        .cart .section-head .button.light { color: #fff; border-color: rgba(255,255,255,.42); background: transparent; }
        .cart .section-head .button.light:hover { background: rgba(255,255,255,.12); }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); grid-template-rows: repeat(3, minmax(124px, 1fr)); grid-auto-rows: minmax(124px, auto); align-content: start; gap: 8px; flex: 1 1 auto; width: 100%; height: 0; min-height: 0; padding: 10px; overflow: auto; }
        .product { display: flex; flex-direction: column; justify-content: space-between; gap: 3px; min-height: 0; min-width: 0; padding: 10px; border: 1px solid #cbdde8; border-radius: 9px; color: var(--ink); background: #fff; overflow: hidden; text-align: left; transition: border-color .12s, box-shadow .12s, transform .08s; }
        .product:hover { border-color: var(--blue); box-shadow: 0 5px 14px rgba(21,133,192,.15); transform: translateY(-1px); }
        .product:active { transform: translateY(1px); }
        .product .name { min-height: 0; display: -webkit-box; overflow: hidden; font-size: 13px; font-weight: 800; line-height: 1.35; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        .product .sku { margin-top: 2px; color: var(--muted); font-size: 10px; }
        .product .price { margin-top: 2px; color: var(--blue); font-size: 18px; font-weight: 900; }
        .product .stock { margin-top: 2px; color: var(--muted); font-size: 10px; }
        .cart { display: flex; flex-direction: column; }
        .cart-list { flex: 1 1 auto; width: 100%; height: 0; min-height: 0; overflow: auto; }
        .cart-empty { display: grid; place-items: center; height: 100%; min-height: 0; padding: 20px; color: var(--muted); text-align: center; }
        .cart-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; padding: 9px 12px; border-bottom: 1px solid #edf2f5; }
        .cart-row .name { font-size: 13px; font-weight: 800; line-height: 1.4; }
        .cart-row .line-total { text-align: right; font-weight: 900; }
        .cart-row .controls { display: flex; align-items: center; gap: 5px; margin-top: 6px; }
        .qty-btn { width: 34px; height: 34px; border: 1px solid #bdd0dd; border-radius: 7px; color: var(--navy); background: #fff; font-size: 18px; font-weight: 800; }
        .qty-btn:hover { border-color: var(--blue); background: var(--blue-soft); }
        .qty { min-width: 30px; text-align: center; font-weight: 800; }
        .remove { min-height: 34px; padding: 0 7px; border: 1px solid rgba(189,47,69,.25); border-radius: 7px; color: var(--red); background: #fff7f8; font-size: 12px; font-weight: 800; }
        .remove:hover { background: #ffe8ec; }
        .cart-footer { flex: 0 0 auto; padding: 10px 12px; border-top: 1px solid var(--line); color: #d9effb; background: var(--navy-dark); }
        .discount-card-row { margin-bottom: 7px; }
        .discount-card-row label { display: block; margin-bottom: 4px; color: #b9d9e9; font-size: 11px; font-weight: 800; }
        .discount-card-input { display: flex; gap: 6px; }
        .discount-card-input .input { min-height: 36px; padding: 6px 9px; border-color: rgba(255,255,255,.3); color: #fff; background: rgba(255,255,255,.1); }
        .discount-card-input .input::placeholder { color: #b9d9e9; }
        .discount-card-input .button { min-height: 36px; padding: 5px 9px; color: #fff; border-color: rgba(255,255,255,.4); background: rgba(255,255,255,.13); white-space: nowrap; }
        .discount-card-input .button:hover:not(:disabled) { background: rgba(255,255,255,.22); }
        .discount-card-status { margin-top: 4px; color: #bbf7d0; font-size: 11px; }
        .discount-card-status.error { margin: 4px 0 0; padding: 0; color: #fecaca; background: transparent; }
        .total-line { display: flex; justify-content: space-between; gap: 10px; margin: 5px 0; color: #b9d9e9; }
        .total-line.grand { margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255,255,255,.25); color: #fff; font-size: 22px; font-weight: 900; }
        .action-row { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-top: 9px; }
        #payButton { min-height: 42px; font-size: 16px; background: var(--blue); }
        #closeShiftButton { min-height: 42px; }
        .empty-state { padding: 38px 20px; text-align: center; color: var(--muted); }
        .modal-backdrop { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; padding: 16px; background: rgba(8, 47, 73, .48); }
        .modal { width: min(480px, 100%); max-height: calc(100vh - 32px); overflow: auto; padding: 22px; border-radius: 14px; background: #fff; box-shadow: 0 20px 70px rgba(8,47,73,.3); }
        .modal.wide { width: min(720px, 100%); }
        .modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 17px; }
        .modal-head h2 { flex: 1; margin: 0; }
        .close { width: 34px; height: 34px; border: 0; border-radius: 7px; color: #526579; background: #eef4f7; }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; }
        .payment-qr { margin-top: 12px; padding: 12px; border: 1px solid #c7e3ee; border-radius: 10px; text-align: center; background: #f4fbfe; }
        .payment-qr h3 { margin-bottom: 7px; color: var(--navy); font-size: 15px; }
        .payment-qr-box { display: inline-block; padding: 7px; border: 1px solid #d6e3e9; border-radius: 8px; background: #fff; }
        .payment-qr-box canvas, .payment-qr-box img { display: block; width: 188px !important; height: 188px !important; }
        .payment-qr-amount { margin-top: 7px; color: var(--green); font-size: 20px; font-weight: 900; }
        .payment-qr-account { margin-top: 4px; color: var(--muted); font-size: 12px; }
        .payment-qr-unavailable { color: #9d2439; font-size: 12px; line-height: 1.45; }
        .settings-section { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--line); }
        .settings-section h3 { margin-bottom: 8px; color: var(--navy); }
        .settings-section-head { display: flex; align-items: center; gap: 8px; }
        .settings-section-head .grow { flex: 1; }
        .settings-seller-grid { display: flex; flex-wrap: wrap; gap: 7px; }
        .settings-seller-grid .seller { min-height: 38px; }
        .settings-shift-row { display: flex; align-items: center; gap: 10px; }
        .settings-shift-row .value { flex: 1; color: var(--muted); font-size: 13px; font-weight: 700; }
        .weight-product-name { margin-bottom: 6px; color: var(--navy); font-size: 16px; font-weight: 900; line-height: 1.45; }
        .weight-price { color: var(--muted); font-size: 13px; }
        .weight-price strong { color: var(--blue); font-size: 17px; }
        .weight-total { display: flex; justify-content: space-between; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--muted); }
        .weight-total strong { color: var(--green); font-size: 22px; }
        .weight-edit { min-height: 34px; padding: 0 9px; border: 1px solid #b9ccda; border-radius: 7px; color: var(--navy); background: #fff; font-size: 12px; font-weight: 800; }
        .weight-edit:hover { border-color: var(--blue); background: var(--blue-soft); }
        .quantity-product-name { margin-bottom: 6px; color: var(--navy); font-size: 16px; font-weight: 900; line-height: 1.45; }
        .quantity-price { color: var(--muted); font-size: 13px; }
        .quantity-price strong { color: var(--blue); font-size: 17px; }
        .scan-hint { margin: 5px 14px 0; color: var(--muted); font-size: 11px; }
        .seller { min-height: 28px; padding: 3px 9px; border: 1px solid #c8d9e4; border-radius: 7px; color: var(--ink); background: #fff; text-align: left; }
        .seller:hover { border-color: var(--blue); background: var(--blue-soft); }
        .seller strong { display: inline; font-size: 12px; }
        .seller small { display: inline; margin: 0 0 0 5px; color: var(--muted); font-size: 10px; }
        .receipt-paper { width: 80mm; max-width: 100%; margin: 0 auto; padding: 4mm; color: #111; background: #fff; font-family: "Courier New", monospace; font-size: 12px; }
        .receipt-paper h3 { margin: 0 0 4px; text-align: center; font-size: 16px; }
        .receipt-paper .center { text-align: center; }
        .receipt-paper .line { display: flex; justify-content: space-between; gap: 8px; margin: 5px 0; }
        .receipt-paper hr { border: 0; border-top: 1px dashed #333; }
        .toast { position: fixed; right: 18px; bottom: 18px; z-index: 40; max-width: min(390px, calc(100vw - 36px)); padding: 12px 15px; border-radius: 9px; color: #fff; background: #183447; box-shadow: var(--shadow); }
        @media (max-width: 900px) { body.pos-active { overflow: auto; } body.pos-active .page { position: static; height: auto; max-width: 1600px; overflow: visible; } .workspace { height: auto; } .top-context { gap: 4px; } .context-item { padding: 3px 6px; } .context-item .value { max-width: 130px; } .sale-grid { grid-template-columns: 1fr; min-height: 0; height: auto; } .cart-list { height: auto; max-height: 430px; min-height: 260px; } .product-grid { grid-template-rows: none; grid-auto-rows: minmax(112px, auto); height: auto; min-height: 360px; } }
        @media (max-width: 560px) { .page { padding: 10px; } .topbar { padding: 9px 11px; } .brand small { display: none; } .top-context { gap: 3px; } .top-meta { gap: 5px; font-size: 11px; } .status { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .context-item .label { display: block; margin: 0; font-size: 8px; } .context-item .value { max-width: 92px; font-size: 10px; } .connect-panel { margin: 5vh auto; padding: 22px 17px; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 10px; gap: 7px; } .product { min-height: 112px; padding: 9px; } .action-row { grid-template-columns: 1fr; } .payment-qr-box canvas, .payment-qr-box img { width: 160px !important; height: 160px !important; } }
        @media print { @page { size: 80mm auto; margin: 0; } body { background: #fff; } body.printing > *:not(#receiptModal) { display: none !important; } body.printing #receiptModal { position: static; display: block !important; padding: 0; background: #fff; } body.printing #receiptModal .modal { width: auto; max-height: none; padding: 0; box-shadow: none; } body.printing #receiptModal .modal-head, body.printing #receiptModal .modal-actions { display: none; } body.printing .receipt-paper { width: 80mm; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">PopCentral Web POS <small>ขายออนไลน์ผ่านเว็บ</small></div>
        <div id="topContext" class="top-context hidden" aria-label="ข้อมูลเครื่อง POS">
            <div class="context-item"><span class="label">สาขา</span><strong id="branchValue" class="value">—</strong></div>
            <div class="context-item"><span class="label">เครื่อง</span><strong id="deviceValue" class="value">—</strong></div>
            <div class="context-item"><span class="label">คนขาย</span><strong id="cashierValue" class="value">ยังไม่เลือก</strong></div>
            <div id="shiftSummary" class="context-item"><span class="label">กะ</span><strong id="shiftValue" class="value">ยังไม่เปิดกะ</strong></div>
        </div>
        <div class="top-meta">
            <span id="status" class="status">ยังไม่เชื่อมต่อ</span>
            <button id="settingsButton" class="icon-btn" type="button" title="ตั้งค่า">⚙</button>
        </div>
    </header>

    <main class="page">
        <section id="connectPanel" class="panel connect-panel">
            <h1>POS ออนไลน์</h1>
            <p class="lead">เลือกชื่อคนขาย เปิดกะ ขายสินค้า รับชำระ และปิดกะได้จากเบราว์เซอร์</p>
            <div class="notice">หน้านี้ใช้ Device Token ของเครื่อง POS เพื่อรักษาสิทธิ์สาขาและบันทึกยอดขายลง ERP โดยตรง ไม่ต้องใช้บัญชีล็อกอินหน้า ERP</div>
            <div id="connectError" class="error hidden"></div>
            <div class="field">
                <label for="tokenInput">Device Token</label>
                <input id="tokenInput" class="input token-input" type="password" autocomplete="off" placeholder="วาง token ของเครื่อง POS ที่นี่">
            </div>
            <button id="connectButton" class="button primary full" type="button" style="margin-top:14px">เชื่อมต่อเครื่อง POS</button>
            <p class="hint">ขอ Device Token จากผู้ดูแลระบบที่หน้าอุปกรณ์ POS แล้ววางในเครื่องแคชเชียร์นี้ ห้ามส่ง token ผ่านแชตหรือใส่ใน URL</p>
        </section>

        <section id="workspace" class="workspace hidden">
            <section id="salePanel" class="sale-grid hidden">
                <div class="panel catalog">
                    <div class="section-head"><h2>สินค้า</h2><small>โหลดทีละ 100 รายการ · ค้นหาเพิ่มได้</small><span class="grow"></span><button id="reloadProducts" class="button light small" type="button">รีเฟรช</button></div>
                    <div style="padding:12px 14px 0"><input id="productSearch" class="input" type="search" placeholder="สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า / SKU"></div>
                    <div class="scan-hint">สแกนแล้วกด Enter เพื่อเพิ่มอัตโนมัติ · กดเลือกสินค้าเองเพื่อกรอกจำนวนหรือน้ำหนัก</div>
                    <div id="productGrid" class="product-grid"></div>
                </div>
                <div class="panel cart">
                    <div class="section-head"><h2>รายการขาย</h2><span class="grow"></span><button id="clearCart" class="button light small" type="button">ล้างรายการ</button></div>
                    <div id="cartList" class="cart-list"></div>
                    <div class="cart-footer">
                        <div class="discount-card-row">
                            <label for="discountCardCode">ส่วนลดบัตร / สแกนบาร์โค้ด</label>
                            <div class="discount-card-input"><input id="discountCardCode" class="input" type="text" autocomplete="off" placeholder="สแกนหรือพิมพ์รหัสบัตรส่วนลด"><button id="applyDiscountCard" class="button" type="button">ใช้บัตร</button></div>
                            <div id="discountCardStatus" class="discount-card-status hidden"></div>
                        </div>
                        <div class="total-line"><span>จำนวนรายการ</span><strong id="cartCount">0</strong></div>
                        <div id="discountLine" class="total-line hidden"><span>ส่วนลด</span><strong id="cartDiscount">-฿0.00</strong></div>
                        <div class="total-line grand"><span>รวมสุทธิ</span><span id="cartTotal">฿0.00</span></div>
                        <div class="action-row"><button id="payButton" class="button success" type="button" disabled>รับชำระ</button><button id="closeShiftButton" class="button danger" type="button">ปิดกะ</button></div>
                    </div>
                </div>
            </section>
        </section>
    </main>

    <div id="settingsModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2>ตั้งค่า Web POS</h2><button class="close" type="button" data-close="settingsModal">×</button></div>
            <div class="settings-section" style="margin-top:0;padding-top:0;border-top:0">
                <div class="settings-section-head"><h3>คนขาย</h3><span class="grow"></span><button id="reloadCashiers" class="button light small" type="button">โหลดรายชื่อใหม่</button></div>
                <div id="sellerError" class="error hidden" style="margin:8px 0 0"></div>
                <div id="sellerGrid" class="settings-seller-grid"></div>
            </div>
            <div class="settings-section">
                <h3>กะขาย</h3>
                <div class="settings-shift-row"><span id="settingsShiftValue" class="value">ยังไม่เลือกคนขาย</span><button id="settingsShiftButton" class="button light small" type="button" disabled>เปิดกะ</button></div>
            </div>
            <div class="field"><label for="settingsToken">Device Token</label><input id="settingsToken" class="input token-input" type="password" autocomplete="off"></div>
            <div class="field" style="margin-top:12px"><label for="paperWidth">ขนาดใบเสร็จ</label><select id="paperWidth" class="select"><option value="80mm">80 มม.</option></select></div>
            <div class="notice">Token จะถูกเก็บใน localStorage ของเบราว์เซอร์เครื่องนี้เท่านั้น ถ้าเป็นเครื่องสาธารณะให้ล้างค่าเมื่อเลิกใช้งาน</div>
            <div class="modal-actions"><button id="clearTokenButton" class="button danger" type="button">ล้าง Token</button><button id="saveSettingsButton" class="button primary" type="button">บันทึกและเชื่อมต่อ</button></div>
        </div>
    </div>

    <div id="shiftModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2 id="shiftModalTitle">เปิดกะ</h2><button class="close" type="button" data-close="shiftModal">×</button></div>
            <p id="shiftModalText" class="lead">ระบุเงินทอนเริ่มต้นของกะ</p>
            <div class="field"><label for="openingCash">เงินสดตั้งต้น</label><input id="openingCash" class="input" type="number" min="0" step="0.01" value="0"></div>
            <div id="closeCashField" class="field hidden" style="margin-top:12px"><label for="countedCash">เงินสดนับปิดกะ</label><input id="countedCash" class="input" type="number" min="0" step="0.01" value="0"></div>
            <div class="modal-actions"><button class="button light" type="button" data-close="shiftModal">ยกเลิก</button><button id="shiftSubmitButton" class="button primary" type="button">ยืนยัน</button></div>
        </div>
    </div>

    <div id="weightModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2 id="weightModalTitle">กรอกน้ำหนัก</h2><button class="close" type="button" data-close="weightModal">×</button></div>
            <div id="weightProductName" class="weight-product-name"></div>
            <div class="weight-price">ราคาต่อกิโลกรัม <strong id="weightUnitPrice">฿0.00</strong></div>
            <div class="field" style="margin-top:14px"><label for="weightInput">น้ำหนัก (กิโลกรัม)</label><input id="weightInput" class="input" type="number" min="0.001" step="0.001" inputmode="decimal" placeholder="เช่น 0.250"></div>
            <div class="weight-total"><span>ยอดสินค้านี้</span><strong id="weightTotal">฿0.00</strong></div>
            <div id="weightError" class="error hidden"></div>
            <div class="modal-actions"><button class="button light" type="button" data-close="weightModal">ยกเลิก</button><button id="confirmWeight" class="button primary" type="button">เพิ่มเข้ารายการ</button></div>
        </div>
    </div>

    <div id="quantityModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2>กรอกจำนวน</h2><button class="close" type="button" data-close="quantityModal">×</button></div>
            <div id="quantityProductName" class="quantity-product-name"></div>
            <div class="quantity-price">ราคาต่อหน่วย <strong id="quantityUnitPrice">฿0.00</strong></div>
            <div class="field" style="margin-top:14px"><label for="quantityInput">จำนวน (ชิ้น/หน่วย)</label><input id="quantityInput" class="input" type="number" min="1" step="1" inputmode="numeric" value="1"></div>
            <div class="weight-total"><span>ยอดสินค้านี้</span><strong id="quantityTotal">฿0.00</strong></div>
            <div id="quantityError" class="error hidden"></div>
            <div class="modal-actions"><button class="button light" type="button" data-close="quantityModal">ยกเลิก</button><button id="confirmQuantity" class="button primary" type="button">เพิ่มเข้ารายการ</button></div>
        </div>
    </div>

    <div id="paymentModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2>รับชำระเงิน</h2><button class="close" type="button" data-close="paymentModal">×</button></div>
            <div class="total-line grand" style="margin-top:0"><span>ยอดที่ต้องชำระ</span><span id="paymentTotal">฿0.00</span></div>
            <div class="field" style="margin-top:14px"><label for="paymentMethod">ช่องทางชำระ</label><select id="paymentMethod" class="select"><option value="cash">เงินสด</option><option value="transfer">โอนเงิน / QR</option></select></div>
            <div id="cashFields" class="field" style="margin-top:12px"><label for="cashReceived">รับเงินมา</label><input id="cashReceived" class="input" type="number" min="0" step="0.01"></div>
            <div id="changeRow" class="total-line" style="margin-top:10px"><span>เงินทอน</span><strong id="changeAmount">฿0.00</strong></div>
            <div id="transferFields" class="hidden">
                <div id="paymentQrPanel" class="payment-qr">
                    <h3>สแกนจ่าย PromptPay</h3>
                    <div id="paymentQr" class="payment-qr-box"></div>
                    <div id="paymentQrAmount" class="payment-qr-amount">฿0.00</div>
                    <div id="paymentQrAccount" class="payment-qr-account"></div>
                    <div id="paymentQrUnavailable" class="payment-qr-unavailable hidden"></div>
                </div>
                <div class="field" style="margin-top:12px"><label for="transferLast4">เลขท้ายบัญชีผู้โอน 4 หลัก</label><input id="transferLast4" class="input" inputmode="numeric" maxlength="4" placeholder="เช่น 1234"></div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px"><input id="paymentConfirmed" type="checkbox"> ตรวจเงินเข้าแล้ว</label>
            </div>
            <div id="paymentError" class="error hidden"></div>
            <div class="modal-actions"><button class="button light" type="button" data-close="paymentModal">ยกเลิก</button><button id="submitPayment" class="button success" type="button">ออกใบเสร็จ</button></div>
        </div>
    </div>

    <div id="receiptModal" class="modal-backdrop hidden">
        <div class="modal wide">
            <div class="modal-head"><h2>ใบเสร็จ</h2><button class="close" type="button" data-close="receiptModal">×</button></div>
            <div id="receiptPaper" class="receipt-paper"></div>
            <div class="modal-actions"><button id="printReceipt" class="button primary" type="button">พิมพ์ใบเสร็จ</button><button class="button light" type="button" data-close="receiptModal">ปิด</button></div>
        </div>
    </div>

    <div id="toast" class="toast hidden"></div>

    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        (() => {
            const TOKEN_KEY = 'popstar_web_pos_device_token';
            const PAPER_KEY = 'popstar_web_pos_paper_width';
            const state = { token: '', config: null, cashiers: [], cashier: null, shift: null, products: [], cart: [], discountCard: null, lastReceipt: null, weightTarget: null, quantityTarget: null, shiftAction: 'open', toastTimer: null, productSearchTimer: null, productRequestId: 0 };
            const $ = (id) => document.getElementById(id);
            const money = (value) => `฿${Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
            const show = (id) => $(id).classList.remove('hidden');
            const hide = (id) => $(id).classList.add('hidden');
            const error = (id, message) => { const node = $(id); node.textContent = message; node.classList.toggle('hidden', !message); };
            const toast = (message) => { const node = $('toast'); node.textContent = message; node.classList.remove('hidden'); clearTimeout(state.toastTimer); state.toastTimer = setTimeout(() => node.classList.add('hidden'), 3500); };

            function setStatus(text, online = false) { $('status').textContent = text; $('status').classList.toggle('online', online); }
            function tokenFromInput() { return $('tokenInput').value.trim(); }
            function apiUrl(path) { return `/api/pos${path}`; }

            async function api(path, options = {}) {
                if (!state.token) throw new Error('ยังไม่ได้เชื่อมต่อ Device Token');
                const headers = { Accept: 'application/json', Authorization: `Bearer ${state.token}`, ...(options.headers || {}) };
                if (options.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
                const response = await fetch(apiUrl(path), { ...options, headers });
                let data = null;
                try { data = await response.json(); } catch (_) { data = {}; }
                if (!response.ok) throw new Error(data.message || `ระบบตอบกลับ ${response.status}`);
                return data;
            }

            function resetSession(clearToken = false) {
                state.config = null; state.cashiers = []; state.cashier = null; state.shift = null; state.products = []; state.cart = []; state.discountCard = null;
                if (clearToken) { state.token = ''; localStorage.removeItem(TOKEN_KEY); $('tokenInput').value = ''; }
                document.body.classList.remove('pos-active'); hide('topContext');
                hide('workspace'); show('connectPanel'); setStatus('ยังไม่เชื่อมต่อ'); renderCart();
            }

            async function connect() {
                const token = tokenFromInput() || state.token;
                if (!token) return error('connectError', 'กรุณาใส่ Device Token ก่อนเชื่อมต่อ');
                state.token = token; error('connectError', ''); setStatus('กำลังเชื่อมต่อ…');
                try {
                    const config = await api('/ping');
                    state.config = config; localStorage.setItem(TOKEN_KEY, state.token);
                    $('branchValue').textContent = config.branch_name || `สาขา #${config.branch_id || '—'}`;
                    $('deviceValue').textContent = config.device?.name || config.device?.terminal_code || 'เครื่อง POS';
                    document.body.classList.add('pos-active'); show('topContext'); show('workspace'); hide('connectPanel'); setStatus('เชื่อมต่อแล้ว', true); renderShift();
                    await loadCashiers();
                    toast('เชื่อมต่อ ERP สำเร็จ');
                } catch (exception) {
                    resetSession(false); state.token = token; $('tokenInput').value = token; error('connectError', exception.message || 'เชื่อมต่อไม่สำเร็จ'); setStatus('เชื่อมต่อไม่สำเร็จ');
                }
            }

            async function loadCashiers() {
                error('sellerError', ''); $('sellerGrid').innerHTML = '<div class="empty-state">กำลังโหลดรายชื่อคนขาย…</div>';
                try {
                    const response = await api('/cashiers'); state.cashiers = response.cashiers || [];
                    if (!state.cashiers.length) { $('sellerGrid').innerHTML = '<div class="empty-state">ยังไม่มีคนขายที่ได้รับอนุญาตบนเครื่องนี้</div>'; return; }
                    $('sellerGrid').innerHTML = state.cashiers.map((cashier) => `<button class="seller" type="button" data-cashier-id="${cashier.id}"><strong>${escapeHtml(cashier.name || cashier.user_name || cashier.code)}</strong><small>${escapeHtml(cashier.code || '')}</small></button>`).join('');
                    $('sellerGrid').querySelectorAll('[data-cashier-id]').forEach((button) => button.addEventListener('click', () => chooseCashier(Number(button.dataset.cashierId))));
                } catch (exception) { error('sellerError', exception.message); $('sellerGrid').innerHTML = ''; }
            }

            async function chooseCashier(id) {
                const cashier = state.cashiers.find((item) => Number(item.id) === id); if (!cashier) return;
                error('sellerError', ''); setStatus('กำลังเลือกคนขาย…');
                try {
                    const response = await api('/cashier/login', { method: 'POST', body: JSON.stringify({ cashier_id: id }) });
                    state.cashier = response.cashier || cashier; $('cashierValue').textContent = state.cashier.name || cashier.name || cashier.code; setStatus('เลือกคนขายแล้ว', true); hide('settingsModal');
                    await loadShift();
                    if (state.shift) { await enterSale(); } else { openShiftModal(); }
                } catch (exception) { error('sellerError', exception.message); setStatus('เลือกคนขายไม่สำเร็จ'); }
            }

            async function loadShift() {
                if (!state.cashier || !state.config?.branch_id) return;
                const query = `?branch_id=${encodeURIComponent(state.config.branch_id)}&cashier_id=${encodeURIComponent(state.cashier.id)}`;
                const response = await api(`/shift${query}`); state.shift = response.shift || null; renderShift();
            }

            function renderShift() {
                const open = Boolean(state.shift); const shiftText = open ? `เปิดอยู่ ${state.shift.shift_no || ''}`.trim() : 'ยังไม่เปิดกะ';
                $('shiftSummary').classList.toggle('shift-open', open); $('shiftValue').textContent = shiftText;
                const settingsValue = $('settingsShiftValue'); const settingsButton = $('settingsShiftButton');
                settingsValue.textContent = state.cashier ? shiftText : 'ยังไม่เลือกคนขาย'; settingsButton.disabled = !state.cashier; settingsButton.textContent = open ? 'ปิดกะ' : 'เปิดกะ'; settingsButton.classList.toggle('danger', open); settingsButton.classList.toggle('light', !open);
            }

            function openShiftModal() {
                state.shiftAction = 'open'; $('shiftModalTitle').textContent = 'เปิดกะ'; $('shiftModalText').textContent = 'ระบุเงินทอนเริ่มต้นของกะ'; $('openingCash').value = '0'; hide('closeCashField'); $('shiftSubmitButton').textContent = 'ยืนยันเปิดกะ'; show('shiftModal');
            }
            function closeShiftModal() {
                if (!state.shift) return; state.shiftAction = 'close'; $('shiftModalTitle').textContent = 'ปิดกะ'; $('shiftModalText').textContent = 'นับเงินสดจริงในลิ้นชักแล้วระบุยอด'; $('countedCash').value = String(state.shift.expected_cash ?? state.shift.opening_cash ?? 0); show('closeCashField'); $('shiftSubmitButton').textContent = 'ยืนยันปิดกะ'; show('shiftModal');
            }
            async function submitShift() {
                const button = $('shiftSubmitButton'); button.disabled = true;
                try {
                    if (state.shiftAction === 'open') {
                        const response = await api('/shift/open', { method: 'POST', body: JSON.stringify({ branch_id: state.config.branch_id, cashier_id: state.cashier.id, opening_cash: Number($('openingCash').value || 0) }) }); state.shift = response.shift;
                        hide('shiftModal'); renderShift(); await enterSale(); toast('เปิดกะเรียบร้อย');
                    } else {
                        await api('/shift/close', { method: 'POST', body: JSON.stringify({ shift_id: state.shift.id, counted_cash: Number($('countedCash').value || 0) }) }); state.shift = null; state.cart = []; hide('shiftModal'); hide('salePanel'); renderShift(); renderCart(); toast('ปิดกะเรียบร้อย');
                    }
                } catch (exception) { toast(exception.message); } finally { button.disabled = false; }
            }

            async function enterSale() {
                show('salePanel'); await loadProducts(); renderCart();
            }
            async function loadProducts(query = '') {
                const requestId = ++state.productRequestId;
                const grid = $('productGrid'); grid.innerHTML = '<div class="empty-state">กำลังโหลดสินค้า…</div>';
                try {
                    const params = new URLSearchParams({ branch_id: String(state.config.branch_id) });
                    if (query.trim()) params.set('q', query.trim());
                    state.products = await api(`/products?${params.toString()}`);
                    if (requestId === state.productRequestId) renderProducts();
                }
                catch (exception) { if (requestId === state.productRequestId) grid.innerHTML = `<div class="error">${escapeHtml(exception.message)}</div>`; }
            }
            function renderProducts() {
                const products = state.products;
                $('productGrid').innerHTML = products.length ? products.map((product) => `<button class="product" type="button" data-product-id="${product.id}"><div class="name">${escapeHtml(product.name_th)}</div><div class="sku">${escapeHtml(product.sku_code || '')}</div><div class="price">${money(product.pos_price)}</div><div class="stock">สต๊อก ${product.stock_qty === null || product.stock_qty === undefined ? 'ไม่ระบุ' : Number(product.stock_qty).toLocaleString('th-TH')}</div></button>`).join('') : '<div class="empty-state">ไม่พบสินค้า</div>';
                $('productGrid').querySelectorAll('[data-product-id]').forEach((button) => button.addEventListener('click', () => {
                    const product = state.products.find((item) => Number(item.id) === Number(button.dataset.productId));
                    if (product?.is_scale) openWeightModal(product); else openQuantityModal(product);
                }));
            }
            function scheduleProductSearch() {
                clearTimeout(state.productSearchTimer);
                state.productSearchTimer = setTimeout(() => loadProducts($('productSearch').value), 220);
            }
            async function scanProduct() {
                const barcode = $('productSearch').value.trim();
                if (!barcode || !state.config?.branch_id) return;
                clearTimeout(state.productSearchTimer);
                state.productRequestId += 1;
                setStatus('กำลังอ่านบาร์โค้ด…');
                try {
                    const result = await api('/scan', { method: 'POST', body: JSON.stringify({ branch_id: state.config.branch_id, barcode }) });
                    addScannedProduct(result.product, Number(result.qty), result.barcode, result.barcode_type);
                    $('productSearch').value = '';
                    await loadProducts();
                    setStatus('พร้อมขาย', true);
                } catch (exception) {
                    setStatus('พร้อมขาย', true);
                    toast(exception.message || 'อ่านบาร์โค้ดไม่สำเร็จ');
                }
            }
            function addScannedProduct(product, qty, barcode, barcodeType) {
                if (!product || !Number.isFinite(qty) || qty <= 0) return;
                invalidateDiscountCard();
                const existing = state.cart.find((item) => item.id === product.id && item.scan_barcode_type !== 'SCALE_WEIGHT');
                if (existing) {
                    existing.qty = Number(existing.qty) + qty;
                    existing.scan_barcode ||= barcode;
                    existing.scan_barcode_type ||= barcodeType;
                } else {
                    state.cart.push({ ...product, qty, scan_barcode: barcode, scan_barcode_type: barcodeType });
                }
                renderCart();
            }
            function isScaleProduct(product) { return Boolean(product?.is_scale); }
            function formatWeight(value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
            function updateWeightTotal() {
                const product = state.weightTarget?.product;
                const weight = Number($('weightInput').value || 0);
                $('weightTotal').textContent = money(weight * Number(product?.pos_price || 0));
            }
            function updateQuantityTotal() {
                const product = state.quantityTarget?.product;
                const quantity = Number($('quantityInput').value || 0);
                $('quantityTotal').textContent = money(quantity * Number(product?.pos_price || 0));
            }
            function openQuantityModal(product) {
                state.quantityTarget = { product };
                $('quantityProductName').textContent = product.name_th || 'สินค้า';
                $('quantityUnitPrice').textContent = `${money(product.pos_price)} / หน่วย`;
                $('quantityInput').value = '1';
                error('quantityError', ''); updateQuantityTotal(); show('quantityModal');
                window.setTimeout(() => $('quantityInput').focus(), 0);
            }
            function confirmQuantity() {
                const product = state.quantityTarget?.product;
                const quantity = Number($('quantityInput').value);
                if (!product || !Number.isFinite(quantity) || quantity <= 0) return error('quantityError', 'กรุณากรอกจำนวนมากกว่า 0');
                invalidateDiscountCard();
                const existing = state.cart.find((item) => item.id === product.id && !item.scan_barcode);
                if (existing) existing.qty = Number(existing.qty) + quantity;
                else state.cart.push({ ...product, qty: quantity });
                state.quantityTarget = null; hide('quantityModal'); renderCart();
            }
            function openWeightModal(product, line = null) {
                state.weightTarget = { product, lineId: line?.id || null };
                $('weightModalTitle').textContent = line ? 'แก้ไขน้ำหนัก' : 'กรอกน้ำหนัก';
                $('weightProductName').textContent = product.name_th || 'สินค้าชั่งน้ำหนัก';
                $('weightUnitPrice').textContent = `${money(product.pos_price)} / กก.`;
                $('weightInput').value = line ? Number(line.qty).toFixed(3) : '';
                $('confirmWeight').textContent = line ? 'บันทึกน้ำหนัก' : 'เพิ่มเข้ารายการ';
                error('weightError', ''); updateWeightTotal(); show('weightModal');
                window.setTimeout(() => $('weightInput').focus(), 0);
            }
            function confirmWeight() {
                const target = state.weightTarget;
                const weight = Number($('weightInput').value);
                if (!target?.product || !Number.isFinite(weight) || weight <= 0) return error('weightError', 'กรุณากรอกน้ำหนักมากกว่า 0 กิโลกรัม');
                invalidateDiscountCard();
                const existing = state.cart.find((item) => item.id === target.product.id && item.scan_barcode_type !== 'SCALE_WEIGHT');
                if (target.lineId) {
                    if (existing) existing.qty = weight;
                } else if (existing) {
                    existing.qty = Number(existing.qty) + weight;
                } else {
                    state.cart.push({ ...target.product, qty: weight });
                }
                state.weightTarget = null; hide('weightModal'); renderCart();
            }
            function addToCart(id) { const product = state.products.find((item) => Number(item.id) === id); if (!product) return; invalidateDiscountCard(); const line = state.cart.find((item) => item.id === id); if (line) line.qty = Number(line.qty) + 1; else state.cart.push({ ...product, qty: 1 }); renderCart(); }
            function invalidateDiscountCard() {
                if (!state.discountCard) return;
                state.discountCard = null;
                $('discountCardCode').value = '';
                const status = $('discountCardStatus'); status.textContent = ''; status.classList.add('hidden'); status.classList.remove('error');
            }
            function setQty(id, delta) { const line = state.cart.find((item) => item.id === id); if (!line) return; invalidateDiscountCard(); line.qty = Math.max(0, Number(line.qty) + delta); state.cart = state.cart.filter((item) => item.qty > 0); renderCart(); }
            function cartSubtotal() { return state.cart.reduce((sum, item) => sum + Number(item.qty) * Number(item.pos_price || 0), 0); }
            function cartDiscount() { return Math.min(cartSubtotal(), Math.max(0, Number(state.discountCard?.discount_amount || 0))); }
            function cartTotal() { return Math.max(0, cartSubtotal() - cartDiscount()); }
            function rounded(value) { return Math.round((Number(value) || 0) * 100) / 100; }
            function checkoutItems() {
                const subtotal = cartSubtotal();
                const discount = cartDiscount();
                let remaining = discount;
                return state.cart.map((item, index) => {
                    const qty = Number(item.qty);
                    const gross = rounded(qty * Number(item.pos_price || 0));
                    const lineDiscount = discount > 0
                        ? (index === state.cart.length - 1 ? remaining : Math.min(remaining, rounded(discount * gross / subtotal)))
                        : 0;
                    remaining = rounded(remaining - lineDiscount);
                    const unitPrice = qty > 0 ? rounded(Math.max(0, gross - lineDiscount) / qty) : 0;
                    return { product_id: item.id, qty, unit_price: unitPrice, barcode: item.scan_barcode || item.matched_barcode?.barcode || null, barcode_type: item.scan_barcode_type || item.matched_barcode?.barcode_type || null };
                });
            }
            function renderCart() {
                const subtotal = cartSubtotal(); const discount = cartDiscount(); const total = cartTotal();
                $('cartCount').textContent = state.cart.reduce((sum, item) => sum + Number(item.qty), 0).toLocaleString('th-TH', { maximumFractionDigits: 3 }); $('cartTotal').textContent = money(total); $('payButton').disabled = state.cart.length === 0 || !state.shift;
                $('discountLine').classList.toggle('hidden', discount <= 0); $('cartDiscount').textContent = `-${money(discount)}`;
                if (!state.cart.length) invalidateDiscountCard();
                $('cartList').innerHTML = state.cart.length ? state.cart.map((item) => {
                    const controls = item.scan_barcode_type === 'SCALE_WEIGHT'
                        ? `<span class="qty">${formatWeight(item.qty)} กก. (จากฉลาก)</span>`
                        : isScaleProduct(item)
                        ? `<button class="weight-edit" type="button" data-weight-edit="${item.id}">แก้น้ำหนัก ${formatWeight(item.qty)} กก.</button>`
                        : `<button class="qty-btn" type="button" data-minus="${item.id}">−</button><span class="qty">${item.qty}</span><button class="qty-btn" type="button" data-plus="${item.id}">+</button>`;
                    return `<div class="cart-row"><div><div class="name">${escapeHtml(item.name_th)}</div><div class="controls">${controls}<button class="remove" type="button" data-remove="${item.id}">ลบ</button></div></div><div class="line-total">${money(Number(item.qty) * Number(item.pos_price || 0))}</div></div>`;
                }).join('') : '<div class="cart-empty">ยังไม่มีสินค้าในรายการ<br><small>แตะสินค้าด้านซ้ายเพื่อเพิ่มเข้าบิล</small></div>';
                $('cartList').querySelectorAll('[data-minus]').forEach((button) => button.addEventListener('click', () => setQty(Number(button.dataset.minus), -1)));
                $('cartList').querySelectorAll('[data-plus]').forEach((button) => button.addEventListener('click', () => setQty(Number(button.dataset.plus), 1)));
                $('cartList').querySelectorAll('[data-weight-edit]').forEach((button) => button.addEventListener('click', () => {
                    const line = state.cart.find((item) => item.id === Number(button.dataset.weightEdit) && item.scan_barcode_type !== 'SCALE_WEIGHT');
                    if (line) openWeightModal(line, line);
                }));
                $('cartList').querySelectorAll('[data-remove]').forEach((button) => button.addEventListener('click', () => { invalidateDiscountCard(); state.cart = state.cart.filter((item) => item.id !== Number(button.dataset.remove)); renderCart(); }));
            }

            function promptPayTarget(id) {
                const raw = String(id || '').replace(/[^0-9]/g, '');
                if (raw.length === 10 && raw.startsWith('0')) return { tag: '01', value: `0066${raw.substring(1)}` };
                if (raw.length === 13) return { tag: '02', value: raw };
                if (raw.length === 15) return { tag: '03', value: raw };
                throw new Error('PromptPay ID ต้องเป็นเบอร์โทร 10 หลัก เลขบัตร/ภาษี 13 หลัก หรือ e-Wallet 15 หลัก');
            }
            function crc16(data) {
                let crc = 0xFFFF;
                for (let index = 0; index < data.length; index += 1) {
                    crc ^= data.charCodeAt(index) << 8;
                    for (let bit = 0; bit < 8; bit += 1) crc = (crc & 0x8000) ? ((crc << 1) ^ 0x1021) & 0xFFFF : (crc << 1) & 0xFFFF;
                }
                return crc.toString(16).toUpperCase().padStart(4, '0');
            }
            function tlv(tag, value) { return `${tag}${String(new TextEncoder().encode(String(value)).length).padStart(2, '0')}${value}`; }
            function buildPromptPayPayload(id, amount, type = 'dynamic') {
                const target = promptPayTarget(id);
                const merchant = tlv('00', 'A000000677010111') + tlv(target.tag, target.value);
                let payload = tlv('00', '01') + tlv('01', type === 'static' ? '11' : '12') + tlv('29', merchant) + tlv('53', '764');
                if (type !== 'static') payload += tlv('54', Number(amount || 0).toFixed(2));
                payload += tlv('58', 'TH') + tlv('59', 'POPSTAR') + tlv('60', 'UBON') + '6304';
                return payload + crc16(payload);
            }
            function renderPaymentQr(amount) {
                const config = state.config?.qr_payment; const box = $('paymentQr'); const unavailable = $('paymentQrUnavailable');
                box.innerHTML = ''; $('paymentQrAmount').textContent = money(amount); $('paymentQrAccount').textContent = '';
                if (!config?.merchant_ref || typeof window.QRCode === 'undefined') {
                    unavailable.textContent = !config?.merchant_ref ? 'ยังไม่ได้ตั้งค่าบัญชี PromptPay ใน ERP' : 'เบราว์เซอร์ยังโหลดตัวสร้าง QR ไม่สำเร็จ';
                    unavailable.classList.remove('hidden'); return;
                }
                try {
                    new window.QRCode(box, { text: buildPromptPayPayload(config.merchant_ref, amount, config.qr_type), width: 188, height: 188, colorDark: '#000', colorLight: '#fff', correctLevel: window.QRCode.CorrectLevel.H });
                    $('paymentQrAccount').textContent = [config.bank_name, config.account_name || config.name].filter(Boolean).join(' · ');
                    unavailable.classList.add('hidden');
                } catch (exception) {
                    unavailable.textContent = exception.message || 'สร้าง QR ไม่สำเร็จ'; unavailable.classList.remove('hidden');
                }
            }
            function openPayment() { $('paymentTotal').textContent = money(cartTotal()); $('cashReceived').value = cartTotal().toFixed(2); $('transferLast4').value = ''; $('paymentConfirmed').checked = false; error('paymentError', ''); updatePaymentFields(); show('paymentModal'); }
            function updatePaymentFields() { const method = $('paymentMethod').value; $('cashFields').classList.toggle('hidden', method !== 'cash'); $('changeRow').classList.toggle('hidden', method !== 'cash'); $('transferFields').classList.toggle('hidden', method !== 'transfer'); if (method === 'transfer') renderPaymentQr(cartTotal()); updateChange(); }
            function updateChange() { const change = Math.max(0, Number($('cashReceived').value || 0) - cartTotal()); $('changeAmount').textContent = money(change); }
            async function applyDiscountCard() {
                const code = $('discountCardCode').value.trim();
                if (!code || !state.cart.length) return;
                const button = $('applyDiscountCard'); button.disabled = true; const status = $('discountCardStatus'); status.textContent = 'กำลังตรวจสอบบัตร…'; status.classList.remove('hidden', 'error');
                try {
                    const response = await api('/discount-card/check', { method: 'POST', body: JSON.stringify({ card_code: code, subtotal: cartSubtotal() }) });
                    state.discountCard = response; status.textContent = `ใช้ ${response.name || response.card_code} · ลด ${money(response.discount_amount)}`; status.classList.remove('error'); $('discountCardCode').value = '';
                    renderCart(); toast('ใช้บัตรส่วนลดแล้ว');
                } catch (exception) { state.discountCard = null; status.textContent = exception.message; status.classList.remove('hidden'); status.classList.add('error'); }
                finally { button.disabled = false; }
            }
            async function submitPayment() {
                const method = $('paymentMethod').value; const total = cartTotal(); const received = Number($('cashReceived').value || 0); const last4 = $('transferLast4').value.trim();
                if (method === 'cash' && received + .009 < total) return error('paymentError', `รับเงินไม่ครบ ขาดอีก ${money(total - received)}`);
                if (method === 'transfer' && (!/^\d{4}$/.test(last4) || !$('paymentConfirmed').checked)) return error('paymentError', 'กรุณาระบุเลขท้ายบัญชี 4 หลักและยืนยันว่าตรวจเงินเข้าแล้ว');
                const button = $('submitPayment'); button.disabled = true; error('paymentError', '');
                const vatRate = Number(state.config?.vat_rate || 0); const vatAmount = Math.round((total * vatRate / (100 + vatRate)) * 100) / 100;
                const payload = { branch_id: state.config.branch_id, shift_id: state.shift.id, cashier_id: state.cashier.id, method, payment_confirmed: method === 'cash' || $('paymentConfirmed').checked, cash_received: method === 'cash' ? received : null, change_amount: method === 'cash' ? Math.max(0, received - total) : null, transfer_account_last4: method === 'transfer' ? last4 : null, discount_amount: cartDiscount(), manual_discount_amount: 0, discount_card_code: state.discountCard?.card_code || null, items: checkoutItems(), vat_mode: 'included', vat_amount: vatAmount };
                try {
                    const key = `web-${Date.now()}-${window.crypto?.randomUUID ? window.crypto.randomUUID() : Math.random().toString(36).slice(2)}`;
                    const response = await api('/checkout', { method: 'POST', headers: { 'Idempotency-Key': key }, body: JSON.stringify(payload) });
                    state.lastReceipt = { ...response, discount_amount: cartDiscount(), items: state.cart.map((item) => ({ ...item })) }; state.cart = []; state.discountCard = null; hide('paymentModal'); renderCart(); renderReceipt(); show('receiptModal'); await loadProducts(); toast(`ออกใบเสร็จ ${response.receipt_no || response.doc_number || ''} แล้ว`);
                } catch (exception) { error('paymentError', exception.message); } finally { button.disabled = false; }
            }
            function renderReceipt() {
                const receipt = state.lastReceipt || {}; const company = state.config?.company || {}; const paper = localStorage.getItem(PAPER_KEY) || '80mm'; $('receiptPaper').style.width = paper; $('receiptPaper').innerHTML = `<h3>${escapeHtml(company.name || 'PopStar')}</h3><div class="center">${escapeHtml(company.address || '')}</div><div class="center">${escapeHtml(company.phone || '')}</div><hr><div>เลขที่: ${escapeHtml(receipt.receipt_no || receipt.doc_number || '—')}</div><div>ผู้ขาย: ${escapeHtml(state.cashier?.name || '')}</div><div>เวลา: ${new Date().toLocaleString('th-TH')}</div><hr>${(receipt.items || []).map((item) => `<div class="line"><span>${escapeHtml(item.name_th)} x${item.qty}</span><span>${money(Number(item.qty) * Number(item.pos_price || 0))}</span></div>`).join('')}<hr><div class="line"><strong>รวมสุทธิ</strong><strong>${money(receipt.total_amount || 0)}</strong></div><div class="center" style="margin-top:10px">ขอบคุณที่ใช้บริการ</div>`; }

            document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => hide(button.dataset.close)));
            $('connectButton').addEventListener('click', connect); $('tokenInput').addEventListener('keydown', (event) => { if (event.key === 'Enter') connect(); }); $('reloadCashiers').addEventListener('click', loadCashiers); $('settingsShiftButton').addEventListener('click', () => { if (!state.cashier) return toast('กรุณาเลือกคนขายก่อน'); if (state.shift) closeShiftModal(); else openShiftModal(); }); $('reloadProducts').addEventListener('click', () => loadProducts($('productSearch').value)); $('productSearch').addEventListener('input', scheduleProductSearch); $('productSearch').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); scanProduct(); } }); $('weightInput').addEventListener('input', updateWeightTotal); $('weightInput').addEventListener('keydown', (event) => { if (event.key === 'Enter') confirmWeight(); }); $('confirmWeight').addEventListener('click', confirmWeight); $('quantityInput').addEventListener('input', updateQuantityTotal); $('quantityInput').addEventListener('keydown', (event) => { if (event.key === 'Enter') confirmQuantity(); }); $('confirmQuantity').addEventListener('click', confirmQuantity); $('clearCart').addEventListener('click', () => { invalidateDiscountCard(); state.cart = []; renderCart(); }); $('discountCardCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); applyDiscountCard(); } }); $('applyDiscountCard').addEventListener('click', applyDiscountCard); $('payButton').addEventListener('click', openPayment); $('closeShiftButton').addEventListener('click', closeShiftModal); $('shiftSubmitButton').addEventListener('click', submitShift); $('paymentMethod').addEventListener('change', updatePaymentFields); $('cashReceived').addEventListener('input', updateChange); $('submitPayment').addEventListener('click', submitPayment); $('settingsButton').addEventListener('click', () => { $('settingsToken').value = state.token || ''; $('paperWidth').value = localStorage.getItem(PAPER_KEY) || '80mm'; renderShift(); show('settingsModal'); }); $('saveSettingsButton').addEventListener('click', () => { const token = $('settingsToken').value.trim(); if (!token) return toast('กรุณาใส่ Device Token'); localStorage.setItem(PAPER_KEY, $('paperWidth').value); $('tokenInput').value = token; hide('settingsModal'); connect(); }); $('clearTokenButton').addEventListener('click', () => { resetSession(true); hide('settingsModal'); toast('ล้าง Device Token จากเครื่องนี้แล้ว'); }); $('printReceipt').addEventListener('click', () => { document.body.classList.add('printing'); window.print(); setTimeout(() => document.body.classList.remove('printing'), 500); });

            state.token = localStorage.getItem(TOKEN_KEY) || ''; $('tokenInput').value = state.token;
            if (state.token) connect();
        })();
    </script>
</body>
</html>
