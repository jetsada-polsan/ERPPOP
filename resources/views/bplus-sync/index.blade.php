@extends('layout')
@section('title', 'เอกสารสายขาย (ระบบเดิม)')
@section('page-title', 'เอกสารสายขาย (ระบบเดิม)')
@section('page-subtitle', 'ใบจอง ใบขายเชื่อ ใบเสร็จ และลูกหนี้ยกมา ที่คีย์ในระบบเดิม แล้วซิงค์มา · ลงบัญชีตามยอดเอกสาร ไม่ตัดสต๊อก')
@section('content')
@php($m = fn ($v) => number_format((float) $v, 2))
@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

@if (auth()->user()?->hasPermission('finance.manage'))
<div class="content-card p-4 mb-3">
    <form method="post" action="{{ route('bplus.sync.import.preview') }}" enctype="multipart/form-data" class="row g-2 align-items-end">@csrf
        <div class="col-md-6"><label class="form-label small text-muted">ไฟล์จากสคริปต์ดึงระบบเดิม (.json)</label><input type="file" name="file" accept=".json,application/json" required class="form-control"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">ตรวจไฟล์ก่อนบันทึก</button></div>
        <div class="col-md-4 small text-muted">ซิงค์ล่าสุด: {{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d/m/Y H:i') : 'ยังไม่เคย' }}
            <div data-testid="last-auto-sync">ส่งอัตโนมัติล่าสุด:
                @if($lastAutoSync)
                    {{ \Illuminate\Support\Carbon::parse($lastAutoSync->created_at)->format('d/m/Y H:i') }}
                    @if($lastAutoSync->status === 'processing')
                        <span class="text-warning">กำลังบันทึก</span> {{ number_format($lastAutoSync->received) }} ใบ
                    @elseif($lastAutoSync->status === 'ok')
                        <span class="text-success">สำเร็จ</span> รับ {{ number_format($lastAutoSync->received) }} ใบ@if($lastAutoSync->problems) · <span class="text-danger">มีปัญหา {{ $lastAutoSync->problems }} ใบ</span>@endif
                    @else
                        <span class="text-danger">ไม่สำเร็จ</span> {{ $lastAutoSync->message }}
                    @endif
                @else
                    ยังไม่เคย
                @endif
            </div>
        </div>
    </form>
</div>
@endif

<div class="content-card p-4 mb-3">
    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-md-2"><label class="form-label small text-muted">ตั้งแต่</label><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ถึง</label><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ประเภท</label><select name="kind" class="form-select"><option value="">ทั้งหมด</option>@foreach ($kindLabels as $value => $label)<option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label small text-muted">ค้นหา เลขที่ / ใบจอง / ลูกค้า</label><input name="q" value="{{ $search }}" class="form-control"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100">แสดง</button></div>
    </form>

    <div class="row g-2 mb-3">
        @foreach ([
            ['ยอดขายรวม', $m($daily->sum('sales_total')), $daily->sum('sales').' ใบขายเชื่อ', 'primary'],
            ['VAT', $m($daily->sum('vat')), 'มี VAT '.$m($daily->sum('vatable')), 'secondary'],
            ['ยกเว้น VAT', $m($daily->sum('exempt')), '', 'secondary'],
            ['ยอดจอง', $m($daily->sum('booking_total')), $daily->sum('bookings').' ใบจอง', 'secondary'],
            ['รับเงินสด', $m($daily->sum('cash')), '', 'success'],
            ['รับเงินโอน', $m($daily->sum('transfer')), '', 'success'],
        ] as [$label, $value, $note, $tone])
            <div class="col-6 col-md-4 col-xl-2">
                <div class="border rounded-3 p-3 h-100 border-{{ $tone }}">
                    <div class="small text-muted">{{ $label }}</div>
                    <div class="fs-5 fw-bold text-{{ $tone === 'secondary' ? 'body' : $tone }}">{{ $value }}</div>
                    @if ($note)<div class="small text-muted">{{ $note }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>

    <h2 class="h6 fw-bold">ยอดขายรายวัน (ไม่รวมใบยกเลิก)</h2>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>วันที่</th><th class="text-end">ใบจอง</th><th class="text-end">ยอดจอง</th><th class="text-end">ใบขายเชื่อ</th><th class="text-end">มูลค่ามี VAT</th><th class="text-end">ยกเว้น VAT</th><th class="text-end">VAT</th><th class="text-end">ยอดขายรวม</th><th class="text-end">รับเงินสด</th><th class="text-end">รับเงินโอน</th></tr></thead>
        <tbody>
        @forelse ($daily as $day)
            <tr><td>{{ \Illuminate\Support\Carbon::parse($day->doc_date)->format('d/m/Y') }}</td><td class="text-end">{{ $day->bookings }}</td><td class="text-end">{{ $m($day->booking_total) }}</td><td class="text-end">{{ $day->sales }}</td><td class="text-end">{{ $m($day->vatable) }}</td><td class="text-end">{{ $m($day->exempt) }}</td><td class="text-end">{{ $m($day->vat) }}</td><td class="text-end fw-semibold">{{ $m($day->sales_total) }}</td><td class="text-end">{{ $m($day->cash) }}</td><td class="text-end">{{ $m($day->transfer) }}</td></tr>
        @empty
            <tr><td colspan="10" class="text-center text-muted py-4">ยังไม่มีข้อมูลในช่วงนี้</td></tr>
        @endforelse
        </tbody>
        @if ($daily->isNotEmpty())
        <tfoot class="fw-bold"><tr><td>รวม</td><td class="text-end">{{ $daily->sum('bookings') }}</td><td class="text-end">{{ $m($daily->sum('booking_total')) }}</td><td class="text-end">{{ $daily->sum('sales') }}</td><td class="text-end">{{ $m($daily->sum('vatable')) }}</td><td class="text-end">{{ $m($daily->sum('exempt')) }}</td><td class="text-end">{{ $m($daily->sum('vat')) }}</td><td class="text-end">{{ $m($daily->sum('sales_total')) }}</td><td class="text-end">{{ $m($daily->sum('cash')) }}</td><td class="text-end">{{ $m($daily->sum('transfer')) }}</td></tr></tfoot>
        @endif
    </table></div>
</div>

<div class="content-card p-4">
    <h2 class="h6 fw-bold">เอกสาร</h2>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>วันที่</th><th>ประเภท</th><th>เลขที่</th><th>อ้างใบจอง</th><th>ลูกค้า</th><th class="text-end">ยอด</th><th>สถานะ</th><th>ใน ERP</th></tr></thead>
        <tbody>
        @forelse ($documents as $doc)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($doc->doc_date)->format('d/m/Y') }}</td>
                <td>{{ $kindLabels[$doc->kind] ?? $doc->kind }} <span class="text-muted small">{{ $doc->book_code }}</span></td>
                <td><a href="{{ route('bplus.sync.show', $doc->id) }}" class="fw-semibold">{{ $doc->ref }}</a></td>
                <td>{{ $doc->booking_ref ?? '-' }}</td>
                <td>{{ $doc->customer_code }} {{ $doc->customer_name }}</td>
                <td class="text-end">{{ $m($doc->total_amount) }}</td>
                <td>@if ($doc->is_cancelled)<span class="badge text-bg-danger">ยกเลิก</span>@else<span class="badge text-bg-light border">ปกติ</span>@endif</td>
                <td>@if ($doc->problem)<span class="text-danger small">{{ $doc->problem }}</span>@elseif ($doc->document_id)<span class="text-success small">สร้างแล้ว</span>@else<span class="text-muted small">-</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีเอกสาร</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $documents->links('pagination::bootstrap-5') }}
</div>
@endsection
