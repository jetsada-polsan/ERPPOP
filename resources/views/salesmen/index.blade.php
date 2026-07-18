@extends('layout')
@section('title', 'พนักงานขาย - POPSTAR ERP')
@section('page-title', 'พนักงานขาย')
@section('page-subtitle', 'ทะเบียนพนักงาน/นักขาย สำหรับอ้างอิงในเอกสารขาย')
@section('content')
    <div x-data="salesmanPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">รายการพนักงานขาย</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อ'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()"><i class="bi bi-plus-lg me-1"></i> เพิ่มพนักงาน</button>
        </div>
        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>ชื่อ</th><th>สาขา</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                        @forelse($salesmen as $s)
                        <tr>
                            <td class="fw-semibold">{{ $s->code }}</td>
                            <td>{{ $s->name }}</td>
                            <td>{{ $s->branch?->name_th ?? '-' }}</td>
                            <td><span class="badge {{ $s->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $s->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light border"
                                    @click="openEdit({{ $s->id }}, '{{ $s->code }}', '{{ addslashes($s->name) }}', {{ $s->branch_id ?? 'null' }}, {{ $s->is_active ? 'true' : 'false' }})">แก้ไข</button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-5 text-center text-muted">ยังไม่มีพนักงาน</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $salesmen->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width:min(520px,100%)" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขพนักงาน' : 'เพิ่มพนักงาน'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen=false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-6"><label class="form-label text-muted small">รหัส</label><input type="text" name="code" x-model="code" required class="form-control"></div>
                            <div class="col-6"><label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" x-model="branchId" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-12"><label class="form-label text-muted small">ชื่อ</label><input type="text" name="name" x-model="name" required class="form-control"></div>
                            <div class="col-12"><div class="form-check"><input type="checkbox" name="is_active" value="1" x-model="isActive" class="form-check-input" id="smActive"><label class="form-check-label" for="smActive">ใช้งาน</label></div></div>
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
@push('head')<style>[x-cloak]{display:none!important}.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:24px}.booking-modal{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24);max-height:calc(100vh - 48px);overflow:auto}</style>@endpush
@push('scripts')
<script>
function salesmanPage() {
    return {
        modalOpen: false, editingId: null, code: '', name: '', branchId: '', isActive: true,
        openCreate() { this.editingId=null; this.code=''; this.name=''; this.branchId=''; this.isActive=true; this.modalOpen=true; },
        openEdit(id,code,name,branchId,isActive) { this.editingId=id; this.code=code; this.name=name; this.branchId=branchId||''; this.isActive=isActive; this.modalOpen=true; },
        get formAction() { return this.editingId ? `{{ url('salesmen') }}/${this.editingId}` : `{{ route('salesmen.store') }}`; },
    };
}
</script>
@endpush
