@extends('layout')
@section('title', 'แผนเติมเต็มและจัดซื้อ')
@section('page-title', 'แผนเติมเต็มและจัดซื้อ')
@section('page-subtitle', 'ยอดขาย - Stock - ของกำลังมา - จุดสั่งซื้อ - Supplier')

@section('content')
<form method="get" class="content-card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" class="form-select">
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((int)$branchId === $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">ช่วงยอดขาย</label><select name="sales_days" class="form-select">@foreach([14,30,60,90,180] as $days)<option value="{{ $days }}" @selected($salesDays === $days)>{{ $days }} วัน</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-muted">Safety stock</label><div class="input-group"><input type="number" min="0" max="90" name="safety_days" value="{{ $safetyDays }}" class="form-control text-end"><span class="input-group-text">วัน</span></div></div>
        <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat me-1"></i>คำนวณคำแนะนำใหม่</button></div>
    </div>
</form>

@php
    $suggestedValue = $suggestions->sum(fn($row) => $row['suggested_qty'] * $row['unit_price']);
    $missingSupplier = $suggestions->whereNull('supplier_id')->count();
@endphp
<div class="replenish-summary mb-3">
    <div><span>รายการควรเติม</span><strong>{{ number_format($suggestions->count()) }}</strong></div>
    <div><span>มูลค่าเสนอซื้อ</span><strong>฿{{ number_format($suggestedValue, 2) }}</strong></div>
    <div><span>ยังไม่มี Supplier หลัก</span><strong class="{{ $missingSupplier ? 'text-danger' : 'text-success' }}">{{ number_format($missingSupplier) }}</strong></div>
    <div><span>ช่วงวิเคราะห์</span><strong>{{ $salesDays }} + {{ $safetyDays }} วัน</strong></div>
</div>

<form method="post" action="{{ route('bplus.purchase-planning.generate-requisitions') }}" x-data>
    @csrf
    <input type="hidden" name="branch_id" value="{{ $branchId }}">
    <div class="content-card overflow-hidden">
        <div class="d-flex justify-content-between align-items-center gap-2 px-3 py-2 border-bottom">
            <strong class="small">คำแนะนำเติมเต็ม</strong>
            <button class="btn btn-success btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>สร้างใบขอซื้อจากรายการที่เลือก</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 replenish-table">
                <thead><tr><th></th><th>สินค้า / Supplier</th><th class="text-end">พร้อมขาย</th><th class="text-end">กำลังมา</th><th class="text-end">ขาย/วัน</th><th class="text-end">Lead</th><th class="text-end">จุดสั่ง</th><th class="text-end">เป้า</th><th class="text-end">MOQ</th><th class="text-end" style="width:125px">เสนอซื้อ</th><th class="text-end">มูลค่า</th></tr></thead>
                <tbody>
                @forelse($suggestions as $index => $row)
                    <tr x-data="{ checked: false }">
                        <td><input type="checkbox" class="form-check-input" x-model="checked" aria-label="เลือก {{ $row['sku_code'] }}"></td>
                        <td><strong>{{ $row['sku_code'] }} - {{ $row['name_th'] }}</strong><small>{{ $row['supplier'] ?? 'ยังไม่ได้กำหนด Supplier หลัก' }} · {{ $row['unit'] }}</small></td>
                        <td class="text-end {{ $row['available'] < 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['available'], 2) }}<small>คงเหลือ {{ number_format($row['on_hand'], 2) }} / จอง {{ number_format($row['reserved'], 2) }}</small></td>
                        <td class="text-end">{{ number_format($row['incoming'], 2) }}</td>
                        <td class="text-end">{{ number_format($row['daily_sales'], 2) }}<small>{{ number_format($row['sold_qty'], 2) }} ใน {{ $salesDays }} วัน</small></td>
                        <td class="text-end">{{ number_format($row['lead_days']) }} วัน</td>
                        <td class="text-end">{{ number_format($row['reorder_point'], 2) }}</td>
                        <td class="text-end">{{ number_format($row['target_stock'], 2) }}</td>
                        <td class="text-end">{{ $row['moq'] > 0 ? number_format($row['moq'], 2) : '-' }}</td>
                        <td>
                            <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $row['product_id'] }}" :disabled="!checked">
                            <input type="hidden" name="items[{{ $index }}][supplier_id]" value="{{ $row['supplier_id'] }}" :disabled="!checked">
                            <input type="hidden" name="items[{{ $index }}][unit_price]" value="{{ $row['unit_price'] }}" :disabled="!checked">
                            <input type="number" step="0.0001" min="0.0001" name="items[{{ $index }}][qty]" value="{{ $row['suggested_qty'] }}" class="form-control form-control-sm text-end" :disabled="!checked">
                        </td>
                        <td class="text-end fw-semibold">฿{{ number_format($row['suggested_qty'] * $row['unit_price'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-5"><i class="bi bi-check2-circle d-block fs-3 text-success mb-2"></i>ไม่มีสินค้าต่ำกว่าจุดสั่งซื้อ</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>

<div class="content-card overflow-hidden mt-3">
    <div class="px-3 py-2 border-bottom"><strong class="small">ประวัติแผนจัดซื้อ</strong></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>เลขแผน</th><th>สินค้า</th><th>ผู้จำหน่าย</th><th class="text-end">เสนอซื้อ</th><th>สถานะ</th></tr></thead><tbody>@forelse($plans as $plan)<tr><td class="fw-semibold">{{ $plan->plan_no }}</td><td>{{ $plan->product?->sku_code }}</td><td>{{ $plan->supplier?->name_th ?? '-' }}</td><td class="text-end">{{ number_format((float)$plan->suggested_qty, 4) }}</td><td><span class="badge text-bg-light border">{{ $plan->status }}</span></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีประวัติแผน</td></tr>@endforelse</tbody></table></div>
    <div class="p-3 border-top">{{ $plans->links() }}</div>
</div>
@endsection

@push('head')
<style>
.replenish-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.replenish-summary>div{padding:11px 13px;border:1px solid #dbe7ef;border-radius:7px;background:#fff}.replenish-summary span,.replenish-table small{display:block;color:#73899a;font-size:10px}.replenish-summary strong{display:block;margin-top:2px;color:#15364d;font-size:18px}.replenish-table{min-width:1180px}.replenish-table th{color:#5d7485;background:#f7fafc;font-size:10px}.replenish-table td{font-size:11px}.replenish-table td>strong{color:#274b63;font-size:11.5px}@media(max-width:800px){.replenish-summary{grid-template-columns:1fr 1fr}}
</style>
@endpush
