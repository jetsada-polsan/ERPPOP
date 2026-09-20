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
        body.pos-active .page { height: calc(100vh - 56px); padding: 8px 14px; overflow: hidden; }
        button, input, select { font: inherit; }
        button { cursor: pointer; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        .topbar { min-height: 56px; padding: 7px 15px; display: flex; align-items: center; gap: 12px; color: #fff; background: linear-gradient(110deg, var(--navy-dark), var(--navy)); box-shadow: 0 3px 14px rgba(8, 47, 73, .25); }
        .brand { font-size: 18px; font-weight: 900; letter-spacing: -.03em; white-space: nowrap; }
        .brand small { display: inline; margin-left: 7px; color: #bde5f7; font-size: 10px; font-weight: 600; letter-spacing: 0; }
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
        .workspace { display: grid; grid-template-rows: 40px 40px minmax(0, 1fr); gap: 8px; height: 100%; min-height: 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; min-height: 0; }
        .summary { padding: 4px 10px; min-height: 0; display: flex; flex-direction: row; align-items: center; gap: 7px; }
        .summary .label { color: var(--muted); font-size: 9px; font-weight: 700; white-space: nowrap; }
        .summary .value { margin-top: 0; font-size: 12px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .summary.shift-open { border-color: #b5e4ca; background: #f2fff7; }
        .summary.shift-open .value { color: var(--green); }
        .sale-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(380px, .65fr); gap: 10px; align-items: stretch; min-height: 0; height: 100%; }
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
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(142px, 1fr)); gap: 8px; flex: 1; min-height: 0; padding: 10px; overflow: auto; }
        .product { min-height: 102px; padding: 9px; border: 1px solid #cbdde8; border-radius: 8px; color: var(--ink); background: #fff; text-align: left; }
        .product:hover { border-color: var(--blue); box-shadow: 0 4px 12px rgba(21,133,192,.13); }
        .product .name { min-height: 34px; font-size: 12px; font-weight: 800; line-height: 1.4; }
        .product .sku { margin-top: 5px; color: var(--muted); font-size: 11px; }
        .product .price { margin-top: 6px; color: var(--blue); font-size: 16px; font-weight: 900; }
        .product .stock { margin-top: 4px; color: var(--muted); font-size: 11px; }
        .cart { display: flex; flex-direction: column; }
        .cart-list { flex: 1; min-height: 0; overflow: auto; }
        .cart-empty { display: grid; place-items: center; height: 100%; min-height: 0; padding: 20px; color: var(--muted); text-align: center; }
        .cart-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; padding: 9px 12px; border-bottom: 1px solid #edf2f5; }
        .cart-row .name { font-size: 13px; font-weight: 800; line-height: 1.4; }
        .cart-row .line-total { text-align: right; font-weight: 900; }
        .cart-row .controls { display: flex; align-items: center; gap: 5px; margin-top: 6px; }
        .qty-btn { width: 28px; height: 28px; border: 1px solid #bdd0dd; border-radius: 6px; color: var(--navy); background: #fff; }
        .qty { min-width: 30px; text-align: center; font-weight: 800; }
        .remove { border: 0; color: var(--red); background: transparent; font-size: 12px; }
        .cart-footer { flex: 0 0 auto; padding: 10px 12px; border-top: 1px solid var(--line); color: #d9effb; background: var(--navy-dark); }
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
        #sellerPanel { display: flex; align-items: center; min-height: 0; height: 40px; }
        #sellerPanel .section-head { flex: 0 0 auto; padding: 5px 10px; border-bottom: 0; }
        #sellerPanel .section-head h2 { font-size: 14px; }
        #sellerPanel .seller-grid { flex: 1; display: flex; align-items: center; flex-wrap: wrap; gap: 7px; margin: 0; padding: 0 12px 0 0 !important; }
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
        @media (max-width: 900px) { body.pos-active { overflow: auto; } body.pos-active .page { height: auto; overflow: visible; } .workspace { height: auto; grid-template-rows: auto auto auto; } .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .sale-grid { grid-template-columns: 1fr; min-height: 0; height: auto; } .cart-list { max-height: 430px; min-height: 260px; } .product-grid { min-height: 360px; } }
        @media (max-width: 560px) { .page { padding: 10px; } .topbar { padding: 9px 11px; } .top-meta { gap: 5px; font-size: 11px; } .status { max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .connect-panel { margin: 5vh auto; padding: 22px 17px; } .summary-grid { gap: 7px; } .summary { padding: 8px 10px; min-height: 58px; } .summary .value { font-size: 13px; } #sellerPanel { display: block; } #sellerPanel .section-head { padding: 9px 12px 5px; } #sellerPanel .seller-grid { padding: 0 10px 10px !important; } .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 10px; gap: 7px; } .product { min-height: 112px; padding: 9px; } .action-row { grid-template-columns: 1fr; } }
        @media print { @page { size: 80mm auto; margin: 0; } body { background: #fff; } body.printing > *:not(#receiptModal) { display: none !important; } body.printing #receiptModal { position: static; display: block !important; padding: 0; background: #fff; } body.printing #receiptModal .modal { width: auto; max-height: none; padding: 0; box-shadow: none; } body.printing #receiptModal .modal-head, body.printing #receiptModal .modal-actions { display: none; } body.printing .receipt-paper { width: 80mm; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">PopCentral Web POS <small>ขายออนไลน์ผ่านเว็บ</small></div>
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
            <div class="summary-grid">
                <div class="panel summary"><div class="label">สาขา</div><div id="branchValue" class="value">—</div></div>
                <div class="panel summary"><div class="label">เครื่อง</div><div id="deviceValue" class="value">—</div></div>
                <div class="panel summary"><div class="label">คนขาย</div><div id="cashierValue" class="value">ยังไม่เลือก</div></div>
                <div id="shiftSummary" class="panel summary"><div class="label">สถานะกะ</div><div id="shiftValue" class="value">ยังไม่เปิดกะ</div></div>
            </div>

            <section id="sellerPanel" class="panel">
                <div class="section-head"><h2>เลือกชื่อคนขาย</h2><span class="grow"></span><button id="reloadCashiers" class="button light small" type="button">โหลดรายชื่อใหม่</button></div>
                <div id="sellerError" class="error hidden" style="margin:14px"></div>
                <div id="sellerGrid" class="seller-grid" style="padding:0 14px 14px"></div>
            </section>

            <section id="salePanel" class="sale-grid hidden">
                <div class="panel catalog">
                    <div class="section-head"><h2>สินค้า</h2><small>โหลดทีละ 100 รายการ · ค้นหาเพิ่มได้</small><span class="grow"></span><button id="reloadProducts" class="button light small" type="button">รีเฟรช</button></div>
                    <div style="padding:12px 14px 0"><input id="productSearch" class="input" type="search" placeholder="ค้นหาชื่อสินค้า / SKU / บาร์โค้ด"></div>
                    <div id="productGrid" class="product-grid"></div>
                </div>
                <div class="panel cart">
                    <div class="section-head"><h2>รายการขาย</h2><span class="grow"></span><button id="clearCart" class="button light small" type="button">ล้างรายการ</button></div>
                    <div id="cartList" class="cart-list"></div>
                    <div class="cart-footer">
                        <div class="total-line"><span>จำนวนรายการ</span><strong id="cartCount">0</strong></div>
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

    <div id="paymentModal" class="modal-backdrop hidden">
        <div class="modal">
            <div class="modal-head"><h2>รับชำระเงิน</h2><button class="close" type="button" data-close="paymentModal">×</button></div>
            <div class="total-line grand" style="margin-top:0"><span>ยอดที่ต้องชำระ</span><span id="paymentTotal">฿0.00</span></div>
            <div class="field" style="margin-top:14px"><label for="paymentMethod">ช่องทางชำระ</label><select id="paymentMethod" class="select"><option value="cash">เงินสด</option><option value="transfer">โอนเงิน / QR</option></select></div>
            <div id="cashFields" class="field" style="margin-top:12px"><label for="cashReceived">รับเงินมา</label><input id="cashReceived" class="input" type="number" min="0" step="0.01"></div>
            <div id="changeRow" class="total-line" style="margin-top:10px"><span>เงินทอน</span><strong id="changeAmount">฿0.00</strong></div>
            <div id="transferFields" class="hidden">
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

    <script>
        (() => {
            const TOKEN_KEY = 'popstar_web_pos_device_token';
            const PAPER_KEY = 'popstar_web_pos_paper_width';
            const state = { token: '', config: null, cashiers: [], cashier: null, shift: null, products: [], cart: [], lastReceipt: null, shiftAction: 'open', toastTimer: null, productSearchTimer: null, productRequestId: 0 };
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
                state.config = null; state.cashiers = []; state.cashier = null; state.shift = null; state.products = []; state.cart = [];
                if (clearToken) { state.token = ''; localStorage.removeItem(TOKEN_KEY); $('tokenInput').value = ''; }
                document.body.classList.remove('pos-active');
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
                    document.body.classList.add('pos-active'); show('workspace'); hide('connectPanel'); setStatus('เชื่อมต่อแล้ว', true);
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
                    state.cashier = response.cashier || cashier; $('cashierValue').textContent = state.cashier.name || cashier.name || cashier.code; setStatus('เลือกคนขายแล้ว', true);
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
                const open = Boolean(state.shift); $('shiftSummary').classList.toggle('shift-open', open); $('shiftValue').textContent = open ? `เปิดอยู่ ${state.shift.shift_no || ''}`.trim() : 'ยังไม่เปิดกะ';
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
                $('productGrid').querySelectorAll('[data-product-id]').forEach((button) => button.addEventListener('click', () => addToCart(Number(button.dataset.productId))));
            }
            function scheduleProductSearch() {
                clearTimeout(state.productSearchTimer);
                state.productSearchTimer = setTimeout(() => loadProducts($('productSearch').value), 220);
            }
            function addToCart(id) { const product = state.products.find((item) => Number(item.id) === id); if (!product) return; const line = state.cart.find((item) => item.id === id); if (line) line.qty = Number(line.qty) + 1; else state.cart.push({ ...product, qty: 1 }); renderCart(); }
            function setQty(id, delta) { const line = state.cart.find((item) => item.id === id); if (!line) return; line.qty = Math.max(0, Number(line.qty) + delta); state.cart = state.cart.filter((item) => item.qty > 0); renderCart(); }
            function cartTotal() { return state.cart.reduce((sum, item) => sum + Number(item.qty) * Number(item.pos_price || 0), 0); }
            function renderCart() {
                const total = cartTotal(); $('cartCount').textContent = state.cart.reduce((sum, item) => sum + Number(item.qty), 0).toLocaleString('th-TH'); $('cartTotal').textContent = money(total); $('payButton').disabled = state.cart.length === 0 || !state.shift;
                $('cartList').innerHTML = state.cart.length ? state.cart.map((item) => `<div class="cart-row"><div><div class="name">${escapeHtml(item.name_th)}</div><div class="controls"><button class="qty-btn" type="button" data-minus="${item.id}">−</button><span class="qty">${item.qty}</span><button class="qty-btn" type="button" data-plus="${item.id}">+</button><button class="remove" type="button" data-remove="${item.id}">ลบ</button></div></div><div class="line-total">${money(Number(item.qty) * Number(item.pos_price || 0))}</div></div>`).join('') : '<div class="cart-empty">ยังไม่มีสินค้าในรายการ<br><small>แตะสินค้าด้านซ้ายเพื่อเพิ่มเข้าบิล</small></div>';
                $('cartList').querySelectorAll('[data-minus]').forEach((button) => button.addEventListener('click', () => setQty(Number(button.dataset.minus), -1)));
                $('cartList').querySelectorAll('[data-plus]').forEach((button) => button.addEventListener('click', () => setQty(Number(button.dataset.plus), 1)));
                $('cartList').querySelectorAll('[data-remove]').forEach((button) => button.addEventListener('click', () => { state.cart = state.cart.filter((item) => item.id !== Number(button.dataset.remove)); renderCart(); }));
            }

            function openPayment() { $('paymentTotal').textContent = money(cartTotal()); $('cashReceived').value = cartTotal().toFixed(2); $('transferLast4').value = ''; $('paymentConfirmed').checked = false; error('paymentError', ''); updatePaymentFields(); show('paymentModal'); }
            function updatePaymentFields() { const method = $('paymentMethod').value; $('cashFields').classList.toggle('hidden', method !== 'cash'); $('changeRow').classList.toggle('hidden', method !== 'cash'); $('transferFields').classList.toggle('hidden', method !== 'transfer'); updateChange(); }
            function updateChange() { const change = Math.max(0, Number($('cashReceived').value || 0) - cartTotal()); $('changeAmount').textContent = money(change); }
            async function submitPayment() {
                const method = $('paymentMethod').value; const total = cartTotal(); const received = Number($('cashReceived').value || 0); const last4 = $('transferLast4').value.trim();
                if (method === 'cash' && received + .009 < total) return error('paymentError', `รับเงินไม่ครบ ขาดอีก ${money(total - received)}`);
                if (method === 'transfer' && (!/^\d{4}$/.test(last4) || !$('paymentConfirmed').checked)) return error('paymentError', 'กรุณาระบุเลขท้ายบัญชี 4 หลักและยืนยันว่าตรวจเงินเข้าแล้ว');
                const button = $('submitPayment'); button.disabled = true; error('paymentError', '');
                const vatRate = Number(state.config?.vat_rate || 0); const vatAmount = Math.round((total * vatRate / (100 + vatRate)) * 100) / 100;
                const payload = { branch_id: state.config.branch_id, shift_id: state.shift.id, cashier_id: state.cashier.id, method, payment_confirmed: method === 'cash' || $('paymentConfirmed').checked, cash_received: method === 'cash' ? received : null, change_amount: method === 'cash' ? Math.max(0, received - total) : null, transfer_account_last4: method === 'transfer' ? last4 : null, items: state.cart.map((item) => ({ product_id: item.id, qty: Number(item.qty), unit_price: Number(item.pos_price), barcode: item.matched_barcode?.barcode || null, barcode_type: item.matched_barcode?.barcode_type || null })), vat_mode: 'included', vat_amount: vatAmount };
                try {
                    const key = `web-${Date.now()}-${window.crypto?.randomUUID ? window.crypto.randomUUID() : Math.random().toString(36).slice(2)}`;
                    const response = await api('/checkout', { method: 'POST', headers: { 'Idempotency-Key': key }, body: JSON.stringify(payload) });
                    state.lastReceipt = { ...response, items: state.cart.map((item) => ({ ...item })) }; state.cart = []; hide('paymentModal'); renderCart(); renderReceipt(); show('receiptModal'); await loadProducts(); toast(`ออกใบเสร็จ ${response.receipt_no || response.doc_number || ''} แล้ว`);
                } catch (exception) { error('paymentError', exception.message); } finally { button.disabled = false; }
            }
            function renderReceipt() {
                const receipt = state.lastReceipt || {}; const company = state.config?.company || {}; const paper = localStorage.getItem(PAPER_KEY) || '80mm'; $('receiptPaper').style.width = paper; $('receiptPaper').innerHTML = `<h3>${escapeHtml(company.name || 'PopStar')}</h3><div class="center">${escapeHtml(company.address || '')}</div><div class="center">${escapeHtml(company.phone || '')}</div><hr><div>เลขที่: ${escapeHtml(receipt.receipt_no || receipt.doc_number || '—')}</div><div>ผู้ขาย: ${escapeHtml(state.cashier?.name || '')}</div><div>เวลา: ${new Date().toLocaleString('th-TH')}</div><hr>${(receipt.items || []).map((item) => `<div class="line"><span>${escapeHtml(item.name_th)} x${item.qty}</span><span>${money(Number(item.qty) * Number(item.pos_price || 0))}</span></div>`).join('')}<hr><div class="line"><strong>รวมสุทธิ</strong><strong>${money(receipt.total_amount || 0)}</strong></div><div class="center" style="margin-top:10px">ขอบคุณที่ใช้บริการ</div>`; }

            document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => hide(button.dataset.close)));
            $('connectButton').addEventListener('click', connect); $('tokenInput').addEventListener('keydown', (event) => { if (event.key === 'Enter') connect(); }); $('reloadCashiers').addEventListener('click', loadCashiers); $('reloadProducts').addEventListener('click', () => loadProducts($('productSearch').value)); $('productSearch').addEventListener('input', scheduleProductSearch); $('clearCart').addEventListener('click', () => { state.cart = []; renderCart(); }); $('payButton').addEventListener('click', openPayment); $('closeShiftButton').addEventListener('click', closeShiftModal); $('shiftSubmitButton').addEventListener('click', submitShift); $('paymentMethod').addEventListener('change', updatePaymentFields); $('cashReceived').addEventListener('input', updateChange); $('submitPayment').addEventListener('click', submitPayment); $('settingsButton').addEventListener('click', () => { $('settingsToken').value = state.token || ''; $('paperWidth').value = localStorage.getItem(PAPER_KEY) || '80mm'; show('settingsModal'); }); $('saveSettingsButton').addEventListener('click', () => { const token = $('settingsToken').value.trim(); if (!token) return toast('กรุณาใส่ Device Token'); localStorage.setItem(PAPER_KEY, $('paperWidth').value); $('tokenInput').value = token; hide('settingsModal'); connect(); }); $('clearTokenButton').addEventListener('click', () => { resetSession(true); hide('settingsModal'); toast('ล้าง Device Token จากเครื่องนี้แล้ว'); }); $('printReceipt').addEventListener('click', () => { document.body.classList.add('printing'); window.print(); setTimeout(() => document.body.classList.remove('printing'), 500); });

            state.token = localStorage.getItem(TOKEN_KEY) || ''; $('tokenInput').value = state.token;
            if (state.token) connect();
        })();
    </script>
</body>
</html>
