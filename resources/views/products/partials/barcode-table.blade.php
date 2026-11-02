{{-- ตารางบาร์โค้ดของสินค้า: หนึ่งสินค้ามีได้หลายบาร์โค้ด แต่ละตัวมีหน่วย ตัวคูณ และราคาของตัวเอง --}}
@php
    $groups = \App\Support\BarcodePolicy::GROUPS;
    $groupLabels = \App\Support\BarcodePolicy::GROUP_LABELS;
    $factor = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
@endphp
<div class="content-card p-4 barcode-full-table">
    <div class="d-flex align-items-center justify-content-between mb-1">
        <h3 class="h6 fw-bold mb-0">บาร์โค้ด</h3>
        <button type="button" class="btn btn-sm btn-light border" @click="barcodeOpen = true"><i class="bi bi-plus-lg me-1"></i> เพิ่ม</button>
    </div>
    <div class="small text-muted mb-3">บาร์โค้ดคือรหัสที่ยิงขาย แยกจาก SKU · ยิงแต่ละตัวจะขายตามหน่วย ตัวคูณ และราคาที่ผูกไว้</div>
    <div class="table-responsive">
        <table class="table align-middle table-sm mb-0">
            <thead><tr><th>บาร์โค้ด</th><th>ประเภท</th><th>หน่วย</th><th class="text-end">ตัวคูณ</th><th class="text-end">ราคา</th><th>สถานะ</th><th>แก้ไขล่าสุด</th><th></th></tr></thead>
            <tbody>
                @forelse($product->barcodes as $barcode)
                    @php
                        $group = $groups[$barcode->barcode_type] ?? 'internal';
                        $editRow = [
                            'id' => $barcode->id, 'barcode' => $barcode->barcode, 'barcode_type' => $barcode->barcode_type,
                            'unit_id' => (string) $barcode->unit_id, 'unit_factor' => $factor($barcode->unit_factor),
                            'price' => $barcode->price === null ? '' : number_format((float) $barcode->price, 2, '.', ''),
                            'is_active' => $barcode->is_active, 'scale_barcode_profile_id' => (string) ($barcode->scale_barcode_profile_id ?? ''),
                        ];
                    @endphp
                    <tr class="{{ $barcode->is_active ? '' : 'text-muted' }}">
                        <td class="font-monospace fw-semibold">{{ $barcode->barcode }}</td>
                        <td><span class="barcode-badge is-{{ $group }}" title="{{ \App\Support\BarcodePolicy::LABELS[$barcode->barcode_type] ?? $barcode->barcode_type }}">{{ $groupLabels[$group] }}</span></td>
                        <td>{{ $barcode->unit?->displayLabel() ?? '-' }}</td>
                        <td class="text-end">{{ $factor($barcode->unit_factor) }}</td>
                        <td class="text-end">{{ $barcode->price !== null ? number_format((float) $barcode->price, 2) : 'ใช้ราคาหลัก' }}</td>
                        <td>@if($barcode->is_active)<span class="badge text-bg-success">ใช้งาน</span>@else<span class="badge text-bg-secondary">ปิดใช้งาน</span>@endif</td>
                        <td class="small text-muted text-nowrap">{{ $barcode->updated_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light border" @click='openBarcodeEdit(@json($editRow))'>แก้ไข</button>
                            <form method="post" action="{{ route('products.barcodes.destroy', [$product, $barcode]) }}" class="d-inline" onsubmit="return confirm('ลบบาร์โค้ด {{ $barcode->barcode }}? ถ้ามีประวัติขาย ระบบจะไม่ให้ลบ ให้ปิดใช้งานแทน')">
                                @csrf @method('DELETE')
                                <input type="hidden" name="popup" value="{{ request()->boolean('popup') ? 1 : 0 }}">
                                <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-3">ยังไม่มีบาร์โค้ด</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="booking-modal-backdrop" x-show="barcodeEdit" x-transition.opacity @keydown.escape.window="barcodeEdit = null">
    <div class="booking-modal" style="width: min(480px, 100%);" @click.outside="barcodeEdit = null" x-transition>
        <template x-if="barcodeEdit">
            <form method="post" :action="`{{ url('products/'.$product->id.'/barcodes') }}/${barcodeEdit.id}`">
                @csrf @method('PUT')
                <input type="hidden" name="popup" value="{{ request()->boolean('popup') ? 1 : 0 }}">
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">แก้ไขบาร์โค้ด</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="barcodeEdit = null"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <div class="row g-3">
                        <div class="col-12"><label class="form-label text-muted small">บาร์โค้ด</label>
                            <input type="text" name="barcode" x-model="barcodeEdit.barcode" required class="form-control font-monospace">
                            <div class="small mt-1" x-show="barcodeEdit.barcode" x-text="hintFor(barcodeEdit.barcode_type, barcodeEdit.barcode)"
                                :class="okFor(barcodeEdit.barcode_type, barcodeEdit.barcode) ? 'text-success' : 'text-danger'"></div></div>
                        <div class="col-12"><label class="form-label text-muted small">ประเภท</label>
                            <select name="barcode_type" x-model="barcodeEdit.barcode_type" class="form-select">
                                @foreach(\App\Support\BarcodePolicy::LABELS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select></div>
                        <div class="col-12" x-show="barcodeEdit.barcode_type === 'SCALE_PLU'"><label class="form-label text-muted small">โปรไฟล์เครื่องชั่ง</label>
                            <select name="scale_barcode_profile_id" x-model="barcodeEdit.scale_barcode_profile_id" class="form-select">
                                <option value="">เลือกโปรไฟล์</option>
                                @foreach($scaleProfiles as $profile)<option value="{{ $profile->id }}">{{ $profile->code }} {{ $profile->name }}</option>@endforeach
                            </select></div>
                        <div class="col-6"><label class="form-label text-muted small">หน่วย</label>
                            <select name="unit_id" x-model="barcodeEdit.unit_id" required class="form-select">
                                @foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->displayLabel() }}</option>@endforeach
                            </select></div>
                        <div class="col-6"><label class="form-label text-muted small">ตัวคูณ</label>
                            <input type="number" step="0.0001" min="0.0001" name="unit_factor" x-model="barcodeEdit.unit_factor" required class="form-control"></div>
                        <div class="col-6"><label class="form-label text-muted small">ราคา (ว่าง = ใช้ราคาหลัก)</label>
                            <input type="number" step="0.01" min="0" name="price" x-model="barcodeEdit.price" class="form-control"></div>
                        <div class="col-6 d-flex align-items-end"><label class="form-check mb-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="barcodeEdit.is_active" class="form-check-input"> ใช้งาน</label></div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light border px-4" @click="barcodeEdit = null">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                </div>
            </form>
        </template>
    </div>
</div>
