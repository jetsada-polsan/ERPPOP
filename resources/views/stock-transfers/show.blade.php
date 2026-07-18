@extends('layout')

@section('title', "ใบโอนย้าย {$transfer->doc_number} - POPSTAR ERP")
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
            <span class="badge text-bg-success fs-6 px-3 py-2">โอนย้ายสำเร็จ</span>
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
