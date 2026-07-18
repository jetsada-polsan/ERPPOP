@extends('layout')

@section('title', 'ซัพพลายเออร์ - POPSTAR ERP')
@section('page-title', 'ซัพพลายเออร์')
@section('page-subtitle', 'ทะเบียนซัพพลายเออร์และยอดเจ้าหนี้ (AP)')

@section('content')
    <div x-data="supplierPage()" x-cloak>
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
            <form method="get" class="d-flex gap-2" style="max-width: 420px;">
                <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="ค้นหารหัส/ชื่อซัพพลายเออร์">
                <button class="btn btn-light border"><i class="bi bi-search"></i></button>
            </form>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
                <i class="bi bi-plus-lg me-1"></i> เพิ่มซัพพลายเออร์
            </button>
        </div>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อซัพพลายเออร์</th><th class="text-end">ยอดหนี้สะสม</th><th>สถานะ</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($suppliers as $supplier)
                            <tr>
                                <td class="fw-semibold">{{ $supplier->code }}</td>
                                <td>{{ $supplier->name_th }}</td>
                                <td class="text-end {{ ($balances[$supplier->id] ?? 0) > 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ number_format($balances[$supplier->id] ?? 0, 2) }}
                                </td>
                                <td>
                                    <span class="badge {{ $supplier->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $supplier->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('suppliers.show', $supplier) }}" class="btn btn-sm btn-light border">ดู</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-5 text-center text-muted">ไม่พบซัพพลายเออร์</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $suppliers->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width: min(560px, 100%);" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">เพิ่มซัพพลายเออร์ใหม่</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('suppliers.store') }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสซัพพลายเออร์</label>
                                <input type="text" name="code" required class="form-control">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="newSupplierActive">
                                    <label class="form-check-label" for="newSupplierActive">ใช้งาน</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อซัพพลายเออร์ (ไทย)</label>
                                <input type="text" name="name_th" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อซัพพลายเออร์ (อังกฤษ)</label>
                                <input type="text" name="name_en" class="form-control">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label text-muted small">เลขประจำตัวผู้เสียภาษี (ใช้ในรายงานภาษีซื้อ)</label>
                                <input type="text" name="tax_id" maxlength="20" class="form-control" placeholder="13 หลัก">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">สาขาที่</label>
                                <input type="text" name="tax_branch" maxlength="10" class="form-control" placeholder="00000">
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
    function supplierPage() {
        return { modalOpen: false };
    }
</script>
@endpush
