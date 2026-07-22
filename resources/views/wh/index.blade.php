<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-jet-j-red.png') }}?v={{ filemtime(public_path('images/logo-jet-j-red.png')) }}">
    <title>คลังมือถือ — JET ERP</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <script defer src="{{ asset('vendor/alpinejs/alpine.min.js') }}"></script>
    <style>
        /* ธีมขาว-ฟ้าเดียวกับ ERP (FlowAccount reskin) — หน้าเดียวจบสำหรับมือถือ/PDA */
        :root {
            --bg: #eef3f8; --panel: #fff; --panel-2: #f4f8fb; --line: #dfe8f0;
            --ink: #22384a; --ink-soft: #7b91a4;
            --blue: #1a9bdc; --blue-deep: #1585c0;
            --green: #5fc558; --green-deep: #46ad3f;
            --red: #d9534f; --amber: #c07f00;
            --shadow: 0 1px 3px rgba(23,64,94,.08), 0 4px 14px rgba(23,64,94,.06);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body { background: var(--bg); color: var(--ink); font-family: "Segoe UI", "Sarabun", "Leelawadee UI", Tahoma, sans-serif; font-size: 15px; }
        [x-cloak] { display: none !important; }
        button { font-family: inherit; cursor: pointer; }
        input, select, textarea { font-family: inherit; font-size: 16px; } /* กัน iOS zoom */

        .topbar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; gap: 10px; padding: 10px 14px;
                  background: linear-gradient(135deg, var(--blue), var(--blue-deep)); color: #fff; box-shadow: var(--shadow); }
        .topbar img { width: 34px; height: 34px; border-radius: 9px; }
        .topbar .tt { flex: 1; min-width: 0; }
        .topbar .tt b { display: block; font-size: 16px; }
        .topbar .tt span { display: block; font-size: 12px; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .topbar a.full { color: #fff; font-size: 12.5px; text-decoration: none; border: 1px solid rgba(255,255,255,.4); padding: 6px 10px; border-radius: 8px; white-space: nowrap; }

        main { padding: 12px 12px 84px; max-width: 640px; margin: 0 auto; }

        .scanbar { display: flex; gap: 8px; margin-bottom: 10px; }
        .scanbar input { flex: 1; min-width: 0; height: 52px; padding: 10px 14px; border: 2px dashed #b7cddd; border-radius: 12px;
                         background: var(--panel); outline: none; font-weight: 600; }
        .scanbar input:focus { border-color: var(--blue); border-style: solid; box-shadow: 0 0 0 3px rgba(26,155,220,.15); }
        .scanbar button { width: 52px; height: 52px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); font-size: 20px; color: var(--blue-deep); }

        .searchwrap { position: relative; margin-bottom: 10px; }
        .searchwrap input { width: 100%; height: 44px; padding: 8px 13px; border: 1px solid var(--line); border-radius: 10px; background: var(--panel); outline: none; }
        .searchwrap input:focus { border-color: var(--blue); }
        .suggest { position: absolute; left: 0; right: 0; top: 46px; z-index: 15; background: #fff; border: 1px solid var(--line); border-radius: 10px;
                   box-shadow: var(--shadow); max-height: 260px; overflow: auto; }
        .suggest button { display: flex; justify-content: space-between; gap: 10px; width: 100%; padding: 10px 12px; border: 0; border-bottom: 1px solid var(--panel-2); background: #fff; text-align: left; }
        .suggest button:active { background: #eaf6ff; }
        .suggest .sku { color: var(--ink-soft); font-size: 12.5px; white-space: nowrap; }

        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 14px; margin-bottom: 10px; box-shadow: var(--shadow); }
        .card.found { background: #f2fbf1; border-color: rgba(70,173,63,.4); }
        .pname { font-weight: 800; font-size: 16px; }
        .psub { color: var(--ink-soft); font-size: 12.5px; margin-top: 2px; }
        .chip { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #eaf6ff; color: var(--blue-deep); font-size: 12.5px; font-weight: 700; }
        .chip.warn { background: #fdf1de; color: var(--amber); }

        .frow { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .frow.one { grid-template-columns: 1fr; }
        .frow label { display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--ink-soft); }
        .frow label b.req { color: var(--red); }
        .frow input, .frow select { height: 46px; padding: 8px 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--panel-2); outline: none; width: 100%; }
        .frow input:focus, .frow select:focus { border-color: var(--blue); background: #fff; }
        .qtyline { display: flex; align-items: center; gap: 8px; }
        .qtyline button { width: 46px; height: 46px; border: 1px solid var(--line); border-radius: 10px; background: #fff; font-size: 20px; font-weight: 800; color: var(--blue-deep); }
        .qtyline input { text-align: center; font-weight: 800; font-size: 18px; }
        .hint { font-size: 12px; color: var(--ink-soft); margin-top: 6px; }

        .btnrow { display: flex; gap: 10px; margin-top: 12px; }
        .btn { flex: 1; min-height: 50px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel-2); color: var(--ink); font-size: 16px; font-weight: 700; }
        .btn.primary { background: linear-gradient(135deg, var(--green), var(--green-deep)); border: 0; color: #fff; font-weight: 800; }
        .btn.blue { background: linear-gradient(135deg, var(--blue), var(--blue-deep)); border: 0; color: #fff; font-weight: 800; }
        .btn:disabled { opacity: .45; }

        .cart .item { display: grid; grid-template-columns: 1fr auto 34px; gap: 8px; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--panel-2); }
        .cart .item:last-child { border-bottom: 0; }
        .cart .nm { font-weight: 700; font-size: 13.5px; line-height: 1.25; }
        .cart .dt { color: var(--ink-soft); font-size: 12px; margin-top: 2px; }
        .cart .amt { font-weight: 800; color: var(--blue-deep); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .cart .del { width: 32px; height: 32px; border: 0; border-radius: 8px; background: none; color: #b8c7d4; font-size: 15px; }
        .cart .del:active { background: var(--red); color: #fff; }
        .total { display: flex; justify-content: space-between; align-items: baseline; padding-top: 10px; font-weight: 800; font-size: 17px; }
        .total span:last-child { color: var(--blue-deep); font-size: 22px; font-variant-numeric: tabular-nums; }

        .seg { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .seg button { min-height: 42px; border: 1px solid var(--line); border-radius: 10px; background: #fff; font-weight: 700; color: var(--ink-soft); }
        .seg button.on { background: linear-gradient(135deg, var(--blue), var(--blue-deep)); color: #fff; border: 0; }

        .polist button.po { display: block; width: 100%; text-align: left; background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin-bottom: 8px; box-shadow: var(--shadow); }
        .polist .no { font-weight: 800; color: var(--blue-deep); }
        .polist .sub { color: var(--ink-soft); font-size: 12.5px; margin-top: 3px; }
        .poline { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--panel-2); }
        .poline .prog { font-variant-numeric: tabular-nums; font-weight: 800; color: var(--amber); white-space: nowrap; }
        .poline.done .prog { color: var(--green-deep); }
        .poline.over .prog { color: var(--red); }

        .stockrow { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--panel-2); font-size: 14px; }
        .stockrow b { font-variant-numeric: tabular-nums; }
        .stockrow.neg b { color: var(--red); }

        .empty { text-align: center; color: var(--ink-soft); padding: 26px 10px; font-size: 13.5px; }
        .err { color: var(--red); font-size: 13px; margin-top: 8px; font-weight: 600; }

        .bottomnav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; display: grid; grid-template-columns: repeat(var(--tabs, 3), 1fr);
                     background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -4px 14px rgba(23,64,94,.08); }
        .bottomnav button { padding: 8px 4px calc(8px + env(safe-area-inset-bottom)); border: 0; background: none; color: var(--ink-soft); font-size: 12px; font-weight: 700; }
        .bottomnav button i { display: block; font-size: 21px; margin-bottom: 1px; }
        .bottomnav button.on { color: var(--blue-deep); }

        .overlay { position: fixed; inset: 0; z-index: 40; background: rgba(15,35,50,.5); display: grid; place-items: center; padding: 18px; }
        .sheet { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; padding: 22px; text-align: center; }
        .sheet .ok-ic { font-size: 52px; color: var(--green-deep); }
        .sheet h3 { margin: 6px 0 4px; }
        .sheet .doc { font-size: 20px; font-weight: 900; color: var(--blue-deep); }
        .camwrap video { width: 100%; border-radius: 12px; background: #000; }
    </style>
</head>
<body>
@php $lockedBranch = $branches->count() === 1 ? $branches->first() : null; @endphp
<div x-data="whApp()" x-init="init()">
    <header class="topbar">
        <img src="{{ asset('images/logo-jet-erp-mark.svg') }}" alt="logo">
        <div class="tt">
            <b>คลังมือถือ</b>
            <span><span x-text="branchName()"></span> · {{ auth()->user()->name ?? auth()->user()->username ?? '' }}</span>
        </div>
        <a class="full" href="{{ route('dashboard') }}">ระบบเต็ม</a>
    </header>

    <main>
        {{-- เลือกสาขา (เฉพาะ user ที่ไม่ถูกผูกสาขา) --}}
        @if(!$lockedBranch)
            <div class="card" style="padding:10px 14px">
                <label style="font-size:12.5px;color:var(--ink-soft)">สาขาที่ทำงาน
                    <select x-model.number="branchId" style="width:100%;height:44px;margin-top:4px;border:1px solid var(--line);border-radius:10px;background:var(--panel-2)">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name_th }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        @endif

        {{-- ================= แท็บ: รับเข้า (ใบซื้ออิสระ) ================= --}}
        <section x-show="tab === 'receive'" x-cloak>
            <form class="scanbar" @submit.prevent="scan('receive')">
                <input x-ref="scanReceive" x-model="scanCode" placeholder="ยิงบาร์โค้ด หรือพิมพ์ SKU แล้ว Enter" autocomplete="off" autofocus>
                <button type="button" x-show="cameraOk" @click="openCamera('receive')" title="สแกนด้วยกล้อง"><i class="bi bi-camera-fill"></i></button>
            </form>
            <div class="searchwrap">
                <input x-model="q" @input.debounce.350ms="searchProducts()" placeholder="หรือค้นหาชื่อสินค้า…" autocomplete="off">
                <div class="suggest" x-show="suggests.length" x-cloak>
                    <template x-for="s in suggests" :key="s.id">
                        <button type="button" @click="pickProduct(s.id, 'receive')">
                            <span x-text="s.name_th"></span><span class="sku" x-text="s.sku_code"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- ฟอร์มสินค้าที่สแกนเจอ (สไตล์เดียวกับโพสต์ต้นแบบ) --}}
            <div class="card found" x-show="cur" x-cloak>
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:start">
                    <div>
                        <div class="pname" x-text="cur?.name_th"></div>
                        <div class="psub"><span x-text="cur?.sku_code"></span>
                            <span class="chip" x-show="curOnHand !== null" x-text="'คงเหลือ ' + fmtQty(curOnHand)"></span>
                        </div>
                    </div>
                    <button class="del" style="border:0;background:none;font-size:18px;color:var(--ink-soft)" @click="clearCur()">✕</button>
                </div>
                <div class="frow">
                    <label>จำนวน (<span x-text="cur?.unit_label"></span>)
                        <div class="qtyline">
                            <button type="button" @click="qty = Math.max(1, (+qty || 1) - 1)">−</button>
                            <input x-model="qty" inputmode="decimal">
                            <button type="button" @click="qty = (+qty || 0) + 1">+</button>
                        </div>
                    </label>
                    <label>ราคา/<span x-text="cur?.unit_label"></span> (บาท)
                        <input x-model="price" inputmode="decimal">
                    </label>
                </div>
                <div class="hint" x-show="cur && cur.unit_factor > 1"
                     x-text="'บาร์โค้ดนี้ = 1 ' + cur?.unit_label + ' × ' + fmtQty(cur?.unit_factor) + ' ' + cur?.base_unit_label + ' → รับเข้า ' + fmtQty(baseQty()) + ' ' + cur?.base_unit_label"></div>
                <div class="frow">
                    <label>เลขลอต (ถ้ามี)
                        <input x-model="lot" autocomplete="off">
                    </label>
                    <label>วันผลิต
                        <input x-model="manufacture" @change="calculateExpiry()" type="date">
                    </label>
                    <label>วันหมดอายุ <b class="req" x-show="cur?.tracks_expiry">*</b>
                        <input x-model="expiry" type="date">
                    </label>
                </div>
                <p class="err" x-show="cur?.tracks_expiry && !expiry" x-cloak>สินค้านี้ควบคุมวันหมดอายุ — ต้องระบุก่อนเพิ่ม</p>
                <div class="btnrow">
                    <button class="btn" @click="clearCur()">ยกเลิก</button>
                    <button class="btn primary" :disabled="!canAdd()" @click="addToCart()">+ เพิ่มเข้ารายการ</button>
                </div>
            </div>

            {{-- รายการรอรับเข้า --}}
            <div class="card cart">
                <b>รายการรับเข้า (<span x-text="cart.length"></span>)</b>
                <div class="empty" x-show="!cart.length">ยังไม่มีรายการ — ยิงบาร์โค้ดด้านบน</div>
                <template x-for="(it, i) in cart" :key="i">
                    <div class="item">
                        <div>
                            <div class="nm" x-text="it.name_th"></div>
                            <div class="dt" x-text="fmtQty(it.qty) + ' ' + it.unit_label + ' × ' + fmtMoney(it.price) + (it.lot ? ' · ลอต ' + it.lot : '') + (it.expiry ? ' · หมดอายุ ' + it.expiry : '')"></div>
                        </div>
                        <span class="amt" x-text="fmtMoney(it.qty * it.price)"></span>
                        <button class="del" @click="cart.splice(i, 1)">✕</button>
                    </div>
                </template>
                <div class="total" x-show="cart.length"><span>รวมทั้งใบ</span><span x-text="'฿' + fmtMoney(cartTotal())"></span></div>
            </div>

            {{-- ซัพพลายเออร์ + ชำระ + บันทึก --}}
            <div class="card" x-show="cart.length" x-cloak>
                <div class="searchwrap" style="margin-bottom:8px">
                    <input x-model="supQ" @input.debounce.350ms="searchSuppliers()"
                           :placeholder="supplier ? '' : 'ค้นหาซัพพลายเออร์…'" autocomplete="off">
                    <div class="suggest" x-show="supSuggests.length" x-cloak>
                        <template x-for="s in supSuggests" :key="s.id">
                            <button type="button" @click="supplier = s; supQ = ''; supSuggests = []">
                                <span x-text="s.name_th"></span><span class="sku" x-text="s.code"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div x-show="supplier" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <span class="chip" x-text="'ซัพพลายเออร์: ' + (supplier?.name_th ?? '')"></span>
                    <button class="del" style="border:0;background:none;color:var(--ink-soft)" @click="supplier = null">เปลี่ยน</button>
                </div>
                <div class="seg" style="margin-bottom:10px">
                    <button :class="{ on: !isCredit }" @click="isCredit = false">ซื้อสด</button>
                    <button :class="{ on: isCredit }" @click="isCredit = true">ซื้อเชื่อ (ลงเจ้าหนี้)</button>
                </div>
                <input x-model="remark" placeholder="หมายเหตุ (ถ้ามี)" style="width:100%;height:44px;padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:var(--panel-2);margin-bottom:10px">
                <p class="err" x-show="submitError" x-text="submitError"></p>
                <button class="btn blue" style="width:100%" :disabled="!supplier || submitting" @click="submitReceive()">
                    <span x-text="submitting ? 'กำลังบันทึก…' : ('บันทึกรับเข้า → ออกใบซื้อ (' + cart.length + ' รายการ)')"></span>
                </button>
            </div>
        </section>

        {{-- ================= แท็บ: รับตามใบสั่งซื้อ ================= --}}
        <section x-show="tab === 'po'" x-cloak>
            <template x-if="!poCur">
                <div>
                    <div class="polist">
                        <div class="empty" x-show="!poLoading && !poList.length">ไม่มีใบสั่งซื้อค้างรับของ</div>
                        <div class="empty" x-show="poLoading">กำลังโหลด…</div>
                        <template x-for="po in poList" :key="po.id">
                            <button class="po" @click="openPo(po.id)">
                                <div style="display:flex;justify-content:space-between"><span class="no" x-text="po.doc_number"></span><b x-text="'฿' + fmtMoney(po.total_amount)"></b></div>
                                <div class="sub" x-text="(po.supplier ?? '-') + ' · ' + po.item_count + ' รายการ · ' + (po.doc_date ?? '') + (po.is_credit ? ' · เชื่อ' : ' · สด')"></div>
                            </button>
                        </template>
                    </div>
                    <button class="btn" style="width:100%" @click="loadPos()">↻ โหลดรายการใหม่</button>
                </div>
            </template>

            <template x-if="poCur">
                <div>
                    <div class="card">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <div class="pname" x-text="poCur.doc_number"></div>
                                <div class="psub" x-text="poCur.supplier"></div>
                            </div>
                            <button class="btn" style="flex:0 0 auto;min-height:40px;padding:0 14px" @click="poCur = null">← กลับ</button>
                        </div>
                    </div>
                    <form class="scanbar" @submit.prevent="scan('po')">
                        <input x-ref="scanPo" x-model="scanCode" placeholder="ยิงบาร์โค้ดเช็คของตามใบ…" autocomplete="off">
                        <button type="button" x-show="cameraOk" @click="openCamera('po')"><i class="bi bi-camera-fill"></i></button>
                    </form>
                    <p class="err" x-show="poScanError" x-text="poScanError"></p>
                    <div class="card">
                        <template x-for="line in poCur.items" :key="line.product_id">
                            <div class="poline" :class="{ done: line.scanned >= line.outstanding_qty, over: line.scanned > line.outstanding_qty }">
                                <div>
                                    <div class="nm" style="font-weight:700;font-size:13.5px" x-text="line.name_th"></div>
                                    <div class="dt" style="color:var(--ink-soft);font-size:12px" x-text="line.sku_code"></div>
                                </div>
                                <span class="prog" x-text="fmtQty(line.scanned) + ' / ค้าง ' + fmtQty(line.outstanding_qty)"></span>
                                <div style="grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px">
                                    <input x-model="line.lot_number" placeholder="Lot">
                                    <input x-model="line.manufacture_date" @change="calculatePoExpiry(line)" type="date" title="วันผลิต">
                                    <input x-model="line.expiry_date" type="date" title="วันหมดอายุ">
                                </div>
                            </div>
                        </template>
                    </div>
                    <p class="hint" style="margin:0 4px 10px">ยิงสินค้าที่ได้รับจริง ระบบจะออกใบซื้อเฉพาะจำนวนที่สแกน และเก็บยอดที่เหลือไว้รับรอบถัดไป</p>
                    <p class="err" x-show="submitError" x-text="submitError"></p>
                    <button class="btn primary" style="width:100%" :disabled="submitting" @click="receivePo()">
                        <span x-text="submitting ? 'กำลังรับของ…' : (poAllScanned() ? 'รับครบยอดค้าง ✓' : 'รับของตามจำนวนที่สแกน')"></span>
                    </button>
                </div>
            </template>
        </section>

        {{-- ================= แท็บ: เช็คสต๊อก ================= --}}
        <section x-show="tab === 'stock'" x-cloak>
            <form class="scanbar" @submit.prevent="scan('stock')">
                <input x-ref="scanStock" x-model="scanCode" placeholder="ยิงบาร์โค้ดเช็คยอดคงเหลือ…" autocomplete="off">
                <button type="button" x-show="cameraOk" @click="openCamera('stock')"><i class="bi bi-camera-fill"></i></button>
            </form>
            <div class="searchwrap">
                <input x-model="q" @input.debounce.350ms="searchProducts()" placeholder="หรือค้นหาชื่อสินค้า…" autocomplete="off">
                <div class="suggest" x-show="suggests.length" x-cloak>
                    <template x-for="s in suggests" :key="s.id">
                        <button type="button" @click="pickProduct(s.id, 'stock')">
                            <span x-text="s.name_th"></span><span class="sku" x-text="s.sku_code"></span>
                        </button>
                    </template>
                </div>
            </div>
            <div class="card" x-show="stockProduct" x-cloak>
                <div class="pname" x-text="stockProduct?.name_th"></div>
                <div class="psub" x-text="stockProduct?.sku_code"></div>
                <div style="margin-top:10px">
                    <div class="empty" x-show="!stockRows.length">ไม่มียอดคงเหลือในระบบ</div>
                    <template x-for="r in stockRows" :key="r.location_id">
                        <div class="stockrow" :class="{ neg: +r.on_hand_qty < 0 }">
                            <span x-text="r.location_name"></span>
                            <b x-text="fmtQty(r.on_hand_qty) + ' ' + (stockProduct?.base_unit_label ?? '')"></b>
                        </div>
                    </template>
                    <div class="total" x-show="stockRows.length"><span>รวมทุกคลัง</span><span x-text="fmtQty(stockTotal)"></span></div>
                </div>
            </div>
            <div class="empty" x-show="!stockProduct">ยิงบาร์โค้ดหรือค้นหาชื่อ เพื่อดูยอดคงเหลือรายคลัง</div>
        </section>

        <p class="err" x-show="scanError" x-text="scanError"></p>
    </main>

    {{-- แถบเมนูล่างแบบแอปมือถือ --}}
    <nav class="bottomnav" style="--tabs: {{ $canReceive ? 3 : 1 }}">
        @if($canReceive)
            <button :class="{ on: tab === 'receive' }" @click="switchTab('receive')"><i class="bi bi-box-arrow-in-down"></i>รับเข้า</button>
            <button :class="{ on: tab === 'po' }" @click="switchTab('po')"><i class="bi bi-cart-check"></i>รับตาม PO</button>
        @endif
        <button :class="{ on: tab === 'stock' }" @click="switchTab('stock')"><i class="bi bi-search"></i>เช็คสต๊อก</button>
    </nav>

    {{-- สแกนด้วยกล้อง (ต้องเปิดผ่าน HTTPS/localhost เท่านั้น) --}}
    <div class="overlay" x-show="cameraOpen" x-cloak @click.self="closeCamera()">
        <div class="sheet camwrap">
            <video x-ref="video" autoplay playsinline muted></video>
            <div class="btnrow"><button class="btn" @click="closeCamera()">ปิดกล้อง</button></div>
        </div>
    </div>

    {{-- บันทึกสำเร็จ --}}
    <div class="overlay" x-show="doneDoc" x-cloak @click.self="doneDoc = null">
        <div class="sheet">
            <div class="ok-ic"><i class="bi bi-check-circle-fill"></i></div>
            <h3 x-text="doneDoc?.title"></h3>
            <div class="doc" x-text="doneDoc?.doc_number"></div>
            <p style="color:var(--ink-soft);font-size:13.5px" x-text="doneDoc?.detail"></p>
            <button class="btn blue" style="width:100%" @click="doneDoc = null">ตกลง</button>
        </div>
    </div>
</div>

<script>
function whApp() {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const jfetch = async (url, opts = {}) => {
        const r = await fetch(url, {
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            ...opts,
        });
        const body = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(body.message || ('ผิดพลาด (' + r.status + ')'));
        return body;
    };

    return {
        tab: {{ $canReceive ? "'receive'" : "'stock'" }},
        branchId: {{ $lockedBranch->id ?? ($branches->first()->id ?? 'null') }},
        branches: @json($branches->map(fn ($b) => ['id' => $b->id, 'name_th' => $b->name_th])),

        scanCode: '', scanError: '', q: '', suggests: [],
        cur: null, curOnHand: null, qty: 1, price: 0, lot: '', manufacture: '', expiry: '',
        cart: [], supplier: null, supQ: '', supSuggests: [], isCredit: true, remark: '',
        submitting: false, submitError: '', doneDoc: null,

        poList: [], poLoading: false, poCur: null, poScanError: '',
        stockProduct: null, stockRows: [], stockTotal: 0,

        cameraOk: false, cameraOpen: false, camStream: null, camTarget: 'receive', camTimer: null,

        init() {
            this.cameraOk = !!(window.BarcodeDetector && navigator.mediaDevices?.getUserMedia);
            this.$watch('tab', () => { this.scanError = ''; this.suggests = []; this.q = ''; });
        },
        branchName() {
            return (this.branches.find(b => b.id === this.branchId)?.name_th) ?? '';
        },
        switchTab(t) {
            this.tab = t;
            if (t === 'po' && !this.poList.length) this.loadPos();
            this.$nextTick(() => this.focusScan());
        },
        focusScan() {
            const r = { receive: 'scanReceive', po: 'scanPo', stock: 'scanStock' }[this.tab];
            this.$refs[r]?.focus();
        },

        fmtMoney(v) { return (+v || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        fmtQty(v) { return (+v || 0).toLocaleString('th-TH', { maximumFractionDigits: 3 }); },

        // ---- สแกน/ค้นหา ----
        async scan(target) {
            const code = this.scanCode.trim();
            this.scanCode = '';
            if (!code) return;
            this.scanError = ''; this.poScanError = '';
            try {
                const res = await jfetch(`{{ route('wh.lookup') }}?code=${encodeURIComponent(code)}&branch_id=${this.branchId ?? ''}`);
                if (!res.found) { this.scanError = 'ไม่พบสินค้า: ' + code; return; }
                this.handleFound(res, target);
            } catch (e) { this.scanError = e.message; }
            this.focusScan();
        },
        async pickProduct(id, target) {
            this.suggests = []; this.q = '';
            try {
                const res = await jfetch(`{{ url('wh/products') }}/${id}?branch_id=${this.branchId ?? ''}`);
                this.handleFound(res, target);
            } catch (e) { this.scanError = e.message; }
        },
        handleFound(res, target) {
            if (target === 'stock') {
                this.stockProduct = res.product;
                this.loadStock(res.product.id);
            } else if (target === 'po') {
                this.tickPoLine(res.product);
            } else {
                this.cur = res.product;
                this.curOnHand = res.on_hand;
                this.qty = 1;
                this.price = res.product.default_cost * res.product.unit_factor;
                this.lot = ''; this.manufacture = ''; this.expiry = '';
            }
        },
        async searchProducts() {
            const q = this.q.trim();
            if (q.length < 2) { this.suggests = []; return; }
            try { this.suggests = await jfetch(`{{ route('search.products') }}?q=${encodeURIComponent(q)}`); } catch { this.suggests = []; }
        },

        // ---- รับเข้าอิสระ ----
        clearCur() { this.cur = null; this.curOnHand = null; this.focusScan(); },
        baseQty() { return (+this.qty || 0) * (this.cur?.unit_factor || 1); },
        calculateExpiry() {
            if (!this.manufacture || !this.cur?.shelf_life_days) return;
            const expiry = new Date(this.manufacture + 'T00:00:00');
            expiry.setDate(expiry.getDate() + Number(this.cur.shelf_life_days));
            this.expiry = expiry.toISOString().slice(0, 10);
        },
        canAdd() {
            return this.cur && (+this.qty > 0) && (+this.price >= 0) && (!this.cur.tracks_expiry || this.expiry);
        },
        addToCart() {
            if (!this.canAdd()) return;
            const factor = this.cur.unit_factor || 1;
            this.cart.push({
                product_id: this.cur.id,
                name_th: this.cur.name_th,
                sku_code: this.cur.sku_code,
                qty: +this.qty,                       // จำนวนตามหน่วยที่สแกน (ไว้แสดงผล)
                unit_label: this.cur.unit_label,
                base_qty: this.baseQty(),             // จำนวนหน่วยฐาน (ส่งเข้าระบบ)
                price: +this.price,                   // ราคา/หน่วยที่สแกน
                base_price: (+this.price) / factor,   // ราคา/หน่วยฐาน (ส่งเข้าระบบ)
                lot: this.lot.trim(), manufacture: this.manufacture, expiry: this.expiry,
            });
            this.clearCur();
        },
        cartTotal() { return this.cart.reduce((s, it) => s + it.qty * it.price, 0); },
        async searchSuppliers() {
            const q = this.supQ.trim();
            if (q.length < 1) { this.supSuggests = []; return; }
            try { this.supSuggests = await jfetch(`{{ route('search.suppliers') }}?q=${encodeURIComponent(q)}`); } catch { this.supSuggests = []; }
        },
        async submitReceive() {
            if (!this.supplier || !this.cart.length) return;
            this.submitting = true; this.submitError = '';
            try {
                const res = await jfetch(`{{ route('wh.receive') }}`, {
                    method: 'POST',
                    body: JSON.stringify({
                        branch_id: this.branchId,
                        supplier_id: this.supplier.id,
                        is_credit: this.isCredit,
                        remark: this.remark,
                        items: this.cart.map(it => ({
                            product_id: it.product_id,
                            qty: it.base_qty,
                            unit_price: it.base_price,
                            lot_number: it.lot || null,
                            manufacture_date: it.manufacture || null,
                            expiry_date: it.expiry || null,
                        })),
                    }),
                });
                this.doneDoc = { title: 'รับเข้าเรียบร้อย', doc_number: res.doc_number,
                                 detail: 'ยอดรวม ฿' + this.fmtMoney(res.total_amount) + ' เข้าสต๊อกแล้ว' };
                this.cart = []; this.supplier = null; this.remark = '';
            } catch (e) { this.submitError = e.message; }
            this.submitting = false;
        },

        // ---- รับตาม PO ----
        async loadPos() {
            this.poLoading = true;
            try { this.poList = await jfetch(`{{ route('wh.purchase-orders') }}?branch_id=${this.branchId ?? ''}`); }
            catch (e) { this.scanError = e.message; }
            this.poLoading = false;
        },
        async openPo(id) {
            try {
                const po = await jfetch(`{{ route('wh.purchase-orders') }}/${id}`);
                po.items = po.items.filter(i => +i.outstanding_qty > 0).map(i => ({ ...i, scanned: 0, lot_number: '', manufacture_date: '', expiry_date: '' }));
                this.poCur = po; this.submitError = '';
                this.$nextTick(() => this.focusScan());
            } catch (e) { this.scanError = e.message; }
        },
        tickPoLine(product) {
            const line = this.poCur?.items.find(i => i.product_id === product.id);
            if (!line) { this.poScanError = 'สินค้านี้ไม่อยู่ในใบสั่งซื้อ: ' + product.name_th; return; }
            const addQty = product.unit_factor || 1;
            if (line.scanned + addQty > line.outstanding_qty + 0.0001) {
                this.poScanError = 'จำนวนเกินยอดค้างรับของ ' + product.name_th;
                return;
            }
            this.poScanError = '';
            line.scanned += addQty;
        },
        poAllScanned() { return !!this.poCur && this.poCur.items.every(i => i.scanned >= i.outstanding_qty); },
        calculatePoExpiry(line) {
            if (!line.manufacture_date || !line.shelf_life_days) return;
            const expiry = new Date(line.manufacture_date + 'T00:00:00');
            expiry.setDate(expiry.getDate() + Number(line.shelf_life_days));
            line.expiry_date = expiry.toISOString().slice(0, 10);
        },
        async receivePo() {
            if (!this.poCur) return;
            if (!this.poCur.items.some(i => i.scanned > 0)) {
                this.submitError = 'กรุณาสแกนสินค้าที่รับจริงอย่างน้อย 1 รายการ';
                return;
            }
            if (this.poCur.items.some(i => i.scanned > 0 && i.tracks_expiry && !i.expiry_date && !(i.manufacture_date && i.shelf_life_days))) {
                this.submitError = 'สินค้าที่ควบคุมอายุต้องระบุวันหมดอายุ หรือวันผลิตของสินค้าที่ตั้งอายุไว้';
                return;
            }
            if (!this.poAllScanned() && !confirm('รับของบางส่วนตามจำนวนที่สแกน และเก็บยอดค้างไว้รับรอบถัดไป ใช่หรือไม่?')) return;
            this.submitting = true; this.submitError = '';
            try {
                const res = await jfetch(`{{ route('wh.purchase-orders') }}/${this.poCur.id}/receive`, {
                    method: 'POST',
                    body: JSON.stringify({ items: this.poCur.items.map(i => ({
                        purchase_order_item_id: i.purchase_order_item_id,
                        product_id: i.product_id,
                        qty: i.scanned,
                        lot_number: i.lot_number || null,
                        manufacture_date: i.manufacture_date || null,
                        expiry_date: i.expiry_date || null,
                    })) }),
                });
                this.doneDoc = { title: 'รับของตาม PO แล้ว', doc_number: res.doc_number,
                                 detail: 'ใบสั่งซื้อ ' + res.po_number + ' → ออกใบซื้อเข้าสต๊อกแล้ว' };
                this.poCur = null; this.loadPos();
            } catch (e) { this.submitError = e.message; }
            this.submitting = false;
        },

        // ---- เช็คสต๊อก ----
        async loadStock(productId) {
            try {
                const res = await jfetch(`{{ route('wh.stock') }}?product_id=${productId}`);
                this.stockRows = res.locations; this.stockTotal = res.total;
            } catch (e) { this.scanError = e.message; }
        },

        // ---- กล้องสแกนบาร์โค้ด (BarcodeDetector — ใช้ได้บน HTTPS/localhost) ----
        async openCamera(target) {
            this.camTarget = target; this.cameraOpen = true;
            try {
                this.camStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.$refs.video.srcObject = this.camStream;
                const detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'] });
                this.camTimer = setInterval(async () => {
                    try {
                        const codes = await detector.detect(this.$refs.video);
                        if (codes.length) {
                            this.scanCode = codes[0].rawValue;
                            this.closeCamera();
                            this.scan(this.camTarget);
                        }
                    } catch { /* เฟรมยังไม่พร้อม */ }
                }, 250);
            } catch (e) {
                this.closeCamera();
                this.scanError = 'เปิดกล้องไม่ได้ (ต้องเปิดผ่าน HTTPS): ' + e.message;
            }
        },
        closeCamera() {
            if (this.camTimer) clearInterval(this.camTimer);
            this.camStream?.getTracks().forEach(t => t.stop());
            this.camStream = null; this.cameraOpen = false;
        },
    };
}
</script>
</body>
</html>
