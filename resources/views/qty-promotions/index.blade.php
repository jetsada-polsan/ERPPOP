@extends('layout')
@section('title', 'โปรโมชัน / แคมเปญ - MPM Food Group')
@section('page-title', 'โปรโมชัน / แคมเปญ')
@section('page-subtitle', 'แม่แบบโปรโมชันตาม BPlus เช่น ซื้อครบ N ชิ้นราคาเหลือ Y บาท, 1 แถม 1, ลด %, ซื้อคละ หรือโปรต่อบิล')
@section('content')
<div class="content-card p-4 mb-3" id="create-promo-form" x-data="qtyPromotionProductPage()">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0">สร้างแคมเปญโปรโมชัน</h2>
        <span class="badge text-bg-light border text-secondary" x-text="'แม่แบบ: ' + currentCard().title"></span>
    </div>

    <!-- แผงการ์ดแม่แบบ BPlus 7 รูปแบบ -->
    <div class="mb-4">
        <label class="form-label small text-muted fw-semibold mb-2">เลือกแม่แบบโปรโมชัน (ตาม BPlus)</label>
        <div class="promo-cards">
            <template x-for="card in promoCards" :key="card.type">
                <button type="button" class="promo-card" :class="{ active: type === card.type }" @click="selectCard(card.type)">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <strong x-text="card.title"></strong>
                        <i class="bi" :class="card.icon"></i>
                    </div>
                    <small class="text-primary-emphasis fw-medium" x-text="card.example"></small>
                </button>
            </template>
        </div>
    </div>

    <form method="post" action="{{ route('qty-promotions.store') }}" class="row g-3" @submit="validateProducts($event)">
        @csrf
        <input type="hidden" name="promo_type" :value="type">

        <div class="col-md-2">
            <label class="form-label small text-muted">รหัสแคมเปญ</label>
            <input name="code" x-model="form.code" placeholder="เว้นว่างให้รันอัตโนมัติ" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted">ชื่อแคมเปญ <span class="text-danger">*</span></label>
            <input name="name" x-model="form.name" required class="form-control" placeholder="เช่น ปลาหมึก 3 แพ็ค 100">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สาขาที่ใช้โปรโมชัน</label>
            <select name="branch_id" x-model="form.branch_id" class="form-select">
                <option value="">-- ทุกสาขา --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <div class="form-check mb-2">
                <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="form-check-input" id="promoActive">
                <label class="form-check-label fw-medium" for="promoActive">เปิดใช้งานแคมเปญ</label>
            </div>
        </div>

        <!-- 1) เงื่อนไขยอดซื้อต่อบิล (สำหรับ bill_discount, bill_free_item, bill_addon) -->
        <div class="col-md-4" x-show="isBill()" x-cloak>
            <label class="form-label small text-muted">ยอดซื้อขั้นต่ำต่อบิล X <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="number" step="0.01" min="0.01" name="min_amount" x-model="form.min_amount" class="form-control" placeholder="เช่น 1000" :required="isBill()">
                <span class="input-group-text">บาท</span>
            </div>
            <div class="form-text">ยอดรวมสินค้าทั้งบิลก่อนหักส่วนลดนี้</div>
        </div>

        <!-- 2) สินค้าตัวเดียว (สำหรับ bundle_price, free_item, discount, bill_addon) -->
        <div class="col-md-5" x-show="needsSingleProduct()" x-cloak>
            <label class="form-label small text-muted" x-text="type === 'bill_addon' ? 'สินค้าที่ให้แลกซื้อ *' : 'สินค้าที่ต้องซื้อ *'"></label>
            <input type="hidden" name="product_id" x-model="items[0].product_id">
            <button type="button" class="selected-product" :class="{ empty: !items[0].product_id }" @click="openProductPicker(0)">
                <template x-if="!items[0].product_id">
                    <span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกสินค้า</span>
                </template>
                <template x-if="items[0].product_id">
                    <span class="selected-product-grid">
                        <b class="selected-code" x-text="items[0].sku_code"></b>
                        <span class="selected-copy">
                            <strong x-text="items[0].name_th"></strong>
                            <small x-text="items[0].unit_name || 'หน่วยฐาน'"></small>
                        </span>
                        <span class="selected-change">เปลี่ยน</span>
                    </span>
                </template>
            </button>
        </div>

        <!-- 3) จำนวนขั้นต่ำ X (สำหรับ bundle_price, free_item, discount, mix_bundle) -->
        <div class="col-md-3" x-show="needsMinQty()" x-cloak>
            <label class="form-label small text-muted" x-text="type === 'mix_bundle' ? 'เลือกคละครบ X (ชิ้น) *' : 'ซื้อครบ X (ชิ้น) *'"></label>
            <div class="input-group">
                <input type="number" step="0.0001" min="0.0001" name="min_qty" x-model="form.min_qty" :required="needsMinQty()" class="form-control" placeholder="เช่น 3">
                <span class="input-group-text">ชิ้น/หน่วย</span>
            </div>
        </div>

        <!-- 4) สินค้าในกลุ่มคละ (สำหรับ mix_bundle) -->
        <div class="col-12" x-show="isMix()" x-cloak>
            <label class="form-label small text-muted">สินค้าในกลุ่ม (หยิบตัวไหนในกลุ่มก็นับรวมกัน เลือกอย่างน้อย 2 ตัว) <span class="text-danger">*</span></label>
            <div class="promo-group">
                <template x-for="(item, index) in items.slice(2)" :key="index">
                    <span class="promo-chip" x-show="item.product_id">
                        <input type="hidden" name="product_ids[]" :value="item.product_id">
                        <b x-text="item.sku_code"></b> <span x-text="item.name_th"></span>
                        <button type="button" @click="items.splice(index + 2, 1)">×</button>
                    </span>
                </template>
                <button type="button" class="btn btn-outline-primary btn-sm" @click="items.push({ product_id: '', sku_code: '', name_th: '', unit_name: '' }); openProductPicker(items.length - 1)">
                    <i class="bi bi-plus-lg"></i> เพิ่มสินค้าในกลุ่ม
                </button>
            </div>
        </div>

        <!-- 5) ราคาชุดพิเศษ Y (สำหรับ bundle_price, mix_bundle) -->
        <div class="col-md-4" x-show="type === 'bundle_price' || type === 'mix_bundle'" x-cloak>
            <label class="form-label small text-muted">ราคาพิเศษของชุดที่ซื้อครบ Y <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" name="bundle_price" x-model="form.bundle_price" :disabled="type !== 'bundle_price' && type !== 'mix_bundle'" class="form-control" placeholder="เช่น 100">
                <span class="input-group-text">บาท / ชุด</span>
            </div>
            <div class="form-text">เช่น ซื้อครบ 3 ชิ้น ราคาชุดละ 100 บาท (ส่วนเกินคิดราคาปกติ)</div>
        </div>

        <!-- 6) ของแถมและจำนวน (สำหรับ free_item, bill_free_item) -->
        <div class="col-md-6" x-show="needsGiftProduct()" x-cloak>
            <label class="form-label small text-muted">ของแถม (เลือกตัวเดิม = ซื้อ 1 แถม 1) <span class="text-danger">*</span></label>
            <div class="row g-2">
                <div class="col-8">
                    <input type="hidden" name="free_product_id" x-model="items[1].product_id">
                    <button type="button" class="selected-product" :class="{ empty: !items[1].product_id }" @click="openProductPicker(1)">
                        <template x-if="!items[1].product_id">
                            <span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกของแถม</span>
                        </template>
                        <template x-if="items[1].product_id">
                            <span class="selected-product-grid">
                                <b class="selected-code" x-text="items[1].sku_code"></b>
                                <span class="selected-copy">
                                    <strong x-text="items[1].name_th"></strong>
                                    <small x-text="items[1].unit_name || 'หน่วยฐาน'"></small>
                                </span>
                                <span class="selected-change">เปลี่ยน</span>
                            </span>
                        </template>
                    </button>
                </div>
                <div class="col-4">
                    <div class="input-group">
                        <input type="number" step="0.0001" min="0.0001" name="free_qty" x-model="form.free_qty" :disabled="!needsGiftProduct()" class="form-control" placeholder="จำนวนแถม">
                        <span class="input-group-text">ชิ้น</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 7) ส่วนลด (สำหรับ discount, bill_discount) -->
        <div class="col-md-6" x-show="needsDiscount()" x-cloak>
            <label class="form-label small text-muted" x-text="type === 'bill_discount' ? 'ส่วนลดต่อบิล *' : 'ส่วนลดต่อชุดที่ซื้อครบ *'"></label>
            <div class="row g-2">
                <div class="col-4">
                    <input type="number" step="0.01" min="0.01" name="discount_value" x-model="form.discount_value" class="form-control" placeholder="เช่น 10 หรือ 50">
                </div>
                <div class="col-4">
                    <select name="discount_type" x-model="form.discount_type" class="form-select">
                        <option value="baht" x-text="type === 'bill_discount' ? 'บาทต่อบิล' : 'บาทต่อชุด'"></option>
                        <option value="percent" x-text="type === 'bill_discount' ? '% ของยอดบิล' : '% ของราคาชุด'"></option>
                    </select>
                </div>
                <div class="col-4" x-show="type === 'bill_discount'">
                    <input type="number" step="0.01" min="0.01" name="max_discount" x-model="form.max_discount" class="form-control" placeholder="ลดสูงสุด (บาท)">
                </div>
            </div>
        </div>

        <!-- 8) ราคาแลกซื้อและจำนวนสิทธิ์ (สำหรับ bill_addon) -->
        <div class="col-md-5" x-show="type === 'bill_addon'" x-cloak>
            <label class="form-label small text-muted">ราคาแลกซื้อพิเศษ / จำนวนสิทธิ์ต่อบิล <span class="text-danger">*</span></label>
            <div class="row g-2">
                <div class="col-7">
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" name="bundle_price" x-model="form.bundle_price" :disabled="type !== 'bill_addon'" class="form-control" placeholder="เช่น 19">
                        <span class="input-group-text">บาท</span>
                    </div>
                </div>
                <div class="col-5">
                    <div class="input-group">
                        <input type="number" step="1" min="1" name="free_qty" x-model="form.free_qty" :disabled="type !== 'bill_addon'" class="form-control" placeholder="จำนวนสิทธิ์">
                        <span class="input-group-text">สิทธิ์</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- คำอธิบายแม่แบบที่เลือก -->
        <div class="col-12">
            <div class="promo-preview">
                <i class="bi bi-lightbulb-fill text-warning me-1"></i>
                <span class="fw-semibold me-1" x-text="currentCard().title + ':'"></span>
                <span x-text="currentCard().explain"></span>
            </div>
        </div>

        <!-- วันและเวลา เริ่ม/สิ้นสุด -->
        <div class="col-md-3">
            <label class="form-label small text-muted">เริ่มใช้งาน (วันและเวลา)</label>
            <input type="datetime-local" name="starts_at" x-model="form.starts_at" class="form-control">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สิ้นสุด (วันและเวลา)</label>
            <input type="datetime-local" name="ends_at" x-model="form.ends_at" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted">หมายเหตุ / เงื่อนไขเพิ่มเติม</label>
            <input name="note" x-model="form.note" class="form-control" placeholder="เช่น เฉพาะวันธรรมดา หรือ จำกัด 1 สิทธิ์/ใบเสร็จ">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button class="btn btn-primary w-100 fw-semibold"><i class="bi bi-check-circle me-1"></i> บันทึกสร้างแคมเปญ</button>
        </div>
    </form>
    @include('partials.product-picker')
