@extends('layout')

@section('title', "ใบปรับปรุงสต็อก {$adjustment->doc_number} - POPSTAR ERP")
@section('page-title', 'รายละเอียดใบปรับปรุงสต็อก')
@section('page-subtitle', $adjustment->doc_number)

@section('content')
    <a href="{{ route('stock-adjustments.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปรายการปรับปรุงสต็อก
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบปรับปรุงสต็อก {{ $adjustment->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $adjustment->doc_date->thaiDate() }} &middot;
                    {{ $adjustment->branch->name_th }}
                </div>
                @if($adjustment->remark)
                <div class="text-muted small mt-1">หมายเหตุ: {{ $adjustment->remark }}</div>
                @endif
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge {{ $adjustment->status === 'active' ? 'text-bg-success' : ($adjustment->status === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') }} fs-6 px-3 py-2">
                    {{ ['active' => 'ปรับปรุงสำเร็จ', 'pending_approval' => 'รออนุมัติ', 'rejected' => 'ไม่อนุมัติ'][$adjustment->status] ?? $adjustment->status }}
                </span>
                @if($adjustment->status === 'pending_approval' && auth()->user()->hasPermission('stock.adjust.approve') && $adjustment->created_by !== auth()->id())
                    <form method="post" action="{{ route('stock-adjustments.approve', $adjustment) }}">@csrf<button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>อนุมัติและปรับยอด</button></form>
                    <form method="post" action="{{ route('stock-adjustments.reject', $adjustment) }}" class="d-flex gap-1">@csrf<input name="reason" required class="form-control form-control-sm" placeholder="เหตุผลไม่อนุมัติ"><button class="btn btn-outline-danger">ปฏิเสธ</button></form>
                @endif
            </div>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3">รายการที่ปรับปรุง</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>รหัส</th><th>ชื่อสินค้า</th><th>คลัง</th>
                        <th class="text-end">ยอดระบบ</th><th class="text-end">ยอดนับ</th><th class="text-end">ผลต่าง</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($adjustment->stockDocument->items as $item)
                    <tr>
                        <td>{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td>{{ $item->warehouseLocation->name }}</td>
                        <td class="text-end">{{ number_format($item->system_qty, 2) }}</td>
                        <td class="text-end">{{ number_format($item->counted_qty, 2) }}</td>
                        <td class="text-end fw-semibold {{ $item->qty > 0 ? 'text-success' : 'text-danger' }}">
                            {{ $item->qty > 0 ? '+' : '' }}{{ number_format($item->qty, 2) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
