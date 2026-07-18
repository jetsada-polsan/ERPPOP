@extends('layout')
@section('title', 'ทะเบียนทรัพย์สิน - POPSTAR ERP')
@section('page-title', 'ทะเบียนทรัพย์สิน / ค่าเสื่อมราคา')
@section('page-subtitle', 'บันทึกทรัพย์สินถาวรและคิดค่าเสื่อมราคาแบบเส้นตรงรายเดือน')
@section('content')
<div x-data="{ addOpen: false }" x-cloak>

    {{-- สรุปมูลค่า --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="content-card p-3"><div class="text-muted small">ราคาทุนรวม</div><div class="h4 fw-bold mb-0">฿{{ number_format($totals['cost'], 2) }}</div></div></div>
        <div class="col-md-4"><div class="content-card p-3"><div class="text-muted small">ค่าเสื่อมสะสม</div><div class="h4 fw-bold mb-0 text-warning">฿{{ number_format($totals['accumulated'], 2) }}</div></div></div>
        <div class="col-md-4"><div class="content-card p-3"><div class="text-muted small">มูลค่าตามบัญชี (คงเหลือ)</div><div class="h4 fw-bold mb-0 text-success">฿{{ number_format($totals['book_value'], 2) }}</div></div></div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" style="width:170px" onchange="this.form.submit()">
                <option value="">ทุกสถานะ</option>
                <option value="active" @selected($status === 'active')>ใช้งาน</option>
                <option value="fully_depreciated" @selected($status === 'fully_depreciated')>คิดค่าเสื่อมครบ</option>
                <option value="disposed" @selected($status === 'disposed')>จำหน่าย/ตัดออก</option>
            </select>
            <input type="text" name="q" value="{{ $q }}" class="form-control form-control-sm" style="width:220px" placeholder="รหัส / ชื่อ / หมวด">
            <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </form>

        {{-- คิดค่าเสื่อมทั้งงวด --}}
        <form method="post" action="{{ route('fixed-assets.depreciate') }}" class="d-flex gap-1 align-items-center" onsubmit="return confirm('คิดค่าเสื่อมของทรัพย์สินที่ใช้งานอยู่ทั้งหมดสำหรับงวดนี้?')">
            @csrf
            <input type="month" name="period" value="{{ $currentPeriod }}" class="form-control form-control-sm" style="width:150px" required>
            <button class="btn btn-sm btn-warning text-nowrap"><i class="bi bi-calculator me-1"></i>คิดค่าเสื่อมงวดนี้</button>
        </form>

        <button type="button" class="btn btn-success ms-auto" @click="addOpen = !addOpen"><i class="bi bi-plus-lg me-1"></i>เพิ่มทรัพย์สิน</button>
    </div>

    {{-- ฟอร์มเพิ่มทรัพย์สิน --}}
    <div class="content-card p-4 mb-3" x-show="addOpen">
        <h3 class="h6 fw-bold mb-3">เพิ่มทรัพย์สินถาวร</h3>
        <form method="post" action="{{ route('fixed-assets.store') }}" class="row g-3">
            @csrf
            <div class="col-md-2"><label class="form-label small text-muted">รหัสทรัพย์สิน</label><input name="asset_code" required class="form-control"></div>
            <div class="col-md-4"><label class="form-label small text-muted">ชื่อทรัพย์สิน</label><input name="name" required class="form-control" placeholder="เช่น รถกระบะ / ตู้แช่แข็ง"></div>
            <div class="col-md-3"><label class="form-label small text-muted">หมวด</label><input name="category" class="form-control" placeholder="เช่น ยานพาหนะ / เครื่องมือ"></div>
            <div class="col-md-3">
                <label class="form-label small text-muted">สาขา</label>
                <select name="branch_id" class="form-select">
                    <option value="">-- ส่วนกลาง --</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2"><label class="form-label small text-muted">วันที่ได้มา</label><input type="date" name="acquired_date" required class="form-control" value="{{ now()->toDateString() }}"></div>
            <div class="col-md-3"><label class="form-label small text-muted">ราคาทุน</label><input type="number" step="0.01" min="0.01" name="cost" required class="form-control text-end"></div>
            <div class="col-md-3"><label class="form-label small text-muted">มูลค่าซาก (ถ้ามี)</label><input type="number" step="0.01" min="0" name="salvage_value" class="form-control text-end" value="0"></div>
            <div class="col-md-2"><label class="form-label small text-muted">อายุใช้งาน (ปี)</label><input type="number" step="0.5" min="0.5" name="useful_life_years" required class="form-control text-end" placeholder="เช่น 5"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">บันทึก</button></div>
            <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
        </form>
    </div>

    <div class="content-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>รหัส</th><th>ชื่อ</th><th>หมวด</th><th>วันที่ได้มา</th><th class="text-end">ราคาทุน</th><th class="text-end">ค่าเสื่อม/เดือน</th><th class="text-end">ค่าเสื่อมสะสม</th><th class="text-end">มูลค่าคงเหลือ</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                @forelse($assets as $asset)
                    <tr>
                        <td class="fw-semibold">{{ $asset->asset_code }}</td>
                        <td>{{ $asset->name }}</td>
                        <td class="small text-muted">{{ $asset->category ?? '-' }}</td>
                        <td class="text-nowrap small">{{ $asset->acquired_date->thaiDate() }}</td>
                        <td class="text-end">{{ number_format($asset->cost, 2) }}</td>
                        <td class="text-end small">{{ number_format($asset->monthlyDepreciation(), 2) }}</td>
                        <td class="text-end text-warning">{{ number_format($asset->accumulated_depreciation, 2) }}</td>
                        <td class="text-end fw-semibold text-success">{{ number_format($asset->bookValue(), 2) }}</td>
                        <td><span class="badge {{ ['active' => 'text-bg-success', 'fully_depreciated' => 'text-bg-secondary', 'disposed' => 'text-bg-dark'][$asset->status] ?? 'text-bg-light' }}">{{ $asset->statusLabel() }}</span></td>
                        <td class="text-end"><a href="{{ route('fixed-assets.show', $asset) }}" class="btn btn-sm btn-light border">ดู</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-5">ยังไม่มีทรัพย์สิน — เพิ่มด้านบน</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $assets->links() }}</div>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
