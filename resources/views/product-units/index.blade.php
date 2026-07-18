@extends('layout')

@section('title', 'หน่วยนับ - POPSTAR ERP')
@section('page-title', 'หน่วยนับสินค้า')
@section('page-subtitle', 'จัดการหน่วยนับและตัวคูณเทียบหน่วยฐาน')

@section('content')
    <div x-data="unitPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <a href="{{ route('products.index') }}" class="text-decoration-none small d-inline-flex align-items-center gap-1 text-muted">
                    <i class="bi bi-arrow-left"></i> สินค้า
                </a>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อหน่วย'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()">
                <i class="bi bi-plus-lg me-1"></i> เพิ่มหน่วยนับ
            </button>
        </div>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr><th>รหัส</th><th>ชื่อหน่วย</th><th class="text-end">ตัวคูณ/หน่วยฐาน</th><th class="text-end">จำนวนสินค้าที่ใช้</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($units as $unit)
                        <tr>
                            <td class="fw-semibold">{{ $unit->code }}</td>
                            <td>{{ $unit->name }}</td>
                            <td class="text-end">{{ number_format($unit->qty_per_base_unit, 4) }}</td>
                            <td class="text-end">{{ $unit->products_count }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light border"
                                    @click="openEdit({{ $unit->id }}, '{{ $unit->code }}', '{{ addslashes($unit->name) }}', {{ $unit->qty_per_base_unit }})">
                                    แก้ไข
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-5 text-center text-muted">ยังไม่มีหน่วยนับ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $units->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขหน่วยนับ' : 'เพิ่มหน่วยนับ'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label text-muted small">รหัส</label>
                                <input type="text" name="code" x-model="code" required class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">ตัวคูณ/หน่วยฐาน</label>
                                <input type="number" step="0.0001" min="0.0001" name="qty_per_base_unit" x-model="qty" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อหน่วย</label>
                                <input type="text" name="name" x-model="name" required class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
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
    .booking-modal {
        width: min(480px, 100%); background: #fff; border-radius: 18px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
</style>
@endpush

@push('scripts')
<script>
    function unitPage() {
        return {
            modalOpen: false,
            editingId: null,
            code: '', name: '', qty: 1,
            openCreate() {
                this.editingId = null;
                this.code = ''; this.name = ''; this.qty = 1;
                this.modalOpen = true;
            },
            openEdit(id, code, name, qty) {
                this.editingId = id;
                this.code = code; this.name = name; this.qty = qty;
                this.modalOpen = true;
            },
            get formAction() {
                return this.editingId
                    ? `{{ url('product-units') }}/${this.editingId}`
                    : `{{ route('product-units.store') }}`;
            },
        };
    }
</script>
@endpush
