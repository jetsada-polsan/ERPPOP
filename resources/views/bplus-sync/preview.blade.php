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
    <p class="small text-muted">บันทึกเป็นสำเนาเอกสารไว้ดูเท่านั้น ไม่ตัดสต๊อก ไม่สร้างลูกหนี้ ไม่ลงบัญชี ใบเดิมที่ถูกแก้ใน BPlus จะถูกอัปเดตทับ</p>
    <form method="post" action="{{ route('bplus.sync.import.store') }}" class="d-flex gap-2">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button class="btn btn-primary">ยืนยันบันทึก</button>
        <a href="{{ route('bplus.sync.index') }}" class="btn btn-outline-secondary">ยกเลิก</a>
    </form>
</div>
@endsection
