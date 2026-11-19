@extends('layout')
@section('page-title', 'ชุดส่งสำนักงานบัญชี')
@section('content')
<form class="card card-body" action="{{ route('accounting-package.download') }}" method="get">
<h2 class="h5">ดาวน์โหลดรายงานประจำเดือน</h2>
<p>รวมรายงาน Excel 7 ไฟล์และสรุปยอดในไฟล์ ZIP เดียว</p>
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="row g-3">
<label class="col-md-4">เดือน<input type="month" class="form-control" name="month" value="{{ old('month', $month) }}" required></label>
<label class="col-md-5">สายขาย<select class="form-select" name="sales_area_id" required>
@foreach($areas as $area)<option value="{{ $area->id }}" @selected($area->code === 'B18')>{{ $area->code }} · {{ $area->name }}</option>@endforeach
</select></label></div>
@if($areas->isEmpty())<p class="mt-3">ยังไม่มีสายขายในสาขาที่บัญชีนี้ดูรายงานได้</p>@endif
<div class="mt-3"><button class="btn btn-primary" @disabled($areas->isEmpty())>ดาวน์โหลดชุดส่งสำนักงานบัญชี</button></div>
</form>
@endsection
