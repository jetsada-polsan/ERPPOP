@extends('layout')

@php
    $categoryMeta = [
        'sales' => ['icon' => 'bi-receipt-cutoff', 'color' => '#2563eb', 'label' => 'ขาย'],
        'management' => ['icon' => 'bi-graph-up-arrow', 'color' => '#f97316', 'label' => 'ผู้บริหาร'],
        'ar' => ['icon' => 'bi-person-lines-fill', 'color' => '#db2777', 'label' => 'ลูกหนี้'],
        'inventory' => ['icon' => 'bi-box-seam-fill', 'color' => '#0891b2', 'label' => 'สินค้า / สต็อก'],
        'documents' => ['icon' => 'bi-files', 'color' => '#4f46e5', 'label' => 'เอกสาร'],
        'pos' => ['icon' => 'bi-cart-check-fill', 'color' => '#0f766e', 'label' => 'POS'],
        'purchasing' => ['icon' => 'bi-basket-fill', 'color' => '#d97706', 'label' => 'ซื้อสินค้า'],
        'transfer' => ['icon' => 'bi-arrow-left-right', 'color' => '#7c3aed', 'label' => 'โอนสินค้า'],
        'payment' => ['icon' => 'bi-cash-coin', 'color' => '#059669', 'label' => 'การเงิน / ชำระ'],
        'tax' => ['icon' => 'bi-receipt', 'color' => '#9333ea', 'label' => 'ภาษี (ภพ.30)'],
        'audit' => ['icon' => 'bi-shield-check', 'color' => '#b45309', 'label' => 'ตรวจสอบระบบ'],
    ];

    $reportLabels = [
        'daily_sales' => 'ยอดขายรายวัน',
        'sales_by_branch' => 'ยอดขายตามสาขา',
        'sales_by_staff' => 'ยอดขายตามพนักงาน / แคชเชียร์',
        'sales_by_category' => 'ยอดขายตามหมวดสินค้า',
        'sales_by_seller' => 'ยอดขายตามคนขาย',
        'sales_by_category_seller' => 'ยอดขายตามหมวดสินค้า / คนขาย',
        'top_products' => 'สินค้าขายดี',
        'products_by_branch' => 'สินค้าขายตามสาขา',
        'gross_margin' => 'กำไรขั้นต้นเบื้องต้น',
        'credit_sales' => 'ใบขายเชื่อ',
        'pending_bookings' => 'ใบจองค้างแปลงขาย',
        'sales_by_booking' => 'ยอดขายตามใบจอง',
        'sales_returns_by_document' => 'ใบขาย-รับคืน ตามเอกสาร',
        'bplus_sales_return_by_document' => 'รายงานรับจ่าย-รับคืนสินค้า ตามเอกสาร',
        'sale_return_by_product' => 'สรุปขาย-รับคืน ตามสินค้า',
        'bplus_sale_return_by_product' => 'รายงานสรุปการขาย-รับคืนตามสินค้า',
        'sales_summary_by_customer' => 'รายงานสรุปยอดขายตามลูกค้า',
        'sales_summary_12m_customer' => 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้',
        'sales_summary_12m_customer_product' => 'รายงานสรุปยอดขาย 12 เดือน ตามลูกหนี้-สินค้า',
        'sales_summary_12m_category' => 'รายงานสรุปยอดขาย 12 เดือน ตามหมวดสินค้า',
        'sales_summary_12m_salesman_product' => 'รายงานสรุปยอดขาย 12 เดือน ตามพนักงานขาย-สินค้า',
        'loss_sales' => 'สินค้าขายต่ำกว่าทุน / ขาดทุน',
        'loss_sales_6m' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน',
        'loss_sales_6m_by_type' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามประเภทสินค้า',
        'loss_sales_6m_by_brand' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามยี่ห้อสินค้า',
        'loss_sales_6m_by_category' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามหมวดสินค้า',
        'loss_sales_6m_by_supplier' => 'รายงานแสดงสินค้าที่ขายขาดทุน 6 เดือน ตามผู้จำหน่ายหลัก',
        'loss_price_table' => 'รายงานราคาขายต่ำกว่าทุนตามตารางราคา',
        'loss_sales_documents_summary' => 'รายงานสรุปเอกสารขายที่ขาดทุน',
        'loss_sales_documents_detail' => 'รายงานรายละเอียดเอกสารขายที่ขาดทุน',
        'ar_summary' => 'สรุปยอดลูกหนี้',
        'ar_summary_bplus' => 'รายงานสรุปยอดลูกหนี้',
        'ar_aging' => 'อายุหนี้ AR Aging',
        'overdue_customers' => 'ลูกหนี้เกินกำหนด',
        'open_items' => 'ลูกหนี้คงค้าง',
        'ar_detail_short' => 'รายงานรายละเอียดยอดลูกหนี้ แบบย่อ',
        'ar_detail_full' => 'รายงานรายละเอียดยอดลูกหนี้ แบบละเอียด',
        'ar_overdue_detail' => 'รายงานรายละเอียดลูกหนี้เกินกำหนดชำระ',
        'ar_over_credit_limit' => 'รายงานรายละเอียดลูกหนี้เกินวงเงินเครดิต',
        'stock_balance' => 'สินค้าคงเหลือ',
        'stock_by_branch' => 'สต็อกตามสาขา',
        'stock_alerts' => 'สต็อกต่ำ / ติดลบ',
        'stock_movements' => 'เคลื่อนไหวสินค้า',
        'documents_summary' => 'สรุปเอกสาร',
        'document_list' => 'รายการเอกสารทั้งหมด',
        'document_items' => 'รายการสินค้าในเอกสาร',
        'booking_documents' => 'ใบจอง',
        'cash_sale_documents' => 'ใบขายสด',
        'credit_sale_documents' => 'ใบขายเชื่อ',
        'sale_return_documents' => 'ใบรับคืนสินค้า',
        'receipt_documents' => 'ใบเสร็จรับเงิน',
        'pos_receipts' => 'ใบเสร็จ POS',
        'pos_by_terminal' => 'ยอดขายตามเครื่อง POS',
        'pos_payments' => 'รับชำระตามช่องทาง',
        'pos_hourly' => 'ยอดขายรายชั่วโมง',
        'pos_tax_discount' => 'ภาษี / ส่วนลด POS',
        'purchase_documents' => 'เอกสารซื้อสินค้า',
        'purchase_by_supplier' => 'ยอดซื้อตามผู้ขาย',
        'purchase_items' => 'รับสินค้าเข้าตามสินค้า',
        'stock_transfers' => 'เอกสารโอนสินค้า',
        'transfer_items' => 'รายการสินค้าโอน',
        'transfer_by_location' => 'ยอดโอนตามคลังต้นทาง / ปลายทาง',
        'vat_sales' => 'รายงานภาษีขาย',
        'vat_purchase' => 'รายงานภาษีซื้อ',
        'payment_documents' => 'เอกสารรับชำระ',
        'payment_allocations' => 'ตัดหนี้ / จัดสรรยอด',
        'gl_journals' => 'GL Journal',
        'import_batches' => 'Import / Sync status',
        'import_errors' => 'Import errors',
        'import_error_summary' => 'สรุป Import errors',
        'void_bill_history' => 'ประวัติลบบิล / ยกเลิกบิลย้อนหลัง',
        'deleted_bill_audit' => 'ตรวจสอบเอกสารที่ถูกยกเลิก',
        'pending_work' => 'งานค้างต้องตาม',
    ];

    $columnLabels = [
        'sale_date' => 'วันที่',
        'receipt_date' => 'วันที่',
        'doc_date' => 'วันที่',
        'movement_date' => 'วันที่',
        'entry_date' => 'วันที่',
        'created_at' => 'วันที่',
        'sale_hour' => 'ชั่วโมงขาย',
        'channel' => 'ช่องทาง',
        'bill_count' => 'บิล',
        'receipt_count' => 'บิล',
        'line_count' => 'รายการ',
        'document_count' => 'เอกสาร',
        'document_type' => 'ประเภทเอกสาร',
        'record_count' => 'จำนวน',
        'product_count' => 'รายการสินค้า',
        'error_count' => 'จำนวน Error',
        'item_count' => 'รายการ',
        'total_items' => 'รายการ',
        'amount' => 'ยอดเงิน',
        'unit_price' => 'ราคา',
        'net_sales' => 'ยอดขาย',
        'gross_sales' => 'ยอดก่อนลด',
        'vat_amount' => 'VAT',
        'sales_amount' => 'ยอดขาย',
        'total_amount' => 'ยอดเงิน',
        'allocated_amount' => 'ยอดจัดสรร',
        'discount_amount' => 'ส่วนลด',
        'wht_amount' => 'หัก ณ ที่จ่าย',
        'cost_amount' => 'ต้นทุนประมาณ',
        'gross_profit' => 'กำไรขั้นต้น',
        'qty' => 'จำนวน',
        'total_qty' => 'จำนวน',
        'on_hand_qty' => 'คงเหลือ',
        'reserved_qty' => 'จอง',
        'sku_code' => 'รหัส',
        'name_th' => 'สินค้า',
        'product_name' => 'สินค้า',
        'location_name' => 'คลัง / ที่เก็บ',
        'from_location' => 'ต้นทาง',
        'to_location' => 'ปลายทาง',
        'branch_name' => 'สาขา',
        'terminal_name' => 'เครื่อง POS',
        'staff_name' => 'พนักงาน',
        'salesman_name' => 'พนักงานขาย',
        'customer_name' => 'ลูกค้า',
        'supplier_name' => 'ผู้ขาย',
        'doc_number' => 'เลขที่',
        'receipt_no' => 'เลขที่',
        'status' => 'สถานะ',
        'method' => 'ช่องทางชำระ',
        'movement_type' => 'ประเภท',
        'party_type' => 'ประเภทคู่ค้า',
        'party_name' => 'คู่ค้า / ลูกค้า',
        'account_name' => 'บัญชี',
        'debit' => 'เดบิต',
        'credit' => 'เครดิต',
        'remark' => 'หมายเหตุ',
        'bucket' => 'ช่วงอายุหนี้',
        'balance_amount' => 'ยอดค้าง',
        'oldest_due_date' => 'ครบกำหนดเก่าสุด',
        'due_date' => 'ครบกำหนด',
        'pos_code' => 'POS',
        'error_type' => 'ประเภท',
        'error_message' => 'รายละเอียด',
        'first_seen' => 'ครั้งแรก',
        'last_seen' => 'ล่าสุด',
        'label' => 'รายการ',
        'count' => 'จำนวน',
    ];

    $displayColumns = collect($result['columns'])->map(function ($column) use ($columnLabels) {
        $column['label'] = $columnLabels[$column['key']] ?? $column['label'];
        return $column;
    })->all();

    // รายงานที่ใช้ประจำ: ชุดจำเป็นสำหรับงานขายส่งเนื้อสัตว์/แช่แข็ง - แก้ลิสต์นี้ที่เดียว
    $essentialReports = [
        ['sales', 'daily_sales', 'ยอดขายรายวัน'],
        ['sales', 'top_products', 'สินค้าขายดี'],
        ['sales', 'gross_margin', 'กำไรขั้นต้น'],
        ['inventory', 'stock_balance', 'สินค้าคงเหลือ'],
        ['inventory', 'stock_alerts', 'สต็อกต่ำ / ติดลบ'],
        ['inventory', 'stock_movements', 'เคลื่อนไหวสินค้า'],
        ['ar', 'open_items', 'ลูกหนี้คงค้าง'],
        ['ar', 'overdue_customers', 'ลูกหนี้เกินกำหนด'],
        ['purchasing', 'purchase_by_supplier', 'ยอดซื้อตามผู้ขาย'],
    ];

    $dateParams = ['from' => $from, 'to' => $to, 'branch_id' => $filters['branch_id']];
