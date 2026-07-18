@extends('layout')

@section('title', "ใบซื้อ {$purchase->doc_number} - POPSTAR ERP")
@section('page-title', 'รายละเอียดใบซื้อ')
@section('page-subtitle', $purchase->doc_number)

@section('content')
    <a href="{{ route('purchases.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปรายการใบซื้อ
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบซื้อ {{ $purchase->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $purchase->doc_date->thaiDate() }} &middot;
                    {{ $purchase->branch->name_th }} &middot;
                    ซัพพลายเออร์: {{ $purchase->supplier->name_th }} ({{ $purchase->supplier->code }})
                </div>
                @if($purchase->remark)
                <div class="text-muted small mt-1">หมายเหตุ: {{ $purchase->remark }}</div>
                @endif
            </div>
            <span class="badge text-bg-success fs-6 px-3 py-2">รับเข้าคลังแล้ว</span>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3">รายการสินค้า (รับเข้าคลังจริง)</h3>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>รหัส</th><th>ชื่อสินค้า</th>
                                <th class="text-end">จำนวน</th>
                                <th class="text-end">ราคา/หน่วย</th>
                                <th class="text-end">รวม</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchase->stockDocument->items as $item)
                            <tr>
                                <td>{{ $item->product->sku_code }}</td>
                                <td>{{ $item->product->name_th }}</td>
                                <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                                <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                                <td class="text-end">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold border-top">
                                <td colspan="4" class="text-end py-2">รวมทั้งสิ้น</td>
                                <td class="text-end">{{ number_format($purchase->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card p-4">
                <h3 class="h6 fw-bold mb-3 text-warning-emphasis">เจ้าหนี้ (AP)</h3>
                @if($ledgerEntry)
                <dl class="small mb-0">
                    <div class="d-flex justify-content-between mb-2">
                        <dt class="text-muted fw-normal">ยอดใบนี้</dt>
                        <dd class="mb-0">{{ number_format($ledgerEntry->amount, 2) }}</dd>
                    </div>
                    <div class="d-flex justify-content-between mb-2 fw-bold text-danger">
                        <dt>ยอดหนี้สะสม</dt>
                        <dd class="mb-0">{{ number_format($ledgerEntry->balance_after, 2) }}</dd>
                    </div>
                    <div class="d-flex justify-content-between mb-0">
                        <dt class="text-muted fw-normal">วันที่บันทึก</dt>
                        <dd class="mb-0">{{ $ledgerEntry->entry_date->thaiDate() }}</dd>
                    </div>
                </dl>
                @else
                <p class="text-muted small mb-0">ซื้อสด - ไม่มีรายการเจ้าหนี้</p>
                @endif
            </div>
        </div>
    </div>
@endsection
