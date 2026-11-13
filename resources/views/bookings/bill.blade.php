@extends('layout')

@section('title', 'ออกใบขายจากใบจอง '.$booking->document->doc_number)
@section('page-title', 'ออกใบขายจากใบจอง')
@section('page-subtitle', 'ตรวจและแก้ไขรายการก่อนบันทึก รายการที่ไม่ได้ออกบิลรอบนี้จะค้างอยู่ในใบจอง')

@section('content')
<div class="bill-page" x-data="bookingBill()" x-init="checkAll()">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <div><div class="text-muted small">ใบจอง</div><a href="{{ route('bookings.show', $booking) }}" class="fw-bold fs-5">{{ $booking->document->doc_number }}</a></div>
            <div><div class="text-muted small">ลูกค้า</div><div class="fw-semibold fs-6">{{ $booking->document->customer?->code }} · {{ $booking->document->customer?->name_th }}</div></div>
            <div><div class="text-muted small">สาขา</div><div class="fw-semibold">{{ $booking->document->branch?->name_th }}</div></div>
            <div><div class="text-muted small">พนักงานขาย</div><div class="fw-semibold">{{ $booking->document->salesUser?->name ?? '-' }}</div></div>
        </div>
    </div>

    <form method="post" action="{{ route('bookings.bill.store', $booking) }}" @submit="if (!canSave()) { $event.preventDefault(); } else { submitting = true }">
        @csrf
        <div class="row g-3">
            @foreach(['DS' => ['ใบขายเชื่อ DS', 'มี VAT', 'primary'], 'DSN' => ['N-ใบขายเชื่อ DSN', 'ไม่มี VAT', 'secondary']] as $code => [$title, $vatLabel, $tone])
            <div class="col-12">
                <div class="card border-0 shadow-sm h-100 bill-card bill-{{ strtolower($code) }}">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <div class="fs-5 fw-bold">{{ $title }} <span class="badge text-bg-{{ $tone }} ms-1">{{ $vatLabel }}</span></div>
                        <div class="fs-5">รวม <b x-text="fmt(total('{{ $code }}'))"></b> บาท</div>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-sm-4">
                                <label class="form-label">เครดิต (วัน)</label>
                                <input type="number" min="0" max="365" name="bills[{{ $code }}][credit_days]" value="{{ old("bills.$code.credit_days", $creditDays) }}" class="form-control">
                            </div>
                            <div class="col-sm-8">
                                <label class="form-label">หมายเหตุ</label>
                                <input type="text" name="bills[{{ $code }}][remark]" value="{{ old("bills.$code.remark", 'อ้างถึงใบจองสินค้า #'.$booking->document->doc_number) }}" class="form-control">
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead><tr>
                                    <th>สินค้า</th>
                                    <th class="text-end" style="width:110px">ค้าง</th>
                                    <th class="text-end" style="width:130px">จำนวน</th>
                                    <th class="text-end" style="width:150px">ราคา/หน่วย</th>
                                    <th class="text-end" style="width:140px">จำนวนเงิน</th>
                                    <th style="width:110px"></th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="(line, idx) in lines" :key="line.id">
                                        <tr x-show="line.book === '{{ $code }}'" :class="line.belowCost && line.qty > 0 ? 'bill-below-cost' : ''">
                                            <td>
                                                <div class="fw-semibold"><span class="text-muted me-2" x-text="line.sku"></span><span x-text="line.name"></span></div>
                                                <div class="fw-bold text-danger" x-show="line.belowCost && line.qty > 0"><i class="bi bi-exclamation-triangle-fill me-1"></i>ราคาที่ขายต่ำกว่าทุน</div>
                                            </td>
                                            <td class="text-end text-muted text-nowrap"><span x-text="line.remaining_display || (fmt(line.remaining) + ' ' + line.unit)"></span></td>
                                            <td>
                                                <input type="number" step="0.0001" min="0" :max="line.remaining" class="form-control text-end" x-model.number="line.qty"
                                                       :class="line.qty > line.remaining ? 'is-invalid' : ''"
                                                       :name="line.book === '{{ $code }}' ? `bills[{{ $code }}][lines][${idx}][qty]` : ''">
                                                <input type="hidden" :name="line.book === '{{ $code }}' ? `bills[{{ $code }}][lines][${idx}][booking_item_id]` : ''" :value="line.id">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" class="form-control text-end" x-model.number="line.price"
                                                       @input.debounce.400ms="check(line)"
                                                       :class="line.belowCost && line.qty > 0 ? 'is-invalid' : ''"
                                                       :name="line.book === '{{ $code }}' ? `bills[{{ $code }}][lines][${idx}][unit_price]` : ''">
                                            </td>
                                            <td class="text-end fw-semibold" x-text="fmt(line.qty * line.price)"></td>
                                            <td class="text-end text-nowrap">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" title="ย้ายไปอีกใบ"
                                                        @click="line.book = line.book === 'DS' ? 'DSN' : 'DS'">↔ {{ $code === 'DS' ? 'DSN' : 'DS' }}</button>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr x-show="count('{{ $code }}') === 0"><td colspan="6" class="text-center text-muted py-4">ไม่มีรายการในใบนี้ ระบบจะไม่ออกใบนี้</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div>
                    <div class="text-muted">ใส่จำนวน 0 = ไม่ออกบิลรอบนี้ (ค้างในใบจอง) · จำนวนเกินยอดค้างไม่ได้ ถ้าลูกค้าเพิ่มให้แก้ใบจองก่อน</div>
                    <div class="text-danger fw-semibold mt-1" x-show="hasBelowCost() && !canApprove">มีรายการราคาต่ำกว่าทุน กดปุ่มด้านล่างเพื่อส่งให้ผู้จัดการอนุมัติ อนุมัติแล้วระบบออกใบขายให้เอง</div>
                    <div class="text-warning-emphasis fw-semibold mt-1" x-show="hasBelowCost() && canApprove">มีรายการราคาต่ำกว่าทุน คุณมีสิทธิ์อนุมัติ กดบันทึกถือว่าอนุมัติ</div>
                    <div class="text-danger fw-semibold mt-1" x-show="hasOverQty()">มีรายการจำนวนเกินยอดค้างในใบจอง</div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="fs-5 me-2">รวมทั้งหมด <b x-text="fmt(total('DS') + total('DSN'))"></b> บาท</div>
                    <a href="{{ route('bookings.show', $booking) }}" class="btn btn-light border px-4">ยกเลิก</a>
                    <button type="submit" class="btn btn-success px-4" :disabled="!canSave() || submitting">
                        <i class="bi bi-check2-circle me-1"></i>
                        <span x-text="hasBelowCost() && !canApprove ? 'ส่งให้ผู้จัดการอนุมัติราคา' : 'บันทึกใบขาย'"></span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
    .bill-page .table td, .bill-page .table th { font-size: 15px; }
    .bill-page .form-control { font-size: 15px; }
    html body .bill-page .table > tbody > tr.bill-below-cost > td { background: #fee2e2 !important; }
    html body .bill-page .table > tbody > tr.bill-below-cost > td:first-child { box-shadow: inset 4px 0 0 #dc2626; }
    html body .bill-page .table > tbody > tr.bill-below-cost input.is-invalid { border-color: #dc2626 !important; background: #fff !important; }
    .bill-page .bill-ds .card-header { border-top: 4px solid var(--erp-primary, #1585c0); }
    .bill-page .bill-dsn .card-header { border-top: 4px solid #64748b; }
</style>
<script>
function bookingBill() {
    return {
        lines: @js($lines),
        canApprove: @js($canApproveBelowCost),
        submitting: false,
        fmt(n) { return (Number(n) || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        count(code) { return this.lines.filter(l => l.book === code && l.qty > 0).length; },
        total(code) { return this.lines.filter(l => l.book === code).reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.price) || 0), 0); },
        hasBelowCost() { return this.lines.some(l => l.belowCost && l.qty > 0); },
        hasOverQty() { return this.lines.some(l => l.qty > l.remaining + 0.00001); },
        canSave() {
            // ต่ำกว่าทุนกดได้ — คนไม่มีสิทธิ์จะถูกส่งไปให้ผู้จัดการอนุมัติ (เจ้าของ 13 พ.ย.)
            return this.lines.some(l => l.qty > 0) && !this.hasOverQty();
        },
        async check(line) { await this.ask([line]); },
        async checkAll() { await this.ask(this.lines); },
        async ask(list) {
            const prices = {};
            list.forEach(l => { prices[l.id] = l.price; });
            try {
                const res = await fetch(@js(route('bookings.price-check', $booking)), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                    body: JSON.stringify({ prices }),
                });
                if (!res.ok) return;
                const flags = (await res.json()).below_cost || {};
                list.forEach(l => { if (flags[l.id] !== undefined) l.belowCost = !!flags[l.id]; });
            } catch (_) { /* เน็ตหลุด: เซิร์ฟเวอร์ตรวจซ้ำตอนบันทึกอยู่แล้ว */ }
        },
    };
}
</script>
@endsection
