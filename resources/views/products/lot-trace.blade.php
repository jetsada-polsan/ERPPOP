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

    <div class="content-card p-4">
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
@endsection
