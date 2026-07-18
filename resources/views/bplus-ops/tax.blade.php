@extends('layout')
@section('title', 'ภาษี VAT / WHT')
@section('page-title', 'ภาษี VAT / WHT')
@section('page-subtitle', 'อัตราภาษี รายงานภาษีขาย และงานหัก ณ ที่จ่าย')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="feature-stat content-card"><div class="text-muted small">ยอดขายสุทธิ POS</div><div class="fs-3 fw-bold">฿{{ number_format((float) $vatSales->net_sales, 2) }}</div></div></div>
    <div class="col-md-4"><div class="feature-stat content-card"><div class="text-muted small">VAT จาก POS</div><div class="fs-3 fw-bold text-danger">฿{{ number_format((float) $vatSales->vat_amount, 2) }}</div></div></div>
    <div class="col-md-4"><div class="feature-stat content-card"><div class="text-muted small">รายงาน</div><a href="{{ route('reports.index', ['category' => 'pos', 'report' => 'pos_receipts']) }}" class="btn btn-outline-primary mt-2">ดูใบเสร็จ POS</a></div></div>
</div>
<div class="content-card p-4 mb-3"><form method="post" action="{{ route('bplus.vat-rates.store') }}" class="row g-3">@csrf
    <div class="col-md-3"><label class="form-label small text-muted">VAT %</label><input type="number" step="0.01" name="rate_percent" value="7" required class="form-control"></div>
    <div class="col-md-3"><label class="form-label small text-muted">เริ่มใช้</label><input type="date" name="effective_from" value="{{ now()->toDateString() }}" required class="form-control"></div>
    <div class="col-md-3"><label class="form-label small text-muted">สิ้นสุด</label><input type="date" name="effective_to" class="form-control"></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่มอัตราภาษี</button></div>
</form></div>
<div class="content-card p-4"><h2 class="h5 fw-bold mb-3">อัตราภาษี</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>VAT %</th><th>เริ่ม</th><th>สิ้นสุด</th></tr></thead><tbody>@forelse($vatRates as $rate)<tr><td class="fw-bold">{{ number_format((float) $rate->rate_percent, 2) }}%</td><td>{{ $rate->effective_from?->thaiDate() }}</td><td>{{ $rate->effective_to?->thaiDate() ?? '-' }}</td></tr>@empty<tr><td colspan="3" class="text-center text-muted py-5">ยังไม่มีอัตราภาษี</td></tr>@endforelse</tbody></table></div>{{ $vatRates->links() }}</div>
@endsection
