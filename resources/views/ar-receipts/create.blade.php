@extends('layout')
@section('title', 'ออกใบเสร็จรับเงิน')
@section('page-title', 'ออกใบเสร็จรับเงิน')
@section('page-subtitle', 'เลือกลูกค้า → ติ๊กบิลที่รับชำระ → เลือกเงินสด/โอน/เช็ค → บันทึก')

@section('content')
<form method="get" action="{{ route('ar-receipts.create') }}" class="content-card p-3 mb-3">
    <label class="form-label fw-semibold">ลูกค้า (เฉพาะที่มียอดค้าง)</label>
    <select name="customer_id" class="form-select" onchange="this.form.submit()">
        <option value="">— เลือกลูกค้า —</option>
        @foreach($customersWithDebt as $option)
            <option value="{{ $option->id }}" @selected($customer?->id === $option->id)>{{ $option->code }} - {{ $option->name_th }}</option>
        @endforeach
    </select>
</form>

@if($customer)
<form method="post" action="{{ route('ar-receipts.store') }}" x-data="arReceipt()" @submit="if (!canSave()) $event.preventDefault()">
    @csrf
    <input type="hidden" name="customer_id" value="{{ $customer->id }}">
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="content-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h2 class="h6 fw-bold mb-0">บิลค้างชำระของ {{ $customer->name_th }}</h2>
                    <button type="button" class="btn btn-sm btn-light border" @click="selectAll()">เลือกทั้งหมด</button>
                </div>
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead><tr><th style="width:40px"></th><th>เลขที่บิล</th><th>วันที่</th><th>ครบกำหนด</th><th class="text-end">ยอดค้าง</th><th class="text-end" style="width:160px">รับชำระ</th></tr></thead>
                    <tbody>
                    @foreach($openItems as $i => $item)
                        <tr>
                            <td><input type="checkbox" class="form-check-input" x-model="rows[{{ $i }}].on" @change="rows[{{ $i }}].amount = rows[{{ $i }}].on ? rows[{{ $i }}].balance : 0"></td>
                            <td class="fw-semibold">{{ $item->document?->doc_number }}</td>
                            <td>{{ $item->document?->doc_date?->thaiDate() }}</td>
                            <td>{{ $item->due_date ? \Illuminate\Support\Carbon::parse($item->due_date)->thaiDate() : '-' }}</td>
                            <td class="text-end">{{ number_format((float) $item->balance_amount, 2) }}</td>
                            <td>
                                <input type="hidden" name="items[{{ $i }}][customer_open_item_id]" value="{{ $item->id }}">
                                <input type="number" step="0.01" min="0" :max="rows[{{ $i }}].balance" name="items[{{ $i }}][amount]"
                                       x-model.number="rows[{{ $i }}].amount" :disabled="!rows[{{ $i }}].on" class="form-control text-end">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="content-card p-3" x-data="{ m: '{{ old('method', 'cash') }}' }">
                <div class="mb-3">
                    <label class="form-label">สาขา</label>
                    <select name="branch_id" class="form-select" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">รับชำระด้วย</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="method" id="mCash" value="cash" x-model="m"><label class="btn btn-outline-primary" for="mCash">เงินสด</label>
                        <input type="radio" class="btn-check" name="method" id="mTransfer" value="transfer" x-model="m"><label class="btn btn-outline-primary" for="mTransfer">เงินโอน</label>
                        <input type="radio" class="btn-check" name="method" id="mCheque" value="cheque" x-model="m"><label class="btn btn-outline-primary" for="mCheque">เช็ค</label>
                    </div>
                </div>
                @include('payments._bank-account-select', ['model' => 'm', 'class' => 'mb-3'])
                <div x-show="m === 'transfer'" x-cloak class="row g-2 mb-3">
                    <div class="col-7"><label class="form-label">เลขอ้างอิงการโอน</label><input name="transfer_ref" maxlength="60" class="form-control" value="{{ old('transfer_ref') }}" placeholder="เลขท้ายสลิป / Ref"></div>
                    <div class="col-5"><label class="form-label">เวลาโอน</label><input type="datetime-local" name="transfer_at" class="form-control" value="{{ old('transfer_at') }}"></div>
                </div>
                <template x-if="m === 'cheque'">
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label">เลขที่เช็ค</label><input name="cheque_no" required class="form-control"></div>
                        <div class="col-6"><label class="form-label">วันที่บนเช็ค</label><input type="date" name="cheque_due_date" required class="form-control" value="{{ now()->toDateString() }}"></div>
                        <div class="col-12"><label class="form-label">ธนาคาร</label><input name="cheque_bank" class="form-control" placeholder="เช่น กสิกรไทย"></div>
                    </div>
                </template>
                <div class="p-3 rounded-3 bg-light mb-3">
                    <div class="d-flex justify-content-between"><span>บิลที่เลือก</span><b x-text="rows.filter(r => r.on).length"></b></div>
                    <div class="d-flex justify-content-between fs-5 mt-1"><span>รวมรับชำระ</span><b x-text="fmt(total())"></b></div>
                </div>
                <div class="text-danger small mb-2" x-show="overpaid()">มีบิลที่ใส่ยอดเกินยอดค้าง</div>
                <button type="submit" class="btn btn-success w-100" :disabled="!canSave()"><i class="bi bi-check2-circle me-1"></i> บันทึกใบเสร็จรับเงิน</button>
            </div>
        </div>
    </div>
</form>
<script>
function arReceipt() {
    return {
        rows: @js($openItems->map(fn ($i) => ['on' => false, 'amount' => 0, 'balance' => round((float) $i->balance_amount, 2)])->values()),
        fmt(n) { return (Number(n) || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        total() { return this.rows.filter(r => r.on).reduce((s, r) => s + (Number(r.amount) || 0), 0); },
        overpaid() { return this.rows.some(r => r.on && r.amount > r.balance + 0.001); },
        canSave() { return this.total() > 0 && !this.overpaid(); },
        selectAll() { this.rows.forEach(r => { r.on = true; r.amount = r.balance; }); },
    };
}
</script>
@endif
@endsection
