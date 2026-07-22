@extends('layout')

@section('title', "{$customer->code} - ลูกค้า - POPSTAR ERP")
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
                        วงเงินเครดิต: {{ number_format($customer->credit_limit, 2) }} บาท
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
                                    <td><span class="badge text-bg-light border">{{ $item->status }}</span></td>
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
                                <input type="text" name="code" value="{{ $customer->code }}" required class="form-control">
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
                            <div class="col-md-6">
                                <label class="form-label text-muted small">วิธีชำระ</label>
                                <select name="method" required class="form-select">
                                    <option value="cash">เงินสด</option>
                                    <option value="transfer">โอนเงิน</option>
                                    <option value="cheque">เช็ค</option>
                                </select>
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
</style>
@endpush

@push('scripts')
<script>
    function customerShow() {
        return { editOpen: false, addressOpen: false, contactOpen: false, paymentOpen: false };
    }
</script>
@endpush
