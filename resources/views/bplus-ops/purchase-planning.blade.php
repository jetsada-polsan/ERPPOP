@extends('layout')
@section('title', 'แผนจัดซื้อ')
@section('page-title', 'แผนจัดซื้อ')
@section('page-subtitle', 'Prepare PO จากยอดขายและสต็อกต่ำ')
@section('content')
<div class="content-card p-4 mb-3"><form method="post" action="{{ route('bplus.purchase-planning.store') }}" class="row g-3">@csrf
    <div class="col-md-2"><label class="form-label small text-muted">เลขแผน</label><input name="plan_no" value="PP{{ now()->format('ymdHis') }}" required class="form-control"></div>
    <div class="col-md-3"><label class="form-label small text-muted">สินค้า</label><select name="product_id" class="form-select"><option value="">--</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->sku_code }} - {{ $p->name_th }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label small text-muted">ผู้จำหน่าย</label><select name="supplier_id" class="form-select"><option value="">--</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name }}</option>@endforeach</select></div>
    <div class="col-md-1"><label class="form-label small text-muted">เสนอซื้อ</label><input type="number" step="0.0001" name="suggested_qty" value="0" class="form-control"></div>
    <div class="col-md-1"><label class="form-label small text-muted">เป้า stock</label><input type="number" step="0.0001" name="target_stock_qty" value="0" class="form-control"></div>
    <div class="col-md-1"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="draft">ร่าง</option><option value="approved">อนุมัติ</option><option value="converted">ออก PO</option></select></div>
    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100">เพิ่ม</button></div>
    <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
</form></div>
<div class="row g-3"><div class="col-xl-8"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">แผนจัดซื้อ</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>เลขแผน</th><th>สินค้า</th><th>ผู้จำหน่าย</th><th class="text-end">เสนอซื้อ</th><th>สถานะ</th></tr></thead><tbody>@forelse($plans as $plan)<tr><td class="fw-semibold">{{ $plan->plan_no }}</td><td>{{ $plan->product?->sku_code }}</td><td>{{ $plan->supplier?->name ?? '-' }}</td><td class="text-end">{{ number_format((float) $plan->suggested_qty, 4) }}</td><td><span class="badge text-bg-light border">{{ $plan->status }}</span></td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีแผน</td></tr>@endforelse</tbody></table></div>{{ $plans->links() }}</div></div><div class="col-xl-4"><div class="content-card p-4"><h2 class="h5 fw-bold mb-3">สต็อกต่ำ</h2><div class="table-responsive"><table class="table table-sm"><tbody>@foreach($lowStock as $row)<tr><td>{{ $row->sku_code }}<div class="small text-muted">{{ $row->name_th }}</div></td><td class="text-end">{{ number_format((float) $row->on_hand_qty, 2) }}</td></tr>@endforeach</tbody></table></div></div></div></div>
@endsection
