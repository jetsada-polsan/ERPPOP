@extends('layout')

@section('title', "Trace Lot {$stockLot->lot_number} - POPSTAR ERP")
@section('page-title', 'ติดตาม Lot ย้อนหลัง')
@section('page-subtitle', $stockLot->lot_number)

@section('content')
    <a href="{{ route('products.show', $product) }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปสินค้า {{ $product->sku_code }}
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex justify-content-between gap-3 flex-wrap">
            <div>
                <h2 class="h5 fw-bold mb-1">{{ $product->sku_code }} - {{ $product->name_th }}</h2>
                <div class="text-muted">Lot {{ $stockLot->lot_number }} · {{ $stockLot->warehouseLocation?->warehouse?->name_th ?? $stockLot->warehouseLocation?->code }}</div>
            </div>
            @php($qualityLabels = ['available' => 'พร้อมใช้', 'hold' => 'พักตรวจ', 'quarantine' => 'กักกัน', 'recalled' => 'เรียกคืน'])
            <span class="badge {{ $stockLot->quality_status === 'available' ? 'text-bg-success' : 'text-bg-danger' }} align-self-start fs-6">
                {{ $qualityLabels[$stockLot->quality_status] ?? $stockLot->quality_status }}
            </span>
        </div>
        <div class="row g-3 mt-2 small">
            <div class="col-md-3"><span class="text-muted d-block">เอกสารรับเข้า</span>{{ $stockLot->sourceDocument?->doc_number ?? 'ยอดยกมา' }}</div>
            <div class="col-md-2"><span class="text-muted d-block">วันรับ</span>{{ $stockLot->received_date?->format('d/m/Y') }}</div>
            <div class="col-md-2"><span class="text-muted d-block">วันผลิต</span>{{ $stockLot->manufacture_date?->format('d/m/Y') ?? '-' }}</div>
            <div class="col-md-2"><span class="text-muted d-block">วันหมดอายุ</span>{{ $stockLot->expiry_date?->format('d/m/Y') ?? '-' }}</div>
            <div class="col-md-3"><span class="text-muted d-block">รับเข้า / คงเหลือ</span>{{ number_format($stockLot->initial_qty, 4) }} / {{ number_format($stockLot->remaining_qty, 4) }}</div>
        </div>
        @if($stockLot->quality_reason)<div class="alert alert-warning mt-3 mb-0"><strong>เหตุผลควบคุม:</strong> {{ $stockLot->quality_reason }}</div>@endif
    </div>

    <div class="content-card p-4 mb-4">
        <h3 class="h6 fw-bold mb-3">เส้นทางการเคลื่อนไหวทั้งหมด</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>วันที่</th><th>ประเภท</th><th>เอกสาร</th><th>สาขา</th><th class="text-end">จำนวน</th></tr></thead>
                <tbody>
                @forelse($stockLot->movements as $movement)
                    <tr>
                        <td>{{ $movement->movement_date?->format('d/m/Y') }}</td>
                        <td>{{ $movement->movement_type }}</td>
                        <td class="fw-semibold">{{ $movement->document?->doc_number ?? '-' }}</td>
                        <td>{{ $movement->document?->branch?->name_th ?? '-' }}</td>
                        <td class="text-end">{{ number_format($movement->qty, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีรายการเคลื่อนไหว</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="content-card p-4 h-100">
                <h3 class="h6 fw-bold mb-3">ตรวจคุณภาพ Lot</h3>
                <form method="post" action="{{ route('products.lots.quality-checks.store', [$product, $stockLot]) }}" enctype="multipart/form-data" class="row g-2">@csrf
                    <div class="col-md-4"><select name="result" class="form-select" required><option value="pass">ผ่าน</option><option value="hold">พักตรวจ</option><option value="fail">ไม่ผ่าน/กักกัน</option></select></div>
                    <div class="col-md-8"><input name="note" class="form-control" placeholder="ผลตรวจ/เหตุผล"></div>
                    <div class="col-md-8"><input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png" class="form-control"></div>
                    <div class="col-md-4"><button class="btn btn-primary w-100"><i class="bi bi-clipboard-check me-1"></i>บันทึกผล</button></div>
                </form>
                <div class="mt-3">
                    @forelse($stockLot->qualityChecks->sortByDesc('checked_at') as $check)
                        <div class="border-top py-2 small"><strong>{{ ['pass'=>'ผ่าน','hold'=>'พักตรวจ','fail'=>'ไม่ผ่าน'][$check->result] ?? $check->result }}</strong> · {{ $check->checked_at?->thaiDate(true) }} · {{ $check->checkedBy?->name }}<div class="text-muted">{{ $check->note }}</div></div>
                    @empty
                        <div class="text-muted small">ยังไม่มีผลตรวจคุณภาพ</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="content-card p-4 h-100">
                <h3 class="h6 fw-bold mb-3">เปิดเคส Recall</h3>
                <form method="post" action="{{ route('products.lots.recalls.store', [$product, $stockLot]) }}" class="row g-2">@csrf
                    <div class="col-md-4"><select name="severity" class="form-select"><option value="low">ต่ำ</option><option value="medium" selected>กลาง</option><option value="high">สูง</option><option value="critical">วิกฤต</option></select></div>
                    <div class="col-md-8"><input name="reason" required class="form-control" placeholder="สาเหตุเรียกคืน"></div>
                    <div class="col-12"><button class="btn btn-danger w-100" @disabled($stockLot->quality_status === 'recalled')><i class="bi bi-exclamation-octagon me-1"></i>เปิด Recall และสร้างรายชื่อติดต่อ</button></div>
                </form>
            </div>
        </div>
    </div>

    @foreach($stockLot->recallCases->sortByDesc('id') as $case)
    <div class="content-card p-4 mb-4">
        <div class="d-flex justify-content-between"><h3 class="h6 fw-bold">{{ $case->case_no }} · {{ $case->reason }}</h3><span class="badge text-bg-danger">{{ $case->severity }} / {{ $case->status }}</span></div>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>เอกสารขาย</th><th>สาขา</th><th>ลูกค้า</th><th class="text-end">จำนวน</th><th>ติดตาม</th></tr></thead><tbody>
        @forelse($case->contacts as $contact)<tr>
            <td>{{ $contact->document?->doc_number ?? '-' }}</td><td>{{ $contact->branch?->name_th ?? '-' }}</td>
            <td>{{ $contact->customer?->name_th ?? 'ลูกค้าทั่วไป/ตรวจจาก POS' }}</td><td class="text-end">{{ number_format($contact->qty,4) }}</td>
            <td><form method="post" action="{{ route('products.recall-contacts.update', $contact) }}" class="d-flex gap-1">@csrf @method('PUT')
                <select name="contact_status" class="form-select form-select-sm"><option value="pending" @selected($contact->contact_status==='pending')>รอติดต่อ</option><option value="contacted" @selected($contact->contact_status==='contacted')>ติดต่อแล้ว</option><option value="returned" @selected($contact->contact_status==='returned')>คืนแล้ว</option><option value="unreachable" @selected($contact->contact_status==='unreachable')>ติดต่อไม่ได้</option><option value="closed" @selected($contact->contact_status==='closed')>ปิดรายการ</option></select>
                <input name="contact_note" value="{{ $contact->contact_note }}" class="form-control form-control-sm" placeholder="ผลติดต่อ"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i></button>
            </form></td>
        </tr>@empty<tr><td colspan="5" class="text-center text-muted">ยังไม่พบเอกสารขายจาก Lot นี้</td></tr>@endforelse
        </tbody></table></div>
    </div>
    @endforeach
@endsection
