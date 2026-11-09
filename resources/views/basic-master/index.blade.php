@extends('layout')
@section('title', $label.' - ตั้งค่าค่าคงที่ - MPM Food Group')
@section('page-title', 'ค่าคงที่ระบบ: '.$label)
@section('page-subtitle', 'เพิ่ม แก้ไข หรือลบค่าคงที่ (รายการที่ถูกใช้งานอยู่จะถูกปิดใช้งานแทนการลบจริง)')
@section('content')
@php
    $nameColumn = $nameColumn ?? (in_array($type, ['warehouses', 'warehouse-locations', 'product-units', 'sales-areas', 'member-types'], true) ? 'name' : 'name_th');
    $tabs = [
        'product-categories' => 'หมวดสินค้า',
        'product-departments' => 'แผนกสินค้า',
        'product-brands' => 'ยี่ห้อสินค้า',
        'product-units' => 'หน่วยนับสินค้า',
        'sales-areas' => 'เขต/สายการขาย',
        'member-types' => 'ประเภทสมาชิก',
        'branches' => 'สาขา',
        'warehouses' => 'คลังสินค้า',
        'warehouse-locations' => 'พื้นที่เก็บสินค้า',
    ];
    $hasEnglishName = in_array($type, ['product-categories', 'product-departments', 'product-brands', 'branches'], true);
