@extends('layout')

@section('title', "{$supplier->code} - ซัพพลายเออร์ - POPSTAR ERP")
@section('page-title', 'รายละเอียดซัพพลายเออร์')
@section('page-subtitle', $supplier->code)

@section('content')
    <div x-data="supplierShow()" x-cloak>
        <a href="{{ route('suppliers.index') }}" class="text-decoration-none small d-inline-block mb-3">
            <i class="bi bi-arrow-left me-1"></i> กลับไปรายการซัพพลายเออร์
        </a>

        <div class="content-card p-4 mb-4">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">{{ $supplier->code }} - {{ $supplier->name_th }}</h2>
                    <div class="text-muted small">{{ $supplier->name_en }}</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge {{ $supplier->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                        {{ $supplier->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                    </span>
                    @if($currentBalance > 0)
                    <button type="button" class="btn btn-success" @click="paymentOpen = true">
                        <i class="bi bi-cash-coin me-1"></i> จ่ายชำระหนี้
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
                        <div class="text-muted small">ยอดหนี้สะสม (AP)</div>
                        <div class="fs-4 fw-bold {{ $currentBalance > 0 ? 'text-danger' : '' }}">{{ number_format($currentBalance, 2) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="content-card p-4 mb-4">
                    <h3 class="h6 fw-bold mb-3">รายการเจ้าหนี้ล่าสุด</h3>
                    <div class="table-responsive">
                        <table class="table align-middle table-sm">
                            <thead>
                                <tr><th>เอกสาร</th><th>วันที่</th><th class="text-end">ยอด</th><th class="text-end">ยอดสะสม</th></tr>
                            </thead>
                            <tbody>
                                @forelse($supplier->ledgerEntries as $entry)
                                <tr>
                                    <td>{{ $entry->document->doc_number ?? '-' }}</td>
                                    <td>{{ $entry->entry_date->thaiDate() }}</td>
                                    <td class="text-end">{{ number_format($entry->amount, 2) }}</td>
                                    <td class="text-end">{{ number_format($entry->balance_after, 2) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มีรายการ</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="content-card p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold mb-0">ที่อยู่</h3>
                        <button type="button" class="btn btn-sm btn-light border" @click="addressOpen = true"><i class="bi bi-plus-lg me-1"></i> เพิ่ม</button>
                    </div>
                    @forelse($supplier->addresses as $address)
                    <div class="border rounded-3 p-2 mb-2 small">
                        {{ $address->address_line }}
                        @if($address->is_default)<span class="badge text-bg-info ms-1">หลัก</span>@endif
                    </div>
                    @empty
                    <p class="text-muted small mb-0">ยังไม่มีที่อยู่</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Edit modal --}}
        <div class="booking-modal-backdrop" x-show="editOpen" x-transition.opacity @keydown.escape.window="editOpen = false">
            <div class="booking-modal" style="width: min(560px, 100%);" @click.outside="editOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">แก้ไขซัพพลายเออร์</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="editOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('suppliers.update', $supplier) }}">
                    @csrf @method('PUT')
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสซัพพลายเออร์</label>
                                <input type="text" name="code" value="{{ $supplier->code }}" required class="form-control">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" @checked($supplier->is_active) class="form-check-input" id="editSupplierActive">
                                    <label class="form-check-label" for="editSupplierActive">ใช้งาน</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อซัพพลายเออร์ (ไทย)</label>
                                <input type="text" name="name_th" value="{{ $supplier->name_th }}" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อซัพพลายเออร์ (อังกฤษ)</label>
                                <input type="text" name="name_en" value="{{ $supplier->name_en }}" class="form-control">
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
                <form method="post" action="{{ route('suppliers.addresses.store', $supplier) }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <label class="form-label text-muted small">ที่อยู่</label>
                        <textarea name="address_line" required class="form-control" rows="3"></textarea>
                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_default" value="1" class="form-check-input" id="supAddrDefault">
                            <label class="form-check-label" for="supAddrDefault">ตั้งเป็นที่อยู่หลัก</label>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="addressOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Payment modal --}}
        <div class="booking-modal-backdrop" x-show="paymentOpen" x-transition.opacity @keydown.escape.window="paymentOpen = false">
            <div class="booking-modal" style="width: min(480px, 100%);" @click.outside="paymentOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">จ่ายชำระหนี้ - {{ $supplier->name_th }}</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="paymentOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('suppliers.payments.store', $supplier) }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="mb-3">
                            <label class="form-label text-muted small">สาขา</label>
                            <select name="branch_id" required class="form-select">
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3" x-data="{ m: 'cash' }">
                            <label class="form-label text-muted small">วิธีชำระ</label>
                            <select name="method" x-model="m" required class="form-select">
                                <option value="cash">เงินสด</option>
                                <option value="transfer">โอนเงิน</option>
                                <option value="cheque">เช็ค</option>
                            </select>
                            <template x-if="m === 'cheque'">
                                <div class="row g-2 mt-1">
                                    <div class="col-4"><label class="form-label text-muted small">เลขที่เช็ค</label><input name="cheque_no" required class="form-control"></div>
                                    <div class="col-4"><label class="form-label text-muted small">วันที่บนเช็ค</label><input type="date" name="cheque_due_date" required class="form-control" value="{{ now()->toDateString() }}"></div>
                                    <div class="col-4"><label class="form-label text-muted small">ธนาคาร</label><input name="cheque_bank" class="form-control"></div>
                                    <div class="col-12 text-muted small"><i class="bi bi-info-circle me-1"></i>เช็คจะเข้าทะเบียนเช็คจ่ายอัตโนมัติ</div>
                                </div>
                            </template>
                        </div>
                        <div>
                            <label class="form-label text-muted small">ยอดชำระ (ค้างชำระ {{ number_format($currentBalance, 2) }})</label>
                            <input type="number" step="0.01" min="0.01" max="{{ $currentBalance }}"
                                   name="amount" required class="form-control" value="{{ $currentBalance }}">
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="paymentOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-success px-4">บันทึกจ่ายชำระ</button>
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
    function supplierShow() {
        return { editOpen: false, addressOpen: false, paymentOpen: false };
    }
</script>
@endpush
