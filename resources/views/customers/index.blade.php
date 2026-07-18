@extends('layout')

@section('title', 'ลูกค้า - POPSTAR ERP')
@section('page-title', 'ลูกค้า')
@section('page-subtitle', 'ทะเบียนลูกค้าและยอดค้างชำระ (AR)')

@section('content')
    <div x-data="customerPage()" x-cloak>
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
            <form method="get" class="d-flex gap-2" style="max-width: 420px;">
                <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="ค้นหารหัส/ชื่อลูกค้า">
                <button class="btn btn-light border"><i class="bi bi-search"></i></button>
            </form>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
                <i class="bi bi-plus-lg me-1"></i> เพิ่มลูกค้า
            </button>
        </div>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อลูกค้า</th><th>สาขา</th>
                            <th class="text-end">วงเงินเครดิต</th><th class="text-end">ค้างชำระ</th><th>สถานะ</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            <tr>
                                <td class="fw-semibold">{{ $customer->code }}</td>
                                <td>{{ $customer->name_th }}</td>
                                <td>{{ $customer->branch?->name_th ?? '-' }}</td>
                                <td class="text-end">{{ number_format($customer->credit_limit, 2) }}</td>
                                <td class="text-end {{ ($customer->outstanding_balance ?? 0) > 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ number_format($customer->outstanding_balance ?? 0, 2) }}
                                </td>
                                <td>
                                    <span class="badge {{ $customer->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $customer->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-5 text-center text-muted">ไม่พบลูกค้า</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $customers->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width: min(560px, 100%);" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">เพิ่มลูกค้าใหม่</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.store') }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสลูกค้า</label>
                                <input type="text" name="code" required class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (ไทย)</label>
                                <input type="text" name="name_th" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" class="form-control">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label text-muted small">เลขประจำตัวผู้เสียภาษี (ใช้ในรายงานภาษีขาย)</label>
                                <input type="text" name="tax_id" maxlength="20" class="form-control" placeholder="13 หลัก">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">สาขาที่</label>
                                <input type="text" name="tax_branch" maxlength="10" class="form-control" placeholder="00000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">วงเงินเครดิต</label>
                                <input type="number" step="0.01" min="0" name="credit_limit" value="0" class="form-control">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="newCustomerActive">
                                    <label class="form-check-label" for="newCustomerActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึก</button>
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
    function customerPage() {
        return { modalOpen: false };
    }
</script>
@endpush
