@extends('layout')

@section('title', 'ยอดขายหลังบ้าน')
@section('page-title', 'ยอดขายหลังบ้านจากระบบเดิม')
@section('page-subtitle', 'ข้อมูลสรุปจาก MSSQL แบบอ่านอย่างเดียว ยังไม่กระทบสต็อกหรือบัญชี')

@section('content')
<form method="get" class="dashboard-filter mb-3">
    <div class="filter-title"><i class="bi bi-calendar3"></i><span>วันที่</span></div>
    <div class="filter-fields"><label><input type="date" name="date" value="{{ $date }}" class="form-control form-control-sm"></label></div>
    <div class="filter-actions"><button class="btn btn-primary btn-sm">แสดงผล</button></div>
</form>

@if($syncedAt)
<div class="alert alert-info border-0">ข้อมูลล่าสุดส่งจากระบบเดิมเมื่อ {{ \Carbon\Carbon::parse($syncedAt)->format('d/m/Y H:i') }}</div>
@else
<div class="alert alert-warning border-0">ยังไม่มีข้อมูลของวันที่เลือก ให้สั่ง Sync Summary จากเครื่องสำนักงานก่อน</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="metric-card metric-card-green"><div class="metric-label">เอกสาร DS / DSN</div><div class="metric-value text-success">฿{{ number_format($creditAmount, 2) }}</div><div class="metric-unit">{{ number_format($creditCount) }} เอกสาร</div></div></div>
    <div class="col-md-4"><div class="metric-card metric-card-blue"><div class="metric-label">เอกสารสถานะใบจอง 207</div><div class="metric-value">฿{{ number_format($reservationAmount, 2) }}</div><div class="metric-unit">{{ number_format($reservationCount) }} เอกสาร</div></div></div>
    <div class="col-md-4"><div class="metric-card metric-card-amber"><div class="metric-label">ยอดขายหลังบ้านไม่ซ้ำเอกสาร</div><div class="metric-value">฿{{ number_format($uniqueAmount, 2) }}</div><div class="metric-unit">{{ number_format($uniqueCount) }} เอกสาร</div></div></div>
</div>

<div class="panel-card"><div class="panel-title"><i class="bi bi-table"></i> แยกตามประเภทเอกสาร</div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>รหัสเอกสาร</th><th>Properties</th><th class="text-end">จำนวน</th><th class="text-end">ยอดเงิน</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row['doc_code'] ?? '-' }}</td><td>{{ $row['doc_properties'] ?? '-' }}</td><td class="text-end">{{ number_format($row['document_count'] ?? 0) }}</td><td class="text-end fw-semibold">฿{{ number_format($row['amount'] ?? 0, 2) }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr>@endforelse</tbody></table></div></div>
@endsection
