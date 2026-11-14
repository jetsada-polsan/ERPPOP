<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Display — MPM Food Group POS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <style>
        :root {
            --brand-primary: #0284c7;
            --brand-dark: #0369a1;
            --bg-page: #0f172a;
            --surface-panel: #1e293b;
            --surface-card: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Sarabun', 'IBM Plex Sans Thai', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            user-select: none;
        }

        /* HEADER */
        .cd-header {
            background: linear-gradient(90deg, #0284c7, #0ea5e9);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 10;
        }
        .cd-logo-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .cd-logo-img {
            max-height: 48px;
            max-width: 140px;
            background: #fff;
            padding: 4px 8px;
            border-radius: 8px;
            object-fit: contain;
        }
        .cd-title {
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            letter-spacing: .5px;
        }
        .cd-clock {
            font-size: 16px;
            font-weight: 600;
            color: #e0f2fe;
            background: rgba(0,0,0,0.2);
            padding: 6px 14px;
            border-radius: 20px;
        }

        /* MAIN DUAL LAYOUT */
        .cd-main {
            flex: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(360px, 1fr);
            gap: 16px;
            padding: 16px 20px;
            overflow: hidden;
        }

        /* LEFT PANEL: ITEMS & LAST SCANNED */
        .cd-left {
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow: hidden;
        }

        /* BANNER: LAST SCANNED ITEM (Convenience store style) */
        .last-scanned-card {
            background: linear-gradient(135deg, #0369a1, #0284c7);
            border: 2px solid #38bdf8;
            border-radius: 14px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 24px rgba(2,132,199,0.3);
            animation: pulse-border 1.5s ease;
        }
        @keyframes pulse-border {
            0% { transform: scale(0.99); }
            50% { transform: scale(1.01); }
            100% { transform: scale(1); }
        }
        .last-item-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .last-tag {
            font-size: 13px;
            font-weight: 700;
            color: #bae6fd;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .last-name {
            font-size: 24px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
        }
        .last-calc {
            font-size: 18px;
            font-weight: 600;
            color: #e0f2fe;
        }
        .last-price-wrap {
            text-align: right;
        }
        .last-line-total {
            font-size: 36px;
            font-weight: 900;
            color: #fef08a;
            font-variant-numeric: tabular-nums;
        }

        /* CART TABLE */
        .cd-table-card {
            flex: 1;
            background: var(--surface-panel);
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .cd-table-head {
            background: rgba(0,0,0,0.25);
            padding: 12px 18px;
            display: grid;
            grid-template-columns: 45px 1fr 100px 110px 120px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-muted);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .cd-table-body {
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .cd-table-row {
            display: grid;
            grid-template-columns: 45px 1fr 100px 110px 120px;
            padding: 10px 18px;
            align-items: center;
            font-size: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            transition: background .15s;
        }
        .cd-table-row:nth-child(even) {
            background: rgba(255,255,255,0.02);
        }
        .cd-table-row.is-latest {
            background: rgba(14,165,233,0.15);
            border-left: 4px solid #38bdf8;
        }
        .col-no { color: var(--text-muted); text-align: center; }
        .col-name { font-weight: 600; color: #fff; }
        .col-name small { display: block; font-size: 12px; color: var(--text-muted); }
        .col-qty { text-align: center; font-weight: 700; color: #38bdf8; font-variant-numeric: tabular-nums; }
        .col-price { text-align: right; color: var(--text-muted); font-variant-numeric: tabular-nums; }
        .col-total { text-align: right; font-weight: 800; color: #fff; font-variant-numeric: tabular-nums; }

        .cart-empty-hero {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            color: var(--text-muted);
        }
        .cart-empty-hero i { font-size: 64px; color: #475569; }
        .cart-empty-hero h2 { font-size: 26px; color: #cbd5e1; font-weight: 700; }

        /* RIGHT PANEL: TOTALS & PAYMENT */
        .cd-right {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* GRAND TOTAL BOX (Prominent) */
        .grand-total-card {
            background: linear-gradient(180deg, #1e293b, #0f172a);
            border: 2px solid #0284c7;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 12px 30px rgba(0,0,0,0.4);
        }
        .grand-label {
            font-size: 18px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 6px;
        }
        .grand-amount {
            font-size: 64px;
            font-weight: 900;
            color: #38bdf8;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            margin-bottom: 12px;
        }
        .grand-sub-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
        }
        .sub-cell {
            display: flex;
            justify-content: space-between;
            color: var(--text-muted);
        }
        .sub-cell strong {
            color: #fff;
            font-variant-numeric: tabular-nums;
        }

        /* PAYMENT CALLOUT CARD */
        .payment-display-card {
            flex: 1;
            background: var(--surface-panel);
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.08);
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .qr-frame {
            background: #fff;
            padding: 14px;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            margin-bottom: 12px;
        }
        .payment-badge {
            font-size: 15px;
            font-weight: 700;
            color: #38bdf8;
            background: rgba(14,165,233,0.15);
            padding: 6px 16px;
            border-radius: 20px;
            margin-bottom: 10px;
        }
        .payment-change-hero {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }
        .change-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: rgba(255,255,255,0.04);
            border-radius: 10px;
            font-size: 18px;
        }
        .change-row.highlight {
            background: rgba(16,185,129,0.15);
            border: 1px solid #10b981;
        }
        .change-row.highlight .amt {
            font-size: 32px;
            font-weight: 900;
            color: #34d399;
        }

        /* FOOTER */
        .cd-footer {
            background: #090d16;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text-muted);
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sync-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--accent-green);
            display: inline-block;
            margin-right: 6px;
            box-shadow: 0 0 8px var(--accent-green);
        }
    </style>
</head>
<body x-data="customerDisplay()" x-init="init()">
    <header class="cd-header">
        <div class="cd-logo-wrap">
            <img class="cd-logo-img" src="{{ asset('images/logos/mpm-food-group.jpg') }}" alt="MPM Food Group">
            <span class="cd-title">{{ $company['name'] ?: 'MPM Food Group' }}</span>
        </div>
        <div class="cd-clock" x-text="currentTime"></div>
    </header>

    <main class="cd-main">
        <!-- LEFT: Items & Last Scanned -->
        <section class="cd-left">
            <!-- Last Scanned Banner -->
            <template x-if="lastItem">
                <div class="last-scanned-card">
                    <div class="last-item-info">
                        <span class="last-tag"><i class="bi bi-upc-scan me-1"></i> สินค้าล่าสุด</span>
                        <div class="last-name" x-text="lastItem.name_th"></div>
                        <div class="last-calc">
                            <span x-text="lastItem.name_th"></span> — 
                            <span x-text="money(lastItem.qty) + ' ' + (lastItem.unit_name || 'ชิ้น')"></span> × 
                            <span x-text="money(lastItem.unit_price)"></span> = 
                            <span x-text="money(lastItem.qty * lastItem.unit_price) + ' ฿'"></span>
                        </div>
                    </div>
                    <div class="last-price-wrap">
                        <div class="last-line-total" x-text="'฿ ' + money(lastItem.qty * lastItem.unit_price)"></div>
                    </div>
                </div>
            </template>

            <!-- Table of Items in Cart -->
            <div class="cd-table-card">
                <template x-if="cart.length === 0">
                    <div class="cart-empty-hero">
                        <i class="bi bi-cart3"></i>
                        <h2>ยินดีต้อนรับ (Welcome)</h2>
                        <p>กรุณาสแกนหรือเลือกสินค้าที่เคาน์เตอร์แคชเชียร์</p>
                    </div>
                </template>

                <template x-if="cart.length > 0">
                    <div style="display:flex;flex-direction:column;height:100%">
                        <div class="cd-table-head">
                            <div class="col-no">#</div>
                            <div>รายการสินค้า</div>
                            <div style="text-align:center">จำนวน</div>
                            <div style="text-align:right">ราคา/หน่วย</div>
                            <div style="text-align:right">ยอดรวม</div>
                        </div>
                        <div class="cd-table-body">
                            <template x-for="(item, idx) in cart" :key="item.uid || item.id || idx">
                                <div class="cd-table-row" :class="{ 'is-latest': lastItem && (item.id === lastItem.id && idx === cart.length - 1) }">
                                    <div class="col-no" x-text="idx + 1"></div>
                                    <div class="col-name">
                                        <span x-text="item.name_th"></span>
                                        <small x-text="item.sku_code || ''"></small>
                                    </div>
                                    <div class="col-qty" x-text="money(item.qty) + ' ' + (item.unit_name || '')"></div>
                                    <div class="col-price" x-text="'฿ ' + money(item.unit_price)"></div>
                                    <div class="col-total" x-text="'฿ ' + money(item.qty * item.unit_price)"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- RIGHT: Grand Total & Payment Callout -->
        <section class="cd-right">
            <!-- Grand Total -->
            <div class="grand-total-card">
                <div class="grand-label">ยอดรวมสุทธิ (Total)</div>
                <div class="grand-amount" x-text="'฿ ' + money(totalAmount)"></div>
                <div class="grand-sub-grid">
                    <div class="sub-cell">
                        <span>จำนวนชิ้น:</span>
                        <strong x-text="money(totalQty) + ' ชิ้น'"></strong>
                    </div>
                    <div class="sub-cell">
                        <span>ส่วนลด:</span>
                        <strong style="color:#f87171" x-text="totalDiscount > 0 ? '-฿ ' + money(totalDiscount) : '0.00'"></strong>
                    </div>
                    <div class="sub-cell">
                        <span>มูลค่าก่อน VAT:</span>
                        <strong x-text="'฿ ' + money(subtotalAmount - vatAmount)"></strong>
                    </div>
                    <div class="sub-cell">
                        <span>VAT 7%:</span>
                        <strong x-text="'฿ ' + money(vatAmount)"></strong>
                    </div>
                </div>
            </div>

            <!-- Payment / QR / Change Display -->
            <div class="payment-display-card">
                <template x-if="mode === 'qr' && qrPayload">
                    <div>
                        <div class="payment-badge"><i class="bi bi-qr-code-scan me-1"></i> ชำระผ่าน QR พร้อมเพย์</div>
                        <div class="qr-frame">
                            <div id="customer-qr-box"></div>
                        </div>
                        <div style="font-size:15px;color:#cbd5e1;font-weight:600">สแกนเพื่อชำระยอด ฿<span x-text="money(totalAmount)"></span></div>
                    </div>
                </template>

                <template x-if="mode === 'cash'">
                    <div class="payment-change-hero">
                        <div class="payment-badge"><i class="bi bi-cash-stack me-1"></i> ชำระด้วยเงินสด</div>
                        <div class="change-row">
                            <span>ยอดที่ต้องชำระ:</span>
                            <strong x-text="'฿ ' + money(totalAmount)"></strong>
                        </div>
                        <div class="change-row">
                            <span>รับเงินสดมา:</span>
                            <strong style="color:#60a5fa" x-text="'฿ ' + money(cashReceived)"></strong>
                        </div>
                        <div class="change-row highlight">
                            <span>เงินทอน (Change):</span>
                            <span class="amt" x-text="'฿ ' + money(changeAmount)"></span>
                        </div>
                    </div>
                </template>

                <template x-if="mode === 'idle' || !mode">
                    <div style="color:var(--text-muted)">
                        <i class="bi bi-shield-check" style="font-size:42px;color:#0ea5e9;margin-bottom:8px;display:block"></i>
                        <div style="font-size:16px;color:#e2e8f0;font-weight:700">ขอบคุณที่เลือกซื้อสินค้ากับเรา</div>
                        <small>MPM Food Group • สะดวก สะอาด ปลอดภัย</small>
                    </div>
                </template>
            </div>
        </section>
    </main>

    <footer class="cd-footer">
        <div>
            <span class="sync-dot"></span>
            <span>เชื่อมต่อกับเคาน์เตอร์แคชเชียร์แบบเรียลไทม์ (Dual Screen Sync Active)</span>
        </div>
        <div>
            <span>เลขประจำตัวผู้เสียภาษี: {{ $company['tax_id'] ?: '-' }}</span>
        </div>
    </footer>

    <script>
        function customerDisplay() {
            return {
                cart: [],
                lastItem: null,
                totalAmount: 0,
                subtotalAmount: 0,
                vatAmount: 0,
                totalQty: 0,
                totalDiscount: 0,
                mode: 'idle',
                qrPayload: null,
                cashReceived: 0,
                changeAmount: 0,
                currentTime: '',
                channel: null,

                init() {
                    this.tickClock();
                    setInterval(() => this.tickClock(), 1000);

                    // Dual screen sync via BroadcastChannel
                    try {
                        this.channel = new BroadcastChannel('pos_customer_display');
                        this.channel.onmessage = (event) => {
                            this.handleMessage(event.data || {});
                        };
                    } catch (e) {
                        console.warn('BroadcastChannel not supported', e);
                    }

                    // Fallback sync via localStorage for cross-window
                    window.addEventListener('storage', (event) => {
                        if (event.key === 'pos_customer_display_state' && event.newValue) {
                            try {
                                const data = JSON.parse(event.newValue);
                                this.handleMessage(data);
                            } catch (e) {}
                        }
                    });

                    // Check initial state from localStorage if available
                    try {
                        const raw = localStorage.getItem('pos_customer_display_state');
                        if (raw) this.handleMessage(JSON.parse(raw));
                    } catch (e) {}
                },

                handleMessage(data) {
                    if (!data) return;
                    this.cart = Array.isArray(data.cart) ? data.cart : [];
                    this.lastItem = data.lastItem || (this.cart.length > 0 ? this.cart[this.cart.length - 1] : null);
                    this.totalAmount = Number(data.totalAmount) || 0;
                    this.subtotalAmount = Number(data.subtotalAmount) || 0;
                    this.vatAmount = Number(data.vatAmount) || 0;
                    this.totalQty = Number(data.totalQty) || 0;
                    this.totalDiscount = Number(data.totalDiscount) || 0;
                    this.mode = data.mode || (data.paymentMethod === 'transfer' ? 'qr' : (data.paymentMethod === 'cash' ? 'cash' : 'idle'));
                    this.cashReceived = Number(data.cashReceived) || 0;
                    this.changeAmount = Number(data.changeAmount) || 0;
                    this.qrPayload = data.qrPayload || null;

                    if (this.mode === 'qr' && this.qrPayload) {
                        this.$nextTick(() => this.renderQr(this.qrPayload));
                    }
                },

                renderQr(payload) {
                    const el = document.getElementById('customer-qr-box');
                    if (!el || typeof QRCode === 'undefined') return;
                    el.innerHTML = '';
                    new QRCode(el, {
                        text: String(payload),
                        width: 170,
                        height: 170,
                        correctLevel: QRCode.CorrectLevel.M,
                    });
                },

                tickClock() {
                    const now = new Date();
                    this.currentTime = now.toLocaleDateString('th-TH', {
                        year: 'numeric', month: 'short', day: 'numeric',
                    }) + ' • ' + now.toLocaleTimeString('th-TH', { hour12: false });
                },

                money(val) {
                    return (Number(val) || 0).toLocaleString('th-TH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },
            };
        }
    </script>
</body>
</html>