@endphp
    <div x-data="basicMasterPage()" x-cloak>
        <div class="content-card p-3 mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex flex-wrap gap-1" role="tablist" aria-label="หมวดค่าคงที่ระบบ">
                    @foreach($tabs as $key => $tab)
                        <a href="{{ route('basic-master.index', $key) }}"
                           class="btn btn-sm {{ $key === $type ? 'btn-primary fw-semibold' : 'btn-light border text-secondary' }}">
                            {{ $tab }}
                        </a>
                    @endforeach
                    {{-- เล่มเอกสาร (ใบจองแยกสาย B10–B39, DS/DSN, ใบเสร็จ ฯลฯ) จัดการที่หน้าสมุดเอกสาร --}}
                    <a href="{{ route('document-books.index') }}" class="btn btn-sm btn-light border text-secondary">
                        <i class="bi bi-journals me-1"></i>เล่มเอกสาร (ใบจอง / DS / DSN / ใบเสร็จ)
                    </a>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อ'])
                    <button type="button" class="btn btn-primary px-3 text-nowrap" @click="openCreate()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่ม{{ $label }}
                    </button>
                </div>
            </div>
        </div>

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:140px">รหัส</th>
                            <th>ชื่อ{{ $label }}</th>
                            @if($hasEnglishName)
                                <th>ชื่ออังกฤษ</th>
                            @endif
                            @if($type === 'product-units')
                                <th class="text-end">จำนวนต่อหน่วยฐาน</th>
                            @endif
                            @if($type === 'member-types')
                                <th class="text-end">อัตราแต้มสะสม</th>
                            @endif
                            @if(in_array($type, ['warehouses', 'sales-areas'], true))
                                <th>สาขา</th>
                            @endif
                            @if($type === 'warehouse-locations')
                                <th>คลังสินค้า</th>
                            @endif
                            @if($type === 'branches')
                                <th>คลังเริ่มต้น</th>
                                <th>สต็อกติดลบ</th>
                            @endif
                            @if(in_array($type, ['product-categories', 'product-departments', 'product-brands', 'product-units'], true))
                                <th class="text-end">สินค้าที่อ้างอิง</th>
                            @endif
                            @if($type === 'member-types')
                                <th class="text-end">สมาชิกที่อ้างอิง</th>
                            @endif
                            <th style="width:120px">สถานะ</th>
                            <th class="text-end" style="width:140px">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                        @php
                            $isActive = (bool) ($row->is_active ?? true);
                        @endphp
                        <tr class="{{ $isActive ? '' : 'text-muted' }}">
                            <td class="fw-semibold font-monospace">{{ $row->code }}</td>
                            <td>{{ $row->{$nameColumn} }}</td>
                            @if($hasEnglishName)
                                <td class="text-muted">{{ $row->name_en ?: '-' }}</td>
                            @endif
                            @if($type === 'product-units')
                                <td class="text-end">{{ number_format((float) ($row->qty_per_base_unit ?? 1), 2) }}</td>
                            @endif
                            @if($type === 'member-types')
                                <td class="text-end">{{ number_format((float) ($row->point_rate ?? 1), 2) }}</td>
                            @endif
                            @if(in_array($type, ['warehouses', 'sales-areas'], true))
                                <td class="text-muted">{{ $row->branch ? $row->branch->code.' '.$row->branch->name_th : '-' }}</td>
                            @endif
                            @if($type === 'warehouse-locations')
                                <td class="text-muted">{{ $row->warehouse ? $row->warehouse->code.' '.$row->warehouse->name : '-' }}</td>
                            @endif
                            @if($type === 'branches')
                                <td class="text-muted">{{ $locations->firstWhere('id', $row->default_warehouse_location_id)?->name ?? '-' }}</td>
                                <td>{{ ($row->negative_stock_policy ?? 'allow') === 'block' ? 'ไม่ยอมให้ติดลบ' : 'ยอมให้ติดลบ' }}</td>
                            @endif
                            @if(in_array($type, ['product-categories', 'product-departments', 'product-brands', 'product-units'], true))
                                <td class="text-end text-muted">{{ number_format((int) ($row->products_count ?? 0)) }}</td>
                            @endif
                            @if($type === 'member-types')
                                <td class="text-end text-muted">{{ number_format((int) ($row->members_count ?? 0)) }}</td>
                            @endif
                            <td>
                                @if($isActive)
                                    <span class="badge text-bg-success">ใช้งาน</span>
                                @else
                                    <span class="badge text-bg-secondary">ปิดใช้งาน</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-light border" @click='openEdit(@json($row))'>แก้ไข</button>
                                @include('partials.safe-delete', ['type' => $type, 'id' => $row->id])
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="py-5 text-center text-muted">ยังไม่มี{{ $label }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rows->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen=false">
            <div class="booking-modal" style="width:min(540px,100%)" @click.outside="modalOpen=false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไข{{ $label }}' : 'เพิ่ม{{ $label }}'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen=false" aria-label="ปิดหน้าต่าง"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-5">
                                <label class="form-label text-muted small">รหัส</label>
                                <input type="text" name="code" x-model="form.code" :disabled="editingId" required class="form-control">
                            </div>
                            <div class="col-7">
                                <label class="form-label text-muted small">ชื่อ{{ $label }}</label>
                                <input type="text" name="{{ $nameColumn }}" x-model="form.{{ $nameColumn }}" required class="form-control">
                            </div>

                            @if($hasEnglishName)
                                <div class="col-12">
                                    <label class="form-label text-muted small">ชื่ออังกฤษ</label>
                                    <input type="text" name="name_en" x-model="form.name_en" class="form-control">
                                </div>
                            @endif

                            @if($type === 'product-units')
                                <div class="col-6">
                                    <label class="form-label text-muted small">จำนวนต่อหน่วยฐาน</label>
                                    <input type="number" step="0.0001" min="0.0001" name="qty_per_base_unit" x-model="form.qty_per_base_unit" class="form-control">
                                </div>
                            @endif

                            @if($type === 'member-types')
                                <div class="col-6">
                                    <label class="form-label text-muted small">อัตราแต้มสะสม</label>
                                    <input type="number" step="0.01" min="0" name="point_rate" x-model="form.point_rate" class="form-control">
                                </div>
                            @endif

                            @if(in_array($type, ['warehouses', 'sales-areas'], true))
                                <div class="col-12">
                                    <label class="form-label text-muted small">สาขา</label>
                                    <select name="branch_id" x-model="form.branch_id" class="form-select">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($branches as $branch)
                                            <option value="{{ $branch->id }}">{{ $branch->code }} {{ $branch->name_th }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if($type === 'warehouse-locations')
                                <div class="col-12">
                                    <label class="form-label text-muted small">คลังสินค้า</label>
                                    <select name="warehouse_id" x-model="form.warehouse_id" required class="form-select">
                                        <option value="">เลือกคลังสินค้า</option>
                                        @foreach($warehouses as $warehouse)
                                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} {{ $warehouse->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if($type === 'branches')
                                <div class="col-12">
                                    <label class="form-label text-muted small">คลังเริ่มต้น</label>
                                    <select name="default_warehouse_location_id" x-model="form.default_warehouse_location_id" class="form-select">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->code }} {{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label text-muted small">สต็อกติดลบ</label>
                                    <select name="negative_stock_policy" x-model="form.negative_stock_policy" class="form-select">
                                        <option value="allow">ยอมให้ติดลบ</option>
                                        <option value="block">ไม่ยอมให้ติดลบ</option>
                                    </select>
                                </div>
                            @endif

                            <div class="col-5 d-flex align-items-end">
                                <label class="form-check mb-2">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="form-check-input">
                                    ใช้งาน
                                </label>
                            </div>
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
    const blank = {
        code: '',
        name: '',
        name_th: '',
        name_en: '',
        branch_id: '',
        warehouse_id: '',
        qty_per_base_unit: '1',
        point_rate: '1',
        default_warehouse_location_id: '',
        negative_stock_policy: 'allow',
        is_active: true,
    };
    return {
        modalOpen: false, editingId: null, form: { ...blank },
        openCreate() { this.editingId = null; this.form = { ...blank }; this.modalOpen = true; },
        openEdit(row) {
            this.editingId = row.id;
            this.form = { ...blank, ...Object.fromEntries(Object.entries(row).map(([k, v]) => [k, v == null ? '' : (typeof v === 'boolean' ? v : String(v))])) };
            this.form.is_active = row.is_active === undefined ? true : !!row.is_active;
            this.form.negative_stock_policy = row.negative_stock_policy || 'allow';
            this.modalOpen = true;
        },
        get formAction() { return this.editingId ? `{{ url('basic-master/'.$type) }}/${this.editingId}` : `{{ route('basic-master.store', $type) }}`; },
    };
}
</script>
@endpush
