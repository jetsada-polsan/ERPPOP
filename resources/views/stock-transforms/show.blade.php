@extends('layout')

@section('title', "ใบแปรรูป {$document->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบแปรรูปสินค้า')
@section('page-subtitle', $document->doc_number)

@section('content')
    <a href="{{ route('stock-transforms.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการใบแปรรูป
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบแปรรูปสินค้า {{ $document->doc_number }}</h2>
                <div class="text-muted small">{{ $document->doc_date->thaiDate() }} &middot; {{ $document->branch->name_th }}</div>
                @if($document->remark)<div class="text-muted small mt-1">หมายเหตุ: {{ $document->remark }}</div>@endif
            </div>
            <div class="text-end">
                <div class="text-muted small">มูลค่าวัตถุดิบ</div>
                <div class="h4 fw-bold text-danger mb-0">{{ number_format($document->total_amount, 2) }}</div>
            </div>
        </div>
    </div>

    @php($rawItems = $document->stockDocument->items->where('qty', '<', 0))
    @php($outputItems = $document->stockDocument->items->where('qty', '>=', 0))

    <div class="content-card p-4 mb-4">
        <h3 class="h6 fw-bold mb-3 text-danger"><i class="bi bi-box-arrow-up me-1"></i>วัตถุดิบ (ตัดออกจากสต๊อก)</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ทุน/หน่วย</th><th class="text-end">รวม</th></tr></thead>
                <tbody>
                    @foreach($rawItems as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td class="text-end">{{ number_format(abs($item->qty), 2) }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format(abs($item->qty) * $item->unit_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>ผลผลิต (รับเข้าสต๊อก)</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ทุนที่ได้/หน่วย</th><th class="text-end">มูลค่ารวม</th></tr></thead>
                <tbody>
                    @foreach($outputItems as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
