@extends('layout')
@section('title', "ใบขายสด {$sale->doc_number} - POPSTAR ERP")
@section('page-title', 'รายละเอียดใบขายสด')
@section('page-subtitle', $sale->doc_number)
@section('content')
    <a href="{{ route('cash-sales.index') }}" class="text-decoration-none small d-inline-block mb-3"><i class="bi bi-arrow-left me-1"></i> กลับไปรายการ</a>
    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบขายสด {{ $sale->doc_number }}</h2>
                <div class="text-muted small">{{ $sale->doc_date->thaiDate() }} &middot; {{ $sale->branch->name_th }} &middot; ลูกค้า: {{ $sale->customer?->name_th ?? 'เงินสด' }}</div>
                @if($sale->remark)<div class="text-muted small mt-1">หมายเหตุ: {{ $sale->remark }}</div>@endif
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a href="{{ route('documents.tax-invoice', $sale) }}" target="_blank" class="btn btn-primary px-3">
                    <i class="bi bi-receipt me-1"></i> ใบกำกับภาษี/ใบเสร็จ (A4)
                </a>
                <span class="badge text-bg-success fs-6 px-3 py-2">ขายสำเร็จ</span>
            </div>
        </div>
    </div>
    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead>
                <tbody>
                    @foreach($sale->stockDocument->items as $item)
                    <tr>
                        <td>{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot><tr class="fw-bold border-top"><td colspan="4" class="text-end py-2">รวมทั้งสิ้น</td><td class="text-end">{{ number_format($sale->total_amount, 2) }}</td></tr></tfoot>
            </table>
        </div>
    </div>
@endsection
