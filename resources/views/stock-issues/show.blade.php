@extends('layout')

@section('title', "{$document->documentType->name_th} {$document->doc_number} - POPSTAR ERP")
@section('page-title', $document->documentType->name_th)
@section('page-subtitle', $document->doc_number)

@section('content')
    <a href="{{ route('stock-issues.index', ['type' => array_search($document->documentType->code, \App\Services\Inventory\StockIssueService::TYPES) ?: 'requisition']) }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการเอกสาร
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">{{ $document->documentType->name_th }} {{ $document->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $document->doc_date->thaiDate() }} &middot; {{ $document->branch->name_th }}
                    @if($document->reference) &middot; อ้างอิง: {{ $document->reference }} @endif
                </div>
                @if($document->remark)
                <div class="text-muted small mt-1">หมายเหตุ: {{ $document->remark }}</div>
                @endif
            </div>
            <span class="badge {{ $document->documentType->code === 'STOCK_REQUISITION_RETURN' ? 'text-bg-success' : 'text-bg-warning' }} fs-6 px-3 py-2">
                {{ $document->documentType->code === 'STOCK_REQUISITION_RETURN' ? 'รับเข้าสต๊อกแล้ว' : 'ตัดสต๊อกแล้ว' }}
            </span>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3">รายการสินค้า</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th>ตำแหน่งเก็บ</th><th class="text-end">จำนวน</th></tr>
                </thead>
                <tbody>
                    @foreach($document->stockDocument->items as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td>{{ $item->warehouseLocation->name }}</td>
                        <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold border-top">
                        <td colspan="4" class="text-end py-2">รวมทั้งสิ้น</td>
                        <td class="text-end">{{ number_format($document->stockDocument->total_qty, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection
