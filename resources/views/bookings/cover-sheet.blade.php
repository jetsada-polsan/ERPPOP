@extends('layout')

@section('content')
<div class="cover-sheet-page">
    <!-- Toolbar / Filters -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
        <div class="card-body p-4">
            <form method="get" action="{{ route('bookings.cover-sheet') }}" id="coverSheetFilterForm">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fs-4 text-primary"><i class="bi bi-truck"></i></span>
                        <div>
                            <h1 class="h5 fw-bold mb-0">ใบปะหน้า (ใบจองรายวัน) สำหรับขนส่ง</h1>
                            <small class="text-muted">พิมพ์สรุปยอดส่งสินค้าต่อวันที่และสายขาย ให้ขนส่งเช็คแล้วนำส่งบัญชี</small>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('bookings.summary', ['date' => $date->toDateString(), 'branch_id' => $branchId, 'sales_area_id' => $salesAreaId]) }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="bi bi-clipboard2-check me-1"></i> ใบรวมจอง
                        </a>
                        <a href="{{ route('bookings.index') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="bi bi-arrow-left me-1"></i> รายการใบจอง
                        </a>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary mb-1">วันที่</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="{{ $date->toDateString() }}">
                    </div>

                    @if(count($branches) > 1)
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-secondary mb-1">สาขา</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">ทุกสาขา</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->code }} {{ $b->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary mb-1">สาย (Sale)</label>
                        <select name="sales_area_id" id="filter_sales_area" class="form-select form-select-sm">
                            <option value="all" @selected($isAllRoutes)>ทุกสาย (พิมพ์ต่อเนื่องทุกสาย)</option>
                            @foreach($salesAreas as $area)
                                <option value="{{ $area->id }}" @selected(!$isAllRoutes && $salesAreaId === $area->id)>{{ $area->code }} - {{ $area->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary mb-1">เลขที่ใบจอง</label>
                        <select name="booking_id" class="form-select form-select-sm">
                            <option value="">ทั้งหมดในวันนั้น</option>
                            @foreach($availableBookings as $b)
                                <option value="{{ $b->id }}" @selected($bookingId === $b->id)>
                                    {{ $b->document?->doc_number }} ({{ $b->document?->customer?->name_th ?? '-' }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary mb-1">
                            บัญชีโอน <span class="text-danger fw-normal">(*เลือกให้ตรงสายของท่าน)</span>
                        </label>
                        <select name="bank_account_id" id="filter_bank" class="form-select form-select-sm">
                            <option value="">— กรุณาเลือกบัญชีโอนให้ตรงสายของท่าน —</option>
                            @foreach($bankAccounts as $bank)
                                <option value="{{ $bank->id }}" @selected($bankAccountId === $bank->id)
                                        data-info="ธนาคาร {{ $bank->bank_name }} {{ $bank->account_name }} เลขที่บัญชี {{ $bank->account_no }}">
                                    {{ $bank->bank_name }} {{ $bank->account_name }} ({{ $bank->account_no }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2 col-sm-4">
                        <label class="form-label small fw-bold text-secondary mb-1">ผู้ส่งสินค้า</label>
                        <input type="text" name="sender_name" id="filter_sender" class="form-control form-control-sm"
                               placeholder="พิมพ์ชื่อ..." value="{{ $senderName }}">
                    </div>

                    <div class="col-md-2 col-sm-4">
                        <label class="form-label small fw-bold text-secondary mb-1">ผู้ตรวจนับสินค้า</label>
                        <input type="text" name="checker_name" id="filter_checker" class="form-control form-control-sm"
                               placeholder="พิมพ์ชื่อ..." value="{{ $checkerName }}">
                    </div>

                    <div class="col-md-2 col-sm-4">
                        <label class="form-label small fw-bold text-secondary mb-1">ผู้จัดทำบิล</label>
                        <input type="text" name="biller_name" id="filter_biller" class="form-control form-control-sm"
                               placeholder="พิมพ์ชื่อ..." value="{{ $billerName }}">
                    </div>

                    <div class="col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-search me-1"></i> แสดง
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div class="small text-muted">
                        <i class="bi bi-info-circle me-1"></i> ชื่อผู้ส่ง/ผู้ตรวจ/ผู้จัดทำ และบัญชีโอนประจำสาย ระบบจะบันทึกจำไว้ในเครื่องอัตโนมัติ
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-success btn-sm rounded-pill px-3 shadow-sm" onclick="window.print()">
                            <i class="bi bi-printer-fill me-1"></i> พิมพ์ A4
                        </button>
                        <a href="{{ route('bookings.cover-sheet', array_merge(request()->query(), ['export' => 'csv'])) }}"
                           class="btn btn-outline-success btn-sm rounded-pill px-3">
                            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Cover Sheets (A4 Paper Container) -->
    <div class="cover-sheets-wrapper">
        @php
            $thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
            $dateText = sprintf('%d %s %d', $date->day, $thaiMonths[$date->month] ?? '', $date->year + 543);
            $bookingDocText = $bookingId && ($bFound = $availableBookings->firstWhere('id', $bookingId))
                ? $bFound->document?->doc_number
                : 'ทั้งหมด';
        @endphp

        @forelse($routeSheets as $sheet)
            <div class="cover-sheet-sheet">
                <!-- Header -->
                <div class="sheet-head">
                    <div class="company-name">{{ $companyName }}</div>
                    <div class="doc-title">ใบจองสินค้า-{{ $sheet->areaName }} / สรุปยอดส่งสินค้า (รายวัน)</div>
                    <div class="meta-row">
                        <div><span class="label">วันที่:</span> <b>{{ $dateText }}</b></div>
                        <div><span class="label">สาย:</span> <b>{{ $sheet->areaName }}</b></div>
                        <div><span class="label">เลขที่ใบจอง:</span> <b>{{ $bookingDocText }}</b></div>
                    </div>
                </div>

                <!-- Table -->
                <table class="cover-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;" class="text-center">ลำดับ</th>
                            <th style="width: 36%;">ชื่อลูกค้า</th>
                            <th style="width: 16%;">เลขที่บิล</th>
                            <th style="width: 12%;" class="text-end">จำนวนเงิน</th>
                            <th style="width: 8%;" class="text-center">ยอดค้าง</th>
                            <th style="width: 8%;" class="text-center">รวม</th>
                            <th style="width: 15%;" class="text-center">หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sheet->bookings as $booking)
                            <tr>
                                <td class="text-center">{{ $loop->iteration }}</td>
                                <td class="customer-cell">{{ $booking->document?->customer?->name_th ?? $booking->document?->customer_name ?? '-' }}</td>
                                <td class="font-monospace fw-semibold doc-cell">{{ $booking->document?->doc_number ?? '-' }}</td>
                                <td class="text-end fw-bold">{{ number_format((float) ($booking->document?->total_amount ?? 0), 2) }}</td>
                                <td class="write-box"></td>
                                <td class="write-box"></td>
                                <td class="write-box"></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">ไม่พบใบจองค้างในสายนี้</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-total">
                            <td colspan="3" class="text-center fw-bold">รวม</td>
                            <td class="text-end fw-bold">{{ number_format($sheet->totalAmount, 2) }}</td>
                            <td class="write-box"></td>
                            <td class="write-box"></td>
                            <td class="write-box"></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Bottom Details Grid: Money Box & Vehicle Box -->
                <div class="bottom-grid avoid-break">
                    <!-- Money Box -->
                    <div class="box-pane">
                        <div class="box-title">ส่งเงินผ่านการโอน/เคาน์เตอร์เซอร์วิส/ตู้ ATM</div>
                        <div class="write-row">
                            <span>ค่าธรรมเนียม</span>
                            <span class="write-dots"></span>
                            <span>บาท</span>
                        </div>
                        <div class="write-row">
                            <span>ค่าน้ำมัน</span>
                            <span class="write-dots"></span>
                            <span>บาท</span>
                        </div>
                        <div class="write-row">
                            <span>ค่าใช้จ่ายอื่นๆ (ค่าน้ำมัน, ค่าจอดรถ)</span>
                            <span class="write-dots"></span>
                            <span>บาท</span>
                        </div>
                        <div class="write-row">
                            <span>ยอดลูกค้านำส่งโอนเข้าบัญชีบริษัท</span>
                            <span class="write-dots"></span>
                            <span>บาท</span>
                        </div>
                        <div class="write-row fw-bold">
                            <span>ยอดเก็บเงินสดทั้งหมด</span>
                            <span class="write-dots"></span>
                            <span>บาท</span>
                        </div>
                    </div>

                    <!-- Vehicle Info Box -->
                    <div class="box-pane">
                        <div class="box-title">ข้อมูลรถและการใช้น้ำมัน</div>
                        <div class="write-row">
                            <span>ทะเบียนรถ:</span>
                            <span class="write-dots"></span>
                        </div>
                        <div class="write-row-split">
                            <div class="split-col"><span>เลขไมล์เริ่มต้น:</span><span class="write-dots"></span></div>
                            <div class="split-col"><span>เลขไมล์สิ้นสุด:</span><span class="write-dots"></span></div>
                        </div>
                        <div class="write-row-split">
                            <div class="split-col"><span>ระยะทางที่วิ่ง:</span><span class="write-dots"></span><span>กม.</span></div>
                            <div class="split-col"><span>ออกกะเวลา:</span><span class="write-dots"></span></div>
                        </div>
                        <div class="write-row-split">
                            <div class="split-col"><span>เติมน้ำมันรอบเช้า:</span><span class="write-dots"></span><span>ลิตร</span></div>
                            <div class="split-col"><span>เติมน้ำมันเพิ่ม:</span><span class="write-dots"></span><span>ลิตร</span></div>
                        </div>
                        <div class="write-row fw-bold">
                            <span>รวมเติมน้ำมัน:</span>
                            <span class="write-dots"></span>
                            <span>ลิตร</span>
                        </div>
                    </div>
                </div>

                <!-- Signatures Row -->
                <div class="signs-row avoid-break">
                    <div class="sign-block">
                        <div class="sign-name sig_sender_name">{{ $senderName ?: '' }}</div>
                        <div class="sign-line"></div>
                        <div class="sign-role">ผู้ส่งสินค้า (ตัวบรรจง)</div>
                    </div>
                    <div class="sign-block">
                        <div class="sign-name sig_checker_name">{{ $checkerName ?: '' }}</div>
                        <div class="sign-line"></div>
                        <div class="sign-role">ผู้ตรวจนับสินค้า</div>
                    </div>
                    <div class="sign-block">
                        <div class="sign-name sig_biller_name">{{ $billerName ?: '' }}</div>
                        <div class="sign-line"></div>
                        <div class="sign-role">ผู้จัดทำบิล</div>
                    </div>
                    <div class="sign-block">
                        <div class="sign-name">&nbsp;</div>
                        <div class="sign-line"></div>
                        <div class="sign-role">ผู้ขนส่ง (ลงชื่อจริง)</div>
                    </div>
                </div>

                <!-- Bank Account Footer -->
                <div class="sheet-footer avoid-break">
                    <span class="label">โอนเข้า:</span>
                    <span class="bank-info-display">
                        @if($selectedBankAccount)
                            ธนาคาร {{ $selectedBankAccount->bank_name }} {{ $selectedBankAccount->account_name }} เลขที่บัญชี {{ $selectedBankAccount->account_no }}
                        @else
                            ธนาคาร .............................................................. เลขที่บัญชี ..............................................................
                        @endif
                    </span>
                </div>
            </div>
        @empty
            <div class="card p-5 text-center text-muted">
                ไม่พบข้อมูลใบจองตามเงื่อนไขที่เลือก
            </div>
        @endforelse
    </div>
</div>

<style>
/* Base Screen Styling */
.cover-sheet-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 16px;
    font-family: 'IBM Plex Sans Thai', sans-serif;
    color: #1e293b;
}

.cover-sheet-sheet {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
}

.sheet-head {
    border-bottom: 2px solid #0f172a;
    padding-bottom: 10px;
    margin-bottom: 12px;
}
.sheet-head .company-name {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
}
.sheet-head .doc-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e3a8a;
    margin-top: 2px;
}
.sheet-head .meta-row {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 6px;
    font-size: 13px;
    color: #334155;
}

/* Table Styling */
.cover-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 12.5px;
    margin-bottom: 14px;
}
/* ชื่อลูกค้าอยู่บรรทัดเดียว (ยาวเกินช่องค่อยตัดด้วย …) */
/* แถวสูงพอให้ขนส่งเขียนยอดค้าง/รวม/หมายเหตุด้วยมือ */
.cover-table tbody td {
    height: 8mm;
}
/* เลขที่บิลบรรทัดเดียว เพื่อเหลือที่ให้ช่องหมายเหตุ (ขนส่งเขียนบันทึก) */
.cover-table .doc-cell {
    white-space: nowrap !important;
    font-size: 11px !important;
}
.cover-table .customer-cell {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.cover-table th, .cover-table td {
    border: 1px solid #64748b;
    padding: 6px 8px;
    vertical-align: middle;
}
.cover-table th {
    background: #f1f5f9;
    color: #0f172a;
    font-weight: 700;
}
.cover-table .table-total td {
    background: #f8fafc;
    font-size: 13px;
    border-top: 2px solid #0f172a;
}
.write-box {
    background: #ffffff;
    min-width: 90px;
}

/* Bottom Grid: Money Box & Vehicle Box */
.bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}
.box-pane {
    border: 1px solid #64748b;
    border-radius: 4px;
    padding: 8px 12px;
    background: #fafafa;
}
.box-title {
    font-weight: 700;
    font-size: 12px;
    border-bottom: 1px solid #94a3b8;
    padding-bottom: 4px;
    margin-bottom: 6px;
    color: #0f172a;
}
.write-row {
    display: flex;
    align-items: baseline;
    gap: 6px;
    font-size: 11.5px;
    line-height: 1.8;
}
.write-row-split {
    display: flex;
    gap: 10px;
    font-size: 11.5px;
    line-height: 1.8;
}
.split-col {
    flex: 1;
    display: flex;
    align-items: baseline;
    gap: 4px;
}
.write-dots {
    flex: 1;
    border-bottom: 1px dotted #64748b;
    height: 12px;
}

/* Signatures */
.signs-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    margin-top: 14px;
    padding-top: 8px;
}
.sign-block {
    flex: 1;
    text-align: center;
    font-size: 11.5px;
}
.sign-name {
    height: 20px;
    font-weight: 600;
    color: #1e3a8a;
    font-size: 12px;
}
.sign-line {
    border-bottom: 1px dotted #475569;
    margin: 4px 8px 6px;
}
.sign-role {
    color: #475569;
}

/* Footer */
.sheet-footer {
    border-top: 1px solid #cbd5e1;
    padding-top: 8px;
    margin-top: 14px;
    font-size: 12px;
    color: #1e293b;
}

/* Print CSS */
@media print {
    .no-print {
        display: none !important;
    }
    body {
        background: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .cover-sheet-page {
        max-width: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .cover-sheet-sheet {
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        margin-bottom: 0 !important;
        page-break-after: always;
        break-after: page;
    }
    .cover-sheet-sheet:last-child {
        page-break-after: auto;
        break-after: auto;
    }
    thead {
        display: table-header-group;
    }
    tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .avoid-break {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}
@page {
    size: A4 portrait;
    margin: 8mm 10mm;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const senderInput = document.getElementById('filter_sender');
    const checkerInput = document.getElementById('filter_checker');
    const billerInput = document.getElementById('filter_biller');
    const bankSelect = document.getElementById('filter_bank');
    const salesAreaSelect = document.getElementById('filter_sales_area');

    // 1. Restore sender, checker, biller from localStorage if input was empty
    if (senderInput && !senderInput.value) {
        const savedSender = localStorage.getItem('cover_sheet_sender');
        if (savedSender) {
            senderInput.value = savedSender;
            updateSig('sig_sender_name', savedSender);
        }
    }
    if (checkerInput && !checkerInput.value) {
        const savedChecker = localStorage.getItem('cover_sheet_checker');
        if (savedChecker) {
            checkerInput.value = savedChecker;
            updateSig('sig_checker_name', savedChecker);
        }
    }
    if (billerInput && !billerInput.value) {
        const savedBiller = localStorage.getItem('cover_sheet_biller');
        if (savedBiller) {
            billerInput.value = savedBiller;
            updateSig('sig_biller_name', savedBiller);
        }
    }

    // 2. Real-time update signatures and localStorage on typing
    function updateSig(className, val) {
        document.querySelectorAll('.' + className).forEach(el => {
            el.textContent = val || '';
        });
    }

    if (senderInput) {
        senderInput.addEventListener('input', function () {
            localStorage.setItem('cover_sheet_sender', this.value);
            updateSig('sig_sender_name', this.value);
        });
    }
    if (checkerInput) {
        checkerInput.addEventListener('input', function () {
            localStorage.setItem('cover_sheet_checker', this.value);
            updateSig('sig_checker_name', this.value);
        });
    }
    if (billerInput) {
        billerInput.addEventListener('input', function () {
            localStorage.setItem('cover_sheet_biller', this.value);
            updateSig('sig_biller_name', this.value);
        });
    }

    // 3. Bank Account memory per route
    const currentRoute = salesAreaSelect ? salesAreaSelect.value : 'default';
    if (bankSelect && !bankSelect.value) {
        const savedBank = localStorage.getItem('cover_sheet_bank_' + currentRoute);
        if (savedBank && bankSelect.querySelector('option[value="' + savedBank + '"]')) {
            bankSelect.value = savedBank;
            updateBankDisplay();
        }
    }

    if (bankSelect) {
        bankSelect.addEventListener('change', function () {
            localStorage.setItem('cover_sheet_bank_' + currentRoute, this.value);
            updateBankDisplay();
        });
    }

    function updateBankDisplay() {
        if (!bankSelect) return;
        const opt = bankSelect.options[bankSelect.selectedIndex];
        const info = opt && opt.dataset.info ? opt.dataset.info : 'ธนาคาร .............................................................. เลขที่บัญชี ..............................................................';
        document.querySelectorAll('.bank-info-display').forEach(el => {
            el.textContent = info;
        });
    }
});
</script>
@endsection
