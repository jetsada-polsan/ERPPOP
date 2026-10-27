@extends('layout')

@section('title', 'สร้างใบจ่ายชำระเจ้าหนี้ - PopCentral')
@section('page-title', 'สร้างใบจ่ายชำระเจ้าหนี้')
@section('page-subtitle', 'ติ๊กใบซื้อที่จะจ่าย แก้ยอดได้ถ้าจ่ายบางส่วน')

@section('content')
<a href="{{ route('ap-payments.index') }}" class="text-decoration-none small d-inline-block mb-3">
    <i class="bi bi-arrow-left me-1"></i> กลับไปรายการเจ้าหนี้ค้างจ่าย
</a>

{{-- เลือกผู้จำหน่าย: โหลดหน้าใหม่พร้อมใบค้างจ่ายของรายนั้น --}}
<div class="content-card p-4 mb-3">
    <form method="get" action="{{ route('ap-payments.create') }}" class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label small text-muted">ผู้จำหน่าย (เฉพาะรายที่มียอดค้างจ่าย)</label>
            <select name="supplier_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- เลือกผู้จำหน่าย --</option>
                @foreach($suppliersWithDebt as $option)
                    <option value="{{ $option->id }}" @selected($supplier?->id === $option->id)>{{ $option->code }} - {{ $option->name_th }}</option>
                @endforeach
            </select>
        </div>
        <noscript><div class="col-md-2"><button class="btn btn-light border w-100">แสดงใบค้างจ่าย</button></div></noscript>
    </form>
</div>