</div>

<div class="content-card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0">รายการแคมเปญโปรโมชัน</h2>
        <span class="badge text-bg-light border text-secondary">ทั้งหมด {{ $promotions->total() }} แคมเปญ</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle table-hover">
            <thead>
                <tr>
                    <th style="width: 110px;">รหัส</th>
                    <th>ชื่อแคมเปญ</th>
                    <th>ประเภท / สินค้า</th>
                    <th>เงื่อนไข</th>
                    <th>ของแถม / แลกซื้อ</th>
                    <th>สาขา</th>
                    <th>ช่วงเวลาเริ่ม - สิ้นสุด</th>
                    <th class="text-center" style="width: 100px;">สถานะ</th>
                    <th class="text-end" style="width: 130px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
            @forelse($promotions as $promo)
                @php
                    $copyPayload = [
                        'name' => $promo->name,
                        'promo_type' => $promo->promo_type,
                        'product_id' => $promo->product_id,
                        'product' => $promo->product ? [
                            'id' => $promo->product->id,
                            'sku_code' => $promo->product->sku_code,
                            'name_th' => $promo->product->name_th,
                            'unit_name' => $promo->product->baseUnit?->name_th ?? '',
                        ] : null,
                        'free_product_id' => $promo->free_product_id,
                        'free_product' => $promo->freeProduct ? [
                            'id' => $promo->freeProduct->id,
                            'sku_code' => $promo->freeProduct->sku_code,
                            'name_th' => $promo->freeProduct->name_th,
                            'unit_name' => $promo->freeProduct->baseUnit?->name_th ?? '',
                        ] : null,
                        'group_products' => $promo->groupProducts->map(fn ($p) => [
                            'id' => $p->id,
                            'sku_code' => $p->sku_code,
                            'name_th' => $p->name_th,
                            'unit_name' => '',
                        ])->values()->all(),
                        'min_qty' => $promo->min_qty ? (float) $promo->min_qty : null,
                        'min_amount' => $promo->min_amount ? (float) $promo->min_amount : null,
                        'free_qty' => $promo->free_qty ? (float) $promo->free_qty : 1,
                        'discount_type' => $promo->discount_type ?? 'baht',
                        'discount_value' => $promo->discount_value ? (float) $promo->discount_value : null,
                        'max_discount' => $promo->max_discount ? (float) $promo->max_discount : null,
                        'bundle_price' => $promo->bundle_price !== null ? (float) $promo->bundle_price : null,
                        'branch_id' => $promo->branch_id ? (string) $promo->branch_id : '',
                        'starts_at' => $promo->starts_at?->format('Y-m-d\TH:i'),
                        'ends_at' => $promo->ends_at?->format('Y-m-d\TH:i'),
                        'note' => $promo->note ?? '',
                    ];
                @endphp
                <tr>
                    <td class="fw-semibold text-primary font-monospace">{{ $promo->code }}</td>
                    <td>
                        <div class="fw-medium">{{ $promo->name }}</div>
                        @if($promo->note)
                            <small class="text-muted">{{ $promo->note }}</small>
                        @endif
                    </td>
                    <td class="small">
                        @if($promo->groupProducts->isNotEmpty())
                            <span class="badge text-bg-light border text-dark">ซื้อคละ {{ $promo->groupProducts->count() }} ตัว:</span>
                            <span class="text-muted">{{ $promo->groupProducts->pluck('sku_code')->implode(', ') }}</span>
                        @elseif($promo->promo_type === 'bill_addon')
                            <span class="badge text-bg-light border text-dark">แลกซื้อ:</span> {{ $promo->product?->sku_code }} {{ $promo->product?->name_th }}
                        @elseif($promo->isBillLevel())
                            <span class="badge text-bg-light border text-primary">ทั้งบิล</span>
                        @else
                            {{ $promo->product?->sku_code }} {{ $promo->product?->name_th }}
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ in_array($promo->promo_type, ['free_item', 'bill_free_item'], true) ? 'text-bg-success' : ($promo->promo_type === 'bundle_price' ? 'text-bg-primary' : 'text-bg-warning text-dark') }}">
                            {{ $promo->label() }}
                        </span>
                    </td>
                    <td class="small">
                        @if($promo->freeProduct)
                            <span class="text-success"><i class="bi bi-gift me-1"></i>{{ $promo->freeProduct->sku_code }} {{ $promo->freeProduct->name_th }}</span>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td class="small">{{ $promo->branch?->name_th ?? 'ทุกสาขา' }}</td>
                    <td class="small font-monospace">
                        <div>{{ $promo->starts_at?->format('d/m/Y H:i') ?? 'ไม่ระบุ' }}</div>
                        <div class="text-muted">ถึง {{ $promo->ends_at?->format('d/m/Y H:i') ?? 'ไม่จำกัด' }}</div>
                    </td>
                    <td class="text-center">
                        <span class="badge {{ $promo->statusBadgeClass() }}">{{ $promo->statusLabel() }}</span>
                    </td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="copyPromotion(@js($copyPayload))" title="คัดลอกแคมเปญนี้เพื่อใช้อีกครั้ง">
                                <i class="bi bi-arrow-repeat"></i> ใช้อีกครั้ง
                            </button>
                            @include('partials.safe-delete', ['type' => 'qty-promotions', 'id' => $promo->id])
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5">ยังไม่มีแคมเปญโปรโมชัน</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $promotions->links() }}
</div>
@endsection

