@extends('layout')

@section('title', 'เอกสารระบบหลัก')
@section('page-title', 'เอกสารระบบหลัก')
@section('page-subtitle', 'Scope แกน ERP/POS รอบแรก: สินค้า ขาย POS โอนของ ซื้อ และรายงาน')

@push('head')
<style>
    .doc-shell {
        display: grid;
        gap: 14px;
    }

    .doc-hero {
        background: #fff;
        border: 1px solid #e4e9f2;
        border-radius: 8px;
        padding: 22px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 18px;
        align-items: center;
    }

    .doc-eyebrow {
        color: #0f766e;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .doc-title {
        color: #111827;
        font-size: 30px;
        font-weight: 900;
        line-height: 1.15;
        margin: 5px 0 8px;
    }

    .doc-lead {
        color: #475569;
        font-size: 15px;
        max-width: 820px;
        margin: 0;
    }

    .doc-badge-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(120px, 1fr));
        gap: 8px;
        min-width: 270px;
    }

    .doc-badge {
        border: 1px solid #e4e9f2;
        border-radius: 8px;
        padding: 10px;
        background: #fafcff;
    }

    .doc-badge strong {
        display: block;
        color: #0f172a;
        font-size: 20px;
        line-height: 1;
    }

    .doc-badge span {
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
    }

    .module-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .module-card,
    .phase-card,
    .flow-panel,
    .scope-panel {
        background: #fff;
        border: 1px solid #e4e9f2;
        border-radius: 8px;
    }

    .module-card {
        padding: 16px;
        display: grid;
        grid-template-rows: auto auto 1fr auto;
        gap: 10px;
        min-height: 258px;
    }

    .module-head {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .module-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        font-size: 17px;
        flex: 0 0 36px;
    }

    .tone-teal { background: #ccfbf1; color: #0f766e; }
    .tone-orange { background: #fff2df; color: #f97316; }
    .tone-cyan { background: #e4fbff; color: #0891b2; }
    .tone-amber { background: #fef3c7; color: #b45309; }
    .tone-blue { background: #e8f1ff; color: #2563eb; }
    .tone-red { background: #fee2e2; color: #dc2626; }

    .module-title {
        font-size: 17px;
        font-weight: 900;
        color: #0f172a;
        margin: 0;
    }

    .module-summary {
        color: #64748b;
        font-size: 13px;
        line-height: 1.45;
        margin: 0;
    }

    .clean-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 7px;
    }

    .clean-list li {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        color: #334155;
        font-size: 13px;
        line-height: 1.35;
    }

    .clean-list i {
        color: #0f9aaa;
        margin-top: 2px;
    }

    .section-title {
        font-size: 20px;
        font-weight: 900;
        color: #0f172a;
        margin: 6px 0 10px;
    }

    .phase-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
    }

    .phase-card {
        padding: 13px;
        min-height: 150px;
    }

    .phase-no {
        width: 30px;
        height: 30px;
        border-radius: 7px;
        display: grid;
        place-items: center;
        background: #0f9aaa;
        color: #fff;
        font-weight: 900;
        font-size: 13px;
        margin-bottom: 9px;
    }

    .phase-title {
        font-size: 14px;
        font-weight: 900;
        color: #111827;
        margin: 0 0 7px;
        line-height: 1.25;
    }

    .phase-note {
        color: #64748b;
        font-size: 12px;
        line-height: 1.42;
    }

    .two-col {
        display: grid;
        grid-template-columns: 1.15fr .85fr;
        gap: 14px;
    }

    .flow-panel,
    .scope-panel {
        padding: 18px;
    }

    .flow-row {
        display: grid;
        grid-template-columns: 150px 1fr;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #edf1f7;
    }

    .flow-row:last-child {
        border-bottom: 0;
    }

    .flow-label {
        color: #0f172a;
        font-weight: 900;
        font-size: 13px;
    }

    .flow-text {
        color: #475569;
        font-size: 13px;
        line-height: 1.45;
    }

    .scope-list {
        columns: 2;
        column-gap: 24px;
        margin: 0;
        padding-left: 18px;
        color: #475569;
        font-size: 13px;
        line-height: 1.75;
    }

    .module-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding-top: 2px;
    }

    .module-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #dbe4ef;
        border-radius: 7px;
        padding: 7px 10px;
        color: #0f172a;
        background: #fff;
        text-decoration: none;
        font-size: 12px;
        font-weight: 800;
    }

    .module-action:hover {
        color: #0f766e;
        border-color: #0f9aaa;
        background: #f3ffff;
    }

    .legacy-code-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        padding-top: 2px;
    }

    .legacy-code-strip-title {
        flex: 0 0 100%;
        color: #64748b;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .legacy-code-mini {
        border: 1px solid #dbe4ef;
        border-radius: 7px;
        background: #f8fafc;
        color: #0f172a;
        padding: 4px 7px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        text-decoration: none;
    }

    .legacy-code-mini:hover {
        border-color: #0f9aaa;
        color: #0f766e;
        background: #eefcfc;
    }

    @media (max-width: 1199.98px) {
        .module-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .phase-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .two-col {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 767.98px) {
        .doc-hero {
            grid-template-columns: 1fr;
            padding: 16px;
        }

        .doc-title {
            font-size: 24px;
        }

        .doc-badge-grid,
        .module-grid,
        .phase-grid {
            grid-template-columns: 1fr;
            min-width: 0;
        }

        .flow-row {
            grid-template-columns: 1fr;
            gap: 4px;
        }

        .scope-list {
            columns: 1;
        }
    }
</style>
@endpush

@section('content')
@php
    $modules = [
        [
            'title' => 'สินค้า / สต็อก',
            'icon' => 'bi-box-seam-fill',
            'tone' => 'tone-teal',
            'summary' => 'ฐานข้อมูลสินค้าและยอดคงเหลือแยกสาขา คลัง และตำแหน่งเก็บ',
            'items' => ['สินค้า, หมวดหมู่, หน่วยนับ, บาร์โค้ด', 'ราคาขายและต้นทุน', 'ยอดคงเหลือและ stock movement', 'ปรับปรุงสต็อกและตรวจนับสินค้า'],
            'actions' => [['สินค้า', 'products.index'], ['ตรวจนับ', 'stock-adjustments.index'], ['รายงานสต็อก', 'reports.index', ['category' => 'inventory', 'report' => 'stock_balance']]],
        ],
        [
            'title' => 'ขายสินค้า',
            'icon' => 'bi-receipt-cutoff',
            'tone' => 'tone-orange',
            'summary' => 'เอกสารขายหลังบ้าน รองรับขายสด ขายเชื่อ รับคืน และลดหนี้',
            'items' => ['ใบเสนอราคาและใบจองสินค้า', 'ใบขายสดและใบขายเชื่อ', 'ใบส่งของและใบกำกับภาษี', 'รับชำระเงินและลูกหนี้'],
            'actions' => [['ใบจอง', 'bookings.index'], ['ขายสด', 'cash-sales.index'], ['รับคืน', 'sale-returns.index']],
        ],
        [
            'title' => 'POS หน้าร้าน',
            'icon' => 'bi-cart-check-fill',
            'tone' => 'tone-cyan',
            'summary' => 'ขายหน้าร้านแบบเร็ว สแกนบาร์โค้ด รับชำระหลายช่องทาง และปิดรอบ',
            'items' => ['เปิด/ปิดรอบแคชเชียร์', 'สแกนสินค้าและพิมพ์ใบเสร็จ', 'เงินสด โอน QR บัตร', 'คืนสินค้า ยกเลิกบิล และ audit log'],
            'actions' => [['POS', 'pos-import.page'], ['POS Tools', 'bplus.pos-workbench'], ['รายงาน POS', 'reports.index', ['category' => 'pos', 'report' => 'pos_receipts']]],
        ],
        [
            'title' => 'โอนสินค้า',
            'icon' => 'bi-arrow-left-right',
            'tone' => 'tone-amber',
            'summary' => 'โอนสินค้าระหว่างคลังหรือสาขา พร้อมสถานะสินค้าระหว่างทาง',
            'items' => ['ใบขอโอนสินค้า', 'ใบโอนสินค้าออก', 'ใบรับโอนสินค้า', 'ติดตามสถานะระหว่างทาง'],
            'actions' => [['โอนสินค้า', 'stock-transfers.index'], ['รายงานโอน', 'reports.index', ['category' => 'transfer', 'report' => 'stock_transfers']]],
        ],
        [
            'title' => 'ซื้อสินค้า',
            'icon' => 'bi-basket-fill',
            'tone' => 'tone-blue',
            'summary' => 'จัดซื้อ รับสินค้าเข้า เพิ่มสต็อก และตั้งเจ้าหนี้เมื่อซื้อเชื่อ',
            'items' => ['ใบขอซื้อและใบสั่งซื้อ', 'ใบรับสินค้า', 'ซื้อสดและซื้อเชื่อ', 'คืนสินค้าให้ผู้ขาย'],
            'actions' => [['ซื้อสินค้า', 'purchases.index'], ['ผู้ขาย', 'suppliers.index'], ['รายงานซื้อ', 'reports.index', ['category' => 'purchasing', 'report' => 'purchase_documents']]],
        ],
        [
            'title' => 'รายงานหลัก',
            'icon' => 'bi-clipboard-data-fill',
            'tone' => 'tone-red',
            'summary' => 'รายงานที่ใช้คุมยอดขาย สต็อก POS ซื้อ และโอนสินค้าในรอบแรก',
            'items' => ['สต็อกคงเหลือและสินค้าเคลื่อนไหว', 'ยอดขายรายวัน สาขา พนักงาน', 'สรุปแคชเชียร์และบิล POS', 'ซื้อ โอนสินค้า และกำไรขั้นต้น'],
            'actions' => [['รายงานทั้งหมด', 'reports.index'], ['กำไรขั้นต้น', 'reports.index', ['category' => 'sales', 'report' => 'gross_margin']], ['แดชบอร์ด', 'dashboard']],
        ],
    ];

    $legacyCoreKeys = ['products-stock', 'sales', 'pos', 'transfer', 'purchase', 'reports'];
    foreach ($modules as $index => &$module) {
        $module['legacy_codes'] = \App\Support\LegacyDocumentModules::codesForCore($legacyCoreKeys[$index] ?? '');
    }
    unset($module);

    $phases = [
        ['ฐานสินค้าและคลัง', 'สินค้า บาร์โค้ด ราคา คลัง ตำแหน่งเก็บ ยอดคงเหลือ และสมุดเคลื่อนไหว'],
        ['POS ขายหน้าร้าน', 'เปิดรอบ ขาย รับเงิน พิมพ์ใบเสร็จ คืนสินค้า ยกเลิกบิล และรายงาน POS'],
        ['ขายหลังบ้าน', 'ลูกค้า ใบเสนอราคา ใบจอง ใบขายสด/เชื่อ รับคืน ลดหนี้ และรับชำระ'],
        ['โอนสินค้า', 'ใบขอโอน โอนออก รับโอน สต็อกระหว่างทาง และรายงานโอน'],
        ['ซื้อสินค้า', 'ผู้ขาย ขอซื้อ สั่งซื้อ รับสินค้า ซื้อสด/เชื่อ และคืนสินค้าให้ผู้ขาย'],
        ['รายงาน/ตรวจสอบ', 'รายงานหลัก Audit log และตรวจความถูกต้องก่อนขยายโมดูลบัญชี'],
    ];
@endphp

<div class="doc-shell">
    <section class="doc-hero">
        <div>
            <div class="doc-eyebrow">ERP/POS Core Scope</div>
            <h2 class="doc-title">ทำแกนหลักให้แน่นก่อน แล้วค่อยต่อบัญชีเต็มระบบ</h2>
            <p class="doc-lead">
                หน้าเอกสารนี้สรุปสิ่งที่ต้องทำก่อนสำหรับระบบขายสินค้าแบบหลายสาขา:
                สินค้า, สต็อก, ขาย, POS, โอนสินค้า, ซื้อสินค้า และรายงานที่ใช้ตรวจงานประจำวัน
            </p>
        </div>
        <div class="doc-badge-grid">
            <div class="doc-badge"><strong>6</strong><span>โมดูลหลัก</span></div>
            <div class="doc-badge"><strong>6</strong><span>Phase งาน</span></div>
            <div class="doc-badge"><strong>POS</strong><span>ขายหน้าร้าน</span></div>
            <div class="doc-badge"><strong>Stock</strong><span>คุมสินค้า</span></div>
        </div>
    </section>

    <section>
        <h3 class="section-title">Module หลัก</h3>
        <div class="module-grid">
            @foreach($modules as $module)
                <article class="module-card">
                    <div class="module-head">
                        <span class="module-icon {{ $module['tone'] }}"><i class="bi {{ $module['icon'] }}"></i></span>
                        <h4 class="module-title">{{ $module['title'] }}</h4>
                    </div>
                    <p class="module-summary">{{ $module['summary'] }}</p>
                    <ul class="clean-list">
                        @foreach($module['items'] as $item)
                            <li><i class="bi bi-check2-circle"></i><span>{{ $item }}</span></li>
                        @endforeach
                    </ul>
                    @if(! empty($module['legacy_codes']))
                        <div class="legacy-code-strip">
                            <span class="legacy-code-strip-title">BPlus document codes</span>
                            @foreach(array_slice($module['legacy_codes'], 0, 18) as $code)
                                <a class="legacy-code-mini" href="{{ route('documents.browser', ['legacy_type' => $code, 'year' => now()->subMonth()->year, 'month' => now()->subMonth()->month]) }}">{{ $code }}</a>
                            @endforeach
                            @if(count($module['legacy_codes']) > 18)
                                <a class="legacy-code-mini" href="{{ route('documents.browser', ['q' => $module['legacy_codes'][0] ?? '', 'year' => now()->subMonth()->year, 'month' => now()->subMonth()->month]) }}">+{{ count($module['legacy_codes']) - 18 }}</a>
                            @endif
                        </div>
                    @endif
                    <div class="module-actions">
                        @foreach($module['actions'] as $action)
                            <a class="module-action" href="{{ route($action[1], $action[2] ?? []) }}">
                                <i class="bi bi-box-arrow-up-right"></i>{{ $action[0] }}
                            </a>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section>
        <h3 class="section-title">ลำดับทำงานที่แนะนำ</h3>
        <div class="phase-grid">
            @foreach($phases as $phase)
                <article class="phase-card">
                    <div class="phase-no">{{ $loop->iteration }}</div>
                    <h4 class="phase-title">{{ $phase[0] }}</h4>
                    <div class="phase-note">{{ $phase[1] }}</div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="two-col">
        <div class="flow-panel">
            <h3 class="section-title mt-0">Flow สำคัญ</h3>
            <div class="flow-row">
                <div class="flow-label">ขายหลังบ้าน</div>
                <div class="flow-text">ใบเสนอราคา -> ใบจองสินค้า -> ใบขาย/ใบส่งของ/ใบกำกับภาษี -> รับชำระเงิน</div>
            </div>
            <div class="flow-row">
                <div class="flow-label">POS</div>
                <div class="flow-text">เปิดรอบแคชเชียร์ -> สแกนสินค้า -> รับเงิน -> พิมพ์ใบเสร็จ -> ตัดสต็อก -> ปิดรอบ</div>
            </div>
            <div class="flow-row">
                <div class="flow-label">โอนสินค้า</div>
                <div class="flow-text">ใบขอโอน -> ใบจัดส่งโอน -> ลดสต็อกต้นทาง -> สินค้าระหว่างทาง -> ใบรับโอน -> เพิ่มสต็อกปลายทาง</div>
            </div>
            <div class="flow-row">
                <div class="flow-label">ซื้อสินค้า</div>
                <div class="flow-text">ใบขอซื้อ -> ใบสั่งซื้อ -> ใบรับสินค้า -> เพิ่มสต็อก -> ตั้งเจ้าหนี้หรือจ่ายเงิน</div>
            </div>
        </div>

        <div class="scope-panel">
            <h3 class="section-title mt-0">ยังไม่ต้องทำรอบแรก</h3>
            <ul class="scope-list">
                <li>บัญชีแยกประเภทเต็มระบบ</li>
                <li>ปิดงบการเงิน</li>
                <li>ทรัพย์สินถาวร</li>
                <li>เงินเดือน</li>
                <li>ผลิต/BOM ขั้นสูง</li>
                <li>BI ขั้นสูง</li>
                <li>E-commerce sync</li>
                <li>สมาชิก/แต้มแบบละเอียด</li>
                <li>โปรโมชันซับซ้อน</li>
            </ul>
        </div>
    </section>
</div>
@endsection