@if($supplier)
<form method="post" action="{{ route('ap-payments.store') }}" novalidate x-data="apPaymentForm()" @submit="onSubmit">
    @csrf
    <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">

    <div class="content-card p-4 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 fw-bold mb-0">ใบซื้อค้างจ่ายของ {{ $supplier->name_th }}</h2>
            <button type="button" class="btn btn-sm btn-light border" @click="selectAll()">เลือกทั้งหมด</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:44px"></th>
                        <th>เลขที่ใบซื้อ</th>
                        <th>วันที่</th>
                        <th>ครบกำหนด</th>
                        <th class="text-end">ยอดเต็ม</th>
                        <th class="text-end">ค้างจ่าย</th>
                        <th class="text-end" style="width:170px">จ่ายครั้งนี้</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($openItems as $item)
                        <tr :class="{ 'table-active': rows[{{ $loop->index }}].selected }">
                            <td>
                                <input type="checkbox" class="form-check-input" x-model="rows[{{ $loop->index }}].selected"
                                    @change="toggle({{ $loop->index }})" aria-label="เลือกใบ {{ $item->document_no }}">
                                <input type="hidden" name="items[{{ $loop->index }}][supplier_open_item_id]" value="{{ $item->id }}"
                                    :disabled="!rows[{{ $loop->index }}].selected">
                            </td>
                            <td class="fw-semibold">
                                @if($item->source_document_id)
                                    <a href="{{ route('purchases.show', $item->source_document_id) }}">{{ $item->document_no }}</a>
                                @else
                                    {{ $item->document_no }}
                                @endif
                            </td>
                            <td>{{ $item->document_date?->thaiDate() }}</td>
                            <td>
                                {{ $item->due_date?->thaiDate() ?? '-' }}
                                @if($item->due_date?->isPast())<span class="badge text-bg-danger ms-1">เกินกำหนด</span>@endif
                            </td>
                            <td class="text-end">{{ number_format((float) $item->original_amount, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $item->balance_amount, 2) }}</td>
                            <td>
                                <input type="number" step="0.01" min="0" max="{{ (float) $item->balance_amount }}"
                                    name="items[{{ $loop->index }}][amount]" class="form-control form-control-sm text-end"
                                    x-model.number="rows[{{ $loop->index }}].amount" :disabled="!rows[{{ $loop->index }}].selected">
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-muted">ผู้จำหน่ายรายนี้ไม่มีใบค้างจ่าย</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="6" class="text-end">รวมจ่ายครั้งนี้</td>
                        <td class="text-end" x-text="money(total())"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="content-card p-4 h-100">
                <h2 class="h6 fw-bold mb-3">วิธีจ่าย</h2>
                <div class="mb-3">
                    <label class="form-label small text-muted">สาขาที่จ่าย</label>
                    <select name="branch_id" class="form-select">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->code }} - {{ $branch->name_th }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small text-muted">จ่ายด้วย</label>
                    <select name="method" class="form-select" x-model="method">
                        <option value="cash">เงินสด</option>
                        <option value="transfer">โอนเงิน</option>
                        <option value="cheque">เช็ค</option>
                    </select>
                </div>
                <div class="row g-2" x-show="method === 'cheque'" x-cloak>
                    <div class="col-4"><label class="form-label small text-muted">เลขที่เช็ค</label><input name="cheque_no" x-model="chequeNo" class="form-control" :disabled="method !== 'cheque'"></div>
                    <div class="col-4"><label class="form-label small text-muted">วันที่บนเช็ค</label><input type="date" name="cheque_due_date" x-model="chequeDate" class="form-control" :disabled="method !== 'cheque'"></div>
                    <div class="col-4"><label class="form-label small text-muted">ธนาคาร</label><input name="cheque_bank" class="form-control" :disabled="method !== 'cheque'"></div>
                    <div class="col-12 small text-muted"><i class="bi bi-info-circle me-1"></i>เช็คจะเข้าทะเบียนเช็คจ่ายอัตโนมัติ</div>
                </div>
                <div class="small text-muted mt-2">
                    ลงบัญชี: Dr เจ้าหนี้การค้า / Cr <span x-text="method === 'cash' ? 'เงินสด' : 'เงินฝากธนาคาร'"></span>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card p-4 h-100">
                <h2 class="h6 fw-bold mb-3">หัก ณ ที่จ่าย (ถ้ามี)</h2>
                @unless(preg_match('/^\d{13}$/', (string) $supplier->tax_id))
                    <div class="alert alert-warning small py-2">ผู้จำหน่ายรายนี้ยังไม่มีเลขประจำตัวผู้เสียภาษี 13 หลัก จึงหัก ณ ที่จ่ายไม่ได้</div>
                @endunless
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small text-muted">ฐานภาษี</label><input type="number" step="0.01" min="0" name="withholding_base" x-model.number="whtBase" class="form-control"></div>
                    <div class="col-6"><label class="form-label small text-muted">อัตรา (%)</label><input type="number" step="0.01" min="0" max="99.99" name="withholding_rate" x-model.number="whtRate" class="form-control"></div>
                    <div class="col-6"><label class="form-label small text-muted">แบบภาษี</label>
                        <select name="withholding_form" class="form-select"><option value="PND53">ภ.ง.ด.53 (นิติบุคคล)</option><option value="PND3">ภ.ง.ด.3 (บุคคลธรรมดา)</option></select>
                    </div>
                    <div class="col-6"><label class="form-label small text-muted">ประเภทเงินได้</label><input name="withholding_income_type" x-model="whtIncomeType" maxlength="255" class="form-control" placeholder="เช่น ค่าขนส่ง ค่าบริการ"></div>
                </div>
                <dl class="row small mt-3 mb-0">
                    <dt class="col-7 fw-normal text-muted">ยอดตัดเจ้าหนี้</dt><dd class="col-5 text-end mb-1" x-text="money(total())"></dd>
                    <dt class="col-7 fw-normal text-muted">ภาษีหัก ณ ที่จ่าย</dt><dd class="col-5 text-end mb-1" x-text="money(tax())"></dd>
                    <dt class="col-7">จ่ายจริง</dt><dd class="col-5 text-end fw-bold mb-0" x-text="money(total() - tax())"></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="{{ route('ap-payments.index') }}" class="btn btn-light border px-4">ยกเลิก</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึกจ่ายชำระ</button>
    </div>
</form>
@endif
@endsection

@if($supplier)
@php
    $jsItems = $openItems->map(fn ($item) => [
        'id' => $item->id,
        'no' => $item->document_no,
        'balance' => round((float) $item->balance_amount, 2),
    ])->values();
@endphp
@push('scripts')
<script>
    function apPaymentForm() {
        const items = {{ \Illuminate\Support\Js::from($jsItems) }};
        const preselected = {{ \Illuminate\Support\Js::from($preselectedId) }};

        return {
            method: 'cash',
            chequeNo: '',
            chequeDate: @json(now()->toDateString()),
            whtBase: 0,
            whtRate: 0,
            whtIncomeType: '',
            // ใบที่มาจากปุ่ม "จ่ายชำระ" หน้าใบซื้อ ถูกเลือกไว้ให้แล้ว
            rows: items.map(item => ({ ...item, selected: item.id === preselected, amount: item.id === preselected ? item.balance : 0 })),

            money(value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            total() { return Math.round(this.rows.filter(r => r.selected).reduce((sum, r) => sum + Number(r.amount || 0), 0) * 100) / 100; },
            tax() { return Math.round(Number(this.whtBase || 0) * Number(this.whtRate || 0)) / 100; },

            toggle(index) {
                const row = this.rows[index];
                row.amount = row.selected ? row.balance : 0;
            },
            selectAll() {
                this.rows.forEach(row => { row.selected = true; row.amount = row.balance; });
            },

            onSubmit(event) {
                const problems = [];
                const chosen = this.rows.filter(r => r.selected);
                if (chosen.length === 0) problems.push('ยังไม่ได้เลือกใบซื้อที่จะจ่าย');
                chosen.forEach(r => {
                    if (!(Number(r.amount) > 0)) problems.push(`ใบ ${r.no}: ยอดจ่ายต้องมากกว่า 0`);
                    else if (Number(r.amount) > r.balance + 0.001) problems.push(`ใบ ${r.no}: จ่ายเกินยอดค้าง (${this.money(r.balance)})`);
                });
                if (this.method === 'cheque') {
                    if (!this.chequeNo.trim()) problems.push('ยังไม่ได้กรอกเลขที่เช็ค');
                    if (!this.chequeDate) problems.push('ยังไม่ได้ระบุวันที่บนเช็ค');
                }
                if (Number(this.whtRate) > 0) {
                    if (!(Number(this.whtBase) > 0)) problems.push('หัก ณ ที่จ่าย: ยังไม่ได้ใส่ฐานภาษี');
                    if (Number(this.whtBase) > this.total()) problems.push('หัก ณ ที่จ่าย: ฐานภาษีมากกว่ายอดที่จ่าย');
                    if (!this.whtIncomeType.trim()) problems.push('หัก ณ ที่จ่าย: ยังไม่ได้ระบุประเภทเงินได้');
                }
                window.erpStopSubmit(event, problems);
            },
        };
    }
</script>
@endpush
@endif