@push('head')<style>
[x-cloak]{display:none!important}
.promo-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.promo-card{display:flex;flex-direction:column;gap:4px;padding:12px 14px;border:1px solid #d6e0e6;border-radius:10px;background:#fff;text-align:left;transition:all .15s ease}
.promo-card strong{font-size:14px;color:#1d3b52;line-height:1.3}
.promo-card small{font-size:12px;color:#5a6e7d}
.promo-card i{font-size:16px;color:#6c8293}
.promo-card:hover{border-color:#1585c0;background:#eef4f9}
.promo-card.active{border:2px solid #1585c0;background:#eef4f9;box-shadow:0 0 0 3px rgba(21,133,192,.12)}
.promo-card.active strong{color:#0c5984}
.promo-card.active i{color:#1585c0}
.promo-group{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px;border:1px dashed #b8c8d4;border-radius:8px;min-height:50px;background:#fafbfc}
.promo-chip{display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;background:#e2edf5;color:#18425f;font-size:13px;border:1px solid #c5d8e7}
.promo-chip button{border:0;background:none;font-size:16px;line-height:1;color:#c9212d;cursor:pointer;padding:0 2px}
.promo-preview{padding:10px 14px;border-left:4px solid #1585c0;background:#f3f7fb;color:#1d3b52;font-size:13.5px;border-radius:0 6px 6px 0}
</style>@endpush

@push('scripts')
<script>
function qtyPromotionProductPage() {
    return {
        ...erpProductPicker(),
        type: 'bundle_price',
        form: {
            code: '',
            name: '',
            branch_id: '',
            min_qty: 3,
            min_amount: '',
            bundle_price: 100,
            discount_type: 'baht',
            discount_value: '',
            max_discount: '',
            free_qty: 1,
            starts_at: '',
            ends_at: '',
            note: '',
            is_active: true,
        },
        promoCards: [
            { type: 'bundle_price', title: 'ซื้อครบ X ชิ้น ราคาเหลือ Y บาท', example: 'เช่น 3 แพ็ค 100 บาท', icon: 'bi-tags-fill', explain: 'ซื้อสินค้าเดียวกันครบ X ชิ้น จ่ายราคาชุดละ Y บาท ส่วนที่เกินคิดราคาปกติ' },
            { type: 'free_item', title: 'ซื้อ X แถม Y (1 แถม 1)', example: 'เช่น ซื้อ 1 แถม 1, 2 แถม 1', icon: 'bi-gift-fill', explain: 'ลูกค้าซื้อสินค้าครบ X ชิ้น ได้รับสินค้าของแถม Y ชิ้น (ระบบใส่ของแถมให้ราคา 0 บาท)' },
            { type: 'discount', title: 'ซื้อครบ X ชิ้น ลด % / บาท', example: 'เช่น ซื้อ 2 ชิ้น ลด 10 บาท / 10%', icon: 'bi-percent', explain: 'ซื้อสินค้าครบ X ชิ้น ได้รับส่วนลดเป็นจำนวนบาท หรือส่วนลดเป็น % ต่อชุด' },
            { type: 'mix_bundle', title: 'เลือกซื้อคละครบ X ชิ้น (ราคาเหลือ/ลด)', example: 'เช่น คละรส 3 ถุง 100 บาท', icon: 'bi-collection-fill', explain: 'หยิบสินค้าในกลุ่มที่กำหนดตัวไหนก็ได้รวมกันครบ X ชิ้น คิดราคาชุดพิเศษ' },
            { type: 'bill_discount', title: 'ซื้อครบยอด X บาท ลด', example: 'เช่น ครบ 1,000 บาท ลด 50 บาท', icon: 'bi-receipt-cutoff', explain: 'ยอดซื้อรวมทั้งบิลถึงขั้นต่ำ ได้รับส่วนลดท้ายบิลเป็นบาท หรือ % (ตั้งเพดานลดสูงสุดได้)' },
            { type: 'bill_free_item', title: 'ซื้อครบยอด X บาท ได้ของแถม', example: 'เช่น ครบ 500 บาท แถมน้ำ 1 ขวด', icon: 'bi-basket-fill', explain: 'ยอดซื้อรวมทั้งบิลถึงขั้นต่ำ ได้รับสินค้าของแถมราคา 0 บาท' },
            { type: 'bill_addon', title: 'ซื้อครบยอด X บาท แลกซื้อ', example: 'เช่น ครบ 300 บาท แลกซื้อ 19 บาท', icon: 'bi-cart-check-fill', explain: 'ยอดซื้อรวมทั้งบิลถึงขั้นต่ำ ได้รับสิทธิ์แลกซื้อสินค้าราคาพิเศษตามจำนวนที่กำหนด' },
        ],
        currentCard() {
            return this.promoCards.find((card) => card.type === this.type) || this.promoCards[0];
        },
        selectCard(cardType) {
            this.type = cardType;
            if (cardType === 'bundle_price') {
                if (!this.form.min_qty) this.form.min_qty = 3;
                if (!this.form.bundle_price) this.form.bundle_price = 100;
            } else if (cardType === 'free_item') {
                if (!this.form.min_qty) this.form.min_qty = 1;
                if (!this.form.free_qty) this.form.free_qty = 1;
            } else if (cardType === 'discount') {
                if (!this.form.min_qty) this.form.min_qty = 2;
                if (!this.form.discount_value) this.form.discount_value = 10;
            } else if (cardType === 'mix_bundle') {
                if (!this.form.min_qty) this.form.min_qty = 3;
                if (!this.form.bundle_price) this.form.bundle_price = 100;
            } else if (cardType === 'bill_discount') {
                if (!this.form.min_amount) this.form.min_amount = 1000;
                if (!this.form.discount_value) this.form.discount_value = 50;
            } else if (cardType === 'bill_free_item') {
                if (!this.form.min_amount) this.form.min_amount = 500;
                if (!this.form.free_qty) this.form.free_qty = 1;
            } else if (cardType === 'bill_addon') {
                if (!this.form.min_amount) this.form.min_amount = 300;
                if (!this.form.bundle_price) this.form.bundle_price = 19;
                if (!this.form.free_qty) this.form.free_qty = 1;
            }
        },
        isMix() { return this.type === 'mix_bundle'; },
        isBill() { return this.type === 'bill_discount' || this.type === 'bill_free_item' || this.type === 'bill_addon'; },
        needsSingleProduct() { return this.type === 'bundle_price' || this.type === 'free_item' || this.type === 'discount' || this.type === 'bill_addon'; },
        needsMinQty() { return this.type === 'bundle_price' || this.type === 'free_item' || this.type === 'discount' || this.type === 'mix_bundle'; },
        needsGiftProduct() { return this.type === 'free_item' || this.type === 'bill_free_item'; },
        needsDiscount() { return this.type === 'discount' || this.type === 'bill_discount'; },
        items: [
            { product_id: '', sku_code: '', name_th: '', unit_name: '' },
            { product_id: '', sku_code: '', name_th: '', unit_name: '' },
        ],
        copyPromotion(data) {
            this.type = data.promo_type || 'bundle_price';
            this.form.code = ''; // เว้นว่างเพื่อให้ระบบ AutoCode รันรหัสใหม่ ไม่ให้ชนของเดิม
            this.form.name = data.name ? data.name + ' (คัดลอก)' : '';
            this.form.branch_id = data.branch_id || '';
            this.form.min_qty = data.min_qty ?? 1;
            this.form.min_amount = data.min_amount ?? '';
            this.form.bundle_price = data.bundle_price ?? '';
            this.form.discount_type = data.discount_type || 'baht';
            this.form.discount_value = data.discount_value ?? '';
            this.form.max_discount = data.max_discount ?? '';
            this.form.free_qty = data.free_qty ?? 1;
            this.form.starts_at = data.starts_at || '';
            this.form.ends_at = data.ends_at || '';
            this.form.note = data.note || '';
            this.form.is_active = true;

            this.items = [
                data.product ? { product_id: data.product.id, sku_code: data.product.sku_code, name_th: data.product.name_th, unit_name: data.product.unit_name || '' } : { product_id: '', sku_code: '', name_th: '', unit_name: '' },
                data.free_product ? { product_id: data.free_product.id, sku_code: data.free_product.sku_code, name_th: data.free_product.name_th, unit_name: data.free_product.unit_name || '' } : { product_id: '', sku_code: '', name_th: '', unit_name: '' },
            ];

            if (Array.isArray(data.group_products) && data.group_products.length > 0) {
                data.group_products.forEach(p => {
                    this.items.push({ product_id: p.id, sku_code: p.sku_code, name_th: p.name_th, unit_name: p.unit_name || '' });
                });
            }

            const formEl = document.getElementById('create-promo-form');
            if (formEl) {
                formEl.scrollIntoView({ behavior: 'smooth' });
            }
            window.erpToast?.('คัดลอกข้อมูลแคมเปญเรียบร้อยแล้ว สามารถปรับแก้ไขแล้วบันทึกเป็นแคมเปญใหม่ได้ทันที', 'info');
        },
        validateProducts(event) {
            if (this.isMix() && this.items.slice(2).filter((item) => item.product_id).length < 2) {
                event.preventDefault();
                window.erpToast?.('ซื้อคละต้องเลือกสินค้าในกลุ่มอย่างน้อย 2 ตัว', 'warning');
            } else if (this.needsSingleProduct() && !this.items[0].product_id) {
                event.preventDefault();
                this.openProductPicker(0);
                window.erpToast?.(this.type === 'bill_addon' ? 'เลือกสินค้าที่ให้แลกซื้อก่อนบันทึก' : 'เลือกสินค้าที่ต้องซื้อก่อนบันทึก', 'warning');
            } else if (this.needsGiftProduct() && !this.items[1].product_id) {
                event.preventDefault();
                this.openProductPicker(1);
                window.erpToast?.('เลือกสินค้าของแถมก่อนบันทึกแคมเปญ', 'warning');
            }
        },
    };
}
</script>
@endpush
