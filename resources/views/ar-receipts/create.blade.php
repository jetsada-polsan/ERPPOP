@extends('layout')
@section('title', 'ออกใบเสร็จรับเงิน (BPlus) - MPM Food Group')
@section('page-title', 'ออกใบเสร็จรับเงิน')
@section('page-subtitle', 'เลือกลูกค้า ติ๊กบิลค้างชำระ ลดสด หัก ณ ที่จ่าย และระบุเงินสด/โอน/เช็ค จบในหน้าเดียว')

@section('content')
<div x-data="bplusReceipt()" x-cloak>
    <form id="arReceiptForm" method="post" action="{{ route('ar-receipts.store') }}" @submit="if (!canSave()) $event.preventDefault()">
        @csrf

        {{-- แถบหัวข้อมูลเอกสาร (สาขา, ลูกค้า, วันที่, ผู้รับเงิน, หนังสือรับรองหัก ณ ที่จ่าย) --}}
        <div class="content-card p-3 mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold text-primary mb-1">
                        <i class="bi bi-person-fill me-1"></i> เลือกลูกหนี้ / ลูกค้า <span class="text-danger">*</span>
                    </label>
                    <select name="customer_id" class="form-select form-select-sm" x-model="customerId" @change="onCustomerChange()" required>
                        <option value="">— เลือกลูกค้าที่มีหนี้ค้าง —</option>
                        @foreach($customersWithDebt as $option)
                            <option value="{{ $option->id }}" @selected($customer?->id === $option->id)>
                                {{ $option->code }} - {{ $option->name_th }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold text-secondary mb-1">
                        <i class="bi bi-shop me-1"></i> สาขา <span class="text-danger">*</span>
                    </label>
                    <select name="branch_id" class="form-select form-select-sm" x-model="branchId" required>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold text-secondary mb-1">
                        <i class="bi bi-calendar-event me-1"></i> วันที่ใบเสร็จ
                    </label>
                    <input type="date" name="doc_date" class="form-control form-control-sm" x-model="docDate" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold text-secondary mb-1">
                        <i class="bi bi-person-badge me-1"></i> ผู้รับเงิน / พนักงานเก็บเงิน
                    </label>
                    <select name="salesman_id" class="form-select form-select-sm" x-model="salesmanId">
                        <option value="">— ไม่ระบุ / ส่วนกลาง —</option>
                        @foreach($salesmen as $sm)
                            <option value="{{ $sm->id }}">{{ $sm->code }} - {{ $sm->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- หนังสือรับรองหัก ณ ที่จ่าย (ถ้ามี) --}}
            <div class="row g-3 align-items-center mt-1 pt-2 border-top">
                <div class="col-md-4">
                    <div class="text-muted small">
                        <i class="bi bi-file-earmark-check text-warning me-1"></i> ข้อมูลหนังสือรับรองหัก ณ ที่จ่าย (ลูกค้าออกให้)
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light text-muted small">เลขที่หนังสือรับรอง</span>
                        <input type="text" name="wht_cert_no" class="form-control form-control-sm"
                               placeholder="เช่น WHT-2026/001 (ถ้ามี)">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light text-muted small">ลงวันที่</span>
                        <input type="date" name="wht_cert_date" class="form-control form-control-sm">
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            {{-- ฝั่งซ้าย: ตารางบิลค้าง + รายละเอียดการชำระเงิน --}}
            <div class="col-xl-8">
                {{-- 1. บิลค้างชำระ (ยอดหนี้) --}}
                <div class="content-card p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h6 fw-bold mb-0 text-dark">
                                <i class="bi bi-file-earmark-text text-primary me-1"></i> รายการบิลค้างชำระ (ยอดหนี้)
                            </h2>
                            <span class="badge bg-light text-secondary border" x-text="openItems.length + ' บิล'"></span>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="toggleOnlySelected" x-model="onlySelected">
                                <label class="form-check-label small fw-semibold" for="toggleOnlySelected">แสดงเฉพาะที่เลือก</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" @click="selectAll(true)" :disabled="openItems.length === 0">
                                <i class="bi bi-check-all me-1"></i> ติ๊กทั้งหมด
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2" @click="selectAll(false)" :disabled="openItems.length === 0">
                                ล้าง
                            </button>
                        </div>
                    </div>

                    {{-- แถบเครื่องมือ: ยอดที่ลูกค้าจ่ายมา + ตัดบิลเก่าสุดก่อนอัตโนมัติ (แบบ BPlus) --}}
                    <div class="p-3 mb-3 rounded-3 border bg-light bg-opacity-75">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-dark mb-0">
                                    <i class="bi bi-cash me-1 text-success"></i> ยอดที่ลูกค้าจ่ายมา
                                </label>
                                <div class="text-muted" style="font-size: 11px;">ใส่ยอดจ่ายครั้งเดียว ระบบตัดบิลเก่าสุดก่อน</div>
                            </div>
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white fw-bold">฿</span>
                                    <input type="number" step="0.01" min="0" class="form-control text-end fw-bold fs-6"
                                           placeholder="0.00" x-model.number="customerPaidInput"
                                           @input="onCustomerPaidInput()">
                                    <button type="button" class="btn btn-primary fw-semibold px-3" @click="autoDistribute()" :disabled="openItems.length === 0">
                                        <i class="bi bi-lightning-charge-fill me-1"></i> ตัดอัตโนมัติ
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="d-flex flex-wrap align-items-center justify-content-md-end gap-2">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="autoDistributeOnPayChange" x-model="autoDistributeOnPayChange">
                                        <label class="form-check-label small fw-semibold" for="autoDistributeOnPayChange" title="เมื่อเปลี่ยนยอดเงินสด/โอน/เช็ค จะตัดหนี้บิลเก่าสุดให้อัตโนมัติ">
                                            ตัดตามยอดที่รับอัตโนมัติ
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- แถบสรุป 3 ส่วนแบบ BPlus --}}
                        <div class="row g-2 mt-2 pt-2 border-top align-items-center small">
                            <div class="col-sm-4">
                                <span class="text-muted">ยอดหนี้ที่เลือก:</span>
                                <span class="fw-bold ms-1" x-text="'฿' + fmt(totalSelectedDebt())"></span>
                            </div>
                            <div class="col-sm-4 text-sm-center">
                                <span class="text-muted">ยอดชำระรวม:</span>
                                <span class="fw-bold text-primary ms-1" x-text="'฿' + fmt(totalAllocated())"></span>
                            </div>
                            <div class="col-sm-4 text-sm-end">
                                <span class="text-muted">ชำระครั้งถัดไป (คงค้าง):</span>
                                <span class="fw-bold text-danger fs-6 ms-1" x-text="'฿' + fmt(nextPaymentRemaining())"></span>
                            </div>
                        </div>

                        {{-- เตือนถ้ายอดที่จ่าย > ยอดหนี้ที่เลือก --}}
                        <template x-if="isPaidExceedingDebt()">
                            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                                <div>
                                    <b>ยอดที่จ่ายเกินยอดหนี้ที่เลือก:</b>
                                    ลูกค้าจ่ายมา ฿<span x-text="fmt(customerPaidInput)"></span> แต่หนี้ที่เลือกมีเพียง ฿<span x-text="fmt(totalSelectedDebt())"></span> (เกินมา ฿<span x-text="fmt(customerPaidInput - totalSelectedDebt())"></span>)
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- ตารางบิล BPlus --}}
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0" style="min-width: 860px;">
                            <thead>
                                <tr class="table-light">
                                    <th style="width: 40px;" class="text-center">ชำระ</th>
                                    <th>เลขที่บิล</th>
                                    <th>วันที่</th>
                                    <th>ครบกำหนด</th>
                                    <th class="text-end">ยอดค้าง</th>
                                    <th class="text-end" style="width: 125px;">ยอดตัดหนี้</th>
                                    <th class="text-end" style="width: 90px;">ลดสด</th>
                                    <th style="width: 135px;">หัก ณ ที่จ่าย</th>
                                    <th class="text-end" style="width: 100px;">รับจริง</th>
                                    <th class="text-end text-danger" style="width: 110px;">คงค้างยกไป</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="loadingItems">
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <div class="spinner-border spinner-border-sm text-primary me-1"></div> กำลังดึงรายการบิลค้าง...
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="!loadingItems && openItems.length === 0">
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="bi bi-inbox fs-4 d-block mb-1 text-secondary"></i>
                                            <span x-text="customerId ? 'ลูกค้ารายนี้ไม่มียอดหนี้ค้างชำระ' : 'กรุณาเลือกลูกหนี้ด้านบนเพื่อแสดงบิลค้าง'"></span>
                                        </td>
                                    </tr>
                                </template>
                                <template x-for="(item, idx) in openItems" :key="item.id">
                                    <tr :class="{'table-success bg-opacity-10': item.checked}" x-show="!onlySelected || item.checked">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input"
                                                   x-model="item.checked"
                                                   @change="toggleItem(item)">
                                            <input type="hidden" :name="'allocations[' + idx + '][customer_open_item_id]'" :value="item.id">
                                        </td>
                                        <td class="fw-bold" :class="item.checked ? 'text-primary' : 'text-dark'">
                                            <span x-text="item.doc_number"></span>
                                            <template x-if="item.has_vat">
                                                <span class="badge text-bg-light border text-muted small ms-1" style="font-size: 10px;">VAT</span>
                                            </template>
                                        </td>
                                        <td class="text-muted small" x-text="item.doc_date"></td>
                                        <td class="small" x-text="item.due_date"></td>
                                        <td class="text-end fw-semibold text-secondary" x-text="fmt(item.balance)"></td>
                                        {{-- ยอดตัดหนี้ --}}
                                        <td>
                                            <input type="number" step="0.01" min="0" :max="item.balance"
                                                   :name="'allocations[' + idx + '][amount]'"
                                                   x-model.number="item.amount"
                                                   @input="onAmountChange(item)"
                                                   :disabled="!item.checked"
                                                   class="form-control form-control-sm text-end fw-bold"
                                                   :class="{'is-invalid': item.checked && (item.amount > item.balance + 0.001 || item.amount < 0)}"
                                                   placeholder="0.00">
                                        </td>
                                        {{-- ลดสด --}}
                                        <td>
                                            <input type="number" step="0.01" min="0" :max="item.amount"
                                                   :name="'allocations[' + idx + '][discount_amount]'"
                                                   x-model.number="item.discount_amount"
                                                   :disabled="!item.checked"
                                                   class="form-control form-control-sm text-end text-danger"
                                                   placeholder="0.00">
                                        </td>
                                        {{-- หัก ณ ที่จ่าย (% และเงิน) --}}
                                        <td>
                                            <div class="d-flex gap-1 align-items-center">
                                                <select class="form-select form-select-sm p-1 text-center" style="width: 58px;"
                                                         :name="'allocations[' + idx + '][wht_rate]'"
                                                         x-model.number="item.wht_rate"
                                                         @change="calcWht(item)"
                                                         :disabled="!item.checked">
                                                    <option value="0">0%</option>
                                                    <option value="1">1%</option>
                                                    <option value="2">2%</option>
                                                    <option value="3">3%</option>
                                                    <option value="5">5%</option>
                                                </select>
                                                <input type="number" step="0.01" min="0" :max="item.amount"
                                                       :name="'allocations[' + idx + '][wht_amount]'"
                                                       x-model.number="item.wht_amount"
                                                       :disabled="!item.checked"
                                                       class="form-control form-control-sm text-end text-warning-emphasis"
                                                       placeholder="0.00">
                                            </div>
                                        </td>
                                        {{-- รับจริงสุทธิของบิลนี้ --}}
                                        <td class="text-end fw-bold text-success" x-text="fmt(itemNet(item))"></td>
                                        {{-- คงค้างยกไป (ชำระครั้งถัดไปของบิลนี้) --}}
                                        <td class="text-end fw-semibold text-danger" x-text="fmt(itemRemaining(item))"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- 2. รายละเอียดการชำระเงิน (BPlus: เงินสด / โอน / เช็ค ในใบเดียว) --}}
                <div class="content-card p-3 mb-3">
                    <h2 class="h6 fw-bold mb-3 text-dark">
                        <i class="bi bi-wallet2 text-success me-1"></i> รายละเอียดการชำระเงิน (ใส่ได้หลายวิธีในใบเดียว)
                    </h2>

                    {{-- 2.1 เงินสด --}}
                    <div class="p-3 rounded-3 mb-3 border bg-light bg-opacity-50">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-3">
                                <span class="fw-bold text-success"><i class="bi bi-cash-stack me-1"></i> 1. เงินสด</span>
                            </div>
                            <div class="col-md-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">฿</span>
                                    <input type="number" step="0.01" min="0" name="cash_amount"
                                           class="form-control text-end fw-bold fs-6"
                                           x-model.number="cashAmount" @input="onPaymentChange()" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="button" class="btn btn-sm btn-outline-success w-100 rounded-pill" @click="fillRemainingToCash()">
                                    ใส่ยอดคงเหลือ
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- 2.2 โอนเงิน --}}
                    <div class="p-3 rounded-3 mb-3 border bg-light bg-opacity-50">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-md-3">
                                <span class="fw-bold text-primary"><i class="bi bi-bank me-1"></i> 2. ยอดโอน</span>
                            </div>
                            <div class="col-md-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">฿</span>
                                    <input type="number" step="0.01" min="0" name="transfer_amount"
                                           class="form-control text-end fw-bold fs-6"
                                           x-model.number="transferAmount" @input="onPaymentChange()" placeholder="0.00">
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary w-100 rounded-pill" @click="fillRemainingToTransfer()">
                                    ใส่ยอดคงเหลือ
                                </button>
                            </div>
                        </div>

                        {{-- รายละเอียดโอน (แสดงเมื่อมียอดโอน) --}}
                        <div class="row g-2 pt-2 border-top" x-show="transferAmount > 0">
                            <div class="col-md-5">
                                <label class="form-label small text-muted mb-1">เข้าบัญชีธนาคาร</label>
                                <select name="transfer_bank_account_id" class="form-select form-select-sm" x-model="transferBankAccountId">
                                    <option value="">อัตโนมัติ — บัญชีของสาขา</option>
                                    @foreach($bankAccounts as $bank)
                                        <option value="{{ $bank->id }}">
                                            {{ $bank->bank_name }} {{ $bank->account_no }} ({{ $bank->branch ? $bank->branch->code : 'ส่วนกลาง' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">เลขอ้างอิง / สลิปโอน</label>
                                <input type="text" name="transfer_ref" class="form-control form-control-sm"
                                       x-model="transferRef" placeholder="Ref / สลิป">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-muted mb-1">วันเวลาโอน</label>
                                <input type="datetime-local" name="transfer_at" class="form-control form-control-sm"
                                       x-model="transferAt">
                            </div>
                        </div>
                    </div>

                    {{-- 2.3 เช็ค (BPlus ใส่ได้หลายแถว) --}}
                    <div class="p-3 rounded-3 border bg-light bg-opacity-50">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark"><i class="bi bi-card-checklist text-warning me-1"></i> 3. เช็ค</span>
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark rounded-pill px-3" @click="addCheque()">
                                <i class="bi bi-plus-lg me-1"></i> เพิ่มเช็ค
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="small text-muted">
                                        <th style="width: 30px;">#</th>
                                        <th style="width: 25%;">ธนาคารบนเช็ค</th>
                                        <th style="width: 25%;">เลขที่เช็ค</th>
                                        <th style="width: 20%;">ลงวันที่</th>
                                        <th style="width: 25%;" class="text-end">ยอดเงินเช็ค</th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(ch, cIdx) in cheques" :key="cIdx">
                                        <tr>
                                            <td class="small text-muted" x-text="cIdx + 1"></td>
                                            <td>
                                                <input type="text" :name="'cheques[' + cIdx + '][cheque_bank]'"
                                                       class="form-control form-control-sm"
                                                       x-model="ch.cheque_bank" placeholder="เช่น กสิกรไทย">
                                            </td>
                                            <td>
                                                <input type="text" :name="'cheques[' + cIdx + '][cheque_no]'"
                                                       class="form-control form-control-sm"
                                                       x-model="ch.cheque_no" placeholder="เลขที่เช็ค"
                                                       :required="ch.amount > 0">
                                            </td>
                                            <td>
                                                <input type="date" :name="'cheques[' + cIdx + '][cheque_due_date]'"
                                                       class="form-control form-control-sm"
                                                       x-model="ch.cheque_due_date"
                                                       :required="ch.amount > 0">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" :name="'cheques[' + cIdx + '][amount]'"
                                                       class="form-control form-control-sm text-end fw-bold"
                                                       x-model.number="ch.amount" @input="onPaymentChange()" placeholder="0.00">
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                                        @click="removeCheque(cIdx)" title="ลบแถวนี้">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ฝั่งขวา: แผงสรุปยอดเงินและปุ่มบันทึก (Sticky) --}}
            <div class="col-xl-4">
                <div class="content-card p-4 position-sticky" style="top: 20px;">
                    <h3 class="h6 fw-bold mb-3 border-bottom pb-2">
                        <i class="bi bi-calculator me-1"></i> สรุปการรับชำระเงิน
                    </h3>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">จำนวนบิลที่เลือก</span>
                        <span class="fw-bold" x-text="selectedCount() + ' บิล'"></span>
                    </div>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">ยอดหนี้ที่เลือก (ทั้งหมด)</span>
                        <span class="fw-bold fs-6 text-dark" x-text="'฿' + fmt(totalSelectedDebt())"></span>
                    </div>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">ยอดหนี้ตัดชำระรอบนี้</span>
                        <span class="fw-bold fs-6 text-primary" x-text="'฿' + fmt(totalAllocated())"></span>
                    </div>

                    <div class="d-flex justify-content-between mb-2 text-danger">
                        <span class="fw-semibold">ชำระครั้งถัดไป (คงค้าง)</span>
                        <span class="fw-bold fs-6 text-danger" x-text="'฿' + fmt(nextPaymentRemaining())"></span>
                    </div>

                    <div class="d-flex justify-content-between mb-1 text-danger small" x-show="totalDiscount() > 0">
                        <span>- ส่วนลดเงินสดรวม</span>
                        <span class="fw-bold" x-text="'-฿' + fmt(totalDiscount())"></span>
                    </div>

                    <div class="d-flex justify-content-between mb-2 text-warning-emphasis small" x-show="totalWht() > 0">
                        <span>- ภาษีหัก ณ ที่จ่ายรวม</span>
                        <span class="fw-bold" x-text="'-฿' + fmt(totalWht())"></span>
                    </div>

                    {{-- ช่องเศษขาด/เกิน (±1.00 บาท) --}}
                    <div class="p-2 mb-2 rounded border bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-bold mb-0 text-secondary">
                                <i class="bi bi-plus-slash-minus me-1"></i> เศษขาด / เกิน (±1.00 ฿)
                            </label>
                            <template x-if="canAutoSetRounding()">
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 rounded-pill" style="font-size: 11px;" @click="applyRoundingDiff()">
                                    ปัดเศษพอดี
                                </button>
                            </template>
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white">฿</span>
                            <input type="number" step="0.01" min="-1.00" max="1.00" name="rounding_amount"
                                   class="form-control form-control-sm text-end fw-bold"
                                   :class="{'text-danger': roundingAmount < 0, 'text-success': roundingAmount > 0}"
                                   x-model.number="roundingAmount"
                                   @input="onRoundingChange()"
                                   placeholder="0.00">
                        </div>
                        <div class="form-text" style="font-size: 11px;">
                            <span x-show="roundingAmount < 0" class="text-danger">เศษขาด ฿<span x-text="fmt(Math.abs(roundingAmount))"></span> (Dr เศษสตางค์)</span>
                            <span x-show="roundingAmount > 0" class="text-success">เศษเกิน ฿<span x-text="fmt(roundingAmount)"></span> (Cr เศษสตางค์)</span>
                            <span x-show="roundingAmount === 0" class="text-muted">ลงบัญชีเศษสตางค์ ไม่เกิน ±1.00 บาท</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mb-2 border-top pt-2">
                        <span class="fw-bold text-success">ยอดที่ต้องรับจริงสุทธิ</span>
                        <span class="fw-bold fs-5 text-success" x-text="'฿' + fmt(targetNetToReceiveWithRounding())"></span>
                    </div>

                    <hr class="my-2">

                    <div class="mb-2">
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>- เงินสด</span>
                            <span class="fw-semibold" x-text="'฿' + fmt(cashAmount)"></span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>- โอนเงิน</span>
                            <span class="fw-semibold" x-text="'฿' + fmt(transferAmount)"></span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>- เช็ค</span>
                            <span class="fw-semibold" x-text="'฿' + fmt(cheques.reduce((s, c) => s + (Number(c.amount) || 0), 0))"></span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mb-3 border-top pt-2">
                        <span class="fw-bold">ยอดรับชำระรวมทุกช่องทาง</span>
                        <span class="fw-bold fs-5 text-primary" x-text="'฿' + fmt(totalPaid())"></span>
                    </div>

                    {{-- กล่องแสดงสถานะส่วนต่าง --}}
                    <div class="p-3 rounded-3 mb-3 text-center"
                         :class="{
                             'bg-success bg-opacity-10 text-success border border-success': totalNetToReceive() > 0 && Math.abs(difference()) <= 0.009,
                             'bg-danger bg-opacity-10 text-danger border border-danger': totalNetToReceive() > 0 && Math.abs(difference()) > 0.009,
                             'bg-secondary bg-opacity-10 text-muted border': totalNetToReceive() === 0
                         }">
                        <template x-if="totalNetToReceive() === 0">
                            <div><i class="bi bi-info-circle me-1"></i> กรุณาติ๊กเลือกบิลค้างชำระ</div>
                        </template>
                        <template x-if="totalNetToReceive() > 0 && Math.abs(difference()) <= 0.009">
                            <div class="fw-bold"><i class="bi bi-check-circle-fill me-1"></i> ยอดเงินถูกต้องลงตัว (0.00 บาท)</div>
                        </template>
                        <template x-if="totalNetToReceive() > 0 && difference() < -0.009">
                            <div class="fw-bold">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> ยอดรับชำระ ขาดอีก ฿<span x-text="fmt(Math.abs(difference()))"></span>
                            </div>
                        </template>
                        <template x-if="totalNetToReceive() > 0 && difference() > 0.009">
                            <div class="fw-bold">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> ยอดรับชำระ เกินมา ฿<span x-text="fmt(difference())"></span>
                            </div>
                        </template>
                    </div>

                    {{-- แจ้งเตือนข้อผิดพลาด --}}
                    <div class="text-danger small mb-3" x-show="isPaidExceedingDebt()">
                        <i class="bi bi-x-circle me-1"></i> ยอดที่ลูกค้าจ่ายเกินยอดหนี้ที่เลือก
                    </div>
                    <div class="text-danger small mb-3" x-show="Math.abs(Number(roundingAmount) || 0) > 1.009">
                        <i class="bi bi-x-circle me-1"></i> เศษขาด/เกิน ต้องไม่เกิน ±1.00 บาท
                    </div>
                    <div class="text-danger small mb-3" x-show="hasOverpaidItem()">
                        <i class="bi bi-x-circle me-1"></i> มียอดชำระบางบิลเกินยอดหนี้คงค้าง
                    </div>
                    <div class="text-danger small mb-3" x-show="hasExcessiveDeduction()">
                        <i class="bi bi-x-circle me-1"></i> ยอดส่วนลดหรือหัก ณ ที่จ่าย เกินยอดตัดหนี้
                    </div>
                    <div class="text-danger small mb-3" x-show="hasIncompleteCheque()">
                        <i class="bi bi-x-circle me-1"></i> กรุณากรอกเลขที่เช็คและวันที่ให้ครบ
                    </div>

                    {{-- ปุ่มบันทึก --}}
                    <button type="button" class="btn btn-success btn-lg w-100 fw-bold shadow-sm"
                            :disabled="!canSave()"
                            @click="confirmAndSave()">
                        <i class="bi bi-check2-circle me-1"></i> บันทึกใบเสร็จรับเงิน
                    </button>
                    <div class="form-text text-center small mt-2 text-muted">
                        ลงบัญชี Dr เงินสด/ธนาคาร/ภาษีหัก ณ ที่จ่าย/ส่วนลดจ่าย/เศษสตางค์ และ Cr ลูกหนี้การค้าอัตโนมัติ
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function bplusReceipt() {
    return {
        customerId: '{{ $customer?->id ?? "" }}',
        branchId: '{{ $branches->first()?->id ?? "" }}',
        docDate: '{{ now()->toDateString() }}',
        salesmanId: '',
        onlySelected: false,
        loadingItems: false,

        openItems: [
            @foreach($openItems as $item)
            @php
                $subtotal = $item->document ? (float) $item->document->subtotal_amount : (float) $item->gross_amount;
                $vat = $item->document ? (float) $item->document->vat_amount : (float) $item->vat_amount;
                $total = $item->document ? (float) $item->document->total_amount : (float) $item->net_amount;
            @endphp
            {
                id: {{ $item->id }},
                doc_number: '{{ $item->document?->doc_number ?? "-" }}',
                doc_date: '{{ $item->document?->doc_date?->thaiDate() ?? "-" }}',
                doc_date_raw: '{{ $item->document?->doc_date?->toDateString() ?? "" }}',
                due_date: '{{ $item->due_date ? \Illuminate\Support\Carbon::parse($item->due_date)->thaiDate() : "-" }}',
                balance: {{ (float) $item->balance_amount }},
                amount: {{ (float) $item->balance_amount }},
                discount_amount: 0,
                wht_rate: 0,
                wht_amount: 0,
                subtotal_amount: {{ (float) $subtotal }},
                vat_amount: {{ (float) $vat }},
                total_amount: {{ (float) $total }},
                has_vat: {{ $vat > 0 ? 'true' : 'false' }},
                checked: false,
            },
            @endforeach
        ],

        customerPaidInput: '{{ request("paid_amount", "") }}',
        autoDistributeOnPayChange: true,
        roundingAmount: 0,

        cashAmount: 0,
        transferAmount: 0,
        transferBankAccountId: '',
        transferRef: '',
        transferAt: '',

        cheques: [
            { cheque_bank: '', cheque_no: '', cheque_due_date: '{{ now()->toDateString() }}', amount: 0 }
        ],

        init() {
            @if(request('paid_amount'))
                this.autoDistribute('{{ request("paid_amount") }}');
            @else
                if (this.openItems.length > 0) {
                    this.openItems[0].checked = true;
                }
            @endif
        },

        async onCustomerChange() {
            if (!this.customerId) {
                this.openItems = [];
                return;
            }
            this.loadingItems = true;
            try {
                const res = await fetch(`{{ url('ar-receipts/customers') }}/${this.customerId}/open-items`);
                if (!res.ok) throw new Error('โหลดรายการบิลไม่สำเร็จ');
                const data = await res.json();
                this.openItems = data.map(item => ({
                    id: item.id,
                    doc_number: item.doc_number,
                    doc_date: item.doc_date,
                    doc_date_raw: item.doc_date_raw || item.doc_date || '',
                    due_date: item.due_date,
                    balance: Number(item.balance) || 0,
                    amount: Number(item.balance) || 0,
                    discount_amount: 0,
                    wht_rate: 0,
                    wht_amount: 0,
                    subtotal_amount: Number(item.subtotal_amount) || 0,
                    vat_amount: Number(item.vat_amount) || 0,
                    total_amount: Number(item.total_amount) || 0,
                    has_vat: !!item.has_vat,
                    checked: false,
                }));
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: err.message });
            } finally {
                this.loadingItems = false;
            }
        },

        toggleItem(item) {
            if (item.checked) {
                item.amount = item.balance;
            } else {
                item.amount = 0;
                item.discount_amount = 0;
                item.wht_rate = 0;
                item.wht_amount = 0;
            }
        },

        onAmountChange(item) {
            if (item.wht_rate > 0) {
                this.calcWht(item);
            }
        },

        calcWht(item) {
            const rate = Number(item.wht_rate) || 0;
            if (rate <= 0) {
                item.wht_amount = 0;
                return;
            }
            const amt = Number(item.amount) || 0;
            let base = amt;
            if (item.has_vat && item.total_amount > 0) {
                const preVatRatio = item.subtotal_amount / item.total_amount;
                base = Math.round(amt * preVatRatio * 100) / 100;
            }
            item.wht_amount = Math.round(base * (rate / 100) * 100) / 100;
        },

        itemNet(item) {
            if (!item.checked) return 0;
            const amt = Number(item.amount) || 0;
            const disc = Number(item.discount_amount) || 0;
            const wht = Number(item.wht_amount) || 0;
            return Math.max(0, Math.round((amt - disc - wht) * 100) / 100);
        },

        selectAll(check = true) {
            this.openItems.forEach(item => {
                item.checked = check;
                item.amount = check ? item.balance : 0;
                if (!check) {
                    item.discount_amount = 0;
                    item.wht_rate = 0;
                    item.wht_amount = 0;
                }
            });
        },

        addCheque() {
            this.cheques.push({
                cheque_bank: '',
                cheque_no: '',
                cheque_due_date: '{{ now()->toDateString() }}',
                amount: 0
            });
        },

        removeCheque(idx) {
            if (this.cheques.length > 1) {
                this.cheques.splice(idx, 1);
            } else {
                this.cheques[0].amount = 0;
                this.cheques[0].cheque_no = '';
                this.cheques[0].cheque_bank = '';
            }
        },

        totalSelectedDebt() {
            return Math.round(this.openItems
                .filter(i => i.checked)
                .reduce((s, i) => s + (Number(i.balance) || 0), 0) * 100) / 100;
        },

        nextPaymentRemaining() {
            const debtSatang = Math.round(this.totalSelectedDebt() * 100);
            const allocatedSatang = Math.round(this.totalAllocated() * 100);
            const remSatang = Math.max(0, debtSatang - allocatedSatang);
            return remSatang / 100;
        },

        itemRemaining(item) {
            if (!item.checked) return Number(item.balance) || 0;
            const balSatang = Math.round((Number(item.balance) || 0) * 100);
            const amtSatang = Math.round((Number(item.amount) || 0) * 100);
            return Math.max(0, (balSatang - amtSatang) / 100);
        },

        isPaidExceedingDebt() {
            const debtSatang = Math.round(this.totalSelectedDebt() * 100);
            if (debtSatang <= 0) return false;
            const paidInputSatang = Math.round((Number(this.customerPaidInput) || 0) * 100);
            const totalPaidSatang = Math.round(this.totalPaid() * 100);
            return (paidInputSatang > debtSatang) || (totalPaidSatang > debtSatang);
        },

        targetNetToReceiveWithRounding() {
            const netSatang = Math.round(this.totalNetToReceive() * 100);
            const roundSatang = Math.round((Number(this.roundingAmount) || 0) * 100);
            return Math.max(0, (netSatang + roundSatang) / 100);
        },

        canAutoSetRounding() {
            const net = this.totalNetToReceive();
            if (net <= 0) return false;
            const rawDiff = Math.round((this.totalPaid() - net) * 100) / 100;
            return Math.abs(rawDiff) <= 1.00 && Math.abs(rawDiff - (Number(this.roundingAmount) || 0)) > 0.009;
        },

        applyRoundingDiff() {
            const net = this.totalNetToReceive();
            const rawDiff = Math.round((this.totalPaid() - net) * 100) / 100;
            if (Math.abs(rawDiff) <= 1.00) {
                this.roundingAmount = rawDiff;
            }
        },

        autoDistribute(explicitPaid = null) {
            let paid = explicitPaid !== null ? Number(explicitPaid) : Number(this.customerPaidInput);
            if (isNaN(paid) || paid < 0) paid = 0;

            if (this.openItems.length === 0) return;

            // If none checked, check all
            const hasChecked = this.openItems.some(i => i.checked);
            if (!hasChecked) {
                this.openItems.forEach(i => i.checked = true);
            }

            // Create sorted list of checked items (oldest doc_date_raw first, then doc_number)
            const sortedItems = [...this.openItems.filter(i => i.checked)].sort((a, b) => {
                const dateA = a.doc_date_raw || a.doc_date || '';
                const dateB = b.doc_date_raw || b.doc_date || '';
                if (dateA !== dateB) return dateA.localeCompare(dateB);
                return (a.doc_number || '').localeCompare(b.doc_number || '');
            });

            let remainingSatang = Math.round(paid * 100);

            for (const item of sortedItems) {
                const balanceSatang = Math.round((Number(item.balance) || 0) * 100);
                if (remainingSatang >= balanceSatang) {
                    item.amount = item.balance;
                    remainingSatang -= balanceSatang;
                } else if (remainingSatang > 0) {
                    item.amount = remainingSatang / 100;
                    remainingSatang = 0;
                } else {
                    item.amount = 0;
                }
                if (item.wht_rate > 0) {
                    this.calcWht(item);
                }
            }

            // Reset unchecked items
            this.openItems.filter(i => !i.checked).forEach(i => {
                i.amount = 0;
            });

            // If customerPaidInput is set and totalPaid is 0, auto-fill cash
            if (explicitPaid === null && this.totalPaid() === 0 && paid > 0) {
                this.cashAmount = paid;
            }
        },

        onCustomerPaidInput() {
            const paid = Number(this.customerPaidInput);
            if (!isNaN(paid) && paid >= 0 && this.autoDistributeOnPayChange) {
                this.cashAmount = paid;
                this.transferAmount = 0;
                this.cheques.forEach(c => c.amount = 0);
                this.autoDistribute(paid);
            }
        },

        onPaymentChange() {
            if (!this.autoDistributeOnPayChange) return;
            const total = this.totalPaid();
            if (total > 0) {
                this.customerPaidInput = total;
                this.autoDistribute(total);
            }
        },

        onRoundingChange() {
            // Alpine reactivity updates automatically
        },

        totalAllocated() {
            return this.openItems
                .filter(i => i.checked)
                .reduce((s, i) => s + (Number(i.amount) || 0), 0);
        },

        totalDiscount() {
            return this.openItems
                .filter(i => i.checked)
                .reduce((s, i) => s + (Number(i.discount_amount) || 0), 0);
        },

        totalWht() {
            return this.openItems
                .filter(i => i.checked)
                .reduce((s, i) => s + (Number(i.wht_amount) || 0), 0);
        },

        totalNetToReceive() {
            return Math.max(0, Math.round((this.totalAllocated() - this.totalDiscount() - this.totalWht()) * 100) / 100);
        },

        selectedCount() {
            return this.openItems.filter(i => i.checked).length;
        },

        totalPaid() {
            const cash = Number(this.cashAmount) || 0;
            const transfer = Number(this.transferAmount) || 0;
            const cheques = this.cheques.reduce((s, c) => s + (Number(c.amount) || 0), 0);
            return Math.round((cash + transfer + cheques) * 100) / 100;
        },

        difference() {
            const totalPaidSatang = Math.round(this.totalPaid() * 100);
            const targetSatang = Math.round(this.targetNetToReceiveWithRounding() * 100);
            return (totalPaidSatang - targetSatang) / 100;
        },

        fillRemainingToCash() {
            const rem = Math.max(0, this.targetNetToReceiveWithRounding() - ((Number(this.transferAmount) || 0) + this.cheques.reduce((s, c) => s + (Number(c.amount) || 0), 0)));
            this.cashAmount = Math.round(rem * 100) / 100;
        },

        fillRemainingToTransfer() {
            const rem = Math.max(0, this.targetNetToReceiveWithRounding() - ((Number(this.cashAmount) || 0) + this.cheques.reduce((s, c) => s + (Number(c.amount) || 0), 0)));
            this.transferAmount = Math.round(rem * 100) / 100;
        },

        hasOverpaidItem() {
            return this.openItems.some(i => i.checked && (Number(i.amount) || 0) > i.balance + 0.001);
        },

        hasExcessiveDeduction() {
            return this.openItems.some(i => i.checked && (Number(i.discount_amount) || 0) + (Number(i.wht_amount) || 0) > (Number(i.amount) || 0) + 0.001);
        },

        hasIncompleteCheque() {
            return this.cheques.some(c => (Number(c.amount) || 0) > 0 && (!c.cheque_no || !c.cheque_due_date));
        },

        canSave() {
            const net = this.targetNetToReceiveWithRounding();
            if (net <= 0) return false;
            if (Math.abs(this.difference()) > 0.009) return false;
            if (this.isPaidExceedingDebt()) return false;
            if (Math.abs(Number(this.roundingAmount) || 0) > 1.009) return false;
            if (this.hasOverpaidItem()) return false;
            if (this.hasExcessiveDeduction()) return false;
            if (this.hasIncompleteCheque()) return false;
            if (!this.branchId) return false;
            return true;
        },

        fmt(n) {
            return (Number(n) || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        confirmAndSave() {
            if (!this.canSave()) return;
            let breakdownHtml = `<div class="text-start p-2 bg-light rounded small mt-2">
                <div><b>ยอดตัดหนี้:</b> ฿${this.fmt(this.totalAllocated())}</div>`;
            if (this.totalDiscount() > 0) {
                breakdownHtml += `<div class="text-danger"><b>- ส่วนลดเงินสด:</b> ฿${this.fmt(this.totalDiscount())}</div>`;
            }
            if (this.totalWht() > 0) {
                breakdownHtml += `<div class="text-warning-emphasis"><b>- ภาษีหัก ณ ที่จ่าย:</b> ฿${this.fmt(this.totalWht())}</div>`;
            }
            if (Math.abs(Number(this.roundingAmount) || 0) > 0.001) {
                const isShort = Number(this.roundingAmount) < 0;
                breakdownHtml += `<div class="${isShort ? 'text-danger' : 'text-success'}"><b>${isShort ? '- เศษขาด (ลดหย่อน):' : '+ เศษเกิน:'}</b> ฿${this.fmt(Math.abs(this.roundingAmount))}</div>`;
            }
            breakdownHtml += `<div class="text-success border-top pt-1 mt-1"><b>ยอดรับจริง:</b> ฿${this.fmt(this.targetNetToReceiveWithRounding())}</div></div>`;

            Swal.fire({
                title: 'ยืนยันการบันทึกใบเสร็จรับเงิน',
                html: `จำนวนบิลที่ตัดชำระ: <b>${this.selectedCount()}</b> ใบ<br>${breakdownHtml}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'บันทึกใบเสร็จ',
                cancelButtonText: 'ยกเลิก',
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('arReceiptForm').submit();
                }
            });
        }
    };
}
</script>
@endsection
