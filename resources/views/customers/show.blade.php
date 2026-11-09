@extends('layout')

@section('title', "{$customer->code} - ลูกค้า - MPM Food Group")
@section('page-title', 'รายละเอียดลูกค้า')
@section('page-subtitle', $customer->code)

@section('content')
    <div x-data="customerShow()" x-cloak>
        <a href="{{ route('customers.index') }}" class="text-decoration-none small d-inline-block mb-3">
            <i class="bi bi-arrow-left me-1"></i> กลับไปรายการลูกค้า
        </a>

        <div class="content-card p-4 mb-4">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">{{ $customer->code }} - {{ $customer->name_th }}</h2>
                    <div class="text-muted small">
                        สาขา: {{ $customer->branch?->name_th ?? '-' }} &middot;
                        วงเงินเครดิต: {{ number_format($customer->credit_limit, 2) }} บาท &middot;
                        ผู้ดูแล: {{ $customer->salesUser?->name ?? 'ยังไม่ระบุ' }} &middot;
                        สาย: {{ $customer->salesArea?->code ?? 'ยังไม่ระบุ' }}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge {{ $customer->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $customer->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                    </span>
                    @if($outstandingBalance > 0)
                    <button type="button" class="btn btn-success" @click="paymentOpen = true">
                        <i class="bi bi-cash-coin me-1"></i> รับชำระหนี้
                    </button>
                    @endif
                    <button type="button" class="btn btn-light border" @click="editOpen = true">
                        <i class="bi bi-pencil me-1"></i> แก้ไข
                    </button>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">ยอดค้างชำระ (AR)</div>
                        <div class="fs-4 fw-bold {{ $outstandingBalance > 0 ? 'text-danger' : '' }}">{{ number_format($outstandingBalance, 2) }}</div>
                    </div>
                </div>
            </div>

            @if($customer->credit_limit_requested_by)
            <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-3 mt-3 mb-0">
                <div>
                    <i class="bi bi-hourglass-split me-1"></i>
                    <strong>คำขอเปลี่ยนวงเงินเครดิตรออนุมัติ:</strong>
                    {{ number_format($customer->credit_limit, 2) }} &rarr;
                    <span class="fw-bold">{{ number_format($customer->pending_credit_limit, 2) }} บาท</span>
                    <span class="text-muted small ms-2">ขอโดย {{ $customer->creditLimitRequester?->name ?? '-' }} · {{ $customer->credit_limit_requested_at?->thaiDate(true) }}</span>
                </div>
                @if(auth()->user()->hasPermission('finance.credit.approve') && $customer->credit_limit_requested_by !== auth()->id())
                <div class="d-flex gap-2 align-items-center">
                    <form method="post" action="{{ route('customers.credit-limit.approve', $customer) }}">
                        @csrf
                        <button class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1"></i>อนุมัติวงเงิน</button>
                    </form>
                    <form method="post" action="{{ route('customers.credit-limit.reject', $customer) }}" class="d-flex gap-1">
                        @csrf
                        <input name="reason" required class="form-control form-control-sm" placeholder="เหตุผลไม่อนุมัติ" style="max-width:200px">
                        <button class="btn btn-outline-danger btn-sm">ปฏิเสธ</button>
                    </form>
                </div>
                @elseif($customer->credit_limit_requested_by === auth()->id())
                <span class="badge text-bg-light border">รอผู้มีสิทธิ์คนอื่นอนุมัติ</span>
                @endif
            </div>
            @endif
        </div>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
            <div class="doc-tabs">
                <button type="button" class="doc-tab" :class="activeTab === 'overview' && 'active'" @click="activeTab = 'overview'">
                    <i class="bi bi-person-lines-fill"></i> ภาพรวม & บัญชีลูกหนี้
                </button>
                <button type="button" class="doc-tab" :class="activeTab === 'terms' && 'active'" @click="activeTab = 'terms'">
                    <i class="bi bi-file-earmark-text"></i> ข้อตกลง (Terms)
                    <span class="badge rounded-pill bg-primary ms-1">{{ $customer->terms->count() }}</span>
                </button>
            </div>
            <div x-show="activeTab === 'terms'" x-cloak>
                <button type="button" class="btn btn-primary" @click="openAddTermModal()">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มข้อตกลง
                </button>
            </div>
        </div>

        <div x-show="activeTab === 'overview'">
        <div class="customer-360-activity-grid mb-4">
            <section class="content-card p-4">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold" style="letter-spacing:.04em">Customer 360</div>
                        <h3 class="h5 fw-bold mb-1">ประวัติการติดต่อลูกค้า</h3>
                        <p class="text-muted small mb-0">ติดตามงานขายและกิจกรรมล่าสุดของลูกค้ารายนี้</p>
                    </div>
                    <span class="customer-360-count">{{ $customer->crmActivities->count() }} รายการ</span>
                </div>
                <div class="customer-activity-timeline">
                    @forelse($customer->crmActivities as $activity)
                        @php
                            $activityIcons = ['call' => 'telephone', 'visit' => 'person-walking', 'line' => 'chat-dots', 'email' => 'envelope', 'note' => 'sticky-note'];
                        @endphp
                        <div class="customer-activity-item {{ $activity->status === 'completed' ? 'is-completed' : '' }}">
                            <span class="customer-activity-icon"><i class="bi bi-{{ $activityIcons[$activity->activity_type] ?? 'check2' }}"></i></span>
                            <div class="customer-activity-body">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <strong>{{ $activity->subject }}</strong>
                                    @if($activity->status === 'pending')
                                        <form method="post" action="{{ route('crm.activities.complete', $activity) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-sm btn-light border" title="ปิดงาน"><i class="bi bi-check2"></i></button>
                                        </form>
                                    @else
                                        <span class="customer-activity-done"><i class="bi bi-check2-circle me-1"></i>เสร็จแล้ว</span>
                                    @endif
                                </div>
                                <div class="small text-muted mt-1">{{ $activityTypes[$activity->activity_type] ?? $activity->activity_type }} · {{ $activity->assignedTo?->name ?? '-' }}</div>
                                @if($activity->note)<div class="small mt-2">{{ $activity->note }}</div>@endif
                                <div class="small text-muted mt-2"><i class="bi bi-calendar3 me-1"></i>{{ $activity->due_at?->thaiDate(true) ?? 'ไม่กำหนดวัน' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="customer-activity-empty"><i class="bi bi-chat-square-text"></i><span>ยังไม่มีประวัติการติดต่อลูกค้า</span></div>
                    @endforelse
                </div>
            </section>

            <section class="content-card p-4">
                <div class="text-uppercase small text-muted fw-semibold" style="letter-spacing:.04em">ติดตามต่อ</div>
                <h3 class="h5 fw-bold mb-1">เพิ่มงานติดตาม</h3>
                <p class="text-muted small mb-3">สร้างงานถัดไปให้ลูกค้ารายนี้โดยตรง</p>
                <form method="post" action="{{ route('crm.activities.store') }}" class="customer-activity-form">
                    @csrf
                    <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                    <label>หัวข้องาน<input name="subject" required maxlength="200" placeholder="เช่น โทรติดตามใบเสนอราคา"></label>
                    <div class="row g-2">
                        <div class="col-sm-6"><label>ประเภท<select name="activity_type" required>@foreach($activityTypes as $type => $label)<option value="{{ $type }}">{{ $label }}</option>@endforeach</select></label></div>
                        <div class="col-sm-6"><label>กำหนดติดตาม<input type="datetime-local" name="due_at"></label></div>
                    </div>
                    @if(auth()->user()->hasPermission('sales.assign'))
                        <label>ผู้รับผิดชอบ<select name="assigned_to"><option value="">ฉัน</option>@foreach($activityUsers as $activityUser)<option value="{{ $activityUser->id }}">{{ $activityUser->name }}</option>@endforeach</select></label>
                    @endif
                    <label>รายละเอียด<textarea name="note" rows="3" maxlength="2000" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea></label>
                    <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>เพิ่มงานติดตาม</button>
                </form>
            </section>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="content-card p-4 mb-4">
                    <h3 class="h6 fw-bold mb-3">รายการลูกหนี้ล่าสุด</h3>
                    <div class="table-responsive">
                        <table class="table align-middle table-sm">
                            <thead>
                                <tr><th>เอกสาร</th><th>วันที่</th><th class="text-end">ยอดรวม</th><th class="text-end">ค้างชำระ</th><th>สถานะ</th></tr>
                            </thead>
                            <tbody>
                                @forelse($customer->openItems as $item)
                                <tr>
                                    <td>{{ $item->document->doc_number }}</td>
                                    <td>{{ $item->document->doc_date->thaiDate() }}</td>
                                    <td class="text-end">{{ number_format($item->net_amount, 2) }}</td>
                                    <td class="text-end">{{ number_format($item->balance_amount, 2) }}</td>
                                    <td><span class="badge text-bg-light border">{{ \App\Support\DocumentStatus::label($item->status) }}</span></td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">ยังไม่มีรายการ</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="content-card p-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold mb-0">ที่อยู่</h3>
                        <button type="button" class="btn btn-sm btn-light border" @click="addressOpen = true"><i class="bi bi-plus-lg me-1"></i> เพิ่ม</button>
                    </div>
                    @forelse($customer->addresses as $address)
                    <div class="border rounded-3 p-2 mb-2 small">
                        {{ $address->address_line }}
                        @if($address->is_default)<span class="badge text-bg-info ms-1">หลัก</span>@endif
                    </div>
                    @empty
                    <p class="text-muted small mb-0">ยังไม่มีที่อยู่</p>
                    @endforelse
                </div>

                <div class="content-card p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold mb-0">ผู้ติดต่อ</h3>
                        <button type="button" class="btn btn-sm btn-light border" @click="contactOpen = true"><i class="bi bi-plus-lg me-1"></i> เพิ่ม</button>
                    </div>
                    @forelse($customer->contacts as $contact)
                    <div class="border rounded-3 p-2 mb-2 small">
                        <div class="fw-semibold">{{ $contact->name ?? '-' }}</div>
                        <div class="text-muted">{{ $contact->phone }} {{ $contact->email }}</div>
                    </div>
                    @empty
                    <p class="text-muted small mb-0">ยังไม่มีผู้ติดต่อ</p>
                    @endforelse
                </div>
            </div>
        </div>
        </div>

        {{-- ข้อตกลง (Terms) Tab --}}
        <div x-show="activeTab === 'terms'" x-cloak>
            <div class="content-card p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
                    <div>
                        <h3 class="h5 fw-bold mb-1"><i class="bi bi-file-earmark-ruled me-2 text-primary"></i>ข้อตกลงทางการค้า (Customer Terms)</h3>
                        <p class="text-muted small mb-0">กำหนดเงื่อนไขการค้า ตารางราคา เครดิตเทอม และส่วนลดตามรูปแบบ BPlus (ชื่อขึ้นต้นด้วย <code>N-</code> หมายถึงไม่มีภาษีมูลค่าเพิ่ม No VAT)</p>
                    </div>
                    <button type="button" class="btn btn-primary" @click="openAddTermModal()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มข้อตกลง
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 100px;">สถานะ</th>
                                <th>ชื่อข้อตกลง</th>
                                <th>ตารางราคา</th>
                                <th class="text-end">เครดิต (วัน)</th>
                                <th class="text-end">ส่วนลดการค้า (%)</th>
                                <th class="text-end">ส่วนลดเงินสด (%)</th>
                                <th>ระยะเวลาที่มีผล</th>
                                <th>หมายเหตุ</th>
                                <th class="text-end" style="width: 120px;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($customer->terms as $term)
                                <tr>
                                    <td>
                                        @if($term->is_default)
                                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>ค่าเริ่มต้น</span>
                                        @else
                                            <span class="badge text-bg-light border text-muted">ทั่วไป</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark">{{ $term->name }}</div>
                                        @if($term->isNoVat())
                                            <span class="badge bg-secondary-subtle text-secondary border mt-1"><i class="bi bi-shield-x me-1"></i>No VAT (N-)</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($term->priceTable)
                                            <span class="fw-semibold text-primary"><i class="bi bi-tag me-1"></i>{{ $term->priceTable->name }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">
                                        {{ number_format($term->credit_days) }} วัน
                                    </td>
                                    <td class="text-end fw-semibold {{ $term->trade_discount_percent > 0 ? 'text-danger' : 'text-muted' }}">
                                        {{ number_format($term->trade_discount_percent, 2) }}%
                                    </td>
                                    <td class="text-end fw-semibold {{ $term->cash_discount_percent > 0 ? 'text-success' : 'text-muted' }}">
                                        {{ number_format($term->cash_discount_percent, 2) }}%
                                    </td>
                                    <td class="small">
                                        @if($term->start_date || $term->end_date)
                                            <div>{{ $term->start_date?->thaiDate() ?? 'ไม่ระบุ' }} &rarr; {{ $term->end_date?->thaiDate() ?? 'ไม่มีกำหนด' }}</div>
                                            @if($term->isActiveOn())
                                                <span class="badge bg-success-subtle text-success border">มีผลใช้งาน</span>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning border">อยู่นอกช่วงเวลา</span>
                                            @endif
                                        @else
                                            <span class="text-muted">ใช้ได้ตลอด</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted" style="max-width: 200px;">
                                        {{ $term->notes ?? '—' }}
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-light border" title="แก้ไข"
                                                    @click="openEditTermModal(@js($term))">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="post" action="{{ route('customers.terms.destroy', [$customer, $term]) }}"
                                                  data-confirm="ยืนยันลบข้อตกลง {{ $term->name }} หรือไม่?">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="bi bi-file-earmark-plus fs-3 d-block mb-2 text-secondary"></i>
                                        ยังไม่มีข้อตกลงสำหรับลูกค้ารายนี้ คลิก <strong>"เพิ่มข้อตกลง"</strong> เพื่อเริ่มสร้าง
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Edit modal --}}
        <div class="booking-modal-backdrop" x-show="editOpen" x-transition.opacity @keydown.escape.window="editOpen = false">
            <div class="booking-modal" style="width: min(560px, 100%);" @click.outside="editOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">แก้ไขลูกค้า</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="editOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.update', $customer) }}">
                    @csrf @method('PUT')
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสลูกค้า</label>
                                <input placeholder="เว้นว่างได้ ระบบรันให้" type="text" name="code" value="{{ $customer->code }}" required class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected($branch->id === $customer->branch_id)>{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (ไทย)</label>
                                <input type="text" name="name_th" value="{{ $customer->name_th }}" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" value="{{ $customer->name_en }}" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">วงเงินเครดิต</label>
                                <input type="number" step="0.01" min="0" name="credit_limit" value="{{ $customer->credit_limit }}" class="form-control">
                            </div>
                            @if(auth()->user()->hasPermission('sales.assign'))
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">ผู้ดูแลลูกค้า</label>
                                    <select name="sales_user_id" x-model="salesUserId" @change="syncAreaFromUser()" class="form-select">
                                        <option value="">-- ยังไม่ระบุ --</option>
                                        @foreach($salesUsers as $salesUser)
                                            <option value="{{ $salesUser->id }}">{{ $salesUser->username }} - {{ $salesUser->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">สายการขาย / สายส่ง</label>
                                    <select name="sales_area_id" x-model="salesAreaId" class="form-select">
                                        <option value="">-- ยังไม่ระบุ --</option>
                                        @foreach($salesAreas as $area)
                                            <option value="{{ $area->id }}">{{ $area->code }} - {{ $area->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <div class="col-12">
                                    <div class="alert alert-light border py-2 mb-0 small">
                                        ผู้ดูแล: <strong>{{ $customer->salesUser?->name ?? 'ยังไม่ระบุ' }}</strong>
                                        &middot; สาย: <strong>{{ $customer->salesArea?->code ?? 'ยังไม่ระบุ' }}</strong>
                                        (หัวหน้าฝ่ายขายเป็นผู้โอนความรับผิดชอบ)
                                    </div>
                                </div>
                            @endif
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" @checked($customer->is_active) class="form-check-input" id="editCustomerActive">
                                    <label class="form-check-label" for="editCustomerActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="editOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Address modal --}}
        <div class="booking-modal-backdrop" x-show="addressOpen" x-transition.opacity @keydown.escape.window="addressOpen = false">
            <div class="booking-modal" style="width: min(480px, 100%);" @click.outside="addressOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">เพิ่มที่อยู่</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="addressOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.addresses.store', $customer) }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <label class="form-label text-muted small">ที่อยู่</label>
                        <textarea name="address_line" required class="form-control" rows="3"></textarea>
                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_default" value="1" class="form-check-input" id="addrDefault">
                            <label class="form-check-label" for="addrDefault">ตั้งเป็นที่อยู่หลัก</label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="addressOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Contact modal --}}
        <div class="booking-modal-backdrop" x-show="contactOpen" x-transition.opacity @keydown.escape.window="contactOpen = false">
            <div class="booking-modal" style="width: min(480px, 100%);" @click.outside="contactOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">เพิ่มผู้ติดต่อ</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="contactOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.contacts.store', $customer) }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อ</label>
                                <input type="text" name="name" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">เบอร์โทร</label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">อีเมล</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="contactOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Payment modal --}}
        <div class="booking-modal-backdrop" x-show="paymentOpen" x-transition.opacity @keydown.escape.window="paymentOpen = false">
            <div class="booking-modal" style="width: min(640px, 100%);" @click.outside="paymentOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">รับชำระหนี้ - {{ $customer->name_th }}</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="paymentOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.payments.store', $customer) }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" required class="form-select">
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected($branch->id === $customer->branch_id)>{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6" x-data="{ m: 'cash' }">
                                <label class="form-label text-muted small">วิธีชำระ</label>
                                <select name="method" x-model="m" required class="form-select">
                                    <option value="cash">เงินสด</option>
                                    <option value="transfer">โอนเงิน</option>
                                    <option value="cheque">เช็ค</option>
                                </select>
                                @include('payments._bank-account-select', ['model' => 'm'])
                            </div>
                        </div>
                        <label class="form-label text-muted small">เลือกรายการที่จะตัดชำระ</label>
                        <div class="table-responsive">
                            <table class="table align-middle table-sm">
                                <thead>
                                    <tr><th>เอกสาร</th><th class="text-end">ค้างชำระ</th><th style="width:160px">ยอดชำระ</th></tr>
                                </thead>
                                <tbody>
                                    @forelse($customer->openItems->whereIn('status', ['open', 'partial']) as $item)
                                    <tr>
                                        <td>
                                            {{ $item->document->doc_number }}
                                            <input type="hidden" name="open_item_id[]" value="{{ $item->id }}">
                                        </td>
                                        <td class="text-end">{{ number_format($item->balance_amount, 2) }}</td>
                                        <td>
                                            <input type="number" step="0.01" min="0" max="{{ $item->balance_amount }}"
                                                   name="amount[]" value="0" class="form-control form-control-sm text-end">
                                        </td>
                                    </tr>
                                    @empty
                                    <tr><td colspan="3" class="text-center text-muted py-3">ไม่มีรายการค้างชำระ</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="paymentOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-success px-4">บันทึกรับชำระ</button>
                    </div>
                </form>
            </div>
        </div>
        {{-- Term modal (Add / Edit) --}}
        <div class="booking-modal-backdrop" x-show="termModalOpen" x-transition.opacity @keydown.escape.window="termModalOpen = false">
            <div class="booking-modal" style="width: min(640px, 100%);" @click.outside="termModalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0" x-text="termForm.id ? 'แก้ไขข้อตกลงลูกค้า' : 'เพิ่มข้อตกลงลูกค้า'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="termModalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="termForm.action">
                    @csrf
                    <template x-if="termForm.id">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted small fw-semibold">ชื่อข้อตกลง <span class="text-danger">*</span></label>
                                <input type="text" name="name" x-model="termForm.name" required class="form-control" placeholder="เช่น เงินสด, N-เงินสด (N- = no VAT), เครดิต 30 วัน">
                                <div class="form-text small text-muted">คำแนะนำ BPlus: หากขึ้นต้นด้วย <code>N-</code> หมายถึงไม่มีภาษีมูลค่าเพิ่ม (No VAT)</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">ตารางราคา</label>
                                <select name="price_table_id" x-model="termForm.price_table_id" class="form-select">
                                    <option value="">-- ราคามาตรฐานระบบ --</option>
                                    @foreach($priceTables as $pt)
                                        <option value="{{ $pt->id }}">{{ $pt->code }} - {{ $pt->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">จำนวนวันเครดิต (วัน)</label>
                                <input type="number" name="credit_days" x-model.number="termForm.credit_days" min="0" max="9999" class="form-control text-end" placeholder="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">ส่วนลดการค้า (%)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100" name="trade_discount_percent" x-model="termForm.trade_discount_percent" class="form-control text-end" placeholder="0.00">
                                    <span class="input-group-text bg-light">%</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">ส่วนลดเงินสด (%)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100" name="cash_discount_percent" x-model="termForm.cash_discount_percent" class="form-control text-end" placeholder="0.00">
                                    <span class="input-group-text bg-light">%</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">วันเริ่มต้น</label>
                                <input type="date" name="start_date" x-model="termForm.start_date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small fw-semibold">วันสิ้นสุด</label>
                                <input type="date" name="end_date" x-model="termForm.end_date" class="form-control">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_default" value="1" id="custTermDefault" :checked="termForm.is_default" x-model="termForm.is_default">
                                    <label class="form-check-label fw-semibold" for="custTermDefault">ตั้งเป็นข้อตกลงเริ่มต้น (Default Term)</label>
                                </div>
                                <div class="form-text small text-muted">ระบบอนุญาตให้มีข้อตกลงเริ่มต้นได้เพียง 1 รายการต่อลูกค้า 1 ราย</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small fw-semibold">หมายเหตุ</label>
                                <textarea name="notes" x-model="termForm.notes" rows="2" class="form-control" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="termModalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4" x-text="termForm.id ? 'บันทึกการแก้ไข' : 'บันทึกข้อตกลง'"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(15, 23, 42, .42);
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .booking-modal { background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15, 23, 42, .24); max-height: calc(100vh - 48px); overflow: auto; }
    .customer-360-activity-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:16px}.customer-360-count{padding:5px 9px;border-radius:999px;background:var(--erp-primary-soft);color:var(--erp-primary-dark);font-size:11px;font-weight:700;white-space:nowrap}.customer-activity-timeline{display:grid;gap:0}.customer-activity-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--erp-border-soft,var(--erp-border))}.customer-activity-item:last-child{border-bottom:0;padding-bottom:0}.customer-activity-item:first-child{padding-top:3px}.customer-activity-icon{display:grid;place-items:center;flex:0 0 32px;width:32px;height:32px;border-radius:9px;background:var(--erp-primary-soft);color:var(--erp-primary);font-size:14px}.customer-activity-body{min-width:0;flex:1}.customer-activity-body strong{font-size:13px;color:var(--erp-text)}.customer-activity-item.is-completed .customer-activity-body strong{color:var(--erp-muted);text-decoration:line-through}.customer-activity-done{font-size:11px;color:#168058;white-space:nowrap}.customer-activity-empty{display:flex;align-items:center;justify-content:center;gap:9px;min-height:130px;color:var(--erp-muted);font-size:12px}.customer-activity-empty i{font-size:18px}.customer-activity-form{display:grid;gap:10px}.customer-activity-form label{display:grid;gap:4px;color:var(--erp-muted);font-size:11px;font-weight:600}.customer-activity-form input,.customer-activity-form select,.customer-activity-form textarea{width:100%;border:1px solid var(--erp-border);border-radius:5px;padding:8px 10px;background:var(--erp-surface);color:var(--erp-text);font:inherit;font-size:12px}.customer-activity-form button{justify-self:start}
    @media(max-width:991.98px){.customer-360-activity-grid{grid-template-columns:1fr}}
</style>
@endpush

@push('scripts')
<script>
    function customerShow() {
        return {
            activeTab: @js(request()->query('tab', 'overview')),
            editOpen: false,
            addressOpen: false,
            contactOpen: false,
            paymentOpen: false,
            termModalOpen: false,
            termForm: {
                id: null,
                action: '{{ route('customers.terms.store', $customer) }}',
                name: '',
                price_table_id: '',
                credit_days: 0,
                trade_discount_percent: 0,
                cash_discount_percent: 0,
                start_date: '',
                end_date: '',
                is_default: false,
                notes: '',
            },
            openAddTermModal() {
                this.termForm = {
                    id: null,
                    action: '{{ route('customers.terms.store', $customer) }}',
                    name: '',
                    price_table_id: '',
                    credit_days: 0,
                    trade_discount_percent: 0,
                    cash_discount_percent: 0,
                    start_date: '',
                    end_date: '',
                    is_default: @js($customer->terms->isEmpty()),
                    notes: '',
                };
                this.termModalOpen = true;
            },
            openEditTermModal(term) {
                this.termForm = {
                    id: term.id,
                    action: '{{ url('customers/' . $customer->id . '/terms') }}/' + term.id,
                    name: term.name || '',
                    price_table_id: term.price_table_id ? String(term.price_table_id) : '',
                    credit_days: term.credit_days ?? 0,
                    trade_discount_percent: term.trade_discount_percent ?? 0,
                    cash_discount_percent: term.cash_discount_percent ?? 0,
                    start_date: term.start_date ? term.start_date.substring(0, 10) : '',
                    end_date: term.end_date ? term.end_date.substring(0, 10) : '',
                    is_default: Boolean(term.is_default),
                    notes: term.notes || '',
                };
                this.termModalOpen = true;
            },
            salesUserId: @js((string) ($customer->sales_user_id ?? '')),
            salesAreaId: @js((string) ($customer->sales_area_id ?? '')),
            users: @js($salesUsers->map(fn ($user) => [
                'id' => (string) $user->id,
                'sales_area_id' => $user->sales_area_id ? (string) $user->sales_area_id : '',
            ])->values()),
            syncAreaFromUser() {
                const selected = this.users.find(user => user.id === String(this.salesUserId));
                if (selected?.sales_area_id) this.salesAreaId = selected.sales_area_id;
            },
        };
    }
</script>
@endpush