@endphp

@section('title', 'รายงาน - POPSTAR ERP')
@section('page-title', 'รายงาน')
@section('page-subtitle', 'เลือกรายงานจากแผงซ้าย - ค่าเริ่มต้นแสดงข้อมูลของวันนี้')

@section('content')
<div class="flow-report-shell">
    <aside class="flow-rail no-print">
        <a href="{{ route('dashboard') }}" class="flow-rail-icon" title="แดชบอร์ด"><i class="bi bi-speedometer2"></i></a>
        <a href="{{ route('reports.index') }}" class="flow-rail-icon active" title="รายงาน"><i class="bi bi-graph-up"></i></a>
        <a href="{{ route('documents.browser') }}" class="flow-rail-icon" title="เอกสาร"><i class="bi bi-files"></i></a>
        <a href="{{ route('pos.index') }}" class="flow-rail-icon" title="POS"><i class="bi bi-cart-check"></i></a>
        <a href="{{ route('products.index') }}" class="flow-rail-icon" title="สินค้า"><i class="bi bi-box-seam"></i></a>
        <a href="{{ route('chart-of-accounts.index') }}" class="flow-rail-icon" title="บัญชี"><i class="bi bi-bank"></i></a>
    </aside>

    {{-- แผงซ้าย: ใช้ประจำ + หมวดรายงานแบบต้นไม้ --}}
    <div class="rpt-nav no-print" x-data="{ open: @js($selectedCategory) }">
        <div class="flow-nav-title">รายงาน</div>
        <div class="rpt-nav-head"><i class="bi bi-star-fill" style="color:#f59e0b"></i> ใช้ประจำ</div>
        @foreach($essentialReports as [$favCat, $favReport, $favLabel])
            <a href="{{ route('reports.index', array_merge($dateParams, ['category' => $favCat, 'report' => $favReport])) }}"
               class="rpt-nav-link {{ $selectedCategory === $favCat && $selectedReport === $favReport ? 'active' : '' }}">
                {{ $favLabel }}
            </a>
        @endforeach

        <a href="{{ route('legacy-reports.index') }}" class="rpt-legacy-card">
            <span class="rpt-legacy-icon"><i class="bi bi-database-check"></i></span>
            <span>
                <strong>รายงาน BPlus เดิม</strong>
                <small>REPORTFILE / SQL / .rpt ทั้งหมด</small>
            </span>
            <i class="bi bi-chevron-right ms-auto"></i>
        </a>

        <div class="rpt-nav-head mt-3"><i class="bi bi-folder2-open" style="color:#0284c7"></i> รายงานทั้งหมด</div>
        @foreach($catalog as $catKey => $group)
            @php
                $meta = $categoryMeta[$catKey] ?? ['icon' => 'bi-grid', 'color' => '#64748b', 'label' => $group['title']];
            @endphp
            <button type="button" class="rpt-nav-cat {{ $selectedCategory === $catKey ? 'current' : '' }}"
                @click="open = open === '{{ $catKey }}' ? '' : '{{ $catKey }}'">
                <i class="bi {{ $meta['icon'] }}" style="color:{{ $meta['color'] }}"></i>
                <span>{{ $meta['label'] }}</span>
                <i class="bi ms-auto" :class="open === '{{ $catKey }}' ? 'bi-chevron-down' : 'bi-chevron-right'" style="font-size:11px;color:#94a3b8"></i>
            </button>
            <div x-show="open === '{{ $catKey }}'" class="rpt-nav-sub">
                @foreach($group['reports'] as $repKey => $repLabel)
                    <a href="{{ route('reports.index', array_merge($dateParams, ['category' => $catKey, 'report' => $repKey])) }}"
                       class="rpt-nav-link {{ $selectedCategory === $catKey && $selectedReport === $repKey ? 'active' : '' }}">
                        {{ $reportLabels[$repKey] ?? $repLabel }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- แผงขวา: แถบเครื่องมือ + ผลรายงาน --}}
    <div class="rpt-main">
        <form method="get" id="rform">
            <input type="hidden" name="category" value="{{ $selectedCategory }}">
            <input type="hidden" name="report" value="{{ $selectedReport }}">

            <div class="flow-report-head no-print">
                <div>
                    <div class="flow-eyebrow">{{ $categoryMeta[$selectedCategory]['label'] ?? $catalog[$selectedCategory]['title'] ?? 'รายงาน' }}</div>
                    <h1>{{ $reportLabels[$selectedReport] ?? $result['title'] }}</h1>
                </div>
                <div class="flow-head-actions">
                    <a href="{{ route('legacy-reports.index') }}" class="flow-action-btn flow-action-link">
                        <i class="bi bi-database-check"></i> รายงาน BPlus เดิม
                    </a>
                    <button type="button" class="flow-action-btn" onclick="exportCsv()">
                        <i class="bi bi-download"></i> ดาวน์โหลด Excel
                    </button>
                    <button type="button" class="flow-action-btn" onclick="window.print()">
                        <i class="bi bi-printer"></i> พิมพ์รายงาน
                    </button>
                </div>
            </div>

            <div class="rpt-toolbar no-print mb-3">
                <label class="rpt-report-picker">
                    <span>รายงานหลัก</span>
                    <select class="rpt-select rpt-report-select" onchange="selectReport(this.value)">
                        @foreach($catalog as $catKey => $group)
                            <optgroup label="{{ $categoryMeta[$catKey]['label'] ?? $group['title'] }}">
                                @foreach($group['reports'] as $repKey => $repLabel)
                                    <option value="{{ $catKey }}|{{ $repKey }}" @selected($selectedCategory === $catKey && $selectedReport === $repKey)>
                                        {{ $reportLabels[$repKey] ?? $repLabel }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </label>

                <i class="bi bi-calendar3" style="color:#64748b"></i>
                <input type="date" name="from" value="{{ $from }}" class="rpt-date-input">
                <span style="color:#64748b;font-size:13px">ถึง</span>
                <input type="date" name="to" value="{{ $to }}" class="rpt-date-input">

                <a href="{{ request()->fullUrlWithQuery(['from'=>now()->toDateString(),'to'=>now()->toDateString()]) }}" class="rpt-shortcut">วันนี้</a>
                <a href="{{ request()->fullUrlWithQuery(['from'=>now()->subDays(6)->toDateString(),'to'=>now()->toDateString()]) }}" class="rpt-shortcut">7 วัน</a>
                <a href="{{ request()->fullUrlWithQuery(['from'=>now()->startOfMonth()->toDateString(),'to'=>now()->toDateString()]) }}" class="rpt-shortcut">เดือนนี้</a>

                <select name="branch_id" class="rpt-select" onchange="this.form.submit()">
                    <option value="all" @selected($filters['branch_id'] === null)>ทุกสาขา</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected((string) $filters['branch_id'] === (string) $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rpt-btn-filter"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>

                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="rpt-btn-export" onclick="exportCsv()"><i class="bi bi-download me-1"></i>CSV</button>
                    <button type="button" class="rpt-btn-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>พิมพ์</button>
                </div>
            </div>

            {{-- หัวเอกสารตอนพิมพ์: ข้อมูลบริษัท + ชื่อรายงาน + ช่วงวันที่ (โผล่เฉพาะสั่งพิมพ์) --}}
            @php
                $printBranch = $filters['branch_id'] ? collect($branches)->firstWhere('id', (int) $filters['branch_id']) : null;
            @endphp
            @php
                $moneyKeys = ['net_sales','sales_amount','total_amount','amount','balance_amount','vat_amount','gross_sales'];
                $summaryMoneyKey = collect($moneyKeys)->first(fn ($key) => collect($result['columns'])->contains('key', $key));
                $summaryMoney = $summaryMoneyKey ? collect($result['rows'])->sum(fn ($row) => (float) data_get($row, $summaryMoneyKey)) : 0;
                $summaryVat = collect($result['rows'])->sum(fn ($row) => (float) data_get($row, 'vat_amount'));
            @endphp
            <div class="rpt-print-header">
                <div class="d-flex gap-3 align-items-start">
                    @if($printLogo = \App\Models\AppSetting::logoUrl())
                        <img src="{{ $printLogo }}" alt="" style="max-height:52px;max-width:130px;object-fit:contain">
                    @endif
                    <div>
                        <div class="rpt-print-name">{{ \App\Models\AppSetting::company('name_th') }}</div>
                        <div class="rpt-print-sub">{{ \App\Models\AppSetting::company('name_en') }}</div>
                        <div class="rpt-print-sub">{{ \App\Models\AppSetting::company('address') }}</div>
                        <div class="rpt-print-sub">
                            เลขทะเบียนนิติบุคคล {{ \App\Models\AppSetting::company('tax_id') }}@if(\App\Models\AppSetting::company('phone')) &middot; โทร {{ \App\Models\AppSetting::company('phone') }}@endif
                        </div>
                    </div>
                </div>
                <div class="rpt-print-meta">
                    <div class="rpt-print-title">{{ $reportLabels[$selectedReport] ?? $result['title'] }}</div>
                    <div class="rpt-print-sub">ช่วงวันที่ {{ \Illuminate\Support\Carbon::parse($from)->thaiDate() }} ถึง {{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }}</div>
                    <div class="rpt-print-sub">สาขา: {{ $printBranch->name_th ?? 'ทุกสาขา' }}</div>
                    <div class="rpt-print-sub">พิมพ์เมื่อ {{ now()->thaiDate(true) }} น.</div>
                </div>
            </div>

            <div class="content-card overflow-hidden">
                <div class="flow-report-summary no-print">
                    <div class="flow-report-meta">
                        <div class="flow-dash">-</div>
                        <dl>
                            <div><dt>ณ วันที่</dt><dd>{{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }}</dd></div>
                            <div><dt>ช่วงเวลา</dt><dd>{{ \Illuminate\Support\Carbon::parse($from)->thaiDate() }} @if($from !== $to) - {{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }} @endif</dd></div>
                            <div><dt>จำนวนทั้งหมด</dt><dd>{{ number_format($result['total']) }} รายการ</dd></div>
                        </dl>
                    </div>
                    <div class="flow-report-totals">
                        <div><span>ยอดรวมทั้งสิ้น</span><strong>{{ number_format($summaryMoney, 2) }}</strong></div>
                        <div><span>ภาษีมูลค่าเพิ่ม</span><strong>{{ number_format($summaryVat, 2) }}</strong></div>
                        <div><span>สาขา</span><strong>{{ $printBranch->name_th ?? 'ทุกสาขา' }}</strong></div>
                    </div>
                </div>
                <div class="flow-tabs no-print">
                    <span class="active">เอกสารขาย</span>
                    <span>เอกสารบัญชี</span>
                </div>
                <div class="rpt-panel-title">
                    <div>
                        <div class="fw-bold fs-5">{{ $reportLabels[$selectedReport] ?? $result['title'] }}</div>
                        <div class="text-muted small">
                            {{ \Illuminate\Support\Carbon::parse($from)->thaiDate() }}
                            @if($from !== $to) ถึง {{ \Illuminate\Support\Carbon::parse($to)->thaiDate() }} @endif
                            &middot; {{ $printBranch->name_th ?? 'ทุกสาขา' }}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 flex-wrap no-print">
                        <label class="d-flex align-items-center gap-2 small text-muted">
                            แสดง
                            <select name="per_page" class="form-select form-select-sm" style="width:78px" onchange="this.form.submit()">
                                @foreach([10, 25, 50, 100] as $sz)
                                    <option value="{{ $sz }}" @selected($perPage === $sz)>{{ $sz }}</option>
                                @endforeach
                            </select>
                            แถว
                        </label>
                        <div class="erp-search">
                            <i class="bi bi-search erp-search-icon"></i>
                            <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="ค้นหา..." class="form-control form-control-sm ps-5">
                        </div>
                        <span class="badge text-bg-light border">{{ number_format($result['total']) }} รายการ</span>
                    </div>
                </div>

                @include('reports.partials.result-table', [
                    'columns' => $displayColumns,
                    'rows' => $result['rows'],
                    'empty' => 'ไม่พบข้อมูลตามเงื่อนไขที่เลือก',
                ])
            </div>
        </form>
    </div>
</div>
@endsection

@push('head')
<style>
    .flow-report-shell {
        --flow-blue: #0284c7;
        --flow-blue-dark: #0369a1;
        --flow-blue-ink: #0c4a6e;
        --flow-line: #bae6fd;
        --flow-soft: #eff8ff;
        --flow-soft-2: #e0f2fe;
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        align-items: start;
        gap: 0;
        min-height: calc(100vh - 96px);
        margin: 0;
        background: linear-gradient(180deg, #e0f2fe 0%, #f0f9ff 220px, #f8fbff 100%);
    }
    .flow-rail {
        display: none;
    }
    .flow-rail-icon {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        color: #fff;
        text-decoration: none;
        font-size: 22px;
        opacity: .92;
    }
    .flow-rail-icon:hover,
    .flow-rail-icon.active {
        background: rgba(255,255,255,.18);
        color: #fff;
        opacity: 1;
    }
    .flow-nav-title {
        color: #6b7280;
        font-size: 24px;
        line-height: 1;
        font-weight: 800;
        padding: 4px 12px 18px;
    }
    .rpt-main {
        min-width: 0;
        padding: 24px;
        background: transparent;
    }
    .flow-report-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }
    .flow-eyebrow {
        color: var(--flow-blue-dark);
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 4px;
    }
    .flow-report-head h1 {
        color: var(--flow-blue-ink);
        font-size: 28px;
        font-weight: 800;
        margin: 0;
        letter-spacing: 0;
    }
    .flow-head-actions {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .flow-action-btn {
        min-width: 0;
        border: 1px solid #bae6fd;
        background: linear-gradient(180deg, #ffffff, #f0f9ff);
        color: var(--flow-blue-ink);
        border-radius: 6px;
        padding: 10px 14px;
        font-size: 15px;
        font-weight: 800;
        cursor: pointer;
        font-family: inherit;
        white-space: nowrap;
    }
    .flow-action-btn:hover {
        border-color: #38bdf8;
        background: #e0f2fe;
    }
    .flow-action-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
    }
    .flow-report-summary {
        display: grid;
        grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr);
        gap: 18px;
        padding: 22px 24px 18px;
        border: 1px solid var(--flow-line);
        border-bottom: 0;
        border-radius: 8px 8px 0 0;
        background: linear-gradient(135deg, #f0f9ff, #ffffff 55%, #e0f2fe);
    }
    .flow-report-meta dl {
        margin: 8px 0 0;
        display: grid;
        gap: 8px;
        min-width: 0;
    }
    .flow-report-meta dl div {
        display: grid;
        grid-template-columns: minmax(92px, 130px) minmax(0, 1fr);
        gap: 10px;
    }
    .flow-report-meta dt,
    .flow-report-meta dd {
        margin: 0;
        color: var(--flow-blue-ink);
        font-size: 17px;
        font-weight: 800;
        line-height: 1.35;
    }
    .flow-dash {
        color: var(--flow-blue);
        font-size: 22px;
        font-weight: 800;
    }
    .flow-report-totals {
        min-width: 0;
        padding-top: 28px;
        display: grid;
        gap: 8px;
    }
    .flow-report-totals div {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 16px;
        align-items: baseline;
    }
    .flow-report-totals span {
        color: var(--flow-blue-ink);
        font-size: 16px;
        font-weight: 900;
        min-width: 0;
    }
    .flow-report-totals strong {
        color: var(--flow-blue);
        font-size: 18px;
        font-weight: 900;
        text-align: right;
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .flow-tabs {
        display: flex;
        align-items: flex-end;
        padding: 0 24px;
        border-left: 1px solid var(--flow-line);
        border-right: 1px solid var(--flow-line);
        background: #f0f9ff;
    }
    .flow-tabs span {
        min-width: 160px;
        text-align: center;
        padding: 10px 16px;
        border-radius: 6px 6px 0 0;
        background: #e0f2fe;
        color: #075985;
        font-size: 14px;
        font-weight: 700;
    }
    .flow-tabs span.active {
        background: linear-gradient(180deg, #38bdf8, var(--flow-blue));
        color: #fff;
    }
    .flow-report-shell .content-card {
        border: 1px solid #bae6fd;
        border-radius: 8px;
        box-shadow: 0 16px 40px rgba(2,132,199,.10);
        background: #fff;
        min-width: 0;
    }
    .rpt-shell {
        display: grid;
        grid-template-columns: 240px minmax(0, 1fr);
        gap: 16px;
        align-items: start;
    }
    .rpt-nav {
        display: none;
        background: #f7f9fc;
        border: 0;
        border-right: 1px solid #e2e8f0;
        border-radius: 0;
        padding: 20px 12px;
        position: sticky;
        top: 0;
        max-height: calc(100vh - 90px);
        overflow: auto;
        scrollbar-width: thin;
        min-width: 0;
    }
    .rpt-nav-head {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 900;
        color: #64748b;
        padding: 8px 12px 10px;
        text-transform: none;
    }
    .rpt-nav-link {
        display: block;
        padding: 8px 10px 8px 30px;
        border-radius: 8px;
        color: #667085;
        font-size: 15px;
        font-weight: 700;
        text-decoration: none;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }
    .rpt-nav-link:hover { background: #eef4f8; color: #0f172a; }
    .rpt-nav-link.active { background: #e9edf3; color: #020617; font-weight: 900; }
    .rpt-legacy-card {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 12px 4px 4px;
        padding: 10px;
        border: 1px solid #d7ecf7;
        border-radius: 8px;
        background: #f6fbfe;
        color: #0f172a;
        text-decoration: none;
        box-shadow: 0 8px 22px rgba(35,150,200,.08);
    }
    .rpt-legacy-card:hover {
        border-color: var(--flow-blue);
        color: #0b76a5;
        background: #eef8fd;
    }
    .rpt-legacy-icon {
        width: 34px;
        height: 34px;
        display: inline-grid;
        place-items: center;
        border-radius: 9px;
        background: var(--flow-blue);
        color: #fff;
        flex: 0 0 auto;
    }
    .rpt-legacy-card strong {
        display: block;
        font-size: 13px;
        font-weight: 900;
        line-height: 1.2;
    }
    .rpt-legacy-card small {
        display: block;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.35;
        margin-top: 2px;
    }
    .rpt-nav-cat {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        border: 0;
        background: none;
        padding: 9px 10px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 850;
        color: #0f172a;
        cursor: pointer;
        font-family: inherit;
        text-align: left;
    }
    .rpt-nav-cat:hover { background: #f1f5f9; }
    .rpt-nav-cat.current { background: transparent; color: #020617; }
    .rpt-nav-sub { padding-left: 2px; }

    .rpt-toolbar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        background: rgba(255,255,255,.86);
        border: 1px solid #bae6fd;
        border-radius: 12px;
        padding: 12px;
        margin-bottom: 24px !important;
        box-shadow: 0 10px 30px rgba(2,132,199,.08);
    }
    .rpt-report-picker {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: min(100%, 430px);
        margin: 0;
        color: #0f172a;
        font-size: 14px;
        font-weight: 900;
    }
    .rpt-report-picker span {
        white-space: nowrap;
        color: var(--flow-blue-dark);
    }
    .rpt-date-input,
    .rpt-select {
        border: 1px solid #bae6fd;
        border-radius: 6px;
        padding: 9px 12px;
        font-size: 15px;
        background: #f8fcff;
        color: var(--flow-blue-ink);
        outline: none;
        font-family: inherit;
        max-width: 100%;
    }
    .rpt-date-input { width: 170px; }
    .rpt-select { min-width: 170px; max-width: 260px; }
    .rpt-report-select {
        flex: 1 1 auto;
        width: 100%;
        max-width: none;
    }
    .rpt-date-input:focus,
    .rpt-select:focus { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56,189,248,.18); }
    .rpt-shortcut {
        font-size: 12px;
        color: var(--flow-blue-dark);
        padding: 5px 10px;
        border: 1px solid #bae6fd;
        border-radius: 999px;
        text-decoration: none;
        background: #fff;
        white-space: nowrap;
    }
    .rpt-shortcut:hover { background: #f1f5f9; color: #0f172a; }
    .rpt-btn-filter,
    .rpt-btn-export,
    .rpt-btn-print {
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
        font-family: inherit;
        white-space: nowrap;
    }
    .rpt-btn-filter {
        background: linear-gradient(180deg, #38bdf8, var(--flow-blue));
        color: #fff;
        border: 1px solid #0284c7;
        min-width: 104px;
        padding: 9px 16px;
        font-size: 15px;
        border-radius: 6px;
    }
    .rpt-btn-export,
    .rpt-btn-print {
        display: none;
    }

    .rpt-panel-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 12px 24px;
        flex-wrap: wrap;
        border: 1px solid var(--flow-line);
        border-top: 3px solid var(--flow-blue);
        border-bottom: 0;
        border-radius: 0;
        background: #f8fcff;
    }
    .erp-search { position: relative; min-width: 260px; }
    .erp-search .erp-search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 13px;
        pointer-events: none;
    }

    .rpt-print-header { display: none; }

    /* Compact ERP report workspace: toolbar first, data immediately below. */
    .flow-report-shell { min-height:calc(100vh - 72px); background:#f4f7fa; }
    .rpt-main { padding:12px 14px; }
    .flow-report-head { min-height:38px; margin-bottom:8px; gap:10px; flex-wrap:nowrap; }
    .flow-eyebrow { margin-bottom:1px; font-size:10px; }
    .flow-report-head h1 { font-size:19px; line-height:1.2; }
    .flow-head-actions { gap:6px; flex-wrap:nowrap; }
    .flow-action-btn { min-height:31px; padding:5px 9px; border-radius:5px; font-size:11px; font-weight:700; }
    .flow-action-link { gap:5px; }
    .rpt-toolbar { gap:5px; padding:7px 8px; margin-bottom:8px!important; border-radius:6px; box-shadow:none; flex-wrap:nowrap; }
    .rpt-report-picker { min-width:260px; flex:1 1 340px; gap:5px; font-size:10px; }
    .rpt-date-input,.rpt-select { height:31px; padding:4px 7px; border-radius:4px; font-size:11px; }
    .rpt-date-input { width:125px; }
    .rpt-select { min-width:115px; max-width:165px; }
    .rpt-report-select { min-width:210px; max-width:none; }
    .rpt-shortcut { padding:3px 6px; border-radius:4px; font-size:9px; }
    .rpt-btn-filter { min-width:66px; height:31px; padding:4px 9px; border-radius:4px; font-size:11px; }
    .flow-report-summary { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:7px 12px; border-radius:5px 5px 0 0; background:#f8fbfd; }
    .flow-dash { display:none; }
    .flow-report-meta dl { display:flex; align-items:center; gap:16px; margin:0; }
    .flow-report-meta dl div { display:flex; align-items:center; gap:5px; }
    .flow-report-meta dt,.flow-report-meta dd { font-size:10px; line-height:1.2; }
    .flow-report-meta dt { color:#64748b; font-weight:600; }
    .flow-report-totals { display:flex; align-items:center; gap:16px; padding:0; }
    .flow-report-totals div { display:flex; align-items:center; gap:5px; }
    .flow-report-totals span { font-size:10px; color:#64748b; font-weight:600; }
    .flow-report-totals strong { font-size:12px; }
    .flow-tabs { display:none; }
    .flow-report-shell .content-card { border-radius:5px; box-shadow:0 3px 10px rgba(15,51,74,.05); }
    .rpt-panel-title { min-height:42px; padding:6px 10px; border-top-width:1px; }
    .rpt-panel-title .fs-5 { font-size:13px!important; }
    .rpt-panel-title .small { font-size:9px!important; }
    .rpt-panel-title .form-select-sm,.rpt-panel-title .form-control-sm { min-height:28px; height:28px; font-size:10px; }
    .erp-search { min-width:180px; }
    .report-data-table { font-size:10px; }
    .report-data-table th,.report-data-table td { padding:5px 7px!important; line-height:1.25; }
    @media(max-width:1300px){.rpt-shortcut{display:none}.rpt-report-picker span{display:none}.flow-action-btn{padding-left:7px;padding-right:7px}}
    @media print {
        .app-sidebar, .app-header, .flow-rail, .no-print { display: none !important; }
        .flow-report-shell, .rpt-shell { display: block; margin: 0; }
        .content-card { border: none; box-shadow: none; }
        .rpt-print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .rpt-print-name { font-size: 17px; font-weight: 900; color: #0f172a; }
        .rpt-print-title { font-size: 15px; font-weight: 800; color: #0f172a; }
        .rpt-print-meta { text-align: right; flex-shrink: 0; }
        .rpt-print-sub { font-size: 11.5px; color: #334155; line-height: 1.55; }
    }
    @media (max-width: 1100px) {
        .flow-report-shell { grid-template-columns: 1fr; margin: 0; }
        .flow-rail { display: none; }
        .rpt-nav { position: static; max-height: 300px; }
        .rpt-main { padding: 16px; }
        .flow-report-summary { grid-template-columns: 1fr; }
        .rpt-toolbar { flex-wrap: wrap; }
    }
    @media (max-width: 767.98px) {
        .flow-report-shell { display: block; }
        .rpt-nav { max-height: 260px; border-right: 0; border-bottom: 1px solid #e2e8f0; }
        .rpt-main { padding: 14px; }
        .flow-report-head h1 { font-size: 23px; }
        .flow-head-actions { width: 100%; justify-content: stretch; }
        .flow-action-btn { flex: 1 1 160px; }
        .rpt-date-input, .rpt-select { width: 100%; flex: 1 1 160px; }
        .rpt-report-picker { flex: 1 1 100%; align-items: stretch; flex-direction: column; gap: 6px; }
        .flow-tabs { overflow-x: auto; padding: 0 12px; }
        .flow-tabs span { min-width: 140px; }
        .flow-report-summary, .rpt-panel-title { padding-left: 14px; padding-right: 14px; }
        .erp-search { min-width: 100%; }
    }
</style>
@endpush

@push('scripts')
<script>
function selectReport(value) {
    const [category, report] = value.split('|');
    const form = document.getElementById('rform');
    form.elements.category.value = category;
    form.elements.report.value = report;
    form.submit();
}

function exportCsv() {
    const rows = [...document.querySelectorAll('.report-data-table tr')].map(row =>
        [...row.children].map(c => `"${c.innerText.replaceAll('"', '""')}"`).join(',')
    );
    const blob = new Blob(["\uFEFF" + rows.join("\n")], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = '{{ $selectedCategory }}-{{ $selectedReport }}.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>
@endpush
