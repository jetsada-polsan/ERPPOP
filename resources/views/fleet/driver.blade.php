@extends('layout')
@section('title','งานขนส่งสำหรับคนขับ')
@section('page-title','งานขนส่ง')
@section('page-subtitle','เลือกงาน แล้วอัปเดตสถานะตามการส่งจริง')
@section('content')
<div class="container-fluid px-0" style="max-width:720px"><div class="alert alert-info">หน้านี้ไม่ติดตาม GPS ใช้สำหรับเปลี่ยนสถานะงานและแจ้งผลส่งของเท่านั้น</div>
@forelse($jobs as $j)<div class="card mb-3"><div class="card-body"><div class="d-flex justify-content-between gap-2"><div><h2 class="h5 mb-1">{{ $j->booking->document->doc_number }}</h2><div>{{ $j->booking->document->customer?->name_th ?? '-' }}</div><small class="text-muted">รถ {{ $j->vehicle?->registration ?? 'ยังไม่ระบุ' }}</small></div><span class="badge text-bg-primary align-self-start">{{ ['booked'=>'ใบจอง','loaded'=>'ขึ้นรถ','in_transit'=>'กำลังส่ง','delivered'=>'ส่งสำเร็จ'][$j->status] ?? $j->status }}</span></div><form method="post" action="{{ route('fleet.board.update',$j->booking) }}" class="mt-3">@csrf<input type="hidden" name="vehicle_id" value="{{ $j->vehicle_id }}"><div class="d-grid gap-2">@foreach(['loaded'=>'ขึ้นรถแล้ว','in_transit'=>'เริ่มส่งของ','delivered'=>'ส่งสำเร็จ'] as $status=>$label)<button name="status" value="{{ $status }}" class="btn {{ $status==='delivered'?'btn-success':'btn-outline-primary' }}" @disabled(in_array($j->status,['delivered','paid','cancelled']) || ($status==='loaded' && $j->status!=='booked'))>{{ $label }}</button>@endforeach</div></form></div></div>@empty<div class="card"><div class="card-body text-center">ยังไม่มีงานขนส่ง</div></div>@endforelse</div>
@endsection
