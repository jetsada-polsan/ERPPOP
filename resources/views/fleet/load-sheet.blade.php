@extends('layout')
@section('title','ใบขึ้นของ')
@section('page-title','ใบขึ้นของ')
@section('page-subtitle','ตรวจของจริงก่อนขึ้นรถ หากสินค้าขาดให้แก้ใบจองก่อนยืนยันขึ้นรถ')
@section('content')
<div class="card"><div class="card-body">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
        <div><h2 class="h5 mb-1">{{ $job->booking->document->doc_number }} · {{ $job->booking->document->customer?->name_th }}</h2><div class="text-muted small">ใบส่งของชั่วคราวใช้ตรวจ/แนบเอกสารเท่านั้น ไม่ใช่ใบขาย</div></div>
        <span class="badge text-bg-info">{{ ['booked'=>'รอจัดของ','loaded'=>'ขึ้นรถแล้ว','in_transit'=>'กำลังส่ง','delivered'=>'ส่งครบแล้ว','paid'=>'รับเงินแล้ว','cancelled'=>'ยกเลิก'][$job->status] ?? $job->status }}</span>
    </div>
    @unless($editable)
        <div class="alert alert-info">ยืนยันขึ้นรถแล้ว ใบขึ้นของนี้แก้ไขไม่ได้ หากจำนวนไม่ตรงให้ติดต่อผู้ดูแลก่อนออกใบขาย</div>
    @endunless
    <form method="post" action="{{ route('fleet.load-sheet.save',$job->booking) }}">@csrf
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>สินค้า</th><th>จำนวนตามใบจอง</th><th>สถานะ</th><th>จำนวนขึ้นรถ</th><th>หมายเหตุ</th></tr></thead><tbody>
            @forelse($items as $line)
                <tr>
                    <td>{{ $line->stockItem->product->sku_code }} · {{ $line->stockItem->product->name_th }}</td>
                    <td>{{ number_format($line->planned_qty, 4) }} {{ $line->stockItem->product->baseUnit?->cleanName() }}</td>
                    <td><select name="items[{{ $line->id }}][availability]" class="form-select" @disabled(!$editable)><option value="available" @selected(old("items.{$line->id}.availability", $line->availability) === 'available')>มีครบ</option><option value="partial" @selected(old("items.{$line->id}.availability", $line->availability) === 'partial')>มีบางส่วน (ต้องแก้ใบจองก่อน)</option><option value="unavailable" @selected(old("items.{$line->id}.availability", $line->availability) === 'unavailable')>ไม่มี (ต้องแก้ใบจองก่อน)</option></select></td>
                    <td><input name="items[{{ $line->id }}][loaded_qty]" type="number" step="0.0001" min="0" max="{{ $line->planned_qty }}" value="{{ old("items.{$line->id}.loaded_qty", $line->availability === 'pending' ? $line->planned_qty : $line->loaded_qty) }}" class="form-control" required @disabled(!$editable)></td>
                    <td><input name="items[{{ $line->id }}][note]" value="{{ old("items.{$line->id}.note", $line->note) }}" class="form-control" placeholder="หมายเหตุ" @disabled(!$editable)></td>
                </tr>
            @empty<tr><td colspan="5" class="text-center">ไม่พบรายการสินค้า</td></tr>@endforelse
        </tbody></table></div>
        <div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" @disabled(!$editable)>บันทึกใบขึ้นของ</button><button type="button" class="btn btn-outline-secondary" onclick="window.print()">พิมพ์ใบขึ้นของ</button><a class="btn btn-outline-secondary" href="{{ route('bookings.show',$job->booking) }}">กลับใบจอง</a><a class="btn btn-link" href="{{ route('fleet.board') }}">กลับบอร์ดขนส่ง</a></div>
    </form>
</div></div>
@endsection
