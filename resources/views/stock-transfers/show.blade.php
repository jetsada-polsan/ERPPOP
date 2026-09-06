@extends('layout')

@section('title', "ใบโอนย้าย {$transfer->doc_number} - PopCentral")
@section('page-title', 'รายละเอียดใบโอนย้ายสต็อก')
@section('page-subtitle', $transfer->doc_number)

@section('content')
    <a href="{{ route('stock-transfers.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปรายการโอนย้าย
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบโอนย้าย {{ $transfer->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $transfer->doc_date->thaiDate() }} &middot;
                    {{ $transfer->branch->name_th }} &middot;
                    ปลายทาง: {{ $transfer->stockDocument?->toWarehouseLocation?->name }}
                </div>
                @if($transfer->remark)
                <div class="text-muted small mt-1">หมายเหตุ: {{ $transfer->remark }}</div>
                @endif
            </div>
            <div class="text-end">
                <span class="badge text-bg-success fs-6 px-3 py-2 d-inline-block mb-2">โอนย้ายสำเร็จ</span>
                @if($transfer->status === 'active')
                    <div>
                        @if(! $receipt)
                            <a href="{{ route('stock-transfers.receipt.create', $transfer) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-upc-scan me-1"></i> ตรวจรับด้วยสแกน
                            </a>
                        @elseif($receipt->isEditable())
                            <a href="{{ route('stock-transfers.receipt.create', $transfer) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-upc-scan me-1"></i> ตรวจรับต่อ (ยังไม่ปิด)
                            </a>
                        @else
                            @php $mismatches = $receipt->items->filter(fn ($i) => abs((float) $i->scanned_qty - (float) $i->expected_qty) > 0.0001); @endphp
                            @if($mismatches->isEmpty())
                                <span class="badge text-bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-patch-check-fill me-1"></i>ตรวจรับแล้ว ยอดตรงทุกรายการ
                                </span>
                            @else
                                <a href="{{ route('stock-transfers.receipt.create', $transfer) }}" class="badge text-bg-warning text-decoration-none">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>ตรวจรับแล้ว พบยอดไม่ตรง {{ $mismatches->count() }} รายการ
                                </a>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3">รายการสินค้า</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>รหัส</th><th>ชื่อสินค้า</th><th>ต้นทาง</th>
                        <th class="text-end">จำนวนที่โอน</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transfer->stockDocument->items as $item)
                    <tr>
                        <td>{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td>{{ $item->warehouseLocation->name }}</td>
                        <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold border-top">
                        <td colspan="3" class="text-end py-2">รวมทั้งสิ้น</td>
                        <td class="text-end">{{ number_format($transfer->stockDocument->total_qty, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
