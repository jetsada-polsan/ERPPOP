@extends('layout')

@section('content')
<div class="summary-page">
    <form class="summary-toolbar no-print" method="get">
        <div class="summary-heading">
            <span class="summary-icon"><i class="bi bi-clipboard2-check"></i></span>
            <div>
                <h1>ใบรวมจอง</h1>
                <p>ตรวจสินค้าและส่งบัญชีตัดยอด</p>
            </div>
        </div>

        <label>วันที่<input type="date" name="date" value="{{ $date->toDateString() }}"></label>
        <label>สาขา
            <select name="branch_id">
                <option value="">ทุกสาขา</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->code }} {{ $branch->name_th }}</option>
                @endforeach
            </select>
        </label>
        <label>สายขาย
            <select name="sales_area_id">
                <option value="">ทุกสาย</option>
                @foreach($salesAreas as $area)
                    <option value="{{ $area->id }}" @selected($salesAreaId === $area->id)>{{ $area->code }} {{ $area->name }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>แสดง</button>
        <a class="btn btn-outline-primary" href="{{ route('bookings.cover-sheet', ['date' => $date->toDateString(), 'branch_id' => $branchId, 'sales_area_id' => $salesAreaId ?: 'all']) }}"><i class="bi bi-truck me-1"></i>ใบปะหน้า (ขนส่ง)</a>
        <button type="button" class="btn btn-outline-secondary" onclick="printSection('all')"><i class="bi bi-printer me-1"></i>พิมพ์ใบรวมจอง</button>
        <button type="button" class="btn btn-outline-primary" onclick="printSection('bills')"><i class="bi bi-receipt me-1"></i>พิมพ์ใบขึ้นของ (ทีละบิล)</button>
        <button type="button" class="btn btn-outline-success" onclick="printSection('items')"><i class="bi bi-box-seam me-1"></i>พิมพ์รายการสินค้าที่ต้องเตรียม</button>
        <button type="button" class="btn btn-outline-info" onclick="printSection('route')"><i class="bi bi-truck me-1"></i>พิมพ์ใบเช็คส่งของประจำสาย</button>
        <a href="{{ route('documents.delivery-notes.batch', array_filter(['date' => $date->toDateString(), 'branch_id' => $branchId, 'sales_area_id' => $salesAreaId, 'type' => 'booking'])) }}" target="_blank" class="btn btn-success text-white"><i class="bi bi-truck me-1"></i>พิมพ์ใบส่งของทั้งหมดตามตัวกรองนี้</a>
        <a href="{{ route('bookings.comparison') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left-right me-1"></i>เทียบใบจองกับใบขาย</a>
        <a class="btn btn-link" href="{{ route('bookings.index') }}">กลับ</a>
    </form>

    <div class="summary-meta">
        <div>
            <strong id="meta-title">ใบรวมจอง: เช็คสินค้า</strong>
            <span>{{ $date->format('d/m/Y') }} · {{ $branches->firstWhere('id', $branchId)?->name_th ?? 'ทุกสาขา' }} · {{ $salesAreas->firstWhere('id', $salesAreaId)?->name ?? 'ทุกสาย' }}</span>
        </div>
        <b>{{ $bookings->count() }} ใบจอง</b>
    </div>

    <!-- ส่วนที่ 1: ใบขึ้นของ ทีละบิล -->
    <section class="summary-card" id="section-bills">
        <div class="section-title">
            <i class="bi bi-clipboard2-check"></i>
            <div>
                <h2>ใบขึ้นของ ทีละบิล</h2>
                <small>ติ๊กขึ้นได้หรือไม่ได้ ถ้าไม่ครบใส่จำนวนจริง แล้วนำไปแก้ใบจอง</small>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="btn btn-xs btn-outline-primary no-print" onclick="printSection('bills')">
                    <i class="bi bi-printer me-1"></i>พิมพ์ส่วนนี้
                </button>
                <span>{{ $bills->count() }} บิล</span>
            </div>
        </div>

        @forelse($bills as $booking)
            <div class="load-bill">
                <div class="load-bill-head">
                    <strong class="load-bill-doc">{{ $booking->document->doc_number }}</strong>
                    <span class="load-bill-customer">{{ $booking->document->customer?->code }} {{ $booking->document->customer?->name_th }}</span>
                    <strong class="load-bill-amount">฿{{ number_format((float) $booking->document->total_amount, 2) }}</strong>
                </div>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">#</th>
                            <th>สินค้า</th>
                            <th class="barcode-col" style="display: none; width: 140px;">บาร์โค้ดหน่วยที่จอง</th>
                            <th style="width: 90px;">หน่วย</th>
                            <th class="text-end" style="width: 80px;">จอง</th>
                            <th class="check-col">ขึ้นได้</th>
                            <th class="check-col">ไม่ได้</th>
                            <th style="width: 100px;">จำนวนจริง</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($booking->document->stockDocument?->items ?? [] as $item)
                            @php
                                $bCode = $item->product?->barcodes->where('unit_id', $item->unit_id)->where('is_active', true)->value('barcode')
                                    ?? $item->product?->barcodes->where('is_active', true)->value('barcode')
                                    ?? '';
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $item->product?->sku_code }} {{ $item->product?->name_th }}</td>
                                <td class="barcode-col font-monospace" style="display: none;">{{ $bCode }}</td>
                                <td>{{ $item->unit?->name ?? $item->product?->baseUnit?->name ?? '-' }}</td>
                                <td class="text-end"><x-unit-qty :item="$item" /></td>
                                <td class="check-col">□</td>
                                <td class="check-col">□</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-center text-muted p-3 mb-0">ไม่มีใบจองค้าง</p>
        @endforelse
    </section>

    <!-- ส่วนที่ 2: รายการสินค้าที่ต้องเตรียม -->
    <section class="summary-card" id="section-items">
        <div class="section-title">
            <i class="bi bi-box-seam"></i>
            <div>
                <h2>รายการสินค้าที่ต้องเตรียม</h2>
                <small>รวมจำนวนจากใบจองตามตัวกรอง (คลิกแถวสินค้าเพื่อดูใบจองและจำนวนย่อย)</small>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="btn btn-xs btn-outline-success no-print" onclick="printSection('items')">
                    <i class="bi bi-printer me-1"></i>พิมพ์ส่วนนี้
                </button>
                <span id="items-badge">{{ $items->count() }} รายการ</span>
            </div>
        </div>

        <!-- ตัวกรองค้นหาสินค้า / สายขาย / คลัง / แสดงบาร์โค้ด (no-print) -->
        <div class="prep-filter-toolbar no-print p-3 border-bottom bg-light">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="prep-search" class="form-control" placeholder="ค้นหารหัส หรือชื่อสินค้า...">
                    </div>
                </div>
                <div class="col-md-2">
                    <select id="prep-area-filter" class="form-select form-select-sm">
                        <option value="">ทุกสายขาย</option>
                        @foreach($salesAreas as $area)
                            <option value="{{ $area->id }}">{{ $area->code }} {{ $area->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="prep-warehouse-filter" class="form-select form-select-sm">
                        <option value="">ทุกคลังสินค้า</option>
                        @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="toggle-barcode">
                        <label class="form-check-label small fw-bold text-secondary" for="toggle-barcode">
                            แสดงบาร์โค้ดหน่วยที่จอง
                        </label>
                    </div>
                </div>
                <div class="col-md-2 text-end d-flex gap-2 justify-content-end">
                    <button type="button" id="btn-toggle-all-breakdown" class="btn btn-outline-secondary btn-sm" title="ขยาย/ย่อรายละเอียดทั้งหมด">
                        <i class="bi bi-chevron-expand me-1"></i>ขยาย/ย่อ
                    </button>
                    <button type="button" id="btn-reset-prep-filters" class="btn btn-outline-secondary btn-sm" title="ล้างตัวกรอง">
                        ล้าง
                    </button>
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="summary-table" id="table-prep-items">
                <thead>
                    <tr>
                        <th style="width: 140px;">รหัส</th>
                        <th class="barcode-col" style="display: none; width: 150px;">บาร์โค้ดหน่วยที่จอง</th>
                        <th>สินค้า</th>
                        <th class="text-end" style="width: 100px;">รวม</th>
                        <th style="width: 80px;">หน่วย</th>
                        <th class="check-col">เช็ค</th>
                    </tr>
                </thead>
                <tbody id="prep-items-tbody">
                    @forelse($items as $item)
                        <tr class="prep-item-row"
                            id="prep-row-{{ $item->id }}"
                            data-id="{{ $item->id }}"
                            data-sku="{{ strtolower($item->sku_code) }}"
                            data-name="{{ strtolower($item->name_th) }}"
                            data-areas="{{ $item->area_ids->unique()->implode(',') }}"
                            data-warehouses="{{ $item->warehouse_ids->unique()->implode(',') }}"
                            data-base-qty="{{ $item->total_qty }}"
                            onclick="togglePrepBreakdown({{ $item->id }})"
                            title="คลิกเพื่อดูรายการใบจองที่จองสินค้านี้">
                            <td class="fw-bold text-primary">
                                <i class="bi bi-chevron-right prep-chevron me-1 no-print" id="chevron-{{ $item->id }}"></i>
                                {{ $item->sku_code }}
                            </td>
                            <td class="barcode-col font-monospace" style="display: none;">{{ $item->barcode }}</td>
                            <td>
                                <div>{{ $item->name_th }}</div>
                                <small class="text-muted no-print"><span id="booking-count-{{ $item->id }}">{{ $item->bookings->count() }}</span> ใบจอง (คลิกดูรายละเอียด)</small>
                            </td>
                            <td class="text-end fw-bold prep-qty-cell" id="qty-{{ $item->id }}"><x-unit-qty :product="$item->product" :qty="$item->total_qty" /></td>
                            <td>{{ $item->unit_name }}</td>
                            <td class="check-col">□</td>
                        </tr>
                        <tr class="prep-breakdown-row no-print" id="breakdown-{{ $item->id }}" style="display: none; background: #fbfdff;">
                            <td colspan="6" class="p-3" style="border-bottom: 2px solid #dce7ef;">
                                <div class="card border-0 shadow-sm rounded-3 p-2 bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                                        <span class="small fw-bold text-secondary">
                                            <i class="bi bi-card-checklist me-1"></i> รายการใบจองที่มีสินค้านี้: <strong>{{ $item->sku_code }} {{ $item->name_th }}</strong>
                                        </span>
                                        <span class="badge bg-secondary rounded-pill" id="breakdown-badge-{{ $item->id }}">{{ $item->bookings->count() }} ใบจอง</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered bg-white mb-0 small">
                                            <thead>
                                                <tr class="table-light">
                                                    <th style="width: 45px;" class="text-center">#</th>
                                                    <th style="width: 150px;">เลขที่ใบจอง</th>
                                                    <th>ลูกค้า</th>
                                                    <th style="width: 140px;">สายขาย</th>
                                                    <th style="width: 140px;">คลังตัดสต็อก</th>
                                                    <th style="width: 110px;" class="text-end">จำนวนที่จอง</th>
                                                </tr>
                                            </thead>
                                            <tbody id="breakdown-tbody-{{ $item->id }}">
                                                @foreach($item->bookings as $b)
                                                    <tr class="prep-sub-row" data-area-id="{{ $b->sales_area_id }}" data-warehouse-id="{{ $b->warehouse_id }}" data-qty="{{ $b->qty }}">
                                                        <td class="text-center text-muted">{{ $loop->iteration }}</td>
                                                        <td class="font-monospace fw-bold text-primary">{{ $b->doc_number }}</td>
                                                        <td>{{ $b->customer_name }}</td>
                                                        <td>{{ $b->sales_area_name }}</td>
                                                        <td>{{ $b->warehouse_name }}</td>
                                                        <td class="text-end fw-bold"><x-unit-qty :product="$b->product" :item="$b->stock_item" :qty="$b->qty" /></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr id="prep-no-items-row"><td colspan="6" class="text-center text-muted py-3">ไม่มีใบจองค้าง</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- ส่วนที่ 3: ใบเช็คส่งของประจำสาย -->
    <section class="summary-card" id="section-route">
        <div class="section-title">
            <i class="bi bi-truck"></i>
            <div>
                <h2>ใบเช็คส่งของประจำสาย</h2>
                <small>ส่งบัญชีตัดยอดหลังตรวจส่งสินค้า</small>
            </div>
            <div class="ms-auto d-flex align-items-center gap-2">
                <button type="button" class="btn btn-xs btn-outline-info no-print" onclick="printSection('route')">
                    <i class="bi bi-printer me-1"></i>พิมพ์ส่วนนี้
                </button>
                <span>{{ $bookings->count() }} รายการ</span>
            </div>
        </div>
        <div class="table-wrap">
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 160px;">เลขที่ใบจอง</th>
                        <th>ลูกค้า</th>
                        <th class="text-end" style="width: 120px;">ยอด</th>
                        <th class="check-col">ส่งครบ</th>
                        <th class="check-col">ไม่ส่ง</th>
                        <th style="width: 160px;">หมายเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bookings as $booking)
                        <tr>
                            <td class="font-monospace">{{ $booking->doc_number }}</td>
                            <td>{{ $booking->customer_name }}</td>
                            <td class="text-end">{{ number_format((float) $booking->total_amount, 2) }}</td>
                            <td class="check-col">□</td>
                            <td class="check-col">□</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">ไม่พบใบจอง</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="signatures">ผู้ส่งของ <span></span> ผู้ตรวจรับ (บัญชี) <span></span></div>
>>>>>>> origin/main
</div>

<style>
.summary-page { max-width: 1500px; margin: auto; padding: 22px; color: #243447; }
.summary-toolbar { display: flex; align-items: end; gap: 10px; padding: 14px 16px; background: #fff; border: 1px solid #dce7ef; border-radius: 14px; box-shadow: 0 5px 18px #17324d0d; }
.summary-heading { display: flex; align-items: center; gap: 10px; margin-right: auto; }
.summary-icon { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: #e8f2ff; color: #1464c4; font-size: 20px; }
.summary-heading h1 { font-size: 18px; margin: 0; color: #123b63; }
.summary-heading p, .section-title small { margin: 2px 0 0; color: #7a8b9b; font-size: 11px; }
.summary-toolbar label { display: grid; gap: 4px; color: #63778a; font-size: 10px; font-weight: 700; }
.summary-toolbar input, .summary-toolbar select { height: 36px; min-width: 140px; border: 1px solid #d4e0ea; border-radius: 8px; padding: 0 9px; color: #243447; background: #fbfdff; }
.summary-toolbar .btn { height: 36px; border-radius: 8px; font-size: 12px; }
.summary-meta { display: flex; justify-content: space-between; align-items: center; padding: 22px 4px 12px; }
.summary-meta strong { display: block; font-size: 20px; color: #123b63; }
.summary-meta span { font-size: 12px; color: #76899a; }
.summary-meta b, .section-title > span { padding: 6px 10px; border-radius: 20px; background: #eaf4ff; color: #1464c4; font-size: 11px; }
.summary-card { margin-bottom: 16px; overflow: hidden; background: #fff; border: 1px solid #dce7ef; border-radius: 14px; box-shadow: 0 4px 16px #17324d0a; }
.section-title { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid #e8eef3; }
.section-title > i { font-size: 20px; color: #2878c9; }
.section-title h2 { margin: 0; font-size: 14px; color: #1d486e; }
.table-wrap { overflow: auto; }
.summary-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.summary-table th { background: #f4f8fb; color: #607589; font-size: 11px; text-transform: uppercase; letter-spacing: .02em; }
.summary-table th, .summary-table td { padding: 11px 14px; border-bottom: 1px solid #edf2f5; }
.summary-table tbody tr:hover { background: #f8fbfe; }
.check-col { width: 70px; text-align: center; }
.signatures { padding: 20px 4px; color: #5e7183; font-size: 12px; }
.signatures span { display: inline-block; width: 170px; margin: 0 18px; border-bottom: 1px solid #9aabba; vertical-align: middle; }

/* Load bill styling */
.load-bill { margin: 16px 14px; padding-bottom: 16px; border-bottom: 2px dashed #b8c7d6; break-inside: avoid; }
.load-bill:last-child { border-bottom: none; }
.load-bill-head { display: flex; gap: 14px; align-items: center; margin-bottom: 8px; font-size: 16px; font-weight: 700; }
.load-bill-head .load-bill-doc { font-size: 17px; font-weight: 800; color: #123b63; }
.load-bill-head .load-bill-customer { flex: 1; font-size: 16px; font-weight: 700; color: #243447; }
.load-bill-head .load-bill-amount { font-size: 16px; font-weight: 700; color: #0b5394; }

/* Prep items interactive row */
.prep-item-row { cursor: pointer; transition: background-color 0.15s ease; }
.prep-item-row:hover { background-color: #f1f7fc !important; }
.prep-chevron { display: inline-block; transition: transform 0.2s ease; }
.prep-chevron.rotated { transform: rotate(90deg); }

/* Responsive */
@media(max-width: 1000px) {
    .summary-toolbar { flex-wrap: wrap; }
    .summary-heading { width: 100%; }
    .summary-toolbar label { flex: 1; }
    .summary-toolbar input, .summary-toolbar select { width: 100%; min-width: 0; }
}
@media(max-width: 600px) {
    .summary-page { padding: 10px; }
    .summary-toolbar label { width: 100%; flex: auto; }
    .summary-toolbar .btn, .summary-toolbar a { flex: 1; text-align: center; }
    .summary-meta { align-items: flex-start; gap: 10px; }
    .summary-meta strong { font-size: 16px; }
}

/* Print CSS */
@media print {
    .no-print, .no-print * { display: none !important; }
    html body .app-sidebar { display: none !important; }
    .summary-page { padding: 0; max-width: none; }
    .summary-card { box-shadow: none; border: 1px solid #222; margin-bottom: 14px; }
    .summary-table th { background: #eee !important; color: #111 !important; font-weight: bold !important; font-size: 13px !important; }
    .summary-table td { font-size: 13px !important; line-height: 1.4 !important; padding: 6px 8px !important; }
    .summary-meta { padding-top: 0; }
    .signatures { page-break-inside: avoid; }

    /* Load bill print styling: bold >= 16px, table >= 13px, clear dashed divider */
    .load-bill { margin: 12px 0; padding-bottom: 12px; border-bottom: 2px dashed #444 !important; break-inside: avoid; page-break-inside: avoid; }
    .load-bill:last-child { border-bottom: none !important; }
    .load-bill-head { font-size: 16px !important; margin-bottom: 6px; }
    .load-bill-head .load-bill-doc,
    .load-bill-head .load-bill-customer,
    .load-bill-head .load-bill-amount {
        font-size: 16px !important;
        font-weight: 800 !important;
        color: #000 !important;
    }

    /* Print section isolation */
    body.print-section-bills #section-items,
    body.print-section-bills #section-route,
    body.print-section-bills .signatures {
        display: none !important;
    }

    body.print-section-items #section-bills,
    body.print-section-items #section-route,
    body.print-section-items .signatures {
        display: none !important;
    }

    body.print-section-route #section-bills,
    body.print-section-route #section-items {
        display: none !important;
    }

    /* Barcode column visibility control */
    body.show-barcode-col .barcode-col {
        display: table-cell !important;
    }
    body:not(.show-barcode-col) .barcode-col {
        display: none !important;
    }
}
@page { size: A4; margin: 10mm; }
</style>

<script>
// แยกพิมพ์แต่ละส่วน
function printSection(mode) {
    document.body.classList.remove('print-section-bills', 'print-section-items', 'print-section-route');
    const titleEl = document.getElementById('meta-title');
    const origTitle = titleEl ? titleEl.textContent : '';

    if (mode === 'bills') {
        document.body.classList.add('print-section-bills');
        if (titleEl) titleEl.textContent = 'ใบขึ้นของ (ทีละบิล)';
    } else if (mode === 'items') {
        document.body.classList.add('print-section-items');
        if (titleEl) titleEl.textContent = 'รายการสินค้าที่ต้องเตรียม';
    } else if (mode === 'route') {
        document.body.classList.add('print-section-route');
        if (titleEl) titleEl.textContent = 'ใบเช็คส่งของประจำสาย';
    } else {
        if (titleEl) titleEl.textContent = 'ใบรวมจอง: เช็คสินค้า';
    }

    window.print();
}

window.addEventListener('afterprint', () => {
    document.body.classList.remove('print-section-bills', 'print-section-items', 'print-section-route');
    const titleEl = document.getElementById('meta-title');
    if (titleEl) titleEl.textContent = 'ใบรวมจอง: เช็คสินค้า';
});

// ย่อ/ขยาย รายละเอียดใบจองของแต่ละสินค้า
function togglePrepBreakdown(itemId) {
    const row = document.getElementById('breakdown-' + itemId);
    const chevron = document.getElementById('chevron-' + itemId);
    if (!row) return;

    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = 'table-row';
        if (chevron) chevron.classList.add('rotated');
    } else {
        row.style.display = 'none';
        if (chevron) chevron.classList.remove('rotated');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('prep-search');
    const areaFilter = document.getElementById('prep-area-filter');
    const warehouseFilter = document.getElementById('prep-warehouse-filter');
    const barcodeToggle = document.getElementById('toggle-barcode');
    const btnReset = document.getElementById('btn-reset-prep-filters');
    const btnToggleAll = document.getElementById('btn-toggle-all-breakdown');
    const itemsBadge = document.getElementById('items-badge');

    let allExpanded = false;

    // Toggle barcode column
    if (barcodeToggle) {
        barcodeToggle.addEventListener('change', function () {
            const show = this.checked;
            document.querySelectorAll('.barcode-col').forEach(el => {
                el.style.display = show ? 'table-cell' : 'none';
            });
            if (show) {
                document.body.classList.add('show-barcode-col');
            } else {
                document.body.classList.remove('show-barcode-col');
            }
        });
    }

    // Filter logic
    function applyPrepFilters() {
        const query = (searchInput.value || '').trim().toLowerCase();
        const selectedArea = areaFilter.value;
        const selectedWarehouse = warehouseFilter.value;

        const rows = document.querySelectorAll('.prep-item-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const itemId = row.getAttribute('data-id');
            const sku = row.getAttribute('data-sku') || '';
            const name = row.getAttribute('data-name') || '';
            const breakdownRow = document.getElementById('breakdown-' + itemId);
            const subRows = breakdownRow ? breakdownRow.querySelectorAll('.prep-sub-row') : [];
            const qtyCell = document.getElementById('qty-' + itemId);
            const countBadge = document.getElementById('breakdown-badge-' + itemId);
            const bookingCountText = document.getElementById('booking-count-' + itemId);

            // 1. Text filter (sku or name)
            const textMatch = !query || sku.includes(query) || name.includes(query);

            if (!textMatch) {
                row.style.display = 'none';
                if (breakdownRow) breakdownRow.style.display = 'none';
                return;
            }

            // 2. Filter sub-rows (bookings) by area and warehouse
            let matchingSubCount = 0;
            let filteredQtySum = 0.0;

            subRows.forEach(sub => {
                const subArea = sub.getAttribute('data-area-id') || '';
                const subWarehouse = sub.getAttribute('data-warehouse-id') || '';
                const subQty = parseFloat(sub.getAttribute('data-qty') || '0');

                const areaMatch = !selectedArea || subArea === selectedArea;
                const warehouseMatch = !selectedWarehouse || subWarehouse === selectedWarehouse;

                if (areaMatch && warehouseMatch) {
                    sub.style.display = '';
                    matchingSubCount++;
                    filteredQtySum += subQty;
                } else {
                    sub.style.display = 'none';
                }
            });

            if (matchingSubCount > 0) {
                row.style.display = '';
                visibleCount++;
                if (qtyCell) qtyCell.textContent = filteredQtySum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                if (countBadge) countBadge.textContent = matchingSubCount + ' ใบจอง';
                if (bookingCountText) bookingCountText.textContent = matchingSubCount;
            } else {
                row.style.display = 'none';
                if (breakdownRow) breakdownRow.style.display = 'none';
            }
        });

        if (itemsBadge) {
            itemsBadge.textContent = visibleCount + ' รายการ';
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyPrepFilters);
    if (areaFilter) areaFilter.addEventListener('change', applyPrepFilters);
    if (warehouseFilter) warehouseFilter.addEventListener('change', applyPrepFilters);

    if (btnReset) {
        btnReset.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (areaFilter) areaFilter.value = '';
            if (warehouseFilter) warehouseFilter.value = '';
            applyPrepFilters();
        });
    }

    if (btnToggleAll) {
        btnToggleAll.addEventListener('click', function () {
            allExpanded = !allExpanded;
            document.querySelectorAll('.prep-breakdown-row').forEach(row => {
                const itemId = row.id.replace('breakdown-', '');
                const parentRow = document.getElementById('prep-row-' + itemId);
                const chevron = document.getElementById('chevron-' + itemId);
                if (parentRow && parentRow.style.display !== 'none') {
                    row.style.display = allExpanded ? 'table-row' : 'none';
                    if (chevron) {
                        if (allExpanded) chevron.classList.add('rotated');
                        else chevron.classList.remove('rotated');
                    }
                }
            });
        });
    }
});
</script>
@endsection
