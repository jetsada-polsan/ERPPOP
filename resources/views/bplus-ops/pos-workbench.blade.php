@extends('layout')
@section('title', 'เครื่องมือ POS')
@section('page-title', 'เครื่องมือ POS')
@section('page-subtitle', 'ดูเลขบิล POS ล่าสุด เพื่อออกใบกำกับภาษีและตรวจยอดหลังขาย')

@section('content')
<div class="pos-tool-shell">
    <div class="pos-help-strip">
        <div>
            <div class="pos-kicker">POS Tools</div>
            <h2>เช็กบิล POS ล่าสุด</h2>
            <p>เลือกวันที่/สาขา แล้วดูเลขบิลตอนลูกค้าขอใบกำกับภาษี หรือตรวจยอดหลังขาย</p>
        </div>
        <a href="{{ route('pos.index') }}" class="pos-open-btn"><i class="bi bi-cart-check"></i> เปิดหน้า POS</a>
    </div>

    <div class="pos-install-card">
        <div class="pos-install-icon"><i class="bi bi-windows"></i></div>
        <div class="pos-install-copy">
            <strong>ติดตั้งหรืออัปเดต JET POS บนเครื่องแคชเชียร์</strong>
            <span>Vue + Tauri รุ่น 0.1.7 · ขายผ่าน Local SQLite และ Sync ยอดขึ้น Host</span>
        </div>
        <a href="{{ route('pos.download') }}" class="pos-install-btn">
            <i class="bi bi-download"></i> ดาวน์โหลด/อัปเดต POS
        </a>
    </div>

    <div class="pos-note">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            <strong>พักบิล เรียกบิลคืน และยกเลิกบิล ทำที่หน้า POS โดยตรง</strong>
            ใช้ปุ่ม "พักบิล" ในหน้าขายได้เลย บิลที่พักจะเก็บไว้ในเครื่องแคชเชียร์เครื่องนั้น ไม่ต้องมาจดซ้ำที่หน้านี้
        </div>
    </div>

    <div id="latest-receipts" class="content-card pos-card">
        <div class="pos-card-head">
            <div>
                <div class="pos-card-label">เช็กเร็ว</div>
                <h3>บิล POS ล่าสุด</h3>
            </div>
            <i class="bi bi-receipt"></i>
        </div>
        <form method="get" action="{{ route('bplus.pos-workbench') }}" class="pos-filter row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label">วันที่ขาย</label>
                <input type="date" name="date" value="{{ $selectedDate }}" class="form-control">
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label">สาขา</label>
                <select name="branch_id" class="form-select" @disabled($lockedBranchId)>
                    <option value="">ทุกสาขา</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>
                    @endforeach
                </select>
                @if($lockedBranchId)
                    <input type="hidden" name="branch_id" value="{{ $lockedBranchId }}">
                @endif
            </div>
            <div class="col-12 col-md-3">
                <div class="pos-filter-actions">
                    <button class="btn pos-save-btn"><i class="bi bi-funnel me-1"></i> กรอง</button>
                    <a class="btn pos-light-btn" href="{{ route('bplus.pos-workbench', $selectedBranchId ? ['branch_id' => $selectedBranchId] : []) }}">
                        วันนี้
                    </a>
                </div>
            </div>
        </form>
        <div class="pos-filter-note">
            แสดงบิลวันที่ {{ \Illuminate\Support\Carbon::parse($selectedDate)->thaiDate() }}
            · สาขา {{ $selectedBranchId ? ($branches->firstWhere('id', $selectedBranchId)?->name_th ?? '-') : 'ทุกสาขา' }}
            · {{ $receipts->count() }} บิลล่าสุด
        </div>
        <div class="table-responsive pos-table-wrap">
            <table class="table pos-table align-middle">
                <thead>
                    <tr>
                        <th>เลขบิล</th>
                        <th>วันที่ขาย</th>
                        <th>สาขา</th>
                        <th>เครื่อง</th>
                        <th class="text-end">ยอดสุทธิ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receipts as $receipt)
                        <tr>
                            <td class="fw-bold">{{ $receipt->receipt_no }}</td>
                            <td>{{ $receipt->receipt_date?->thaiDate(true) }}</td>
                            <td>{{ $receipt->terminal?->branch?->name_th ?? $receipt->shift?->branch?->name_th ?? '-' }}</td>
                            <td><span class="pos-chip">{{ $receipt->terminal?->code ?? '-' }}</span></td>
                            <td class="text-end fw-bold">{{ number_format((float) $receipt->net_sales, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">ยังไม่มีบิล POS ตามวันที่/สาขาที่เลือก</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    .pos-tool-shell {
        display: grid;
        gap: 10px;
        max-width: 980px;
    }
    .pos-help-strip {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 12px;
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        box-shadow: 0 8px 22px rgba(2,132,199,.06);
    }
    .pos-kicker {
        color: #0284c7;
        font-size: 12px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: 4px;
    }
    .pos-help-strip h2 {
        margin: 0;
        color: #0c4a6e;
        font-size: 22px;
        font-weight: 900;
    }
    .pos-help-strip p {
        margin: 4px 0 0;
        color: #526b7d;
        font-size: 13px;
    }
    .pos-open-btn,
    .pos-save-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border: 0;
        border-radius: 10px;
        background: #0284c7;
        color: #fff;
        font-weight: 900;
        padding: 9px 14px;
        text-decoration: none;
        white-space: nowrap;
    }
    .pos-open-btn:hover,
    .pos-save-btn:hover {
        background: #0369a1;
        color: #fff;
    }
    .pos-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        padding: 12px 14px;
        border: 1px solid #fde68a;
        border-radius: 12px;
        background: #fffbeb;
        color: #78716c;
        font-size: 13px;
        line-height: 1.5;
    }
    .pos-install-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        background: #f0fdf4;
    }
    .pos-install-icon {
        display: grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #dcfce7;
        color: #15803d;
        font-size: 22px;
    }
    .pos-install-copy { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .pos-install-copy strong { color: #166534; font-weight: 900; }
    .pos-install-copy span { color: #4b6353; font-size: 12.5px; }
    .pos-install-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 14px;
        border-radius: 10px;
        background: #16a34a;
        color: #fff;
        font-weight: 900;
        text-decoration: none;
        white-space: nowrap;
    }
    .pos-install-btn:hover { background: #15803d; color: #fff; }
    .pos-note > i {
        color: #d97706;
        font-size: 18px;
        line-height: 1.2;
    }
    .pos-note strong {
        display: block;
        color: #92400e;
        font-weight: 900;
    }
    .pos-card {
        padding: 14px;
        border: 1px solid #d8eaf5;
        border-radius: 12px;
        box-shadow: 0 8px 22px rgba(15,23,42,.04);
    }
    .pos-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 8px;
    }
    .pos-card-head h3 {
        margin: 0;
        color: #0f172a;
        font-size: 18px;
        font-weight: 900;
    }
    .pos-card-head > i {
        color: #38bdf8;
        font-size: 24px;
    }
    .pos-card-label {
        color: #0284c7;
        font-size: 12px;
        font-weight: 900;
        margin-bottom: 2px;
    }
    .pos-card .form-label {
        color: #334155;
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 5px;
    }
    .pos-card .form-control,
    .pos-card .form-select {
        border-color: #cfe4f1;
        border-radius: 9px;
        min-height: 42px;
    }
    .pos-card .form-control:focus,
    .pos-card .form-select:focus {
        border-color: #38bdf8;
        box-shadow: 0 0 0 3px rgba(56,189,248,.16);
    }
    .pos-filter {
        margin-bottom: 8px;
        padding: 8px 10px;
        border: 1px solid #d8eaf5;
        border-radius: 10px;
        background: #f8fafc;
    }
    .pos-filter-actions {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 6px;
    }
    .pos-light-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #cfe4f1;
        border-radius: 10px;
        background: #fff;
        color: #0369a1;
        font-weight: 900;
        padding: 9px 12px;
        white-space: nowrap;
    }
    .pos-light-btn:hover {
        background: #e0f2fe;
        color: #0c4a6e;
        border-color: #bae6fd;
    }
    .pos-filter-note {
        margin-bottom: 8px;
        color: #64748b;
        font-size: 12.5px;
        font-weight: 800;
    }
    .pos-table-wrap {
        max-height: 480px;
        overflow: auto;
        border: 1px solid #eef5fa;
        border-radius: 10px;
    }
    .pos-table {
        margin-bottom: 0;
        font-size: 13px;
    }
    .pos-table th,
    .pos-table td {
        padding: .45rem .55rem;
        vertical-align: middle;
    }
    .pos-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #e0f2fe;
        color: #0c4a6e;
        border-bottom: 2px solid #bae6fd;
        font-size: 13px;
        font-weight: 900;
    }
    .pos-table td {
        border-bottom-color: #eef5fa;
    }
    .pos-chip {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        padding: 2px 9px;
        border-radius: 999px;
        background: #f0f9ff;
        color: #0369a1;
        font-weight: 900;
        font-size: 12px;
    }
    @media (max-width: 767.98px) {
        .pos-help-strip { align-items: stretch; flex-direction: column; }
        .pos-open-btn, .pos-save-btn { width: 100%; }
        .pos-filter-actions { grid-template-columns: 1fr; }
        .pos-install-card { grid-template-columns: auto minmax(0, 1fr); }
        .pos-install-btn { grid-column: 1 / -1; width: 100%; }
    }
</style>
@endpush
