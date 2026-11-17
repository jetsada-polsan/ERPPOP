@extends('layout')
@section('title', 'ตรวจไฟล์จาก BPlus')
@section('page-title', 'ตรวจไฟล์จาก BPlus ก่อนบันทึก')
@section('page-subtitle', 'สาย '.$route.' · '.$range[0].' ถึง '.$range[1])
@section('content')
<div class="content-card p-4">
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>ประเภท</th><th class="text-end">ใหม่</th><th class="text-end">มีการแก้ใน BPlus</th><th class="text-end">เหมือนเดิม</th><th class="text-end">ยอดรวม (ไม่รวมยกเลิก)</th></tr></thead>
        <tbody>
        @foreach ($summary as $kind => $row)
            <tr><td>{{ $kindLabels[$kind] }}</td><td class="text-end">{{ $row['new'] }}</td><td class="text-end">{{ $row['updated'] }}</td><td class="text-end">{{ $row['unchanged'] }}</td><td class="text-end">{{ number_format($row['total'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @if ($check['errors'])
        <div class="alert alert-danger">ตั้งค่ายังไม่ครบ จะบันทึกสำเนาได้แต่ยังสร้างเป็นเอกสาร ERP ไม่ได้: {{ implode(' · ', $check['errors']) }}</div>
    @endif
    <p class="mb-1">จะสร้างเป็นใบจอง / ใบขายเชื่อ / ใบเสร็จ ของ ERP ที่สาขา <b>{{ $check['branch'] ?? '-' }}</b></p>
    <p class="mb-2 text-muted">จะสร้างลูกค้าใหม่ {{ $masterSummary['new_customers'] ?? 0 }} ราย · อัปเดตชื่อ {{ $masterSummary['updated_customers'] ?? 0 }} ราย · สร้างสินค้าใหม่ {{ $masterSummary['new_products'] ?? 0 }} รายการ</p>
    @if ($check['missing_customers'])
        <div class="alert alert-warning">ไม่พบลูกค้า {{ count($check['missing_customers']) }} รหัสใน ERP ใบขาย/ใบเสร็จของลูกค้าเหล่านี้จะยังไม่ถูกสร้าง: {{ implode(', ', array_slice($check['missing_customers'], 0, 30)) }}</div>
    @endif
    @if ($check['missing_products'])
        <div class="alert alert-warning">ไม่พบสินค้า {{ count($check['missing_products']) }} รหัสใน ERP บรรทัดเหล่านี้จะไม่แสดงในเอกสาร (ยอดรวมยังตรงกับ BPlus): {{ implode(', ', array_slice($check['missing_products'], 0, 30)) }}</div>
    @endif
    <p class="small text-muted">เอกสารที่สร้างจะลงบัญชี GL ตามยอดเอกสาร ไม่ตัดสต๊อก และแก้ไขใน ERP ไม่ได้ (แก้ที่ BPlus แล้วซิงค์ใหม่) ใบเดิมที่ถูกแก้ใน BPlus จะถูกอัปเดตทับ</p>
    <form method="post" action="{{ route('bplus.sync.import.store') }}" class="d-flex gap-2">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button class="btn btn-primary">ยืนยันบันทึก</button>
        <a href="{{ route('bplus.sync.index') }}" class="btn btn-outline-secondary">ยกเลิก</a>
    </form>
</div>
@endsection
