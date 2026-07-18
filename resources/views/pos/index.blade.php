<!DOCTYPE html>
<html lang="th" data-theme="navy">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f1c2e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="JET POS">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>JET POS — {{ \App\Models\AppSetting::company('name_th') }}</title>
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('pos-icon.svg') }}">
    <link rel="icon" href="{{ asset('images/logo-jet-erp-mark.svg') }}?v={{ filemtime(public_path('images/logo-jet-erp-mark.svg')) }}">
    <link rel="apple-touch-icon" href="{{ asset('images/logo-jet-erp-mark.svg') }}?v={{ filemtime(public_path('images/logo-jet-erp-mark.svg')) }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script defer src="{{ asset('vendor/alpinejs/alpine.min.js') }}"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --pos-bg: #07111f;
            --pos-panel: #111c2e;
            --pos-panel-2: #18263b;
            --pos-card: #203149;
            --pos-card-2: #263b57;
            --pos-border: rgba(148,163,184,.18);
            --pos-text: #f1f5f9;
            --pos-muted: #a7b4c8;
            --pos-green: #10b981;
            --pos-blue: #0ea5e9;
            --pos-red: #ef4444;
            --pos-amber: #f59e0b;
            --pos-cyan: #22d3ee;
        }

        html, body { height: 100%; overflow: hidden; }

        body {
            font-family: 'Segoe UI', 'Tahoma', sans-serif;
            background:
                radial-gradient(circle at 72% 10%, rgba(14,165,233,.16), transparent 28%),
                linear-gradient(135deg, #07111f 0%, #0f1c2e 48%, #07111f 100%);
            color: var(--pos-text);
            font-size: 14px;
        }

        /* ── Layout ──────────────────────────────────── */
        .pos-wrap {
            display: grid;
            grid-template-rows: 48px 1fr 54px;
            height: 100vh;
        }

        .pos-topbar {
            background: rgba(17,28,46,.94);
            border-bottom: 1px solid var(--pos-border);
            display: flex; align-items: center;
            padding: 0 10px; gap: 8px;
            box-shadow: 0 10px 30px rgba(2,8,23,.28);
        }

        .pos-logo {
            font-size: 22px; font-weight: 900;
            letter-spacing: -0.5px; color: #f1f5f9;
            min-width: 124px;
        }
        .pos-logo span {
            background: linear-gradient(135deg,#10b981,#34d399);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }

        .pos-body {
            display: grid;
            grid-template-columns: minmax(610px, 44vw) 1fr;
            overflow: hidden;
            gap: 6px;
            padding: 6px;
        }

        /* ── Left: Cart ─────────────────────────────── */
        .pos-cart {
            background: rgba(17,28,46,.96);
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            display: flex; flex-direction: column;
            overflow: hidden;
            box-shadow: 0 18px 44px rgba(2,8,23,.30);
        }

        .pos-cart-header {
            padding: 7px 9px 6px;
            border-bottom: 1px solid var(--pos-border);
        }

        .pos-customer-field {
            display: flex; align-items: center; gap: 8px;
            background: var(--pos-card);
            border: 1px solid var(--pos-border);
            border-radius: 8px; padding: 6px 9px;
            cursor: pointer; position: relative;
        }
        .pos-customer-field input {
            background: transparent; border: none; outline: none;
            color: var(--pos-text); font-size: 13px; flex: 1;
            font-family: inherit;
        }
        .pos-customer-field input::placeholder { color: var(--pos-muted); }

        .pos-cart-items {
            flex: 1; overflow-y: auto; padding: 0;
            scrollbar-width: thin; scrollbar-color: #334155 transparent;
        }

        .cart-empty {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            height: 100%; color: var(--pos-muted); gap: 10px;
        }
        .cart-empty i { font-size: 46px; color: #334155; }

        .cart-list-head,
        .cart-item {
            display: grid;
            grid-template-columns: 26px minmax(0, 1fr) 82px 94px 30px;
            align-items: center;
            gap: 6px;
        }
        .cart-list-head {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 6px 10px;
            background: linear-gradient(180deg, #0d1b2f, #0a1424);
            border-bottom: 1px solid var(--pos-border);
            color: #93c5fd;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .text-end { text-align: right; }
        .cart-item {
            min-height: 54px;
            padding: 7px 10px;
            border-bottom: 1px solid var(--pos-border);
            background: rgba(15,23,42,.44);
            transition: background .1s, box-shadow .1s, border-color .1s;
        }
        .cart-item:nth-child(even) { background: rgba(30,41,59,.42); }
        .cart-item:hover { background: rgba(34,211,238,.07); }
        .cart-item.active {
            background: rgba(34,211,238,.13);
            box-shadow: inset 4px 0 0 var(--pos-cyan), 0 8px 20px rgba(2,8,23,.18);
        }
        .cart-line-no {
            width: 23px;
            height: 23px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: rgba(148,163,184,.16);
            color: #e2e8f0;
            font-size: 11px;
            font-weight: 900;
        }
        .cart-product-cell { min-width: 0; }
        .cart-item-name {
            font-size: 13px; font-weight: 800; color: var(--pos-text);
            white-space: normal;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.4; /* Thai vowel/tone marks need >=1.4 or they clip */
        }
        .cart-item-sku {
            font-size: 11px;
            color: #93c5fd;
            font-weight: 800;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cart-qty-cell {
            display: grid;
            grid-template-columns: 23px minmax(34px, 1fr) 23px;
            align-items: center;
            gap: 4px;
        }
        .cart-item-price {
            font-size: 17px;
            font-weight: 900;
            color: var(--pos-green);
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .qty-btn {
            width: 23px; height: 26px; border-radius: 8px;
            border: 1px solid var(--pos-border);
            background: var(--pos-card); color: var(--pos-text);
            display: grid; place-items: center; cursor: pointer; font-size: 14px;
            transition: background .1s;
        }
        .qty-btn:hover { background: rgba(255,255,255,.12); }
        .qty-display {
            min-width: 32px; text-align: center; font-weight: 700; font-size: 14px;
        }
        .qty-input {
            width: 100%; text-align: center; background: var(--pos-card);
            border: 1px solid var(--pos-green); border-radius: 6px;
            color: var(--pos-text); font-size: 13px; padding: 4px 3px;
            outline: none; font-family: inherit; font-weight: 700;
        }
        .price-input {
            width: 70px; text-align: right; background: var(--pos-card);
            border: 1px solid var(--pos-border); border-radius: 6px;
            color: var(--pos-text); font-size: 12px; padding: 4px 6px;
            outline: none; font-family: inherit;
        }
        .price-input:focus { border-color: var(--pos-green); }
        .cart-line-tools {
            grid-column: 2 / -2;
            display: flex;
            align-items: center;
            gap: 6px;
            padding-top: 4px;
        }
        .cart-line-tools .tool-label {
            color: var(--pos-muted);
            font-size: 10px;
            font-weight: 900;
        }
        .discount-cell {
            display: grid;
            grid-template-columns: minmax(34px, 1fr) 30px;
            gap: 3px;
            align-items: center;
        }
        .discount-input,
        .discount-type {
            height: 26px;
            background: var(--pos-card);
            border: 1px solid var(--pos-border);
            border-radius: 6px;
            color: var(--pos-text);
            font-size: 11px;
            outline: none;
            font-family: inherit;
        }
        .discount-input { width: 100%; min-width: 0; text-align: right; padding: 4px 5px; }
        .discount-type { padding: 0 2px; font-weight: 900; color: #fde68a; }
        .discount-input:focus,
        .discount-type:focus { border-color: #f59e0b; }

        .trash-btn {
            color: var(--pos-muted); background: transparent; border: none;
            cursor: pointer; font-size: 14px; padding: 5px 6px;
            border-radius: 5px; transition: color .1s, background .1s;
        }
        .trash-btn:hover { color: var(--pos-red); background: rgba(239,68,68,.1); }

        /* ── Cart footer ─────────────────────────────── */
        .pos-cart-footer {
            border-top: 1px solid var(--pos-border);
            padding: 6px 10px 8px;
            background: rgba(7,17,31,.42);
        }
        .bill-tools {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 6px;
            align-items: end;
            margin-bottom: 5px;
        }
        .discount-card-row { margin-bottom: 6px; }
        .discount-card-input {
            display: flex; align-items: center; gap: 6px;
            background: var(--pos-card);
            border: 1px solid var(--pos-border);
            border-radius: 8px; padding: 5px 9px;
            color: var(--pos-muted); font-size: 12px;
        }
        .discount-card-input input {
            background: transparent; border: none; outline: none;
            color: var(--pos-text); font-size: 12px; flex: 1; font-family: inherit;
        }
        .discount-card-input input::placeholder { color: var(--pos-muted); }
        .discount-card-input button {
            background: rgba(34,211,238,.14); border: 1px solid rgba(34,211,238,.3);
            color: #67e8f9; border-radius: 6px; padding: 4px 9px; font-size: 11px;
            font-weight: 900; cursor: pointer; font-family: inherit;
        }
        .discount-card-input button:disabled { opacity: .4; cursor: not-allowed; }
        .discount-card-applied {
            display: flex; align-items: center; gap: 7px;
            background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3);
            border-radius: 8px; padding: 6px 10px;
            color: #6ee7b7; font-size: 12px; font-weight: 700;
        }
        .discount-card-error { color: #fca5a5; font-size: 11px; margin-top: 4px; }
        .bill-tools label {
            display: block;
            color: var(--pos-muted);
            font-size: 10px;
            font-weight: 900;
            margin-bottom: 3px;
        }
        .vat-toggle {
            display: inline-grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid var(--pos-border);
            border-radius: 9px;
            overflow: hidden;
            height: 28px;
            background: rgba(15,23,42,.7);
        }
        .vat-toggle button {
            border: 0;
            padding: 0 8px;
            background: transparent;
            color: var(--pos-muted);
            font-size: 10px;
            font-weight: 900;
            font-family: inherit;
            cursor: pointer;
        }
        .vat-toggle button.active {
            background: rgba(34,211,238,.16);
            color: #67e8f9;
        }
        .total-row.muted span { color: #94a3b8; font-size: 11px; }
        .total-row.discount span:last-child { color: #fbbf24; }

        .cart-totals {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 16px;
            margin-bottom: 0;
        }
        .total-row {
            display: flex; justify-content: space-between;
            align-items: center; padding: 1px 0;
            font-size: 12px; color: var(--pos-muted);
        }
        .total-row.grand {
            grid-column: 1 / -1;
            font-size: 24px; font-weight: 900; color: var(--pos-text);
            padding-top: 6px; border-top: 1px solid var(--pos-border);
            margin-top: 5px;
        }
        .total-row.grand .val { color: var(--pos-green); }

        .pay-btn {
            width: 100%; padding: 17px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none; border-radius: 14px; color: #fff;
            font-size: 20px; font-weight: 900; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: opacity .15s, transform .1s;
            box-shadow: 0 4px 16px rgba(16,185,129,.35);
            font-family: inherit;
        }
        .pay-btn:hover { opacity: .92; transform: translateY(-1px); }
        .pay-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; }
        .pos-cart-footer .quick-pay-row,
        .pos-cart-footer .pay-btn { display: none; }
        .quick-pay-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }
        .quick-pay {
            min-height: 48px;
            border: 1px solid var(--pos-border);
            border-radius: 12px;
            background: var(--pos-card);
            color: var(--pos-text);
            font-family: inherit;
            font-size: 14px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .quick-pay:hover { border-color: var(--pos-cyan); background: var(--pos-card-2); }
        .quick-pay.cash { color: #86efac; }
        .quick-pay.qr { color: #67e8f9; }
        .quick-pay:disabled { opacity: .42; cursor: not-allowed; }

        /* ── Right: Products ─────────────────────────── */
        .pos-products {
            display: flex; flex-direction: column; overflow: hidden;
            background: rgba(7,17,31,.55);
            border: 1px solid var(--pos-border);
            border-radius: 14px;
            box-shadow: 0 18px 44px rgba(2,8,23,.22);
        }

        .pos-search-bar {
            padding: 8px 10px; background: rgba(17,28,46,.94);
            border-bottom: 1px solid var(--pos-border);
            display: flex; gap: 10px; align-items: center;
        }

        .pos-search-input {
            flex: 1; background: #f8fafc;
            border: 2px solid transparent; border-radius: 10px;
            color: #0f172a; font-size: 15px; padding: 7px 12px 7px 36px;
            outline: none; font-family: inherit;
            position: relative;
            transition: border-color .15s;
        }
        .pos-search-input:focus { border-color: var(--pos-cyan); box-shadow: 0 0 0 4px rgba(34,211,238,.16); }
        .search-wrap { position: relative; flex: 1; }
        .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--pos-muted); }

        .pos-categories {
            display: flex; gap: 6px; overflow-x: auto;
            padding: 7px 10px; background: rgba(17,28,46,.94);
            border-bottom: 1px solid var(--pos-border);
            scrollbar-width: none;
        }
        .pos-categories::-webkit-scrollbar { display: none; }

        .cat-pill {
            white-space: nowrap; padding: 6px 10px;
            border-radius: 8px; border: 1.5px solid var(--pos-border);
            background: var(--pos-card); color: #dbeafe; font-size: 12px;
            font-weight: 800; cursor: pointer; transition: all .15s;
            font-family: inherit;
        }
        .cat-pill:hover { background: var(--pos-card-2); color: var(--pos-text); }
        .cat-pill.active { background: linear-gradient(135deg,#0ea5e9,#06b6d4); border-color: #22d3ee; color: #fff; }
        .cat-pill:nth-child(6n+1) { --cat-a:#0ea5e9; --cat-b:#06b6d4; }
        .cat-pill:nth-child(6n+2) { --cat-a:#10b981; --cat-b:#34d399; }
        .cat-pill:nth-child(6n+3) { --cat-a:#f97316; --cat-b:#fb923c; }
        .cat-pill:nth-child(6n+4) { --cat-a:#8b5cf6; --cat-b:#a78bfa; }
        .cat-pill:nth-child(6n+5) { --cat-a:#ef4444; --cat-b:#f87171; }
        .cat-pill:nth-child(6n) { --cat-a:#eab308; --cat-b:#facc15; }
        .cat-pill.active { background: linear-gradient(135deg,var(--cat-a),var(--cat-b)); border-color: var(--cat-b); color: #fff; }

        .product-grid {
            flex: 1; overflow-y: auto; padding: 8px 10px;
            display: grid; grid-template-columns: repeat(auto-fill, minmax(156px, 1fr)); gap: 8px;
            align-content: start;
            scrollbar-width: thin; scrollbar-color: #334155 transparent;
        }

        .product-card {
            position: relative;
            min-height: 108px;
            background: linear-gradient(180deg, rgba(32,49,73,.98), rgba(24,38,59,.98));
            border: 1.5px solid rgba(148,163,184,.18);
            border-radius: 10px; padding: 8px 10px 8px;
            cursor: pointer; display: flex; flex-direction: column; gap: 3px;
            transition: all .15s; user-select: none;
            box-shadow: 0 10px 24px rgba(2,8,23,.20);
            overflow: hidden;
        }
        .product-card:hover {
            border-color: var(--pos-cyan); background: linear-gradient(180deg, rgba(14,165,233,.20), rgba(16,185,129,.10));
            transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.3);
        }
        .product-card:active { transform: scale(.97); }

        .product-sku {
            font-size: 10.5px; color: #93c5fd; font-weight: 800;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            flex-shrink: 0;
        }
        .stock-badge {
            position: absolute; top: 6px; right: 6px;
            background: rgba(16,185,129,.16); color: #34d399;
            border: 1px solid rgba(16,185,129,.35);
            border-radius: 6px; padding: 1px 6px;
            font-size: 9.5px; font-weight: 800; white-space: nowrap;
        }
        .stock-badge.low { background: rgba(245,158,11,.16); color: #fbbf24; border-color: rgba(245,158,11,.4); }
        .stock-badge.out { background: rgba(239,68,68,.16); color: #f87171; border-color: rgba(239,68,68,.4); }
        .product-name {
            font-size: 12.5px; font-weight: 700; color: var(--pos-text);
            line-height: 1.45; /* Thai vowel/tone marks need >=1.4 or they clip */
            min-height: 36px;
            overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
            word-break: break-word;
        }
        .product-price {
            font-size: 17px; font-weight: 900; color: var(--pos-green);
            margin-top: auto; padding-top: 2px;
            white-space: nowrap; flex-shrink: 0;
            font-variant-numeric: tabular-nums;
        }
        .product-card.flash-sale {
            border-color: var(--pos-amber);
            background: linear-gradient(180deg, rgba(245,158,11,.20), rgba(24,38,59,.98));
        }
        .flash-badge,
        .promo-badge {
            align-self: flex-start;
            width: fit-content; max-width: 100%;
            color: #fff; font-size: 9.5px; font-weight: 900; line-height: 1.5;
            padding: 1px 8px; border-radius: 999px;
            display: inline-flex; align-items: center; gap: 3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            flex-shrink: 0; margin-bottom: 1px;
        }
        .flash-badge {
            background: linear-gradient(135deg,#f59e0b,#f97316);
            box-shadow: 0 4px 10px rgba(245,158,11,.4);
        }
        .promo-badge {
            background: linear-gradient(135deg,#10b981,#059669);
            box-shadow: 0 4px 10px rgba(16,185,129,.4);
        }
        .product-price-orig {
            font-size: 11px; color: var(--pos-muted);
            text-decoration: line-through; margin-top: 2px;
        }
        .cart-item.gift-line {
            background: rgba(16,185,129,.08);
            box-shadow: inset 4px 0 0 var(--pos-green);
        }
        .cart-item.gift-line .cart-item-price { color: #6ee7b7; }

        .pos-actionbar {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr 1.35fr;
            gap: 6px;
            padding: 6px;
            background: rgba(17,28,46,.96);
            border-top: 1px solid var(--pos-border);
            box-shadow: 0 -16px 38px rgba(2,8,23,.32);
        }
        .action-btn {
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            color: #fff;
            min-height: 42px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 10px 22px rgba(2,8,23,.22);
        }
        .action-btn i { font-size: 17px; }
        .action-btn.hold { background: linear-gradient(135deg,#475569,#334155); }
        .action-btn.clear { background: linear-gradient(135deg,#dc2626,#991b1b); }
        .action-btn.qr { background: linear-gradient(135deg,#0ea5e9,#0891b2); }
        .action-btn.edit { background: linear-gradient(135deg,#f59e0b,#d97706); }
        .action-btn.pay { background: linear-gradient(135deg,#10b981,#047857); font-size: 16px; }
        .action-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .action-btn:disabled { opacity: .42; cursor: not-allowed; transform: none; filter: none; }

        .product-loading {
            grid-column: 1 / -1; text-align: center; padding: 40px 0;
            color: var(--pos-muted);
        }

        /* ── Payment modal ───────────────────────────── */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(5,10,20,.7); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-box {
            background: var(--pos-panel); border: 1px solid var(--pos-border);
            border-radius: 18px; padding: 0; width: min(920px, calc(100vw - 32px));
            max-height: calc(100vh - 32px); overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,.6);
        }
        .modal-head {
            padding: 18px 20px;
            border-bottom: 1px solid var(--pos-border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .modal-title { font-size: 20px; font-weight: 800; }
        .modal-close {
            width: 36px; height: 36px; border-radius: 10px;
            border: 1px solid var(--pos-border); background: var(--pos-card);
            color: var(--pos-muted); cursor: pointer;
        }
        .modal-close:hover { color: var(--pos-text); background: rgba(255,255,255,.08); }
        .payment-layout {
            display: grid; grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
            min-height: 460px; max-height: calc(100vh - 106px);
        }
        .payment-side {
            padding: 18px 20px; border-right: 1px solid var(--pos-border);
            background: rgba(15,23,42,.35);
            overflow: auto;
        }
        .payment-main {
            padding: 16px 18px; overflow: auto;
            display: flex; flex-direction: column;
        }

        .method-tabs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 20px; }
        .method-tab {
            padding: 12px 8px; border-radius: 10px; border: 2px solid var(--pos-border);
            background: var(--pos-card); color: var(--pos-muted); text-align: center;
            cursor: pointer; font-size: 13px; font-weight: 600; transition: all .15s;
            font-family: inherit;
        }
        .method-tab:hover { border-color: rgba(255,255,255,.2); color: var(--pos-text); }
        .method-tab.active { border-color: var(--pos-green); background: rgba(16,185,129,.15); color: var(--pos-green); }
        .method-tab i { display: block; font-size: 22px; margin-bottom: 4px; }

        .pay-summary {
            background: var(--pos-card); border-radius: 12px; padding: 16px;
            margin-bottom: 16px;
        }
        .pay-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .pay-total { font-size: 28px; font-weight: 900; color: var(--pos-green); margin-top: 10px; }

        .shift-modal-body {
            display: grid;
            gap: 12px;
            padding: 14px 16px 16px;
            max-height: calc(100vh - 150px);
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .shift-modal-body .pay-summary {
            padding: 12px 14px;
            margin-bottom: 0;
        }
        .shift-modal-body .pay-row {
            margin-bottom: 5px;
            font-size: 13px;
        }
        .shift-modal-body .pay-total {
            font-size: 22px;
            line-height: 1.15;
        }
        .shift-modal-body .change-display {
            padding: 10px 12px;
        }
        .shift-modal-body .modal-actions {
            position: sticky;
            bottom: -16px;
            background: linear-gradient(180deg, rgba(17,28,46,.82), var(--pos-panel));
            padding-top: 10px;
            padding-bottom: 2px;
            margin-top: 0;
        }

        .amount-input-group { margin-bottom: 14px; }
        .amount-label { font-size: 12px; color: var(--pos-muted); font-weight: 600; margin-bottom: 6px; }
        .amount-input {
            width: 100%; background: var(--pos-card);
            border: 2px solid var(--pos-green); border-radius: 10px;
            color: var(--pos-text); font-size: 24px; font-weight: 800;
            padding: 12px 16px; outline: none; font-family: inherit; text-align: right;
        }
        .cash-screen {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 12px;
        }
        .cash-screen-card {
            border: 1px solid var(--pos-border);
            border-radius: 12px;
            background: rgba(15,23,42,.7);
            padding: 12px 14px;
        }
        .cash-screen-card .label {
            color: var(--pos-muted);
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 4px;
        }
        .cash-screen-card .amount {
            font-size: 30px;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
            color: #f8fafc;
            line-height: 1;
        }
        .cash-screen-card.change .amount { color: var(--pos-green); }
        .cash-screen-card.due .amount { color: #fbbf24; }
        .cash-keypad {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .keypad-btn {
            border: 1px solid var(--pos-border);
            border-radius: 12px;
            min-height: 54px;
            background: var(--pos-card);
            color: var(--pos-text);
            font-size: 22px;
            font-weight: 900;
            font-family: inherit;
            cursor: pointer;
        }
        .keypad-btn:hover { border-color: var(--pos-cyan); background: var(--pos-card-2); }
        .keypad-btn.function {
            font-size: 15px;
            color: #dbeafe;
            background: rgba(37,99,235,.22);
        }
        .keypad-btn.danger {
            font-size: 15px;
            color: #fecaca;
            background: rgba(220,38,38,.24);
        }
        .keypad-btn.exact {
            font-size: 15px;
            color: #bbf7d0;
            background: rgba(16,185,129,.20);
        }
        .cash-quick-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin: -4px 0 14px;
        }
        .cash-quick {
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            background: var(--pos-card);
            color: #dbeafe;
            min-height: 42px;
            font-family: inherit;
            font-weight: 900;
            cursor: pointer;
        }
        .cash-quick:hover { border-color: var(--pos-green); color: var(--pos-green); }
        .change-display {
            background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3);
            border-radius: 10px; padding: 12px 16px; margin-bottom: 18px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .change-display.short {
            background: rgba(245,158,11,.12);
            border-color: rgba(245,158,11,.34);
        }
        .change-display .label { font-size: 13px; color: var(--pos-muted); }
        .change-display .value { font-size: 22px; font-weight: 800; color: var(--pos-green); }
        .payment-check-card {
            background: rgba(15,23,42,.75);
            border: 1px solid var(--pos-border);
            border-radius: 12px;
            padding: 10px;
            margin: 10px auto 0;
            width: min(100%, 360px);
        }
        .check-status {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            color: var(--pos-muted); font-size: 13px; font-weight: 700;
            margin-bottom: 8px;
        }
        .check-status.done { color: var(--pos-green); }
        .check-status i { font-size: 18px; }
        .check-paid {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            background: #2563eb;
            color: #fff;
            font-weight: 900;
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 0 12px 26px rgba(37,99,235,.28);
        }
        .check-paid.done {
            background: var(--pos-green);
            box-shadow: 0 12px 26px rgba(16,185,129,.28);
        }
        .ref-input {
            width: 100%;
            margin-top: 8px;
            background: var(--pos-card);
            border: 1px solid var(--pos-border);
            border-radius: 10px;
            color: var(--pos-text);
            padding: 9px 10px;
            outline: none;
            font-family: inherit;
            font-size: 14px;
        }
        .ref-input:focus { border-color: var(--pos-green); box-shadow: 0 0 0 3px rgba(16,185,129,.14); }
        .pay-hint { margin-top: 6px; color: var(--pos-muted); font-size: 11px; line-height: 1.35; }

        .modal-actions { display: flex; gap: 10px; }
        .btn-cancel {
            flex: 0 0 auto; padding: 13px 20px; border-radius: 10px;
            border: 1px solid var(--pos-border); background: transparent;
            color: var(--pos-muted); cursor: pointer; font-family: inherit; font-weight: 600;
        }
        .btn-cancel:hover { background: rgba(255,255,255,.06); }
        .btn-confirm {
            flex: 1; padding: 13px; border-radius: 10px; border: none;
            background: linear-gradient(135deg,#10b981,#059669);
            color: #fff; font-size: 16px; font-weight: 800; cursor: pointer;
            font-family: inherit; box-shadow: 0 4px 14px rgba(16,185,129,.3);
        }
        .btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
        .btn-confirm > span[x-show="!processing"]:not(.confirm-ready) { display: none !important; }

        @media (max-width: 840px) {
            .modal-overlay { align-items: flex-start; padding: 10px; overflow-y: auto; }
            .modal-box { width: 100%; max-height: none; overflow: visible; }
            .payment-layout { grid-template-columns: 1fr; max-height: none; min-height: 0; }
            .payment-side { border-right: 0; border-bottom: 1px solid var(--pos-border); }
            .method-tabs { grid-template-columns: 1fr 1fr; }
            .modal-actions { position: sticky; bottom: 0; background: var(--pos-panel); padding-top: 12px; }
        }

        @media (max-height: 760px) {
            .modal-overlay { align-items: flex-start; padding: 10px 14px; }
            .modal-head { padding: 12px 16px; }
            .modal-title { font-size: 18px; }
            .shift-modal-body { max-height: calc(100vh - 96px); gap: 9px; padding: 10px 12px 12px; }
            .shift-modal-body .pay-total { font-size: 20px; }
        }

        /* ── Receipt modal ───────────────────────────── */
        .receipt-box {
            background: #fff; color: #0f172a; border-radius: 16px;
            width: min(var(--receipt-screen-width, 360px), 100%); padding: 28px 24px; text-align: center;
            font-family: 'Courier New', monospace;
        }
        .receipt-logo { font-size: 20px; font-weight: 900; letter-spacing: -1px; margin-bottom: 4px; }
        .receipt-logo span { color: #10b981; }
        .receipt-divider { border: none; border-top: 1.5px dashed #cbd5e1; margin: 12px 0; }
        .receipt-doc { font-size: 13px; color: #64748b; margin-bottom: 10px; }
        .receipt-items { text-align: left; margin-bottom: 10px; font-size: 13px; }
        .receipt-item { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .receipt-total { font-size: 18px; font-weight: 900; margin-top: 4px; }
        .receipt-method { font-size: 12px; color: #64748b; margin-top: 4px; }
        .receipt-thanks { font-size: 13px; color: #64748b; margin-top: 14px; }
        .receipt-bottom-feed { display: none; }
        .receipt-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 18px; }
        .receipt-action-btn {
            border: 1px solid #dbe7ef;
            border-radius: 10px;
            padding: 10px 12px;
            background: #f8fafc;
            color: #0f172a;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
        }
        .receipt-action-btn.primary { background: #0f172a; color: #f8fafc; border-color: #0f172a; grid-column: 1 / -1; }
        .receipt-action-btn.danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .receipt-action-btn:hover { filter: brightness(.97); }

        /* ── Topbar controls ─────────────────────────── */
        .topbar-select {
            background: var(--pos-card); border: 1px solid var(--pos-border);
            color: var(--pos-text); border-radius: 9px; padding: 6px 10px;
            font-size: 12px; font-weight: 800; outline: none; cursor: pointer; font-family: inherit;
        }
        .topbar-locked {
            background: rgba(16,185,129,.14); border: 1px solid rgba(16,185,129,.4);
            color: #34d399; border-radius: 9px; padding: 6px 12px;
            font-size: 12px; font-weight: 800; white-space: nowrap; display: inline-flex; align-items: center;
        }
        .topbar-btn {
            background: transparent; border: 1px solid var(--pos-border);
            color: var(--pos-muted); border-radius: 9px; padding: 6px 10px;
            font-size: 12px; font-weight: 800; cursor: pointer; display: flex; align-items: center; gap: 6px;
            transition: all .15s; text-decoration: none; font-family: inherit;
        }
        .topbar-btn:hover { background: rgba(255,255,255,.07); color: var(--pos-text); }

        .pos-clock {
            font-size: 15px; color: #e0f2fe; font-weight: 900;
            font-variant-numeric: tabular-nums;
            background: rgba(14,165,233,.10);
            border: 1px solid rgba(34,211,238,.18);
            padding: 6px 10px;
            border-radius: 9px;
        }

        .shift-pill {
            background: rgba(15,23,42,.58);
            border: 1px solid var(--pos-border);
            color: var(--pos-muted);
            border-radius: 9px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 126px;
            justify-content: center;
        }
        .shift-pill.open { border-color: rgba(16,185,129,.42); background: rgba(16,185,129,.14); color: #bbf7d0; }
        .shift-pill.closed { border-color: rgba(245,158,11,.42); background: rgba(245,158,11,.14); color: #fde68a; }

        /* Popstar Shop skin */
        .pos-wrap {
            background:
                linear-gradient(180deg, rgba(236,253,245,.08), transparent 34%),
                radial-gradient(circle at 18% -10%, rgba(34,211,238,.22), transparent 30%),
                radial-gradient(circle at 88% 0%, rgba(16,185,129,.24), transparent 28%),
                #08111f;
        }

        .pos-topbar {
            background: rgba(248,250,252,.96);
            color: #0f172a;
            border-bottom: 1px solid rgba(15,23,42,.10);
            box-shadow: 0 10px 30px rgba(2,8,23,.10);
        }

        .pos-logo {
            color: #0f172a;
            letter-spacing: -.8px;
        }

        .topbar-select,
        .topbar-btn,
        .pos-clock,
        .shift-pill {
            background: #ffffff;
            border-color: #dbe7ef;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15,23,42,.05);
        }

        .topbar-btn:hover,
        .topbar-select:hover {
            border-color: #22d3ee;
            color: #0891b2;
            background: #f8fafc;
        }

        .pos-clock {
            color: #047857;
            background: #ecfdf5;
            border-color: #a7f3d0;
        }

        .shift-pill.open { color: #047857; background: #ecfdf5; border-color: #a7f3d0; }
        .shift-pill.closed { color: #b45309; background: #fffbeb; border-color: #fde68a; }

        .pos-cart,
        .pos-products {
            background: rgba(248,250,252,.97);
            border-color: rgba(15,23,42,.10);
            box-shadow: 0 18px 40px rgba(15,23,42,.14);
        }

        .pos-cart-header,
        .pos-search-bar,
        .pos-categories {
            background: #ffffff;
            border-color: #e2e8f0;
        }

        .pos-customer-field,
        .discount-card-input,
        .price-input,
        .qty-input,
        .discount-input,
        .discount-type {
            background: #f8fafc;
            color: #0f172a;
            border-color: #dbe7ef;
        }

        .pos-customer-field input,
        .discount-card-input input {
            color: #0f172a;
        }

        .pos-customer-field input::placeholder,
        .discount-card-input input::placeholder {
            color: #64748b;
        }

        .cart-list-head {
            background: #f1f5f9;
            color: #0f766e;
            border-color: #dbe7ef;
        }

        .cart-item,
        .cart-item:nth-child(even) {
            background: #ffffff;
            border-color: #e2e8f0;
        }

        .cart-item:hover {
            background: #f0fdfa;
        }

        .cart-item.active {
            background: #ecfeff;
            box-shadow: inset 4px 0 0 #06b6d4, 0 8px 18px rgba(8,145,178,.12);
        }

        .cart-line-no {
            background: #e0f2fe;
            color: #0369a1;
        }

        .cart-item-name,
        .cart-item-price,
        .total-row.grand {
            color: #0f172a;
        }

        .cart-item-sku,
        .total-row,
        .total-row.muted span,
        .bill-tools label,
        .cart-line-tools .tool-label {
            color: #64748b;
        }

        .cart-item-price,
        .total-row.grand .val {
            color: #059669;
        }

        .qty-btn,
        .trash-btn {
            background: #f8fafc;
            border: 1px solid #dbe7ef;
            color: #334155;
        }

        .qty-btn:hover {
            background: #e0f2fe;
            color: #0369a1;
        }

        .trash-btn:hover {
            color: #dc2626;
            background: #fee2e2;
            border-color: #fecaca;
        }

        .pos-cart-footer {
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            border-color: #e2e8f0;
        }

        .discount-card-applied {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .vat-toggle {
            background: #f8fafc;
            border-color: #dbe7ef;
        }

        .vat-toggle button {
            color: #64748b;
        }

        .vat-toggle button.active {
            color: #0f766e;
            background: #ccfbf1;
        }

        .pos-search-input {
            background: #ffffff;
            border-color: #dbe7ef;
            box-shadow: 0 8px 18px rgba(15,23,42,.06);
        }

        .cat-pill {
            background: #ffffff;
            color: #334155;
            border-color: #dbe7ef;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
        }

        .cat-pill:hover {
            background: #f0fdfa;
            color: #0f766e;
            border-color: #99f6e4;
        }

        .cat-pill.active {
            background: linear-gradient(135deg, #06b6d4, #10b981);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 10px 24px rgba(16,185,129,.22);
        }

        .product-grid {
            background: linear-gradient(180deg, #f8fafc, #eef7f4);
        }

        .product-card {
            background: #ffffff;
            border-color: #dbe7ef;
            box-shadow: 0 10px 22px rgba(15,23,42,.08);
        }

        .product-card:hover {
            border-color: #22d3ee;
            background: #f0fdfa;
            box-shadow: 0 16px 30px rgba(8,145,178,.16);
        }

        .product-sku {
            color: #0284c7;
        }

        .product-name {
            color: #0f172a;
        }

        .product-price {
            color: #059669;
        }

        .product-card.flash-sale {
            border-color: #fbbf24;
            background: linear-gradient(180deg, #fffbeb, #ffffff);
        }

        .product-price-orig {
            color: #94a3b8;
        }

        .cart-item.gift-line {
            background: #ecfdf5;
            box-shadow: inset 4px 0 0 #10b981;
        }

        .cart-item.gift-line .cart-item-price {
            color: #059669;
        }

        .qty-display {
            color: #0f172a;
        }

        .discount-card-error {
            color: #dc2626;
        }

        .pos-actionbar {
            background: rgba(248,250,252,.97);
            border-color: #dbe7ef;
            box-shadow: 0 -14px 30px rgba(15,23,42,.12);
        }

        .action-btn {
            border: 0;
            box-shadow: 0 12px 24px rgba(15,23,42,.14);
        }

        .action-btn.hold { background: linear-gradient(135deg,#64748b,#475569); }
        .action-btn.clear { background: linear-gradient(135deg,#ef4444,#b91c1c); }
        .action-btn.qr { background: linear-gradient(135deg,#06b6d4,#0284c7); }
        .action-btn.edit { background: linear-gradient(135deg,#f59e0b,#d97706); }
        .action-btn.pay {
            background: linear-gradient(135deg,#10b981,#047857);
            box-shadow: 0 14px 28px rgba(16,185,129,.26);
        }

        @media print {
            body * { visibility: hidden !important; }
            .receipt-box, .receipt-box * { visibility: visible !important; }
            .receipt-box {
                position: fixed;
                inset: 0 auto auto 0;
                width: var(--receipt-print-width, 80mm) !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 4mm !important;
            }
            .receipt-bottom-feed {
                display: block !important;
                height: 3.6em;
            }
            .receipt-actions { display: none !important; }
        }

        @media (max-width: 1280px) {
            .pos-body { grid-template-columns: 520px 1fr; }
            .cart-list-head,
            .cart-item { grid-template-columns: 26px minmax(0, 1fr) 78px 86px 28px; gap: 5px; }
            .product-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
            .product-card { min-height: 100px; }
        }

        @media (max-width: 980px) {
            html, body { overflow: auto; }
            .pos-wrap { min-height: 100vh; height: auto; grid-template-rows: auto 1fr auto; }
            .pos-topbar { flex-wrap: wrap; min-height: 64px; padding: 10px; }
            .pos-body { grid-template-columns: 1fr; overflow: visible; }
            .pos-cart { min-height: 420px; }
            .pos-products { min-height: 620px; }
            .pos-actionbar { grid-template-columns: 1fr 1fr; }
        }

        /* ── POPSTAR POS popup system ───────────────── */
        .pos-swal-popup {
            width: min(430px, calc(100vw - 28px)) !important;
            padding: 0 !important;
            color: var(--pos-text) !important;
            background: #111c2e !important;
            border: 1px solid rgba(148,163,184,.24) !important;
            border-radius: 14px !important;
            overflow: hidden !important;
            box-shadow: 0 28px 90px rgba(0,0,0,.45) !important;
        }
        .pos-swal-popup .swal2-icon { margin: 22px auto 10px !important; transform: scale(.86); }
        .pos-swal-title {
            padding: 0 26px !important;
            color: var(--pos-text) !important;
            font-size: 21px !important;
            font-weight: 900 !important;
            line-height: 1.25 !important;
            letter-spacing: 0 !important;
        }
        .pos-swal-html, .pos-swal-popup .swal2-html-container {
            padding: 8px 28px 2px !important;
            margin: 0 !important;
            color: var(--pos-muted) !important;
            font-size: 14px !important;
            line-height: 1.55 !important;
        }
        .pos-swal-actions { gap: 10px !important; padding: 18px 24px 24px !important; margin: 0 !important; }
        .pos-swal-confirm, .pos-swal-cancel {
            min-width: 112px !important;
            min-height: 42px !important;
            padding: 9px 18px !important;
            border: 0 !important;
            border-radius: 10px !important;
            font-weight: 900 !important;
            box-shadow: none !important;
        }
        .pos-swal-confirm { color: #fff !important; background: linear-gradient(135deg, #10b981, #0ea5e9) !important; }
        .pos-swal-cancel { color: #cbd5e1 !important; background: #263b57 !important; }
        .pos-swal-toast {
            width: min(390px, calc(100vw - 24px)) !important;
            padding: 12px 14px !important;
            border-radius: 12px !important;
            box-shadow: 0 18px 55px rgba(0,0,0,.34) !important;
        }
        .pos-swal-toast .swal2-title { color: var(--pos-text) !important; font-size: 14px !important; font-weight: 900 !important; }
        .pos-swal-toast .swal2-timer-progress-bar { background: linear-gradient(90deg, #10b981, #22d3ee) !important; }
        /* แคตตาล็อกแบบหนาแน่น: รหัส + ชื่อ + ราคาขาย */
        .product-grid {
            grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
            gap:5px;
            padding:6px;
            background:#eef3f6;
        }
        .product-card {
            min-height:48px;
            height:48px;
            display:grid;
            grid-template-columns:62px minmax(0,1fr) auto;
            grid-template-rows:1fr;
            align-items:center;
            gap:8px;
            padding:5px 8px;
            border:1px solid #d9e3ea;
            border-radius:6px;
            background:#fff;
            box-shadow:0 1px 3px rgba(15,23,42,.045);
            overflow:hidden;
        }
        .product-card:hover { transform:none; border-color:#0ea5e9; background:#f0f9ff; box-shadow:0 2px 8px rgba(14,165,233,.12); }
        .product-card:active { transform:scale(.99); }
        .product-card .flash-badge,
        .product-card .promo-badge,
        .product-card .stock-badge,
        .product-card .product-price-orig { display:none!important; }
        .product-card .product-sku { grid-column:1; color:#52708a; font-size:10px; font-weight:800; font-variant-numeric:tabular-nums; }
        .product-card .product-name { grid-column:2; min-height:0; color:#172b3a; font-size:11.5px; font-weight:750; line-height:1.25; -webkit-line-clamp:2; }
        .product-card .product-price { grid-column:3; margin:0; padding:0; color:#0284c7; font-size:13px; font-weight:900; text-align:right; }
        .product-card.flash-sale { border-left:3px solid #f59e0b; background:#fffdf5; }
        @media(max-width:1280px){.product-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}.product-card{min-height:46px;height:46px;grid-template-columns:56px minmax(0,1fr) auto}.product-card .product-name{font-size:11px}.product-card .product-price{font-size:12px}}

        /* ERP product-check screen: same visual language as the selling POS, without sale controls. */
        .erp-preview-pill {
            display:inline-flex; align-items:center; gap:7px; padding:6px 12px;
            border:1px solid #0ea5e9; border-radius:999px; background:#e0f2fe;
            color:#075985; font-size:12px; font-weight:900; white-space:nowrap;
        }
        .pos-download-link { background:#0284c7!important; color:#fff!important; border-color:#0369a1!important; }
        .preview-notice {
            display:grid; grid-template-columns:36px minmax(0,1fr) auto; align-items:center; gap:10px;
            padding:9px 11px; border-bottom:1px solid #bae6fd; background:#f0f9ff; color:#0c4a6e;
        }
        .preview-notice-icon { width:34px; height:34px; display:grid; place-items:center; border-radius:9px; background:#0284c7; color:#fff; font-size:17px; }
        .preview-notice strong { display:block; font-size:13px; }
        .preview-notice span { display:block; margin-top:1px; color:#52708a; font-size:10.5px; font-weight:700; }
        .preview-notice button { border:1px solid #bae6fd; border-radius:7px; background:#fff; color:#0369a1; padding:6px 9px; font:800 11px inherit; cursor:pointer; }
        body.view-only .pos-wrap { grid-template-rows:48px 1fr; }
        body.view-only .pos-body { grid-template-columns:minmax(500px,38vw) 1fr; }
        body.view-only .pos-actionbar,
        body.view-only .pos-cart-header,
        body.view-only .pos-cart-footer,
        body.view-only .cart-line-tools,
        body.view-only .trash-btn { display:none!important; }
        body.view-only .cart-qty-cell { pointer-events:none; }
        body.view-only .cart-qty-cell .qty-btn { display:none; }
        body.view-only .cart-qty-cell .qty-input { border:0; background:transparent; color:#334155; }
        body.view-only .cart-item { cursor:default; }
        body.view-only .cart-item.active { box-shadow:none; background:rgba(34,211,238,.06); }
        @media(max-width:1100px){body.view-only .pos-body{grid-template-columns:minmax(390px,42vw) 1fr}.erp-preview-pill{display:none}}
    </style>
</head>
<body class="{{ $canSell ? 'sales-mode' : 'view-only' }}" x-data="posApp()" x-init="init()">
<div class="pos-wrap">

    {{-- TOP BAR --}}
    <div class="pos-topbar">
        @if($posLogo = \App\Models\AppSetting::logoUrl())
            <div class="pos-logo" style="display:flex;align-items:center;gap:6px;min-width:0">
                <img src="{{ $posLogo }}" alt="logo" style="max-height:34px;max-width:120px;object-fit:contain">
                <span style="font-size:11px;font-weight:800;color:#0f766e;-webkit-text-fill-color:#0f766e">JET POS</span>
            </div>
        @else
            <div class="pos-logo">{{ \App\Models\AppSetting::company('name_th') }} <span style="font-size:11px;font-weight:800;color:#0f766e;-webkit-text-fill-color:#0f766e">JET POS</span></div>
        @endif

        @if($lockedBranch)
            {{-- สาขาถูกล็อกตาม user ที่ login - เปลี่ยนไม่ได้ --}}
            <span class="topbar-locked" title="สาขาของบัญชีคุณ"><i class="bi bi-shop me-1"></i>{{ $lockedBranch->code }} - {{ $lockedBranch->name_th }}</span>
        @else
            <select class="topbar-select" x-model="branchId" @change="updateBranchName(); loadProducts(); loadPromotions(); loadActiveShift()">
                @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        @endif

        @if($canSell && $lockedCashier)
            {{-- คนขายถูกล็อกเป็นตัว user เอง - เลือกชื่อคนอื่นไม่ได้ --}}
            <span class="topbar-locked" title="ขายในชื่อของคุณเท่านั้น"><i class="bi bi-person-badge me-1"></i>{{ $lockedCashier->name }}</span>
            <input type="hidden" x-ref="cashierSelect" value="{{ $lockedCashier->name }}">
        @elseif($canSell)
            <select class="topbar-select" x-model="cashierId" @change="loadActiveShift()" x-ref="cashierSelect">
                <option value="">-- แคชเชียร์ --</option>
                @foreach($cashiers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        @endif

        @unless($canSell)
        <span class="erp-preview-pill ms-auto">
            <i class="bi bi-eye-fill"></i> ตรวจสอบสินค้าใน ERP
        </span>
        @endunless
        @if($canSell)
        <button type="button" class="shift-pill ms-auto" :class="activeShift ? 'open' : 'closed'" @click="activeShift ? openCloseShiftModal() : openShiftModal()">
            <i class="bi" :class="activeShift ? 'bi-unlock-fill' : 'bi-lock-fill'"></i>
            <span x-text="activeShift ? 'กะ: ' + activeShift.shift_no : 'ยังไม่เปิดกะ'"></span>
        </button>
        @endif

        <div class="pos-clock" x-text="clock"></div>

        <button class="topbar-btn" @click="toggleFullscreen()">
            <i class="bi" :class="isFullscreen ? 'bi-fullscreen-exit' : 'bi-fullscreen'"></i>
            <span x-text="isFullscreen ? 'ออกเต็มจอ' : 'เต็มจอ'"></span>
        </button>

        <button class="topbar-btn" x-show="canInstall" @click="installApp()" style="display:none">
            <i class="bi bi-download"></i> ติดตั้งแอพ
        </button>

        <a href="{{ route('dashboard') }}" target="_blank" class="topbar-btn" style="margin-left:8px">
            <i class="bi bi-grid"></i> ERP
        </a>
        @unless($canSell)
        <a href="{{ route('pos.download') }}" class="topbar-btn pos-download-link">
            <i class="bi bi-windows"></i> ดาวน์โหลด POS ขายจริง
        </a>
        @endunless
        @if($canSell)
        <button class="topbar-btn" @click="openReceiptSettings()">
            <i class="bi bi-receipt"></i> ใบเสร็จ
        </button>
        <button class="topbar-btn" @click="cancelBill()" :disabled="cart.length === 0">
            <i class="bi bi-trash"></i> ยกเลิก
        </button>
        @endif
        <form method="post" action="{{ route('logout') }}" x-ref="logoutForm" style="display:contents">
            @csrf
            <button type="button" class="topbar-btn" title="ออกจากระบบ" @click="confirmLogout()">
                <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
            </button>
        </form>
    </div>

    <div class="pos-body">

        {{-- CART (LEFT) --}}
        <div class="pos-cart">
            @unless($canSell)
            <div class="preview-notice">
                <div class="preview-notice-icon"><i class="bi bi-display"></i></div>
                <div>
                    <strong>รายการตรวจสอบสินค้า</strong>
                    <span>คลิกสินค้าเพื่อดูรายการและราคา หน้านี้ไม่ออกบิลและไม่ตัดสต็อก</span>
                </div>
                <button type="button" x-show="cart.length" @click="clearCart()"><i class="bi bi-x-circle"></i> ล้างรายการ</button>
            </div>
            @endunless
            <div class="pos-cart-header">
                <div class="pos-customer-field">
                    <i class="bi bi-person" style="color:#94a3b8;font-size:15px"></i>
                    <input type="text" placeholder="ค้นหาลูกค้า (ไม่บังคับ)" x-model="customerQuery"
                        @input.debounce.400ms="searchCustomers()" autocomplete="off">
                    <span x-show="customerName" @click="clearCustomer()" style="color:#94a3b8;cursor:pointer;font-size:12px" x-text="'✕ ' + customerName"></span>
                    <div x-show="customerResults.length" style="position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#1e293b;border:1px solid rgba(255,255,255,.1);border-radius:10px;z-index:100;overflow:hidden">
                        <template x-for="c in customerResults" :key="c.id">
                            <div @click="selectCustomer(c)" style="padding:9px 14px;cursor:pointer;font-size:13px;display:flex;gap:10px" :style="'border-bottom:1px solid rgba(255,255,255,.06)'" @mouseenter="$el.style.background='rgba(255,255,255,.05)'" @mouseleave="$el.style.background='transparent'">
                                <span x-text="c.code" style="color:#94a3b8;font-size:11px;min-width:60px"></span>
                                <span x-text="c.name_th"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Member (สะสม/แลกแต้ม) --}}
                <div class="pos-customer-field" style="margin-top:6px">
                    <i class="bi bi-person-vcard" style="color:#fbbf24;font-size:15px"></i>
                    <template x-if="!member">
                        <input type="text" placeholder="สมาชิกสะสมแต้ม รหัส/ชื่อ/เบอร์ (ไม่บังคับ)" x-model="memberQuery"
                            @input.debounce.400ms="searchMembers()" autocomplete="off">
                    </template>
                    <template x-if="member">
                        <div style="display:flex;align-items:center;gap:8px;flex:1;font-size:13px">
                            <span style="font-weight:800;color:#0f172a" x-text="member.name"></span>
                            <span style="color:#d97706;font-weight:800" x-text="money(member.points) + ' แต้ม'"></span>
                            <template x-if="pointValueBaht > 0">
                                <span style="display:flex;align-items:center;gap:5px;margin-left:auto">
                                    <span style="color:#64748b;font-size:11px;font-weight:900">ใช้แต้ม</span>
                                    <input class="discount-input" type="number" min="0" step="1" x-model.number="redeemPoints"
                                        style="width:64px;height:26px" @focus="$el.select()">
                                    <span style="color:#059669;font-weight:800" x-text="'-฿' + money(pointsDiscountAmount)"></span>
                                </span>
                            </template>
                            <span @click="clearMember()" style="color:#94a3b8;cursor:pointer;font-size:12px" :style="pointValueBaht > 0 ? '' : 'margin-left:auto'">✕</span>
                        </div>
                    </template>
                    <div x-show="memberResults.length" style="position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#1e293b;border:1px solid rgba(255,255,255,.1);border-radius:10px;z-index:100;overflow:hidden">
                        <template x-for="m in memberResults" :key="m.id">
                            <div @click="selectMember(m)" style="padding:9px 14px;cursor:pointer;font-size:13px;display:flex;gap:10px;border-bottom:1px solid rgba(255,255,255,.06)" @mouseenter="$el.style.background='rgba(255,255,255,.05)'" @mouseleave="$el.style.background='transparent'">
                                <span x-text="m.member_code" style="color:#94a3b8;font-size:11px;min-width:60px"></span>
                                <span x-text="m.name"></span>
                                <span x-text="money(m.points) + ' แต้ม'" style="margin-left:auto;color:#fbbf24;font-size:11px;font-weight:800"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="pos-cart-items" x-ref="cartItems">
                <template x-if="cart.length === 0">
                    <div class="cart-empty">
                        <i class="bi bi-bag"></i>
                        <span>{{ $canSell ? 'ยังไม่มีสินค้า' : 'เลือกสินค้าจากด้านขวาเพื่อตรวจสอบ' }}</span>
                    </div>
                </template>
                <template x-if="cart.length > 0">
                    <div class="cart-list-head">
                        <span>#</span>
                        <span>สินค้า</span>
                        <span>จำนวน</span>
                        <span class="text-end">รวม</span>
                        <span></span>
                    </div>
                </template>
                <template x-for="(item, idx) in cart" :key="item.uid || item.id">
                    <div class="cart-item" :class="{ active: selectedCartIdx === idx, 'gift-line': item.is_free_gift }" @click="selectedCartIdx = idx">
                        <div class="cart-line-no" x-text="idx + 1"></div>
                        <div class="cart-product-cell">
                            <div class="cart-item-name" x-text="item.name_th"></div>
                            <div class="cart-item-sku">
                                <span x-text="item.sku_code"></span>
                                <template x-if="!item.is_free_gift">
                                    <span><span> • </span><span x-text="'฿' + money(item.unit_price) + '/หน่วย'"></span></span>
                                </template>
                                <template x-if="item.unit_name">
                                    <span><span> • </span><span x-text="item.unit_name + (item.unit_factor && item.unit_factor !== 1 ? ' x' + money(item.unit_factor) : '')"></span></span>
                                </template>
                                <template x-if="item.matched_barcode">
                                    <span><span> • </span><span x-text="'ยิง: ' + item.matched_barcode"></span></span>
                                </template>
                                <template x-if="itemDiscountAmount(item) > 0">
                                    <span x-text="' • ลด ฿' + money(itemDiscountAmount(item))"></span>
                                </template>
                                <template x-if="item.is_free_gift">
                                    <span style="color:#059669;font-weight:900" x-text="' • 🎁 ของแถม: ' + (item.promo_name || '')"></span>
                                </template>
                            </div>
                        </div>
                        <div class="cart-qty-cell">
                            <template x-if="!item.is_free_gift">
                                <button class="qty-btn" @click.stop="changeQty(idx, -1)"><i class="bi bi-dash"></i></button>
                            </template>
                            <template x-if="!item.is_free_gift">
                                <input class="qty-input" type="number" step="0.001" min="0.001" x-model.number="item.qty" @change="item.qty = Math.max(0.001, item.qty); applyQtyPromotions()">
                            </template>
                            <template x-if="!item.is_free_gift">
                                <button class="qty-btn" @click.stop="changeQty(idx, 1)"><i class="bi bi-plus"></i></button>
                            </template>
                            <template x-if="item.is_free_gift">
                                <div class="qty-display" style="grid-column:1 / -1" x-text="money(item.qty)"></div>
                            </template>
                        </div>
                        <div class="cart-item-price" x-text="item.is_free_gift ? 'ฟรี' : '฿' + money(lineNet(item))"></div>
                        <template x-if="!item.is_free_gift">
                            <button class="trash-btn" @click.stop="removeItem(idx)"><i class="bi bi-trash3"></i></button>
                        </template>
                        <template x-if="item.is_free_gift"><span></span></template>
                        <div class="cart-line-tools" x-show="selectedCartIdx === idx && !item.is_free_gift" @click.stop>
                            <span class="tool-label">ราคา</span>
                            <input class="price-input" type="number" step="0.01" min="0" x-model.number="item.unit_price">
                            <span class="tool-label">ลด</span>
                            <div class="discount-cell">
                                <input class="discount-input" type="number" step="0.01" min="0" x-model.number="item.discount_value" @click.stop @focus="$el.select()">
                                <select class="discount-type" x-model="item.discount_type" @click.stop>
                                    <option value="baht">฿</option>
                                    <option value="percent">%</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="pos-cart-footer">
                <div class="discount-card-row">
                    <template x-if="!appliedCard">
                        <div class="discount-card-input">
                            <i class="bi bi-credit-card-2-back"></i>
                            <input type="text" placeholder="สแกน/พิมพ์รหัสบัตรส่วนลด" x-model="discountCardCode"
                                @keydown.enter.prevent="applyDiscountCard()" autocomplete="off">
                            <button type="button" @click="applyDiscountCard()" :disabled="!discountCardCode.trim() || discountCardChecking">ใช้บัตร</button>
                        </div>
                    </template>
                    <template x-if="appliedCard">
                        <div class="discount-card-applied">
                            <i class="bi bi-patch-check-fill"></i>
                            <span x-text="appliedCard.name + ' (' + appliedCard.card_code + ')'"></span>
                            <span class="ms-auto" @click="removeDiscountCard()" style="cursor:pointer"><i class="bi bi-x-circle"></i></span>
                        </div>
                    </template>
                    <div x-show="discountCardError" class="discount-card-error" x-text="discountCardError"></div>
                </div>
                <div class="bill-tools">
                    <div>
                        <label>ส่วนลดท้ายบิล</label>
                        <div class="discount-cell">
                            <input class="discount-input" type="number" step="0.01" min="0" x-model.number="billDiscountValue" @focus="$el.select()">
                            <select class="discount-type" x-model="billDiscountType">
                                <option value="baht">฿</option>
                                <option value="percent">%</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label>VAT</label>
                        <div class="vat-toggle">
                            <button type="button" :class="{ active: vatMode === 'included' }" @click="vatMode = 'included'">รวม</button>
                            <button type="button" :class="{ active: vatMode === 'excluded' }" @click="vatMode = 'excluded'">แยก</button>
                        </div>
                    </div>
                </div>
                <div class="cart-totals">
                    <div class="total-row">
                        <span>รายการ</span>
                        <span x-text="cart.length + ' รายการ'"></span>
                    </div>
                    <div class="total-row">
                        <span>จำนวนรวม</span>
                        <span x-text="money(totalQty) + ' ชิ้น'"></span>
                    </div>
                    <div class="total-row muted">
                        <span>ยอดก่อนลด</span>
                        <span x-text="'฿' + money(subtotalAmount)"></span>
                    </div>
                    <div class="total-row discount" x-show="promoDiscountTotal > 0">
                        <span>ส่วนลดแคมเปญ</span>
                        <span x-text="'-฿' + money(promoDiscountTotal)"></span>
                    </div>
                    <div class="total-row discount">
                        <span>ส่วนลดรวม</span>
                        <span x-text="'-฿' + money(totalDiscount)"></span>
                    </div>
                    <div class="total-row muted">
                        <span>ฐานก่อน VAT</span>
                        <span x-text="'฿' + money(beforeVatAmount)"></span>
                    </div>
                    <div class="total-row muted">
                        <span>VAT 7%</span>
                        <span x-text="'฿' + money(vatAmount)"></span>
                    </div>
                    <div class="total-row grand">
                        <span>รวมสุทธิ</span>
                        <span class="val" x-text="'฿' + money(totalAmount)"></span>
                    </div>
                </div>
                <div class="quick-pay-row">
                    <button class="quick-pay cash" :disabled="cart.length === 0 || !activeShift" @click="openPayment('cash')">
                        <i class="bi bi-cash-stack"></i>
                        <span>เงินสด</span>
                    </button>
                    <button class="quick-pay qr" :disabled="cart.length === 0 || !activeShift" @click="openPayment('transfer')">
                        <i class="bi bi-qr-code"></i>
                        <span>QR</span>
                    </button>
                </div>
                <button class="pay-btn" :disabled="cart.length === 0 || !activeShift" @click="openPayment()">
                    <i class="bi bi-credit-card-2-front-fill"></i>
                    <span>ชำระเงิน</span>
                </button>
            </div>
        </div>

        {{-- PRODUCT PANEL (RIGHT) --}}
        <div class="pos-products">
            <div class="pos-search-bar">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input class="pos-search-input" type="text" placeholder="พิมพ์รหัสหรือชื่อสินค้า..."
                        x-model="searchQ" @input.debounce.180ms="loadProducts()" @keydown.enter.prevent="scanSearch()" autofocus>
                </div>
            </div>

            <div class="pos-categories">
                <button class="cat-pill" :class="{ active: !categoryId }" @click="selectCategory(null)">
                    ทั้งหมด
                </button>
                @foreach($categories as $cat)
                <button class="cat-pill" :class="{ active: categoryId === {{ $cat->id }} }" @click="selectCategory({{ $cat->id }})">
                    {{ $cat->name_th }}
                </button>
                @endforeach
            </div>

            <div class="product-grid" id="productGrid">
                <template x-if="loading">
                    <div class="product-loading"><i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite"></i> กำลังโหลด...</div>
                </template>
                <template x-if="!loading && products.length === 0">
                    <div class="product-loading" style="color:#64748b">ไม่พบสินค้า</div>
                </template>
                <template x-for="p in products" :key="p.id">
                    <div class="product-card" :class="{ 'flash-sale': p.is_flash_sale }" @click="addToCart(p)">
                        <template x-if="p.is_flash_sale">
                            <div class="flash-badge"><i class="bi bi-lightning-charge-fill"></i> นาทีทอง</div>
                        </template>
                        <template x-if="p.is_promotion && !p.is_flash_sale">
                            <div class="promo-badge"><i class="bi bi-tag-fill"></i> ราคาลดตามวันที่</div>
                        </template>
                        <template x-if="productPromoLabel(p)">
                            <div class="promo-badge"><i class="bi bi-gift-fill"></i> <span x-text="productPromoLabel(p)"></span></div>
                        </template>
                        <template x-if="p.stock_qty !== null && p.stock_qty !== undefined">
                            <div class="stock-badge" :class="p.stock_qty <= 0 ? 'out' : (p.stock_qty < 10 ? 'low' : '')"
                                 x-text="p.stock_qty <= 0 ? 'หมด' : 'คงเหลือ ' + money(p.stock_qty)"></div>
                        </template>
                        <div class="product-sku" x-text="p.sku_code"></div>
                        <div class="product-name" x-text="p.name_th"></div>
                        <template x-if="p.is_flash_sale || p.is_promotion">
                            <div class="product-price-orig" x-text="'฿' + money(p.original_price)"></div>
                        </template>
                        <div class="product-price" x-text="'฿' + money(p.pos_price ?? p.default_price)"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div class="pos-actionbar">
        <button class="action-btn hold" :disabled="cart.length === 0" @click="holdBill()">
            <i class="bi bi-pause-circle"></i>
            <span>พักบิล</span>
        </button>
        <button class="action-btn qr" @click="recallBill()">
            <i class="bi bi-folder2-open"></i>
            <span>เรียกบิล</span>
        </button>
        <button class="action-btn edit" :disabled="cart.length === 0" @click="editBill()">
            <i class="bi bi-pencil-square"></i>
            <span>แก้บิล</span>
        </button>
        <button class="action-btn clear" :disabled="cart.length === 0" @click="cancelBill()">
            <i class="bi bi-trash3"></i>
            <span>ยกเลิกบิล</span>
        </button>
        <button class="action-btn pay" :disabled="cart.length === 0 || !activeShift" @click="openPayment()">
            <i class="bi bi-cash-coin"></i>
            <span>คิดเงิน / ชำระเงิน</span>
        </button>
    </div>
</div>

{{-- SHIFT MODAL --}}
<div class="modal-overlay" x-show="shiftModalOpen" @keydown.escape.window="shiftModalOpen = false" style="display:none">
    <div class="modal-box" style="max-width:560px" @click.outside="shiftModalOpen = false" x-transition>
        <div class="modal-head">
            <div class="modal-title"><i class="bi bi-clock-history me-2" style="color:#10b981"></i>เปิดกะขาย</div>
            <button class="modal-close" type="button" @click="shiftModalOpen = false"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="shift-modal-body">
            <div class="pay-summary">
                <div class="pay-row"><span>สาขา</span><span x-text="branchName"></span></div>
                <div class="pay-row"><span>แคชเชียร์</span><span x-text="cashierName || 'ยังไม่เลือก'"></span></div>
            </div>
            <label style="display:grid;gap:6px;font-weight:900;color:var(--pos-text)">
                เงินทอนตั้งต้น
                <input class="ref-input" type="number" min="0" step="0.01" x-model.number="openingCash" @keydown.enter.prevent="submitOpenShift()">
            </label>
            <label style="display:grid;gap:6px;font-weight:900;color:var(--pos-text)">
                หมายเหตุเปิดกะ
                <input class="ref-input" type="text" x-model="openingNote" placeholder="ไม่บังคับ">
            </label>
            <div class="modal-actions">
                <button class="btn-cancel" @click="shiftModalOpen = false">ยกเลิก</button>
                <button class="btn-confirm" :disabled="shiftProcessing || !cashierId" @click="submitOpenShift()">
                    <span x-show="!shiftProcessing"><i class="bi bi-unlock me-1"></i> เปิดกะ</span>
                    <span x-show="shiftProcessing">กำลังเปิดกะ...</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- CLOSE SHIFT MODAL --}}
<div class="modal-overlay" x-show="closeShiftModalOpen" @keydown.escape.window="closeShiftModalOpen = false" style="display:none">
    <div class="modal-box" style="max-width:640px" @click.outside="closeShiftModalOpen = false" x-transition>
        <div class="modal-head">
            <div class="modal-title"><i class="bi bi-lock-fill me-2" style="color:#f59e0b"></i>ปิดกะขาย</div>
            <button class="modal-close" type="button" @click="closeShiftModalOpen = false"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="shift-modal-body">
            <div class="pay-summary">
                <div class="pay-row"><span>เลขกะ</span><span x-text="activeShift?.shift_no || '-'"></span></div>
                <div class="pay-row"><span>จำนวนบิล</span><span x-text="(activeShift?.receipt_count || 0) + ' บิล'"></span></div>
                <div class="pay-row"><span>เงินตั้งต้น</span><span x-text="'฿ ' + money(activeShift?.opening_cash || 0)"></span></div>
                <div class="pay-row"><span>เงินสดขาย</span><span x-text="'฿ ' + money(activeShift?.cash_sales || 0)"></span></div>
                <div class="pay-row"><span>QR / โอน</span><span x-text="'฿ ' + money(activeShift?.transfer_sales || 0)"></span></div>
                <div class="pay-row"><span>บัตร / เช็ค</span><span x-text="'฿ ' + money((activeShift?.card_sales || 0) + (activeShift?.cheque_sales || 0))"></span></div>
                <div class="pay-total" x-text="'เงินสดควรมี ฿ ' + money(activeShift?.expected_cash || 0)"></div>
            </div>
            <label style="display:grid;gap:6px;font-weight:900;color:var(--pos-text)">
                เงินสดที่นับจริง
                <input class="ref-input" type="number" min="0" step="0.01" x-model.number="countedCash" @keydown.enter.prevent="submitCloseShift()">
            </label>
            <div class="change-display" :class="{ short: closeShiftDiff < 0 }">
                <span class="label" x-text="closeShiftDiff === 0 ? 'เงินตรงกะ' : (closeShiftDiff > 0 ? 'เงินเกิน' : 'เงินขาด')"></span>
                <span class="value" x-text="'฿ ' + money(Math.abs(closeShiftDiff))"></span>
            </div>
            <label style="display:grid;gap:6px;font-weight:900;color:var(--pos-text)">
                หมายเหตุปิดกะ
                <input class="ref-input" type="text" x-model="closingNote" placeholder="ไม่บังคับ">
            </label>
            <div class="modal-actions">
                <button class="btn-cancel" @click="closeShiftModalOpen = false">ยกเลิก</button>
                <button class="btn-confirm" :disabled="shiftProcessing || !activeShift" @click="submitCloseShift()">
                    <span x-show="!shiftProcessing"><i class="bi bi-lock me-1"></i> ปิดกะ</span>
                    <span x-show="shiftProcessing">กำลังปิดกะ...</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- PAYMENT MODAL --}}
<div class="modal-overlay" x-show="payModalOpen" @keydown.escape.window="payModalOpen = false" style="display:none">
    <div class="modal-box" @click.outside="payModalOpen = false" x-transition>
        <div class="modal-head">
            <div class="modal-title"><i class="bi bi-cash-coin me-2" style="color:#10b981"></i>ชำระเงิน</div>
            <button class="modal-close" type="button" @click="payModalOpen = false"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="payment-layout">
            <div class="payment-side">
                <div class="method-tabs">
                    <button class="method-tab" :class="{ active: method === 'cash' }" @click="setMethod('cash')">
                        <i class="bi bi-cash"></i> เงินสด
                    </button>
                    <button class="method-tab" :class="{ active: method === 'transfer' }" @click="setMethod('transfer')">
                        <i class="bi bi-qr-code"></i> QR
                    </button>
                    <button class="method-tab" :class="{ active: method === 'credit_card' }" @click="setMethod('credit_card')">
                        <i class="bi bi-credit-card"></i> บัตร
                    </button>
                    <button class="method-tab" :class="{ active: method === 'cheque' }" @click="setMethod('cheque')">
                        <i class="bi bi-file-earmark-text"></i> เช็ค
                    </button>
                </div>

                <div class="pay-summary">
                    <div class="pay-row"><span>จำนวนสินค้า</span><span x-text="cart.length + ' รายการ'"></span></div>
                    <div class="pay-row"><span>จำนวนรวม</span><span x-text="money(totalQty) + ' ชิ้น'"></span></div>
                    <div class="pay-row"><span>ลูกค้า</span><span x-text="customerName || 'ลูกค้าทั่วไป'"></span></div>
                    <div class="pay-total" x-text="'฿ ' + money(totalAmount)"></div>
                </div>
            </div>

            <div class="payment-main">
                <template x-if="method === 'cash'">
                    <div>
                        <div class="cash-screen">
                            <div class="cash-screen-card">
                                <div class="label">รับเงินมา</div>
                                <div class="amount" x-text="'฿ ' + money(received)"></div>
                            </div>
                            <div class="cash-screen-card" :class="cashShortAmount > 0 ? 'due' : 'change'">
                                <div class="label" x-text="cashShortAmount > 0 ? 'ยังขาด' : 'เงินทอน'"></div>
                                <div class="amount" x-text="'฿ ' + money(cashShortAmount > 0 ? cashShortAmount : cashChangeAmount)"></div>
                            </div>
                        </div>
                        <div class="cash-quick-row">
                            <button type="button" class="cash-quick" @click="setReceivedCash(totalAmount)">พอดี</button>
                            <button type="button" class="cash-quick" @click="setReceivedCash(100)">100</button>
                            <button type="button" class="cash-quick" @click="setReceivedCash(500)">500</button>
                            <button type="button" class="cash-quick" @click="setReceivedCash(1000)">1000</button>
                        </div>
                        <div class="cash-keypad">
                            <button type="button" class="keypad-btn" @click="appendCashDigit('7')">7</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('8')">8</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('9')">9</button>
                            <button type="button" class="keypad-btn danger" @click="clearReceivedCash()">ล้าง</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('4')">4</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('5')">5</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('6')">6</button>
                            <button type="button" class="keypad-btn function" @click="backspaceCash()"><i class="bi bi-backspace"></i></button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('1')">1</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('2')">2</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('3')">3</button>
                            <button type="button" class="keypad-btn exact" @click="setReceivedCash(totalAmount)">พอดี</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('0')">0</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('00')">00</button>
                            <button type="button" class="keypad-btn" @click="appendCashDigit('.')">.</button>
                            <button type="button" class="keypad-btn function" @click="setReceivedCash(received + 100)">+100</button>
                        </div>
                        <div class="change-display" :class="{ short: cashShortAmount > 0 }">
                            <span class="label" x-text="cashShortAmount > 0 ? 'รับเงินยังไม่ครบ' : 'พร้อมออกบิล เงินทอน'"></span>
                            <span class="value" x-text="'฿ ' + money(cashShortAmount > 0 ? cashShortAmount : cashChangeAmount)"></span>
                        </div>
                    </div>
                </template>

                {{-- QR Code for transfer --}}
                <template x-if="method === 'transfer'">
                    <div>
                        @if($qrConfig && $qrConfig->merchant_ref)
                        <div class="qr-panel" x-effect="if (method === 'transfer' && payModalOpen) $nextTick(() => renderQR(totalAmount))">
                            <div class="qr-title">สแกนจ่าย PromptPay</div>
                            <div id="pos-qr-box" class="qr-box"></div>
                            <div class="qr-amount" x-text="'฿ ' + money(totalAmount)"></div>
                            @if($qrConfig->bankAccount)
                            <div class="qr-bank-info">
                                <i class="bi bi-bank2 me-1"></i>
                                {{ $qrConfig->bankAccount->bank_name }} —
                                {{ $qrConfig->bankAccount->account_no }}
                                @if($qrConfig->bankAccount->account_name)
                                ({{ $qrConfig->bankAccount->account_name }})
                                @endif
                            </div>
                            @endif
                            <div class="qr-name">{{ $qrConfig->name }}</div>
                            <button type="button" class="qr-copy" @click="copyQrPayload()">Copy payload</button>
                        </div>
                        <div class="payment-check-card">
                            <div class="check-status" :class="{ done: transferConfirmed }">
                                <span x-text="transferConfirmed ? 'ตรวจเงินเข้าแล้ว พร้อมออกบิล' : 'รอลูกค้าสแกนจ่าย แล้วตรวจเงินเข้า'"></span>
                                <i :class="transferConfirmed ? 'bi bi-check-circle-fill' : 'bi bi-hourglass-split'"></i>
                            </div>
                            <button type="button" class="check-paid" :class="{ done: transferConfirmed }" @click="markTransferPaid()">
                                <i class="bi bi-bank me-1"></i>
                                <span x-text="transferConfirmed ? 'ตรวจแล้ว' : 'เงินเข้าแล้ว'"></span>
                            </button>
                            <input class="ref-input" type="text" x-model="paymentRef"
                                placeholder="เลขอ้างอิง / 4 ตัวท้ายสลิป (ไม่บังคับ)"
                                @keydown.enter.prevent="markTransferPaid()">
                            <div class="pay-hint">กดเงินเข้าแล้วหลังดูแอปธนาคาร จากนั้นกดยืนยันชำระเพื่อออกบิล</div>
                        </div>
                        @else
                        <div class="qr-panel qr-unconfigured">
                            <i class="bi bi-qr-code" style="font-size:40px;color:#475569"></i>
                            <div class="mt-2 text-muted small">ยังไม่ได้ตั้งค่า PromptPay</div>
                            <a href="{{ route('bplus.qr-payments') }}" target="_blank" class="btn btn-sm btn-light border mt-2">
                                <i class="bi bi-gear me-1"></i>ตั้งค่า QR/PromptPay
                            </a>
                        </div>
                        @endif
                    </div>
                </template>

                <template x-if="method === 'cheque'">
                    <div class="change-display">
                        <span class="label">ยอดชำระด้วยเช็ค</span>
                        <span class="value" x-text="'฿ ' + money(totalAmount)"></span>
                    </div>
                </template>

                <template x-if="method === 'credit_card'">
                    <div>
                        <div class="change-display">
                            <span class="label">ยอดชำระด้วยบัตร</span>
                            <span class="value" x-text="'฿ ' + money(totalAmount)"></span>
                        </div>
                        <input class="ref-input" type="text" x-model="paymentRef" placeholder="เลขอ้างอิงบัตร / approval code (ไม่บังคับ)">
                    </div>
                </template>

                <div class="modal-actions">
                    <button class="btn-cancel" @click="payModalOpen = false">ยกเลิก</button>
                    <button class="btn-confirm" :disabled="processing || !canConfirm" @click="processPayment()">
                        <span class="confirm-ready" x-show="!processing"><i class="bi bi-check-circle me-1"></i> <span x-text="confirmLabel"></span> ฿<span x-text="money(totalAmount)"></span></span>
                        <span x-show="!processing"><i class="bi bi-check-circle me-1"></i> ยืนยันชำระ ฿<span x-text="money(totalAmount)"></span></span>
                        <span x-show="processing">กำลังบันทึก...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- RECEIPT MODAL --}}
<div class="modal-overlay" x-show="receiptOpen" style="display:none">
    <div class="receipt-box" x-transition :style="'--receipt-screen-width:' + receiptScreenWidth + ';--receipt-print-width:' + receiptPaperWidth">
        {{-- หัว: ชื่อ+เลขภาษีผู้ขาย (มาตรา 86/6 ข้อ 2) --}}
        @if($logo = \App\Models\AppSetting::logoUrl())
            <img src="{{ $logo }}" alt="" style="max-height:42px;max-width:130px;object-fit:contain;margin-bottom:4px">
        @else
            <div class="receipt-logo">pop<span>star</span></div>
        @endif
        <div style="font-size:12px;font-weight:800;line-height:1.35">{{ $company['name'] }}</div>
        <div style="font-size:10.5px;color:#64748b;line-height:1.4">
            @if($company['address']){{ Str::limit($company['address'], 70) }}<br>@endif
            เลขประจำตัวผู้เสียภาษี {{ $company['tax_id'] ?: '-' }}
            @if($company['phone'])<br>โทร {{ $company['phone'] }}@endif
        </div>
        <div style="font-size:12.5px;font-weight:900;color:#0f172a;margin-top:6px">ใบกำกับภาษีอย่างย่อ</div>
        <div style="font-size:11px;color:#64748b">สาขา: <span x-text="branchName"></span> &middot; แคชเชียร์: <span x-text="lastCashierName || '-'"></span></div>
        <hr class="receipt-divider">
        <div class="receipt-doc" style="display:flex;justify-content:space-between">
            <span>เลขที่: <strong x-text="lastDocNumber"></strong></span>
            <span x-text="lastDateTime"></span>
        </div>
        <div class="receipt-items">
            <template x-for="item in lastItems" :key="item.id">
                <div>
                    <div class="receipt-item">
                        <span x-text="item.name_th.substring(0,24) + (item.name_th.length>24?'…':'')"></span>
                        <span x-text="money(item.qty * item.unit_price)"></span>
                    </div>
                    <div style="font-size:10.5px;color:#94a3b8;text-align:left" x-text="item.qty + ' x ' + money(item.unit_price)"></div>
                </div>
            </template>
        </div>
        <hr class="receipt-divider">
        {{-- แยกฐานภาษี + VAT (มาตรา 86/6 ข้อ 5) --}}
        <div class="receipt-item" style="font-size:12px"><span>มูลค่าสินค้า (ก่อน VAT)</span><span x-text="money(lastTotal - vatPortion(lastTotal))"></span></div>
        <div class="receipt-item" style="font-size:12px"><span>ภาษีมูลค่าเพิ่ม {{ rtrim(rtrim(number_format($vatRate, 2), '0'), '.') }}%</span><span x-text="money(vatPortion(lastTotal))"></span></div>
        <div class="receipt-total">฿ <span x-text="money(lastTotal)"></span></div>
        <div style="font-size:10px;color:#94a3b8">ราคานี้รวมภาษีมูลค่าเพิ่มแล้ว</div>
        <div class="receipt-method" x-text="paymentMethodLabel(lastMethod)"></div>
        <div class="receipt-method" x-show="lastEarnedPoints > 0" style="color:#d97706;font-weight:700">
            ⭐ ได้รับแต้มสะสม +<span x-text="money(lastEarnedPoints)"></span>
        </div>
        <hr class="receipt-divider">
        <div class="receipt-thanks">ขอบคุณที่ใช้บริการ 🙏</div>
        <div class="receipt-bottom-feed" aria-hidden="true"></div>
        <div class="receipt-actions">
            <button class="receipt-action-btn" type="button" @click="printReceipt()">
                <i class="bi bi-printer"></i> พิมพ์
            </button>
            <button class="receipt-action-btn" type="button" @click="openReceiptSettings()">
                <i class="bi bi-gear"></i> ตั้งค่า
            </button>
            <button class="receipt-action-btn danger" type="button" x-show="canVoidBill && lastReceiptId" @click="voidLastReceipt()" style="display:none">
                <i class="bi bi-x-octagon"></i> ยกเลิก
            </button>
            <button class="receipt-action-btn primary" type="button" @click="newBill()">
                <i class="bi bi-plus-circle"></i> บิลใหม่
            </button>
        </div>
    </div>
</div>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* QR panel */
.qr-panel {
    display: flex; flex-direction: column; align-items: center;
    padding: 14px; background: #f8fafc; border-radius: 14px; gap: 7px;
    border: 1px solid #dbe3ec;
    color: #0f172a;
    justify-content: flex-start;
    width: min(100%, 360px);
    margin: 0 auto;
}
.qr-title { font-size: 13px; font-weight: 900; color: #0f172a; }
.qr-box { background: #fff; padding: 8px; border-radius: 11px; border: 1px solid #e5e7eb; }
.qr-box canvas, .qr-box img { display: block; width: 160px !important; height: 160px !important; }
.qr-amount { font-size: 24px; font-weight: 900; color: #059669; line-height: 1; }
.qr-bank-info { font-size: 11px; color: #475569; text-align: center; max-width: 320px; }
.qr-name { font-size: 11px; color: #64748b; font-weight: 700; text-align: center; }
.qr-unconfigured { gap: 4px; text-align: center; }
.qr-copy {
    border: 1px solid #dbe3ec;
    background: #f8fafc;
    color: #334155;
    border-radius: 8px;
    padding: 6px 9px;
    font-size: 11px;
    font-weight: 800;
    cursor: pointer;
}
.qr-copy:hover { background: #eef2f7; }

@media (max-width: 575px) {
    .qr-box canvas, .qr-box img { width: 148px !important; height: 148px !important; }
    .qr-amount { font-size: 22px; }
}
</style>

<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
/* ── POPSTAR POS popup helpers. All Swal.fire() calls inherit this skin. ── */
(function () {
    if (!window.Swal || window.Swal.__popstarPosPatched) return;

    const baseFire = window.Swal.fire.bind(window.Swal);
    const classes = {
        popup: 'pos-swal-popup',
        title: 'pos-swal-title',
        htmlContainer: 'pos-swal-html',
        actions: 'pos-swal-actions',
        confirmButton: 'pos-swal-confirm',
        cancelButton: 'pos-swal-cancel',
    };

    function normalize(options) {
        const config = typeof options === 'object' && options !== null ? { ...options } : options;
        if (typeof config !== 'object' || config === null) return config;

        const customClass = { ...classes, ...(config.customClass || {}) };
        if (config.toast) {
            customClass.popup = [classes.popup, 'pos-swal-toast', config.customClass?.popup].filter(Boolean).join(' ');
        }

        return {
            buttonsStyling: false,
            confirmButtonText: 'ตกลง',
            cancelButtonText: 'ยกเลิก',
            background: '#111827',
            color: '#f8fafc',
            showClass: { popup: 'swal2-show' },
            hideClass: { popup: 'swal2-hide' },
            timerProgressBar: config.toast ? true : config.timerProgressBar,
            ...config,
            customClass,
        };
    }

    window.Swal.fire = function (...args) {
        if (args.length === 1) return baseFire(normalize(args[0]));
        if (args.length > 1) {
            return baseFire(normalize({
                title: args[0],
                html: args[1],
                icon: args[2],
            }));
        }
        return baseFire();
    };
    window.Swal.__popstarPosPatched = true;

    window.erpToast = (icon, title, options = {}) => window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title,
        timer: icon === 'error' ? 5000 : 2200,
        showConfirmButton: false,
        ...options,
    });

    window.erpPopup = (icon, title, text, options = {}) => window.Swal.fire({
        icon,
        title,
        text,
        ...options,
    });

    window.erpConfirm = (title, text, options = {}) => window.Swal.fire({
        icon: 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText: options.confirmButtonText || 'ยืนยัน',
        cancelButtonText: options.cancelButtonText || 'ยกเลิก',
        ...options,
    });
})();

/* ── Thai PromptPay QR (EMVCo) generator ── */
const PROMPTPAY_ID = @json($qrConfig?->merchant_ref ?? '');
const PROMPTPAY_QR_TYPE = @json($qrConfig?->qr_type ?? 'dynamic');
const SCALE_BARCODE_RULES = [
    // POPSTAR store scale: 6 หลักแรก = รหัส PLU (ช่วงเลข 8: 800xxx + เป็ด 801037), 6 หลักถัดไป = **ราคารวม** ÷100 = บาท, หลักสุดท้าย = check
    // ยืนยันจากป้ายจริง 8010370148501 (2026-07-07): เป็ดเชอรี่ 110฿/กก. × 1.35กก. = 148.50 -> ฝัง 014850
    // (ค่าที่ฝังคือราคา ไม่ใช่น้ำหนัก — น้ำหนักคำนวณย้อนกลับจากราคา/กก. ตอนลงตะกร้า)
    // prefix ล็อกช่วง 800/801 — เลขนอกช่วงไม่ตีความเป็นป้ายชั่ง (กันชน EAN สินค้าซองจริง)
    { prefix: ['800', '801'], length: 13, codeStart: 0, codeLength: 6, valueStart: 6, valueLength: 6, divisor: 100 },
    // 12 หลัก: รหัส(6) + ราคารวม(5) ÷100
    { prefix: ['800', '801'], length: 12, codeStart: 0, codeLength: 6, valueStart: 6, valueLength: 5, divisor: 100 },
];
let lastQrPayload = '';

function crc16(data) {
    let crc = 0xFFFF;
    for (let i = 0; i < data.length; i++) {
        crc ^= data.charCodeAt(i) << 8;
        for (let j = 0; j < 8; j++) {
            crc = (crc & 0x8000) ? ((crc << 1) ^ 0x1021) : (crc << 1);
            crc &= 0xFFFF;
        }
    }
    return crc.toString(16).toUpperCase().padStart(4, '0');
}

function tlv(tag, value) {
    const len = String(value.length).padStart(2, '0');
    return `${tag}${len}${value}`;
}

function promptPayTarget(id) {
    const raw = String(id || '').replace(/[^0-9]/g, '');
    if (raw.length === 10 && raw.startsWith('0')) {
        return { tag: '01', value: '0066' + raw.substring(1) };
    }
    if (raw.length === 13) {
        return { tag: '02', value: raw };
    }
    return { tag: '01', value: raw };
}

function buildPromptPayPayload(promptPayId, amount) {
    const target = promptPayTarget(promptPayId);
    const merchantAcct = tlv('00', 'A000000677010111') + tlv(target.tag, target.value);
    const amtStr = amount.toFixed(2);

    let payload =
        tlv('00', '01') +
        tlv('01', PROMPTPAY_QR_TYPE === 'static' ? '11' : '12') +
        tlv('29', merchantAcct) +
        tlv('53', '764');

    if (PROMPTPAY_QR_TYPE !== 'static') {
        payload += tlv('54', amtStr);
    }

    payload +=
        tlv('58', 'TH') +
        tlv('59', @js(strtoupper(\App\Models\AppSetting::company('name_en') ?: 'JET ERP'))) +
        tlv('60', 'BANGKOK') +
        '6304';

    return payload + crc16(payload);
}

let qrInstance = null;

function renderQR(amount) {
    if (!PROMPTPAY_ID) return;
    const box = document.getElementById('pos-qr-box');
    if (!box) return;
    box.innerHTML = '';
    const payload = buildPromptPayPayload(PROMPTPAY_ID, amount);
    lastQrPayload = payload;

    qrInstance = new QRCode(box, {
        text: payload,
        width: 160,
        height: 160,
        colorDark: '#000', colorLight: '#fff',
        correctLevel: QRCode.CorrectLevel.H,
    });
}

function posApp() {
    return {
        branchId: '{{ $lockedBranch?->id ?? $defaultBranchId }}',
        cashierId: '{{ $lockedCashier?->id ?? '' }}',
        lockedCashierName: @js($lockedCashier?->name ?? ''),
        branchName: '{{ $branches->first()?->name_th ?? '' }}',
        canSell: {{ json_encode((bool) $canSell) }},
        canVoidBill: {{ json_encode((bool) $canVoid) }},
        activeShift: null,
        shiftModalOpen: false,
        closeShiftModalOpen: false,
        shiftProcessing: false,
        openingCash: 0,
        openingNote: '',
        countedCash: 0,
        closingNote: '',

        // Products
        products: [], loading: false, searchQ: '', categoryId: null,

        // Cart
        cart: [], selectedCartIdx: null,
        billDiscountValue: 0, billDiscountType: 'baht',
        vatMode: 'included', vatRate: 7,

        // Discount card (บัตรส่วนลด)
        discountCardCode: '', discountCardError: '', discountCardChecking: false, appliedCard: null,

        // Member points (แต้มสมาชิก)
        memberQuery: '', memberResults: [], member: null, redeemPoints: 0,
        pointValueBaht: {{ json_encode((float) $pointValueBaht) }}, lastEarnedPoints: 0,

        // Qty promotions (แคมเปญซื้อครบ แถม/ลด)
        promotions: [],

        // Customer
        customerQuery: '', customerId: null, customerName: '', customerResults: [],

        // Payment
        payModalOpen: false, method: 'cash', received: 0, receivedInput: '', processing: false,
        paymentRef: '', transferConfirmed: false,

        // Receipt
        receiptOpen: false, lastReceiptId: null, lastDocNumber: '', lastItems: [], lastTotal: 0, lastMethod: 'cash',
        lastCashierName: '', lastDateTime: '', vatRate: {{ json_encode((float) $vatRate) }},
        receiptSettings: { paperWidth: '80mm' },
        heldBills: [],

        // Clock
        clock: '', isFullscreen: false, canInstall: false, installPrompt: null,

        init() {
            this.loadReceiptSettings();
            this.loadHeldBills();
            this.loadProducts();
            this.loadPromotions();
            this.loadActiveShift();
            this.tickClock();
            setInterval(() => this.tickClock(), 1000);
            document.addEventListener('fullscreenchange', () => {
                this.isFullscreen = Boolean(document.fullscreenElement);
            });
            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.installPrompt = event;
                this.canInstall = true;
            });
            window.addEventListener('appinstalled', () => {
                this.installPrompt = null;
                this.canInstall = false;
            });
            this.registerServiceWorker();
        },

        tickClock() {
            this.clock = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        },

        updateBranchName() {
            const select = document.querySelector('select[x-model="branchId"]');
            if (!select) return;
            const text = select.options[select.selectedIndex]?.text || '';
            this.branchName = text.replace(/^[^-]+-\s*/, '') || this.branchName;
        },

        get cashierName() {
            const select = document.querySelector('select[x-model="cashierId"]');
            if (!select) return '';
            return select.options[select.selectedIndex]?.text || '';
        },

        get closeShiftDiff() {
            return this.roundMoney((Number(this.countedCash) || 0) - (Number(this.activeShift?.expected_cash) || 0));
        },

        async loadActiveShift() {
            if (!this.branchId) return;
            const qs = new URLSearchParams({ branch_id: this.branchId });
            if (this.cashierId) qs.set('cashier_id', this.cashierId);
            try {
                const res = await fetch(`{{ route('pos.shift.active') }}?${qs.toString()}`);
                const data = await res.json();
                this.activeShift = data.shift || null;
                if (this.activeShift) {
                    this.countedCash = Number(this.activeShift.expected_cash) || 0;
                }
            } catch (e) {
                this.activeShift = null;
            }
        },

        openShiftModal() {
            if (!this.canSell) {
                erpPopup('warning', 'เฉพาะแคชเชียร์เท่านั้นที่เปิดกะขายได้');
                return;
            }
            if (!this.cashierId) {
                erpPopup('warning', 'เลือกแคชเชียร์ก่อนเปิดกะ');
                return;
            }
            this.openingCash = 0;
            this.openingNote = '';
            this.shiftModalOpen = true;
        },

        openCloseShiftModal() {
            if (!this.activeShift) {
                this.openShiftModal();
                return;
            }
            this.countedCash = Number(this.activeShift.expected_cash) || 0;
            this.closingNote = '';
            this.closeShiftModalOpen = true;
        },

        async submitOpenShift() {
            if (!this.cashierId) {
                erpPopup('warning', 'เลือกแคชเชียร์ก่อนเปิดกะ');
                return;
            }
            this.shiftProcessing = true;
            try {
                const res = await fetch('{{ route('pos.shift.open') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        branch_id: this.branchId,
                        cashier_id: this.cashierId,
                        opening_cash: Number(this.openingCash) || 0,
                        opening_note: this.openingNote || null,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    erpPopup('error', data.message || 'เปิดกะไม่ได้');
                    return;
                }
                this.activeShift = data.shift;
                this.shiftModalOpen = false;
                erpToast('success', 'เปิดกะเรียบร้อย', { timer: 1400 });
            } catch (e) {
                erpPopup('error', 'เชื่อมต่อ server ไม่ได้');
            } finally {
                this.shiftProcessing = false;
            }
        },

        async submitCloseShift() {
            if (!this.activeShift) return;
            this.shiftProcessing = true;
            try {
                const res = await fetch('{{ route('pos.shift.close') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        shift_id: this.activeShift.id,
                        counted_cash: Number(this.countedCash) || 0,
                        closing_note: this.closingNote || null,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    erpPopup('error', data.message || 'ปิดกะไม่ได้');
                    return;
                }
                this.activeShift = null;
                this.closeShiftModalOpen = false;
                erpPopup('success', 'ปิดกะเรียบร้อย', 'ขาด/เกิน: ฿ ' + this.money(data.shift?.cash_difference || 0));
            } catch (e) {
                erpPopup('error', 'เชื่อมต่อ server ไม่ได้');
            } finally {
                this.shiftProcessing = false;
            }
        },

        get receiptPaperWidth() {
            return this.receiptSettings.paperWidth || '80mm';
        },

        get receiptScreenWidth() {
            return this.receiptPaperWidth === '58mm' ? '320px' : '420px';
        },

        loadReceiptSettings() {
            try {
                const saved = JSON.parse(localStorage.getItem('popstar_pos_receipt_settings') || '{}');
                this.receiptSettings = {
                    paperWidth: saved.paperWidth || '80mm',
                };
            } catch (e) {
                this.receiptSettings = { paperWidth: '80mm' };
            }
        },

        saveReceiptSettings() {
            localStorage.setItem('popstar_pos_receipt_settings', JSON.stringify(this.receiptSettings));
        },

        async openReceiptSettings() {
            const result = await Swal.fire({
                title: 'ตั้งค่าใบเสร็จ',
                input: 'select',
                inputOptions: {
                    '80mm': 'กระดาษ 80 มม. (พื้นฐาน)',
                    '58mm': 'กระดาษ 58 มม.',
                },
                inputValue: this.receiptPaperWidth,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                showCancelButton: true,
                background: '#1e293b',
                color: '#f1f5f9',
            });

            if (!result.isConfirmed) return;

            this.receiptSettings.paperWidth = result.value || '80mm';
            this.saveReceiptSettings();
            erpToast('success', 'ตั้งค่าใบเสร็จแล้ว', { timer: 1400 });
        },

        printReceipt() {
            window.print();
        },

        async toggleFullscreen() {
            try {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else {
                    await document.documentElement.requestFullscreen();
                }
            } catch (e) {
                erpToast('info', 'เบราว์เซอร์ไม่อนุญาตเต็มจอ', { timer: 1600 });
            }
        },

        async installApp() {
            if (!this.installPrompt) return;

            this.installPrompt.prompt();
            await this.installPrompt.userChoice;
            this.installPrompt = null;
            this.canInstall = false;
        },

        registerServiceWorker() {
            if (!('serviceWorker' in navigator)) return;

            window.addEventListener('load', () => {
                navigator.serviceWorker.register('{{ asset('pos-sw.js') }}', {
                    scope: '{{ rtrim(url('/'), '/') }}/',
                }).catch(() => {});
            });
        },

        async loadProducts() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.searchQ) params.set('q', this.searchQ);
            if (this.categoryId) params.set('category_id', this.categoryId);
            if (this.branchId) params.set('branch_id', this.branchId);
            const res = await fetch(`{{ route('pos.products') }}?${params}`);
            this.products = await res.json();
            this.loading = false;
        },

        parseScaleBarcode(raw) {
            const barcode = String(raw || '').replace(/\D/g, '');
            if (barcode.length < 12) return null;

            for (const rule of SCALE_BARCODE_RULES) {
                if (rule.length && barcode.length !== rule.length) continue;
                if (rule.prefix && !rule.prefix.some((p) => barcode.startsWith(p))) continue;

                const productCode = barcode.substring(rule.codeStart, rule.codeStart + rule.codeLength);
                const valueText = barcode.substring(rule.valueStart, rule.valueStart + rule.valueLength);
                const totalPrice = Number(valueText) / rule.divisor; // ราคารวมบนป้าย (บาท)

                if (productCode && Number.isFinite(totalPrice) && totalPrice > 0) {
                    return { barcode, productCode, totalPrice };
                }
            }

            return null;
        },

        isValidEan13(barcode) {
            if (!/^\d{13}$/.test(barcode)) return false;

            const digits = barcode.split('').map(Number);
            const checkDigit = digits.pop();
            const sum = digits.reduce((total, digit, index) => {
                return total + digit * (index % 2 === 0 ? 1 : 3);
            }, 0);

            return (10 - (sum % 10)) % 10 === checkDigit;
        },

        async fetchExactProducts(query) {
            const params = new URLSearchParams();
            params.set('q', query);
            params.set('exact', '1');
            if (this.categoryId) params.set('category_id', this.categoryId);
            if (this.branchId) params.set('branch_id', this.branchId);

            const res = await fetch(`{{ route('pos.products') }}?${params}`);
            return await res.json();
        },

        async scanSearch() {
            const scanned = String(this.searchQ || '').trim();
            if (!scanned) return;

            this.loading = true;

            try {
                let scaleBarcode = null;
                let matches = await this.fetchExactProducts(scanned);

                if (matches.length === 0) {
                    const candidate = this.parseScaleBarcode(scanned);
                    const digits = scanned.replace(/\D/g, '');
                    const shouldTryScale = candidate && (digits.length !== 13 || this.isValidEan13(digits));

                    if (shouldTryScale) {
                        scaleBarcode = candidate;
                        matches = await this.fetchExactProducts(scaleBarcode.productCode);
                    }
                }

                this.products = matches;

                if (matches.length > 0) {
                    // ป้ายชั่งฝัง "ราคารวม" — แปลงเป็นน้ำหนัก (กก.) ด้วยราคา/กก. เงินจึงตรงป้าย
                    let scaleQty = 1;
                    if (scaleBarcode) {
                        const unitPrice = Number(matches[0].pos_price ?? matches[0].default_price ?? 0);
                        scaleQty = unitPrice > 0
                            ? Math.round((scaleBarcode.totalPrice / unitPrice) * 10000) / 10000
                            : 1;
                    }
                    this.addToCart(matches[0], scaleQty, { scaleBarcode });
                    this.searchQ = '';
                    await this.loadProducts();
                } else {
                    erpToast('warning', 'ไม่พบสินค้า: ' + scanned, { timer: 1800 });
                }
            } finally {
                this.loading = false;
            }
        },

        selectCategory(id) {
            this.categoryId = id;
            this.loadProducts();
        },

        addToCart(product, qty = 1, meta = {}) {
            const addQty = Math.max(0.001, Number(qty) || 1);
            const existing = this.cart.find(i => i.id === product.id && !i.is_free_gift);
            if (existing) {
                existing.qty = Math.round((Number(existing.qty) + addQty) * 1000) / 1000;
                existing.last_scale_barcode = meta.scaleBarcode?.barcode || existing.last_scale_barcode || null;
                this.selectedCartIdx = this.cart.indexOf(existing);
            } else {
                this.cart.push({
                    uid: 'p' + product.id,
                    id: product.id,
                    sku_code: product.sku_code,
                    name_th: product.name_th,
                    qty: Math.round(addQty * 1000) / 1000,
                    unit_price: Number(product.pos_price ?? product.default_price) || 0,
                    unit_name: product.matched_barcode?.unit_name || null,
                    unit_factor: Number(product.matched_barcode?.unit_factor) || 1,
                    matched_barcode: product.matched_barcode?.barcode || null,
                    price_source: product.price_source || null,
                    discount_value: 0,
                    discount_type: 'baht',
                    last_scale_barcode: meta.scaleBarcode?.barcode || null,
                });
                this.selectedCartIdx = this.cart.length - 1;
            }
            this.applyQtyPromotions();

            this.$nextTick(() => {
                if (this.$refs.cartItems) {
                    this.$refs.cartItems.scrollTop = this.$refs.cartItems.scrollHeight;
                }
            });
        },

        changeQty(idx, delta) {
            const item = this.cart[idx];
            if (item.is_free_gift) return;
            item.qty = Math.max(0.001, (item.qty || 0) + delta);
            this.applyQtyPromotions();
        },

        removeItem(idx) {
            this.cart.splice(idx, 1);
            this.selectedCartIdx = null;
            this.applyQtyPromotions();
        },

        confirmLogout() {
            const warn = [];
            if (this.activeShift) warn.push('กะ ' + this.activeShift.shift_no + ' ยังเปิดอยู่ — จะยังเปิดค้างไว้');
            if (this.cart.length > 0) warn.push('มีรายการค้างในตะกร้า ' + this.cart.length + ' รายการ (จะหายไป)');
            erpConfirm('ต้องการออกจากระบบใช่หรือไม่?', warn.join(' · '), {
                icon: warn.length ? 'warning' : 'question',
                confirmButtonText: 'ออกจากระบบ',
            }).then((r) => { if (r.isConfirmed) this.$refs.logoutForm.submit(); });
        },

        async loadPromotions() {
            try {
                const res = await fetch(`{{ route('pos.promotions') }}?branch_id=${this.branchId}`);
                this.promotions = await res.json();
            } catch (e) {
                this.promotions = [];
            }
            this.applyQtyPromotions();
        },

        // Sync auto gift lines (ซื้อครบแถม): per campaign, gift qty follows
        // how many complete sets of the trigger product are in the cart.
        applyQtyPromotions() {
            const activeGiftUids = new Set();

            for (const promo of this.promotions) {
                if (promo.promo_type !== 'free_item' || !promo.free_product_id) continue;
                const boughtQty = this.cart
                    .filter(i => !i.is_free_gift && i.id === promo.product_id)
                    .reduce((s, i) => s + (Number(i.qty) || 0), 0);
                const sets = Math.floor(boughtQty / Number(promo.min_qty || 1));
                const freeQty = Math.round(sets * Number(promo.free_qty || 0) * 1000) / 1000;
                const uid = 'g' + promo.id;
                const existingIdx = this.cart.findIndex(i => i.uid === uid);

                if (freeQty > 0) {
                    activeGiftUids.add(uid);
                    if (existingIdx >= 0) {
                        this.cart[existingIdx].qty = freeQty;
                    } else {
                        this.cart.push({
                            uid,
                            id: promo.free_product_id,
                            sku_code: promo.free_product?.sku_code || '',
                            name_th: promo.free_product?.name_th || 'ของแถม',
                            qty: freeQty,
                            unit_price: 0,
                            discount_value: 0,
                            discount_type: 'baht',
                            is_free_gift: true,
                            promo_name: promo.name,
                        });
                    }
                } else if (existingIdx >= 0) {
                    this.cart.splice(existingIdx, 1);
                }
            }

            // drop gift lines whose campaign is no longer active
            for (let i = this.cart.length - 1; i >= 0; i--) {
                if (this.cart[i].is_free_gift && !activeGiftUids.has(this.cart[i].uid)) {
                    this.cart.splice(i, 1);
                }
            }
        },

        // ซื้อครบได้ส่วนลด: discount per complete set of the trigger product
        get promoDiscountTotal() {
            let total = 0;
            for (const promo of this.promotions) {
                if (promo.promo_type !== 'discount') continue;
                const lines = this.cart.filter(i => !i.is_free_gift && i.id === promo.product_id);
                if (lines.length === 0) continue;
                const qty = lines.reduce((s, i) => s + (Number(i.qty) || 0), 0);
                const sets = Math.floor(qty / Number(promo.min_qty || 1));
                if (sets <= 0) continue;
                const unitPrice = Number(lines[0].unit_price) || 0;
                total += promo.discount_type === 'percent'
                    ? sets * Number(promo.min_qty) * unitPrice * Number(promo.discount_value) / 100
                    : sets * Number(promo.discount_value);
            }
            return this.roundMoney(total);
        },

        productPromoLabel(p) {
            const promo = this.promotions.find(x => x.product_id === p.id);
            if (!promo) return '';
            const min = Number(promo.min_qty);
            if (promo.promo_type === 'free_item') return 'ซื้อ ' + min + ' แถม ' + Number(promo.free_qty);
            return 'ซื้อ ' + min + ' ลด ' + Number(promo.discount_value) + (promo.discount_type === 'percent' ? '%' : '฿');
        },

        resetCart() {
            this.cart = [];
            this.selectedCartIdx = null;
            this.customerQuery = '';
            this.customerId = null;
            this.customerName = '';
            this.billDiscountValue = 0;
            this.billDiscountType = 'baht';
            this.removeDiscountCard();
            this.clearMember();
        },

        clearCart() {
            this.cancelBill();
        },

        newBill() {
            this.receiptOpen = false;
            this.lastReceiptId = null;
            this.resetCart();
        },

        async cancelBill() {
            if (this.cart.length === 0) return;
            if (!this.canVoidBill) {
                erpPopup('warning', 'เฉพาะผู้จัดการหรือ IT เท่านั้นที่ยกเลิก/ล้างบิลได้');
                return;
            }

            const result = await Swal.fire({
                title: 'ยกเลิกบิลนี้?',
                text: 'รายการสินค้าที่กำลังขายจะถูกล้างออก',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ยกเลิกบิล',
                cancelButtonText: 'กลับไปขายต่อ',
                confirmButtonColor: '#dc2626',
                background: '#1e293b',
                color: '#f1f5f9',
            });

            if (!result.isConfirmed) return;

            this.resetCart();
            erpToast('success', 'ยกเลิกบิลแล้ว', { timer: 1300 });
        },

        async voidLastReceipt() {
            if (!this.canVoidBill || !this.lastReceiptId) {
                erpPopup('warning', 'เฉพาะผู้จัดการหรือ IT เท่านั้นที่ยกเลิกบิลได้');
                return;
            }

            const result = await Swal.fire({
                title: 'ยกเลิกบิลที่ออกแล้ว?',
                html: `<div style="font-size:13px;color:#cbd5e1">เลขที่ <b>${this.lastDocNumber}</b><br>ระบบจะ void บิล คืนสต็อก และบันทึก audit log</div>`,
                input: 'textarea',
                inputPlaceholder: 'ระบุเหตุผล เช่น ลูกค้าคืนสินค้า / ยิงผิดรายการ',
                inputValidator: (value) => !value || !value.trim() ? 'กรุณาระบุเหตุผล' : undefined,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันยกเลิกบิล',
                cancelButtonText: 'กลับ',
                confirmButtonColor: '#dc2626',
            });

            if (!result.isConfirmed) return;

            try {
                const res = await fetch(`{{ url('/pos/receipts') }}/${this.lastReceiptId}/void`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ reason: result.value.trim() }),
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    erpPopup('error', data.message || 'ยกเลิกบิลไม่ได้');
                    return;
                }
                this.receiptOpen = false;
                this.lastReceiptId = null;
                this.loadActiveShift();
                erpToast('success', data.message || 'ยกเลิกบิลเรียบร้อย', { timer: 1600 });
            } catch (e) {
                erpPopup('error', 'เชื่อมต่อ server ไม่ได้');
            }
        },

        loadHeldBills() {
            try {
                this.heldBills = JSON.parse(localStorage.getItem('popstar_pos_held_bills') || '[]');
            } catch (e) {
                this.heldBills = [];
            }
        },

        saveHeldBills() {
            localStorage.setItem('popstar_pos_held_bills', JSON.stringify(this.heldBills));
        },

        async holdBill() {
            if (this.cart.length === 0) return;

            const defaultName = this.customerName || 'บิล ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
            const result = await Swal.fire({
                title: 'พักบิล',
                input: 'text',
                inputLabel: 'ชื่อบิล / โต๊ะ / ลูกค้า',
                inputValue: defaultName,
                confirmButtonText: 'พักบิล',
                cancelButtonText: 'ยกเลิก',
                showCancelButton: true,
                background: '#1e293b',
                color: '#f1f5f9',
            });

            if (!result.isConfirmed) return;

            this.heldBills.unshift({
                id: Date.now(),
                label: (result.value || defaultName).trim(),
                createdAt: new Date().toISOString(),
                cart: JSON.parse(JSON.stringify(this.cart)),
                customerQuery: this.customerQuery,
                customerId: this.customerId,
                customerName: this.customerName,
                member: this.member ? JSON.parse(JSON.stringify(this.member)) : null,
                redeemPoints: this.redeemPoints,
                billDiscountValue: this.billDiscountValue,
                billDiscountType: this.billDiscountType,
                vatMode: this.vatMode,
                appliedCard: this.appliedCard ? JSON.parse(JSON.stringify(this.appliedCard)) : null,
            });
            this.saveHeldBills();
            this.resetCart();

            erpToast('success', 'พักบิลไว้ในเครื่องนี้แล้ว', { timer: 1600 });
        },

        async recallBill() {
            this.loadHeldBills();

            if (this.heldBills.length === 0) {
                erpToast('info', 'ยังไม่มีบิลที่พักไว้', { timer: 1500 });
                return;
            }

            const inputOptions = {};
            this.heldBills.forEach((bill) => {
                const qty = bill.cart.reduce((sum, item) => sum + (Number(item.qty) || 0), 0);
                const total = bill.cart.reduce((sum, item) => sum + ((Number(item.qty) || 0) * (Number(item.unit_price) || 0)), 0);
                inputOptions[bill.id] = `${bill.label} - ${bill.cart.length} รายการ / ${this.money(qty)} ชิ้น / ฿${this.money(total)}`;
            });

            const result = await Swal.fire({
                title: 'เรียกบิลที่พักไว้',
                input: 'select',
                inputOptions,
                confirmButtonText: 'เรียกบิล',
                cancelButtonText: 'ยกเลิก',
                showCancelButton: true,
                background: '#1e293b',
                color: '#f1f5f9',
            });

            if (!result.isConfirmed) return;

            if (this.cart.length > 0) {
                const replace = await Swal.fire({
                    title: 'แทนที่บิลปัจจุบัน?',
                    text: 'บิลที่กำลังขายอยู่จะถูกยกเลิกและแทนด้วยบิลที่เรียกคืน',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'แทนที่',
                    cancelButtonText: 'ยกเลิก',
                    background: '#1e293b',
                    color: '#f1f5f9',
                });
                if (!replace.isConfirmed) return;
            }

            const billId = Number(result.value);
            const bill = this.heldBills.find((item) => Number(item.id) === billId);
            if (!bill) return;

            this.cart = JSON.parse(JSON.stringify(bill.cart));
            this.selectedCartIdx = this.cart.length ? this.cart.length - 1 : null;
            this.customerQuery = bill.customerQuery || '';
            this.customerId = bill.customerId || null;
            this.customerName = bill.customerName || '';
            this.member = bill.member || null;
            this.memberQuery = bill.member?.name || '';
            this.redeemPoints = bill.redeemPoints || 0;
            this.billDiscountValue = bill.billDiscountValue || 0;
            this.billDiscountType = bill.billDiscountType || 'baht';
            this.vatMode = bill.vatMode || 'included';
            this.appliedCard = bill.appliedCard || null;
            this.heldBills = this.heldBills.filter((item) => Number(item.id) !== billId);
            this.saveHeldBills();
        },

        editBill() {
            if (this.cart.length === 0) return;

            if (this.selectedCartIdx === null || this.selectedCartIdx >= this.cart.length) {
                this.selectedCartIdx = this.cart.length - 1;
            }

            this.$nextTick(() => {
                if (this.$refs.cartItems) {
                    const active = this.$refs.cartItems.querySelector('.cart-item.active');
                    active?.scrollIntoView({ block: 'nearest' });
                }
            });

            erpToast('info', 'เลือกสินค้าแล้ว แก้จำนวน ราคา หรือส่วนลดได้ที่รายการซ้าย', { timer: 2000 });
        },

        get totalQty() {
            return this.cart.reduce((s, i) => s + (Number(i.qty) || 0), 0);
        },

        roundMoney(value) {
            return Math.round((Number(value) || 0) * 100) / 100;
        },

        lineGross(item) {
            return this.roundMoney((Number(item.qty) || 0) * (Number(item.unit_price) || 0));
        },

        itemDiscountAmount(item) {
            const gross = this.lineGross(item);
            const value = Math.max(0, Number(item.discount_value) || 0);
            const discount = item.discount_type === 'percent' ? gross * value / 100 : value;
            return this.roundMoney(Math.min(gross, discount));
        },

        lineNet(item) {
            return this.roundMoney(this.lineGross(item) - this.itemDiscountAmount(item));
        },

        get subtotalAmount() {
            return this.roundMoney(this.cart.reduce((s, i) => s + this.lineGross(i), 0));
        },

        get itemDiscountTotal() {
            return this.roundMoney(this.cart.reduce((s, i) => s + this.itemDiscountAmount(i), 0));
        },

        get billDiscountAmount() {
            const base = Math.max(0, this.subtotalAmount - this.itemDiscountTotal);
            const value = Math.max(0, Number(this.billDiscountValue) || 0);
            const discount = this.billDiscountType === 'percent' ? base * value / 100 : value;
            return this.roundMoney(Math.min(base, discount));
        },

        get cardDiscountAmount() {
            if (!this.appliedCard) return 0;
            const base = Math.max(0, this.subtotalAmount - this.itemDiscountTotal - this.billDiscountAmount);
            if (this.appliedCard.min_amount && base < Number(this.appliedCard.min_amount)) return 0;
            let discount = this.appliedCard.discount_type === 'percent'
                ? base * Number(this.appliedCard.discount_value) / 100
                : Number(this.appliedCard.discount_value);
            if (this.appliedCard.max_discount_amount) {
                discount = Math.min(discount, Number(this.appliedCard.max_discount_amount));
            }
            return this.roundMoney(Math.min(base, discount));
        },

        async applyDiscountCard() {
            const code = this.discountCardCode.trim();
            if (!code) return;
            this.discountCardChecking = true;
            this.discountCardError = '';
            try {
                const base = Math.max(0, this.subtotalAmount - this.itemDiscountTotal - this.billDiscountAmount);
                const res = await fetch('{{ route('discount-cards.check') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ card_code: code, subtotal: base }),
                });
                const data = await res.json();
                if (!data.success) {
                    this.discountCardError = data.message || 'ใช้บัตรนี้ไม่ได้';
                    this.appliedCard = null;
                    return;
                }
                this.appliedCard = data;
                this.discountCardCode = '';
            } finally {
                this.discountCardChecking = false;
            }
        },

        removeDiscountCard() {
            this.appliedCard = null;
            this.discountCardCode = '';
            this.discountCardError = '';
        },

        async searchMembers() {
            if (this.memberQuery.length < 1) {
                this.memberResults = [];
                return;
            }
            const res = await fetch(`{{ route('pos.members') }}?q=${encodeURIComponent(this.memberQuery)}`);
            this.memberResults = await res.json();
        },

        selectMember(m) {
            this.member = m;
            this.memberQuery = '';
            this.memberResults = [];
            this.redeemPoints = 0;
        },

        clearMember() {
            this.member = null;
            this.memberQuery = '';
            this.memberResults = [];
            this.redeemPoints = 0;
        },

        get pointsDiscountAmount() {
            if (!this.member || this.pointValueBaht <= 0) return 0;
            const pts = Math.max(0, Math.min(Number(this.redeemPoints) || 0, Number(this.member.points) || 0));
            const base = Math.max(0, this.subtotalAmount - this.itemDiscountTotal - this.billDiscountAmount - this.cardDiscountAmount);
            return this.roundMoney(Math.min(base, pts * this.pointValueBaht));
        },

        // Points actually consumed after the discount is capped by the bill.
        get effectiveRedeemPoints() {
            if (this.pointValueBaht <= 0) return 0;
            return Math.round(this.pointsDiscountAmount / this.pointValueBaht * 10000) / 10000;
        },

        get totalDiscount() {
            return this.roundMoney(this.itemDiscountTotal + this.billDiscountAmount + this.cardDiscountAmount + this.pointsDiscountAmount + this.promoDiscountTotal);
        },

        get netBeforeTaxDisplay() {
            return this.roundMoney(Math.max(0, this.subtotalAmount - this.totalDiscount));
        },

        get vatAmount() {
            if (this.vatMode === 'excluded') {
                return this.roundMoney(this.netBeforeTaxDisplay * this.vatRate / 100);
            }

            return this.roundMoney(this.totalAmount * this.vatRate / (100 + this.vatRate));
        },

        get beforeVatAmount() {
            return this.roundMoney(this.totalAmount - this.vatAmount);
        },

        get totalAmount() {
            const net = this.netBeforeTaxDisplay;
            if (this.vatMode === 'excluded') {
                return this.roundMoney(net + this.vatAmount);
            }

            return this.roundMoney(net);
        },

        money(v) {
            return Number(v || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // ส่วน VAT ที่ถอดออกจากราคารวม (ราคา POS รวม VAT แล้ว)
        vatPortion(total) {
            const t = Number(total) || 0;
            return Math.round((t - t * 100 / (100 + this.vatRate)) * 100) / 100;
        },

        setMethod(method) {
            this.method = method;
            this.setReceivedCash(this.totalAmount);

            if (method !== 'transfer') {
                this.transferConfirmed = false;
                this.paymentRef = '';
                return;
            }

            this.$nextTick(() => renderQR(this.totalAmount));
        },

        setReceivedCash(amount) {
            this.received = Math.max(0, Math.round((Number(amount) || 0) * 100) / 100);
            this.receivedInput = this.received > 0 ? String(this.received) : '';
        },

        appendCashDigit(value) {
            let input = String(this.receivedInput || '');

            if (value === '.') {
                if (input.includes('.')) return;
                input = input || '0';
            }

            if (input === '0' && value !== '.') {
                input = '';
            }

            input += value;

            const parts = input.split('.');
            if (parts[1]?.length > 2) {
                input = parts[0] + '.' + parts[1].slice(0, 2);
            }

            if (input.length > 10) return;

            this.receivedInput = input;
            this.received = Number(input) || 0;
        },

        backspaceCash() {
            this.receivedInput = String(this.receivedInput || '').slice(0, -1);
            this.received = Number(this.receivedInput) || 0;
        },

        clearReceivedCash() {
            this.receivedInput = '';
            this.received = 0;
        },

        get cashChangeAmount() {
            return Math.max(0, this.roundMoney((Number(this.received) || 0) - this.totalAmount));
        },

        get cashShortAmount() {
            return Math.max(0, this.roundMoney(this.totalAmount - (Number(this.received) || 0)));
        },

        openPayment(method = null) {
            if (!this.canSell) {
                erpPopup('warning', 'เฉพาะแคชเชียร์เท่านั้นที่คิดเงินได้ - คุณเปิดดูได้อย่างเดียว');
                return;
            }
            if (!this.activeShift) {
                this.openShiftModal();
                erpToast('info', 'เปิดกะก่อนขาย', { timer: 1600 });
                return;
            }
            this.payModalOpen = true;
            this.setMethod(method || this.method);
        },

        markTransferPaid() {
            this.transferConfirmed = true;
        },

        get canConfirm() {
            if (this.cart.length === 0) return false;
            if (this.method === 'cash') return Number(this.received || 0) >= this.totalAmount;
            if (this.method === 'transfer') return this.transferConfirmed;
            return true;
        },

        get confirmLabel() {
            if (this.method === 'transfer' && !this.transferConfirmed) return 'รอตรวจเงินเข้า';
            return 'ยืนยันชำระ';
        },

        paymentMethodLabel(method) {
            const labels = {
                cash: 'ชำระด้วยเงินสด',
                transfer: 'ชำระด้วยการโอนเงิน/QR',
                credit_card: 'ชำระด้วยบัตร',
                cheque: 'ชำระด้วยเช็ค',
            };

            return labels[method] || 'ชำระเงิน';
        },

        checkoutItems() {
            const baseLines = this.cart.map(item => ({
                item,
                qty: Math.max(0.001, Number(item.qty) || 0.001),
                lineNet: this.lineNet(item),
            }));
            const baseTotal = baseLines.reduce((sum, line) => sum + line.lineNet, 0);

            const billAndCardDiscount = this.billDiscountAmount + this.cardDiscountAmount + this.pointsDiscountAmount + this.promoDiscountTotal;

            return baseLines.map(line => {
                const billShare = baseTotal > 0 ? billAndCardDiscount * (line.lineNet / baseTotal) : 0;
                let payableLine = Math.max(0, line.lineNet - billShare);

                if (this.vatMode === 'excluded') {
                    payableLine = payableLine * (1 + this.vatRate / 100);
                }

                return {
                    product_id: line.item.id,
                    qty: line.qty,
                    unit_price: this.roundMoney(payableLine / line.qty),
                };
            });
        },

        async searchCustomers() {
            if (this.customerQuery.length < 1) {
                this.customerResults = [];
                return;
            }
            const res = await fetch(`{{ route('search.customers') }}?q=${encodeURIComponent(this.customerQuery)}`);
            this.customerResults = await res.json();
        },

        selectCustomer(c) {
            this.customerId = c.id;
            this.customerName = c.name_th;
            this.customerQuery = c.name_th;
            this.customerResults = [];
        },

        clearCustomer() {
            this.customerId = null;
            this.customerName = '';
            this.customerQuery = '';
        },

        async copyQrPayload() {
            if (!lastQrPayload) {
                renderQR(this.totalAmount);
            }
            try {
                await navigator.clipboard.writeText(lastQrPayload);
                erpToast('success', 'คัดลอก QR payload แล้ว', { timer: 1600 });
            } catch (e) {
                erpPopup('info', 'Payload', lastQrPayload);
            }
        },

        async processPayment() {
            if (this.cart.length === 0) return;
            this.processing = true;

            const payload = {
                branch_id: this.branchId,
                customer_id: this.customerId || null,
                member_id: this.member?.id || null,
                shift_id: this.activeShift?.id || null,
                cashier_id: this.cashierId || null,
                redeem_points: this.effectiveRedeemPoints,
                method: this.method,
                payment_ref: this.paymentRef || null,
                payment_confirmed: this.method !== 'transfer' || this.transferConfirmed,
                cash_received: this.method === 'cash' ? this.received : null,
                change_amount: this.method === 'cash' ? this.cashChangeAmount : null,
                discount_amount: this.totalDiscount,
                discount_card_code: this.appliedCard?.card_code || null,
                vat_amount: this.vatAmount,
                vat_mode: this.vatMode,
                items: this.checkoutItems(),
                _token: document.querySelector('meta[name=csrf-token]').content,
            };

            try {
                const res = await fetch('{{ route('pos.checkout') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': payload._token },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (data.success) {
                    this.lastReceiptId = data.receipt_id || null;
                    this.lastDocNumber = data.receipt_no || data.doc_number;
                    this.lastItems = this.cart.map((item, index) => ({
                        ...item,
                        unit_price: payload.items[index]?.unit_price ?? item.unit_price,
                    }));
                    this.lastTotal = this.totalAmount;
                    this.lastMethod = this.method;
                    this.lastEarnedPoints = Number(data.earned_points) || 0;
                    const cashierSel = this.$refs.cashierSelect;
                    this.lastCashierName = this.lockedCashierName
                        || (cashierSel && cashierSel.selectedIndex > 0 ? cashierSel.options[cashierSel.selectedIndex].text : '');
                    this.lastDateTime = new Date().toLocaleString('th-TH', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
                    this.payModalOpen = false;
                    this.receiptOpen = true;
                    this.paymentRef = '';
                    this.transferConfirmed = false;
                    this.loadActiveShift();
                } else {
                    erpPopup('error', data.message || 'เกิดข้อผิดพลาด');
                }
            } catch(e) {
                erpPopup('error', 'เชื่อมต่อ server ไม่ได้');
            }
            this.processing = false;
        },
    };
}
</script>
</body>
</html>
