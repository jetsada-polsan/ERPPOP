@extends('layout')

@section('title', 'นัดเปลี่ยนราคาขายล่วงหน้า - MPM Food Group')
@section('page-title', 'นัดเปลี่ยนราคาขายล่วงหน้า')
@section('page-subtitle', 'ตั้งเวลาเปลี่ยนราคาขายอัตโนมัติทั้งระบบหลังบ้านและเครื่อง POS พร้อมกรอกหลายสินค้าในครั้งเดียว')

@section('content')
<div x-data="scheduledPriceApp()" x-cloak class="scheduled-price-page">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="{{ route('products.index') }}" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i> กลับหน้ารายการสินค้า
        </a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="showPasteModal = true">
                <i class="bi bi-clipboard-data me-1"></i> วางข้อความหลายรายการ (รหัส/ราคา)
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if($errors->has('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ $errors->first('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Main form card --}}
    <form method="post" action="{{ route('products.scheduled-prices.store') }}" @submit="handleSubmit($event)" id="scheduled-price-form">
        @csrf
        <div class="content-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 fw-bold mb-0">
                    <i class="bi bi-calendar-plus text-primary me-2"></i>บันทึกนัดเปลี่ยนราคาชุดใหม่
                </h3>
                <span class="badge text-bg-light border text-muted">กรอกหลายรายการในครั้งเดียว</span>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">
                        วันที่และเวลาที่มีผล <span class="text-danger">*</span>
                    </label>
                    <input type="datetime-local" name="effective_at" x-model="effectiveAt" required
                           class="form-control form-control-sm @error('effective_at') is-invalid @enderror">
                    <div class="form-text text-muted" style="font-size: 11px;">
                        ค่าเริ่มต้น 00:00 วันพรุ่งนี้ (เวลาไทย) ระบบจะเปลี่ยนราคาอัตโนมัติและส่งให้ POS ล่วงหน้า
                    </div>
                    @error('effective_at')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold small">หมายเหตุการปรับราคา (ใช้ทั้งชุด)</label>
                    <input type="text" name="note" x-model="note" maxlength="500"
                           placeholder="เช่น ซัพพลายเออร์แจ้งปรับราคาคอหมึก / ปรับราคาตามต้นทุนรอบใหม่"
                           class="form-control form-control-sm @error('note') is-invalid @enderror">
                    <div class="form-text text-muted" style="font-size: 11px;">
                        ระบุสาเหตุหรืออ้างอิงเอกสาร เพื่อบันทึกในประวัติราคา (Price Change History)
                    </div>
                    @error('note')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- Table of items to schedule --}}
            <div class="table-responsive mb-3 border rounded">
                <table class="table table-hover align-middle mb-0" id="items-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;" class="text-center">#</th>
                            <th style="min-width: 320px;">สินค้า</th>
                            <th style="width: 140px;" class="text-end">ราคาปัจจุบัน</th>
                            <th style="width: 180px;" class="text-end">ราคาใหม่ (บาท) <span class="text-danger">*</span></th>
                            <th style="width: 160px;" class="text-end">ส่วนต่าง</th>
                            <th style="width: 60px;" class="text-center"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="index">
                            <tr>
                                <td class="text-center text-muted small" x-text="index + 1"></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm text-start p-1 flex-grow-1 border rounded"
                                                :class="item.product_id ? 'btn-light' : 'btn-outline-primary'"
                                                @click="openProductPicker(index)">
                                            <template x-if="!item.product_id">
                                                <span class="small text-primary"><i class="bi bi-search me-1"></i> คลิกเลือกสินค้า...</span>
                                            </template>
                                            <template x-if="item.product_id">
                                                <div>
                                                    <span class="badge text-bg-secondary me-1" x-text="item.sku_code"></span>
                                                    <strong x-text="item.name_th"></strong>
                                                    <small class="text-muted ms-1" x-text="'(' + (item.unit_name || 'หน่วยฐาน') + ')'"></small>
                                                </div>
                                            </template>
                                        </button>
                                        <input type="hidden" :name="'items[' + index + '][product_id]'" :value="item.product_id">
                                    </div>
                                </td>
                                <td class="text-end text-muted font-monospace">
                                    <span x-text="item.product_id ? money(item.current_price) : '-'"></span>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" step="0.01" min="0.01" required
                                               class="form-control text-end font-monospace fw-bold"
                                               :name="'items[' + index + '][new_price]'"
                                               x-model.number="item.new_price"
                                               :disabled="!item.product_id"
                                               placeholder="0.00">
                                        <span class="input-group-text">฿</span>
                                    </div>
                                </td>
                                <td class="text-end font-monospace small">
                                    <template x-if="item.product_id && item.new_price > 0">
                                        <div :class="getDiffClass(item)">
                                            <span x-text="formatDiff(item)"></span>
                                            <div style="font-size: 10px;" x-text="formatPercent(item)"></div>
                                        </div>
                                    </template>
                                    <template x-if="!item.product_id || !(item.new_price > 0)">
                                        <span class="text-muted">-</span>
                                    </template>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm text-danger p-0"
                                            @click="removeItem(index)" title="ลบแถวนี้"
                                            :disabled="items.length === 1 && !item.product_id">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-sm btn-outline-primary" @click="addItem()">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มแถวสินค้า
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-info text-white px-3"
                            @click="openPreview()"
                            :disabled="validItemsCount === 0">
                        <i class="bi bi-eye me-1"></i> ดูตัวอย่าง (<span x-text="validItemsCount"></span>)
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold"
                            :disabled="validItemsCount === 0">
                        <i class="bi bi-check-circle me-1"></i> บันทึกนัดเปลี่ยนราคา
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- Tabs for Pending, Applied, and Failed --}}
    <div class="content-card p-4">
        <ul class="nav nav-pills mb-3" role="tablist">
            <li class="nav-item">
                <button class="nav-link active position-relative" data-bs-toggle="pill" data-bs-target="#tab-pending">
                    <i class="bi bi-hourglass-split me-1"></i> รายการที่รอมีผล (Pending)
                    @if($pending->count() > 0)
                        <span class="badge text-bg-warning ms-1">{{ $pending->count() }}</span>
                    @endif
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-applied">
                    <i class="bi bi-check-circle-fill me-1 text-success"></i> รายการที่มีผลแล้ว (Applied)
                    <span class="badge text-bg-secondary ms-1">{{ $applied->count() }}</span>
                </button>
            </li>
            @if($failed->count() > 0)
                <li class="nav-item">
                    <button class="nav-link text-danger" data-bs-toggle="pill" data-bs-target="#tab-failed">
                        <i class="bi bi-exclamation-octagon-fill me-1"></i> รายการที่ล้มเหลว (Failed)
                        <span class="badge text-bg-danger ms-1">{{ $failed->count() }}</span>
                    </button>
                </li>
            @endif
        </ul>

        <div class="tab-content">
            {{-- Pending tab --}}
            <div class="tab-pane fade show active" id="tab-pending">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>รหัสชุด (Batch)</th>
                                <th>สินค้า</th>
                                <th class="text-end">ราคาปัจจุบัน</th>
                                <th class="text-end">ราคาใหม่</th>
                                <th>วัน-เวลาที่มีผล</th>
                                <th>POS Schedule</th>
                                <th>ผู้ตั้งรายการ</th>
                                <th>หมายเหตุ</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($pending as $item)
                                <tr>
                                    <td>
                                        <code class="small">{{ $item->batch_ref ?? '-' }}</code>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $item->product?->name_th ?? '-' }}</div>
                                        <small class="text-muted">{{ $item->product?->sku_code }} ({{ $item->product?->baseUnit?->name ?? 'หน่วยฐาน' }})</small>
                                    </td>
                                    <td class="text-end text-muted font-monospace">
                                        {{ number_format((float) ($item->product?->default_price ?? 0), 2) }}
                                    </td>
                                    <td class="text-end font-monospace fw-bold text-primary">
                                        {{ number_format((float) $item->new_price, 2) }}
                                    </td>
                                    <td>
                                        <div><i class="bi bi-clock me-1 text-muted"></i>{{ $item->effective_at?->format('d/m/Y H:i') }}</div>
                                        <small class="text-muted">({{ $item->effective_at?->diffForHumans() }})</small>
                                    </td>
                                    <td>
                                        @if($item->posPriceSchedule)
                                            <span class="badge text-bg-success" title="สร้างช่วงราคาขายล่วงหน้าให้เครื่อง POS แล้ว">
                                                <i class="bi bi-pc-display me-1"></i>POS พร้อม
                                            </span>
                                        @else
                                            <span class="badge text-bg-secondary">-</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $item->creator?->name ?? $item->creator?->username ?? '-' }}</td>
                                    <td class="small text-muted" style="max-width: 180px;">{{ $item->note ?? '-' }}</td>
                                    <td class="text-end">
                                        <form method="post" action="{{ route('products.scheduled-prices.cancel', $item) }}"
                                              data-confirm="ยืนยันยกเลิกการนัดเปลี่ยนราคานี้? แถวราคาล่วงหน้าของ POS จะถูกยกเลิกด้วย">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="ยกเลิกรายการ">
                                                <i class="bi bi-x-circle me-1"></i>ยกเลิก
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="bi bi-calendar-check fs-3 d-block mb-1"></i>
                                        ไม่มีรายการนัดเปลี่ยนราคาที่รอมีผล
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Applied tab --}}
            <div class="tab-pane fade" id="tab-applied">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>รหัสชุด (Batch)</th>
                                <th>สินค้า</th>
                                <th class="text-end">ราคาเดิม</th>
                                <th class="text-center" style="width: 40px;"></th>
                                <th class="text-end">ราคาใหม่</th>
                                <th class="text-end">ส่วนต่าง</th>
                                <th>วันที่มีผล</th>
                                <th>เวลาที่เปลี่ยนจริง</th>
                                <th>ผู้ตั้ง</th>
                                <th>หมายเหตุ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($applied as $item)
                                @php
                                    $diff = (float) $item->new_price - (float) ($item->old_price ?? 0);
                                @endphp
                                <tr>
                                    <td><code class="small">{{ $item->batch_ref ?? '-' }}</code></td>
                                    <td>
                                        <div class="fw-semibold">{{ $item->product?->name_th ?? '-' }}</div>
                                        <small class="text-muted">{{ $item->product?->sku_code }}</small>
                                    </td>
                                    <td class="text-end font-monospace text-muted">
                                        {{ $item->old_price !== null ? number_format((float) $item->old_price, 2) : '-' }}
                                    </td>
                                    <td class="text-center text-muted">
                                        <i class="bi bi-arrow-right"></i>
                                    </td>
                                    <td class="text-end font-monospace fw-bold text-success">
                                        {{ number_format((float) $item->new_price, 2) }}
                                    </td>
                                    <td class="text-end font-monospace small">
                                        @if($item->old_price !== null)
                                            <span class="{{ $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted') }}">
                                                {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 2) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="small">{{ $item->effective_at?->format('d/m/Y H:i') }}</td>
                                    <td class="small text-muted">{{ $item->applied_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                    <td class="small">{{ $item->creator?->name ?? '-' }}</td>
                                    <td class="small text-muted">{{ $item->note ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">ยังไม่มีประวัติรายการที่มีผลแล้ว</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Failed tab --}}
            @if($failed->count() > 0)
                <div class="tab-pane fade" id="tab-failed">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        รายการเหล่านี้ไม่สามารถปรับราคาตามเวลาได้ (เช่น สินค้าถูกล็อคหรือเกิดข้อผิดพลาด)
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>รหัสชุด</th>
                                    <th>สินค้า</th>
                                    <th class="text-end">ราคาใหม่</th>
                                    <th>วัน-เวลานัด</th>
                                    <th>ข้อผิดพลาด (Error)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($failed as $item)
                                    <tr>
                                        <td><code class="small">{{ $item->batch_ref ?? '-' }}</code></td>
                                        <td>{{ $item->product?->name_th ?? '-' }} ({{ $item->product?->sku_code }})</td>
                                        <td class="text-end font-monospace fw-bold text-danger">{{ number_format((float) $item->new_price, 2) }}</td>
                                        <td class="small">{{ $item->effective_at?->format('d/m/Y H:i') }}</td>
                                        <td class="small text-danger font-monospace">{{ $item->error ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Quick paste modal --}}
    <div class="modal fade" id="pasteModal" tabindex="-1" aria-hidden="true" :class="{ 'show d-block': showPasteModal }" style="background: rgba(0,0,0,0.5);" x-show="showPasteModal">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h6 fw-bold">
                        <i class="bi bi-clipboard-plus me-1 text-primary"></i> วางข้อความหลายรายการ (รหัส/ราคา)
                    </h5>
                    <button type="button" class="btn-close" @click="showPasteModal = false"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">
                        คัดลอกจาก Excel หรือพิมพ์แบบ <code>รหัสสินค้า&lt;Tab หรือ จุลภาค&gt;ราคา</code> ทีละบรรทัด เช่น:
                    </p>
                    <pre class="bg-light p-2 rounded small text-secondary mb-3 font-monospace">100/300	54
300/500	59
500+	62</pre>
                    <textarea class="form-control font-monospace small" rows="8" x-model="pasteText"
                              placeholder="วางข้อมูลที่นี่..."></textarea>
                    <div x-show="pasteError" class="text-danger small mt-2" x-text="pasteError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-light border" @click="showPasteModal = false">ยกเลิก</button>
                    <button type="button" class="btn btn-sm btn-primary px-3" @click="processPaste()" :disabled="!pasteText.trim()">
                        <i class="bi bi-arrow-down-circle me-1"></i> แปลงและเพิ่มลงตาราง
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Preview modal --}}
    <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true" :class="{ 'show d-block': showPreviewModal }" style="background: rgba(0,0,0,0.5);" x-show="showPreviewModal">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h6 fw-bold">
                        <i class="bi bi-eye me-1 text-info"></i> ตรวจสอบตัวอย่างก่อนบันทึกนัดเปลี่ยนราคา
                    </h5>
                    <button type="button" class="btn-close" @click="showPreviewModal = false"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3 bg-light p-2 rounded small">
                        <div class="col-sm-6">
                            <span class="text-muted">วัน-เวลาที่มีผล:</span>
                            <strong class="text-primary ms-1" x-text="formatDateTime(effectiveAt)"></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted">หมายเหตุ:</span>
                            <span class="ms-1" x-text="note || '-'"></span>
                        </div>
                    </div>
                    <div class="table-responsive border rounded" style="max-height: 350px;">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>#</th>
                                    <th>รหัส SKU</th>
                                    <th>ชื่อสินค้า</th>
                                    <th class="text-end">ราคาเดิม</th>
                                    <th class="text-center" style="width: 30px;"></th>
                                    <th class="text-end">ราคาใหม่</th>
                                    <th class="text-end">ส่วนต่าง (+/−)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, idx) in validItems" :key="idx">
                                    <tr>
                                        <td class="text-muted small" x-text="idx + 1"></td>
                                        <td><code x-text="item.sku_code"></code></td>
                                        <td class="fw-semibold" x-text="item.name_th"></td>
                                        <td class="text-end font-monospace text-muted" x-text="money(item.current_price)"></td>
                                        <td class="text-center text-muted"><i class="bi bi-arrow-right"></i></td>
                                        <td class="text-end font-monospace fw-bold text-primary" x-text="money(item.new_price)"></td>
                                        <td class="text-end font-monospace" :class="getDiffClass(item)" x-text="formatDiff(item)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-light border" @click="showPreviewModal = false">กลับไปแก้ไข</button>
                    <button type="button" class="btn btn-sm btn-primary px-4 fw-bold" @click="confirmAndSubmit()">
                        <i class="bi bi-check2-circle me-1"></i> ยืนยันและบันทึก
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Include shared product picker --}}
    @include('partials.product-picker')
