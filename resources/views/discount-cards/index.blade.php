@extends('layout')
@section('title', 'บัตรส่วนลด - POPSTAR ERP')
@section('page-title', 'บัตรส่วนลด')
@section('page-subtitle', 'ออกบัตรส่วนลดสำหรับใช้ตัดยอดที่หน้าร้าน POS')
@section('content')
<div class="content-card p-4 mb-3" x-data="{ type: 'percent' }">
    <h2 class="h5 fw-bold mb-3">ออกบัตรส่วนลด</h2>
    <form method="post" action="{{ route('discount-cards.store') }}" class="row g-3">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัสบัตร</label><input name="card_code" required class="form-control" placeholder="เลข/บาร์โค้ดบนบัตร"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อบัตร</label><input name="name" required class="form-control" placeholder="เช่น บัตร VIP ลด 10%"></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">ผูกกับสมาชิก (ไม่บังคับ)</label>
            <select name="member_id" class="form-select">
                <option value="">-- ไม่ผูกสมาชิก --</option>
                @foreach($members as $m)
                    <option value="{{ $m->id }}">{{ $m->member_code }} - {{ $m->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="cardActive"><label class="form-check-label" for="cardActive">ใช้งาน</label></div></div>

        <div class="col-md-2">
            <label class="form-label small text-muted">ประเภทส่วนลด</label>
            <select name="discount_type" x-model="type" class="form-select">
                <option value="percent">เปอร์เซ็นต์ (%)</option>
                <option value="amount">จำนวนเงิน (บาท)</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small text-muted">มูลค่าส่วนลด</label><input type="number" step="0.01" min="0" name="discount_value" required class="form-control"></div>
        <div class="col-md-2" x-show="type === 'percent'" x-cloak><label class="form-label small text-muted">ลดสูงสุด (บาท)</label><input type="number" step="0.01" min="0" name="max_discount_amount" class="form-control" placeholder="ไม่จำกัด"></div>
        <div class="col-md-2"><label class="form-label small text-muted">ยอดซื้อขั้นต่ำ</label><input type="number" step="0.01" min="0" name="min_amount" class="form-control" placeholder="ไม่กำหนด"></div>
        <div class="col-md-2"><label class="form-label small text-muted">จำกัดจำนวนครั้งใช้</label><input type="number" min="1" name="usage_limit" class="form-control" placeholder="ไม่จำกัด"></div>

        <div class="col-md-2"><label class="form-label small text-muted">วันที่เริ่มใช้ได้</label><input type="date" name="starts_date" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">วันหมดอายุ</label><input type="date" name="ends_date" class="form-control"></div>
        <div class="col-md-4"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">ออกบัตร</button></div>
    </form>
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการบัตรส่วนลด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัสบัตร</th><th>ชื่อบัตร</th><th>สมาชิก</th><th>ส่วนลด</th><th>ยอดขั้นต่ำ</th><th>ใช้ไปแล้ว</th><th>วันหมดอายุ</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($cards as $card)
                <tr>
                    <td class="fw-semibold">{{ $card->card_code }}</td>
                    <td>{{ $card->name }}</td>
                    <td>{{ $card->member?->name ?? '-' }}</td>
                    <td>{{ $card->discount_type === 'percent' ? number_format($card->discount_value, 2).'%' : number_format($card->discount_value, 2).' บาท' }}
                        @if($card->max_discount_amount)<div class="text-muted small">สูงสุด {{ number_format($card->max_discount_amount, 2) }}</div>@endif
                    </td>
                    <td>{{ $card->min_amount ? number_format($card->min_amount, 2) : '-' }}</td>
                    <td>{{ $card->used_count }}{{ $card->usage_limit ? ' / '.$card->usage_limit : '' }}</td>
                    <td>{{ $card->ends_date?->thaiDate() ?? 'ไม่มีกำหนด' }}</td>
                    <td><span class="badge {{ $card->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $card->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีบัตรส่วนลด</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $cards->links() }}
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
