@extends('layout')

@section('title', "ปรับต้นทุนซื้อย้อนหลัง {$purchase->doc_number} - PopCentral")
@section('page-title', 'ปรับต้นทุนซื้อย้อนหลัง')
@section('page-subtitle', $purchase->doc_number)

@section('content')
    <a href="{{ route('purchases.show', $purchase) }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับไปใบซื้อ {{ $purchase->doc_number }}
    </a>

    <div class="alert alert-warning small mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        การปรับที่นี่แก้เฉพาะ <strong>มูลค่าสต๊อกคงเหลือ</strong> ของสินค้า (ต้นทุน Lot + ต้นทุนเฉลี่ยสินค้า) เท่านั้น
        <strong>ไม่แก้</strong> ยอดในใบซื้อเดิม ยอดหนี้เจ้าหนี้ หรือบัญชี GL ที่โพสต์ไปแล้ว และ<strong>ไม่ย้อนแก้ต้นทุนขาย</strong>ของสินค้าที่ตัดสต๊อกออกไปแล้ว
        ถ้าจำนวนคงเหลือของ Lot เหลือน้อยกว่าที่ซื้อมา ระบบจะแสดง "มูลค่าผลต่างส่วนที่ตัดไปแล้ว" ไว้ให้บัญชีพิจารณาปรับผ่าน journal แยกเอง
    </div>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">ใบซื้อ {{ $purchase->doc_number }}</h2>
                <div class="text-muted small">
                    {{ $purchase->doc_date->thaiDate() }} &middot; ซัพพลายเออร์: {{ $purchase->supplier->name_th }}
                </div>
            </div>
        </div>

        <form method="post" action="{{ route('purchases.cost-adjustments.store', $purchase) }}">
            @csrf
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อสินค้า</th>
                            <th class="text-end">รับเข้าจริง</th>
                            <th class="text-end">คงเหลือ (Lot นี้)</th>
                            <th class="text-end">ต้นทุนเดิม/หน่วย</th>
                            <th class="text-end" style="width:160px">ต้นทุนใหม่/หน่วย</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            @php($item = $row['item']; $lot = $row['lot'])
                            <tr>
                                <td>{{ $item->product->sku_code }}</td>
                                <td>{{ $item->product->name_th }}</td>
                                <td class="text-end">{{ number_format((float) $lot->initial_qty, 4) }}</td>
                                <td class="text-end">
                                    {{ number_format((float) $lot->remaining_qty, 4) }}
                                    @if($row['partially_consumed'])
                                        <span class="badge text-bg-warning ms-1">ตัดออกไปแล้วบางส่วน</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format((float) $lot->unit_cost, 4) }}</td>
                                <td>
                                    <input type="hidden" name="items[{{ $i }}][stock_lot_id]" value="{{ $lot->id }}">
                                    <input type="number" step="0.0001" min="0" name="items[{{ $i }}][new_unit_cost]"
                                        value="{{ number_format((float) $lot->unit_cost, 4, '.', '') }}"
                                        class="form-control form-control-sm text-end">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="row g-3 align-items-end mt-2">
                <div class="col-md-9">
                    <label class="form-label small text-muted">เหตุผลที่ปรับต้นทุน (บังคับกรอก)</label>
                    <input name="reason" required class="form-control" placeholder="เช่น กรอกราคาผิดตอนรับของ / ใบแจ้งหนี้จริงราคาต่างจากที่บันทึกไว้">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-warning w-100" onclick="return confirm('ยืนยันปรับต้นทุน? การปรับนี้กระทบมูลค่าสต๊อกคงเหลือของสินค้าทันที')">
                        <i class="bi bi-pencil-square me-1"></i>บันทึกการปรับต้นทุน
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3">ประวัติการปรับต้นทุนของใบซื้อนี้</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>วันที่</th><th>สินค้า</th>
                        <th class="text-end">ต้นทุนเดิม</th><th class="text-end">ต้นทุนใหม่</th>
                        <th class="text-end">คงเหลือที่ปรับ</th><th class="text-end">ตัดไปแล้ว</th>
                        <th class="text-end">มูลค่าผลต่างส่วนที่ตัดไปแล้ว</th>
                        <th>เหตุผล</th><th>ผู้ปรับ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($history as $h)
                        <tr>
                            <td>{{ $h->created_at?->thaiDate() }}</td>
                            <td>{{ $h->product->sku_code }}</td>
                            <td class="text-end">{{ number_format((float) $h->old_unit_cost, 4) }}</td>
                            <td class="text-end">{{ number_format((float) $h->new_unit_cost, 4) }}</td>
                            <td class="text-end">{{ number_format((float) $h->remaining_qty_adjusted, 4) }}</td>
                            <td class="text-end">{{ number_format((float) $h->consumed_qty, 4) }}</td>
                            <td class="text-end {{ (float) $h->unconfirmed_variance_amount != 0 ? 'text-warning-emphasis fw-semibold' : '' }}">
                                {{ number_format((float) $h->unconfirmed_variance_amount, 2) }}
                            </td>
                            <td class="small">{{ $h->reason }}</td>
                            <td class="small">{{ $h->createdBy?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">ยังไม่เคยปรับต้นทุนใบซื้อนี้</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
