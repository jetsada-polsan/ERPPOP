@extends('layout')
@section('title', 'พนักงานขาย - POPSTAR ERP')
@section('page-title', 'สายการขายและ POS เดิม')
@section('page-subtitle', 'สายการขายผูกกับบัญชีผู้ใช้โดยตรง ส่วนแฟ้มพนักงานเดิมใช้รองรับ POS และประวัติเก่า')
@section('content')
    <div x-data="salesmanPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">แฟ้มอ้างอิง POS เดิม</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อ'])
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary rounded-pill px-4" @click="openAreaCreate()"><i class="bi bi-signpost-split me-1"></i> เพิ่มสายการขาย</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()"><i class="bi bi-plus-lg me-1"></i> เพิ่มพนักงาน</button>
            </div>
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

        <div class="content-card p-4 mt-4">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1">สายการขาย / เล่มใบจอง</h2>
                    <div class="text-muted small">รหัส B/BK จาก Business Plus แต่ละสายมีเลขเอกสารแยก ผู้ดูแลมาจากบัญชีผู้ใช้ที่ผูกกับสายนี้</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส/เล่ม</th><th>ชื่อสายการขาย</th><th>ใช้กับสาขา</th><th>บัญชีผู้ใช้ในสาย</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    @forelse($salesAreas as $area)
                        <tr>
                            <td class="fw-semibold">{{ $area->code }}</td>
                            <td>{{ $area->name }}</td>
                            <td>{{ $area->branch?->name_th ?? 'ใช้ได้หลายสาขา' }}</td>
                            <td>
                                @forelse($area->users as $user)
                                    <span class="badge text-bg-light border">{{ $user->username }} - {{ $user->name }}</span>
                                @empty
                                    <span class="text-muted">ยังไม่ผูกผู้ใช้</span>
                                @endforelse
                            </td>
                            <td><span class="badge {{ $area->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $area->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-light border"
                                @click="openAreaEdit(@js($area->id), @js($area->code), @js($area->name), @js($area->branch_id), @js($area->is_active))">แก้ไข</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-muted">ยังไม่มีสายการขาย</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
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

        <div class="booking-modal-backdrop" x-show="areaModalOpen" x-transition.opacity @keydown.escape.window="areaModalOpen = false">
            <div class="booking-modal" style="width:min(620px,100%)" @click.outside="areaModalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <div><h3 class="h5 fw-bold mb-1" x-text="areaEditingId ? 'แก้ไขสายการขาย' : 'เพิ่มสายการขาย'"></h3><div class="text-muted small">ระบบสร้างเล่มใบจองรหัสเดียวกัน ผู้ดูแลกำหนดจากหน้าผู้ใช้</div></div>
                    <button type="button" class="btn btn-light rounded-circle" @click="areaModalOpen=false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="areaFormAction">
                    @csrf
                    <template x-if="areaEditingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label small text-muted">รหัสสาย/รหัสเล่ม</label><input name="code" x-model="areaCode" class="form-control" required placeholder="เช่น B20"></div>
                            <div class="col-md-8"><label class="form-label small text-muted">ชื่อสายการขาย</label><input name="name" x-model="areaName" class="form-control" required placeholder="เช่น กันทรารมย์-ราษี-ยโส"></div>
                            <div class="col-md-6"><label class="form-label small text-muted">จำกัดสาขา (ถ้ามี)</label><select name="branch_id" x-model="areaBranchId" class="form-select"><option value="">ใช้ได้หลายสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select></div>
                            <div class="col-12"><label class="form-check"><input type="checkbox" name="is_active" value="1" x-model="areaIsActive" class="form-check-input"><span class="form-check-label">เปิดใช้งานในใบจองและรายงาน</span></label></div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0"><button type="button" class="btn btn-light border px-4" @click="areaModalOpen=false">ยกเลิก</button><button type="submit" class="btn btn-primary px-4">บันทึก</button></div>
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
        areaModalOpen: false, areaEditingId: null, areaCode: '', areaName: '', areaBranchId: '', areaIsActive: true,
        openCreate() { this.editingId=null; this.code=''; this.name=''; this.branchId=''; this.isActive=true; this.modalOpen=true; },
        openEdit(id,code,name,branchId,isActive) { this.editingId=id; this.code=code; this.name=name; this.branchId=branchId||''; this.isActive=isActive; this.modalOpen=true; },
        openAreaCreate() { this.areaEditingId=null; this.areaCode=''; this.areaName=''; this.areaBranchId=''; this.areaIsActive=true; this.areaModalOpen=true; },
        openAreaEdit(id,code,name,branchId,isActive) { this.areaEditingId=id; this.areaCode=code; this.areaName=name; this.areaBranchId=branchId||''; this.areaIsActive=isActive; this.areaModalOpen=true; },
        get formAction() { return this.editingId ? `{{ url('salesmen') }}/${this.editingId}` : `{{ route('salesmen.store') }}`; },
        get areaFormAction() { return this.areaEditingId ? `{{ url('sales-areas') }}/${this.areaEditingId}` : `{{ route('sales-areas.store') }}`; },
    };
}
</script>
@endpush
