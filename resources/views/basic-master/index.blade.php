@extends('layout')
@section('title', $label.' - MPM Food Group')
@section('page-title', $label)
@section('page-subtitle', 'รหัสแก้ไม่ได้หลังบันทึก รายการที่มีประวัติลบไม่ได้')
@section('content')
@php
    $nameColumn = $type === 'warehouses' ? 'name' : 'name_th';
    $tabs = ['product-categories' => 'หมวดสินค้า', 'branches' => 'สาขา', 'warehouses' => 'คลังสินค้า'];
@endphp
    <div x-data="basicMasterPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <div class="btn-group">
                    @foreach($tabs as $key => $tab)
                        <a href="{{ route('basic-master.index', $key) }}" class="btn btn-sm {{ $key === $type ? 'btn-primary' : 'btn-light border' }}">{{ $tab }}</a>
                    @endforeach
                </div>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อ'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()"><i class="bi bi-plus-lg me-1"></i> เพิ่ม{{ $label }}</button>
        </div>
        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr>
                        <th>รหัส</th><th>ชื่อ</th>
                        @if($type === 'branches')<th>คลังเริ่มต้น</th><th>สต็อกติดลบ</th><th>สถานะ</th>@endif
                        @if($type === 'warehouses')<th>สาขา</th>@endif
                        @if($type === 'product-categories')<th>ชื่ออังกฤษ</th>@endif
                        <th></th>
                    </tr></thead>
                    <tbody>
                        @forelse($rows as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row->code }}</td>
                            <td>{{ $row->{$nameColumn} }}</td>
                            @if($type === 'branches')
                                <td class="text-muted">{{ $locations->firstWhere('id', $row->default_warehouse_location_id)?->name ?? '-' }}</td>
                                <td>{{ ($row->negative_stock_policy ?? 'allow') === 'block' ? 'ไม่ยอมให้ติดลบ' : 'ยอมให้ติดลบ' }}</td>
                                <td>@if($row->is_active)<span class="badge text-bg-success">ใช้งาน</span>@else<span class="badge text-bg-secondary">ปิดใช้งาน</span>@endif</td>
                            @endif
                            @if($type === 'warehouses')<td class="text-muted">{{ $row->branch ? $row->branch->code.' '.$row->branch->name_th : '-' }}</td>@endif
                            @if($type === 'product-categories')<td class="text-muted">{{ $row->name_en ?: '-' }}</td>@endif
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-light border" @click='openEdit(@json($row))'>แก้ไข</button>
                                @include('partials.safe-delete', ['type' => $type, 'id' => $row->id])
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="py-5 text-center text-muted">ยังไม่มี{{ $label }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rows->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen=false">
            <div class="booking-modal" style="width:min(520px,100%)" @click.outside="modalOpen=false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไข{{ $label }}' : 'เพิ่ม{{ $label }}'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen=false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-5"><label class="form-label text-muted small">รหัส</label>
                                <input type="text" name="code" x-model="form.code" :disabled="editingId" required class="form-control"></div>
                            <div class="col-7"><label class="form-label text-muted small">ชื่อ</label>
                                <input type="text" name="{{ $nameColumn }}" x-model="form.{{ $nameColumn }}" required class="form-control"></div>
                            @if($type !== 'warehouses')
                                <div class="col-12"><label class="form-label text-muted small">ชื่ออังกฤษ</label>
                                    <input type="text" name="name_en" x-model="form.name_en" class="form-control"></div>
                            @endif
                            @if($type === 'warehouses')
                                <div class="col-12"><label class="form-label text-muted small">สาขา</label>
                                    <select name="branch_id" x-model="form.branch_id" class="form-select">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} {{ $branch->name_th }}</option>@endforeach
                                    </select></div>
                            @endif
                            @if($type === 'branches')
                                <div class="col-12"><label class="form-label text-muted small">คลังเริ่มต้น</label>
                                    <select name="default_warehouse_location_id" x-model="form.default_warehouse_location_id" class="form-select">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->code }} {{ $location->name }}</option>@endforeach
                                    </select></div>
                                <div class="col-7"><label class="form-label text-muted small">สต็อกติดลบ</label>
                                    <select name="negative_stock_policy" x-model="form.negative_stock_policy" class="form-select">
                                        <option value="allow">ยอมให้ติดลบ</option><option value="block">ไม่ยอมให้ติดลบ</option>
                                    </select></div>
                                <div class="col-5 d-flex align-items-end"><label class="form-check mb-2">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="form-check-input"> ใช้งาน</label></div>
                            @endif
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
@push('head')<style>[x-cloak]{display:none!important}.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:16px}.booking-modal{background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(15,23,42,.25);max-height:92vh;overflow:auto}</style>@endpush
@push('scripts')
<script>
function basicMasterPage() {
    const blank = { code: '', name: '', name_th: '', name_en: '', branch_id: '', default_warehouse_location_id: '', negative_stock_policy: 'allow', is_active: true };
    return {
        modalOpen: false, editingId: null, form: { ...blank },
        openCreate() { this.editingId = null; this.form = { ...blank }; this.modalOpen = true; },
        openEdit(row) {
            this.editingId = row.id;
            this.form = { ...blank, ...Object.fromEntries(Object.entries(row).map(([k, v]) => [k, v == null ? '' : (typeof v === 'boolean' ? v : String(v))])) };
            this.form.is_active = !!row.is_active;
            this.form.negative_stock_policy = row.negative_stock_policy || 'allow';
            this.modalOpen = true;
        },
        get formAction() { return this.editingId ? `{{ url('basic-master/'.$type) }}/${this.editingId}` : `{{ route('basic-master.store', $type) }}`; },
    };
}
</script>
@endpush
