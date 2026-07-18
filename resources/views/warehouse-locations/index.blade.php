@extends('layout')
@section('title', 'คลังสินค้า - POPSTAR ERP')
@section('page-title', 'คลังสินค้า / ที่จัดเก็บ')
@section('page-subtitle', 'ทะเบียนคลังและที่เก็บสินค้า สำหรับใช้กับสต็อกและโอนย้าย')
@section('content')
    <div x-data="warehouseLocationPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">รายการคลัง</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อคลัง'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()"><i class="bi bi-plus-lg me-1"></i> เพิ่มคลัง</button>
        </div>
        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>ชื่อคลัง/ที่เก็บ</th><th>ประเภท</th><th></th></tr></thead>
                    <tbody>
                        @forelse($locations as $loc)
                        <tr>
                            <td class="fw-semibold">{{ $loc->code }}</td>
                            <td>{{ $loc->name }}</td>
                            <td class="text-muted">{{ $loc->warehouse?->name }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light border"
                                    @click="openEdit({{ $loc->id }}, {{ $loc->warehouse_id }}, '{{ $loc->code }}', '{{ addslashes($loc->name) }}')">แก้ไข</button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="py-5 text-center text-muted">ยังไม่มีคลัง</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $locations->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen=false">
            <div class="booking-modal" style="width:min(480px,100%)" @click.outside="modalOpen=false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขคลัง' : 'เพิ่มคลัง'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen=false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label text-muted small">ประเภทคลัง</label>
                                <select name="warehouse_id" x-model="warehouseId" required class="form-select">
                                    @foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }} - {{ $w->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-5"><label class="form-label text-muted small">รหัส</label><input type="text" name="code" x-model="code" required class="form-control"></div>
                            <div class="col-7"><label class="form-label text-muted small">ชื่อคลัง/ที่เก็บ</label><input type="text" name="name" x-model="name" required class="form-control"></div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen=false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@push('head')<style>[x-cloak]{display:none!important}.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:24px}.booking-modal{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24)}</style>@endpush
@push('scripts')
<script>
function warehouseLocationPage() {
    return {
        modalOpen: false, editingId: null, warehouseId: '{{ $warehouses->first()?->id }}', code: '', name: '',
        openCreate() { this.editingId=null; this.code=''; this.name=''; this.modalOpen=true; },
        openEdit(id,warehouseId,code,name) { this.editingId=id; this.warehouseId=warehouseId; this.code=code; this.name=name; this.modalOpen=true; },
        get formAction() { return this.editingId ? `{{ url('warehouse-locations') }}/${this.editingId}` : `{{ route('warehouse-locations.store') }}`; },
    };
}
</script>
@endpush