</div>

@push('scripts')
<script>
    function scheduledPriceApp() {
        return {
            ...erpProductPicker(),
            effectiveAt: '{{ old('effective_at', $defaultEffectiveAt) }}',
            note: '{{ old('note', '') }}',
            showPasteModal: false,
            showPreviewModal: false,
            pasteText: '',
            pasteError: '',
            items: [
                { product_id: null, sku_code: '', name_th: '', unit_name: '', current_price: 0, new_price: null }
            ],

            init() {
                // If previous validation failed, load previous items if available
            },

            money(val) {
                return Number(val || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            formatDateTime(dtStr) {
                if (!dtStr) return '-';
                const d = new Date(dtStr);
                return isNaN(d) ? dtStr : d.toLocaleString('th-TH');
            },

            addItem() {
                this.items.push({
                    product_id: null,
                    sku_code: '',
                    name_th: '',
                    unit_name: '',
                    current_price: 0,
                    new_price: null
                });
            },

            removeItem(index) {
                if (this.items.length > 1) {
                    this.items.splice(index, 1);
                } else {
                    this.items[0] = { product_id: null, sku_code: '', name_th: '', unit_name: '', current_price: 0, new_price: null };
                }
            },

            onProductPicked(index, product) {
                const item = this.items[index];
                if (!item) return;
                item.current_price = Number(product.default_price || 0);
                if (item.new_price === null || item.new_price === 0) {
                    item.new_price = item.current_price;
                }
            },

            get validItems() {
                return this.items.filter(i => i.product_id && i.new_price > 0);
            },

            get validItemsCount() {
                return this.validItems.length;
            },

            getDiff(item) {
                return Number(item.new_price || 0) - Number(item.current_price || 0);
            },

            formatDiff(item) {
                const diff = this.getDiff(item);
                const sign = diff > 0 ? '+' : '';
                return sign + this.money(diff);
            },

            formatPercent(item) {
                if (!item.current_price || item.current_price === 0) return '';
                const pct = ((Number(item.new_price) - Number(item.current_price)) / Number(item.current_price)) * 100;
                return (pct > 0 ? '+' : '') + pct.toFixed(1) + '%';
            },

            getDiffClass(item) {
                const diff = this.getDiff(item);
                if (diff > 0) return 'text-success fw-bold';
                if (diff < 0) return 'text-danger fw-bold';
                return 'text-muted';
            },

            openPreview() {
                if (this.validItemsCount === 0) return;
                this.showPreviewModal = true;
            },

            confirmAndSubmit() {
                this.showPreviewModal = false;
                document.getElementById('scheduled-price-form').submit();
            },

            handleSubmit(e) {
                if (this.validItemsCount === 0) {
                    e.preventDefault();
                    return false;
                }
                const eff = new Date(this.effectiveAt);
                if (isNaN(eff) || eff <= new Date()) {
                    e.preventDefault();
                    return false;
                }
            },

            async processPaste() {
                this.pasteError = '';
                try {
                    const res = await fetch('{{ route('products.scheduled-prices.parse-paste') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ text: this.pasteText })
                    });
                    const data = await res.json();
                    if (data.rows && data.rows.length > 0) {
                        // Clear empty initial row if not set
                        if (this.items.length === 1 && !this.items[0].product_id) {
                            this.items = [];
                        }
                        for (const row of data.rows) {
                            this.items.push({
                                product_id: row.product_id,
                                sku_code: row.sku_code,
                                name_th: row.name_th,
                                unit_name: 'หน่วยฐาน',
                                current_price: Number(row.current_price || 0),
                                new_price: Number(row.new_price || 0)
                            });
                        }
                        this.showPasteModal = false;
                        this.pasteText = '';
                    } else {
                        this.pasteError = 'ไม่พบรหัสสินค้าที่ตรงกับข้อความที่วาง กรุณาตรวจสอบรหัสสินค้า';
                    }
                } catch (e) {
                    this.pasteError = 'เกิดข้อผิดพลาดในการประมวลผล: ' + e.message;
                }
            }
        };
    }
</script>
@endpush
@endsection
