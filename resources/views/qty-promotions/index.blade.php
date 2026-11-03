@extends('layout')
@section('title', 'แคมเปญซื้อครบ - MPM Food Group')
@section('page-title', 'แคมเปญซื้อครบ')
@section('page-subtitle', 'ซื้อครบได้ของแถม ลดราคา ราคาชุด เช่น 3 ชิ้น 100 บาท หรือโปรต่อบิล เช่น ยอดครบ 1,000 ลด 50 บาท')
@section('content')
<div class="content-card p-4 mb-3" x-data="qtyPromotionProductPage()">
    <h2 class="h5 fw-bold mb-3">สร้างแคมเปญ</h2>
    <form method="post" action="{{ route('qty-promotions.store') }}" class="row g-3" @submit="validateProducts($event)">
        @csrf
        <div class="col-md-2"><label class="form-label small text-muted">รหัส</label><input placeholder="เว้นว่างได้ ระบบรันให้" name="code" class="form-control"></div>
        <div class="col-md-4"><label class="form-label small text-muted">ชื่อแคมเปญ</label><input name="name" required class="form-control" placeholder="เช่น ปลาหมึกซื้อ 1 แถม 1"></div>
        <div class="col-12">
            <label class="form-label small text-muted">เลือกแบบโปรโมชั่น</label>
            <input type="hidden" name="promo_type" :value="type">
            <div class="promo-cards">
                <template x-for="card in promoCards" :key="card.type">
                    <button type="button" class="promo-card" :class="{ active: type === card.type }" @click="type = card.type">
                        <strong x-text="card.title"></strong><small x-text="card.example"></small>
                    </button>
                </template>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">สาขา</label>
            <select name="branch_id" class="form-select">
                <option value="">-- ทุกสาขา --</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3" x-show="isBill()" x-cloak>
            <label class="form-label small text-muted">ยอดซื้อขั้นต่ำต่อบิล</label>
            <div class="input-group"><input type="number" step="0.01" min="0.01" name="min_amount" class="form-control" placeholder="เช่น 1000"><span class="input-group-text">บาท</span></div>
        </div>
        <div class="col-md-7" x-show="isMix()" x-cloak>
            <label class="form-label small text-muted">สินค้าในกลุ่ม (หยิบตัวไหนก็นับรวม เลือกอย่างน้อย 2 ตัว)</label>
            <div class="promo-group">
                <template x-for="(item, index) in items.slice(2)" :key="index">
                    <span class="promo-chip" x-show="item.product_id"><input type="hidden" name="product_ids[]" :value="item.product_id"><b x-text="item.sku_code"></b> <span x-text="item.name_th"></span> <button type="button" @click="items.splice(index + 2, 1)">×</button></span>
                </template>
                <button type="button" class="btn btn-outline-primary btn-sm" @click="items.push({ product_id: '', sku_code: '', name_th: '', unit_name: '' }); openProductPicker(items.length - 1)"><i class="bi bi-plus-lg"></i> เพิ่มสินค้า</button>
            </div>
        </div>
        <div class="col-md-5" x-show="! isBill() && ! isMix() || type === 'bill_addon'">
            <label class="form-label small text-muted" x-text="type === 'bill_addon' ? 'สินค้าที่ให้แลกซื้อ' : 'สินค้าที่ต้องซื้อ'"></label>
            <input type="hidden" name="product_id" x-model="items[0].product_id">
            <button type="button" class="selected-product" :class="{ empty: !items[0].product_id }" @click="openProductPicker(0)">
                <template x-if="!items[0].product_id"><span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกสินค้า</span></template>
                <template x-if="items[0].product_id"><span class="selected-product-grid"><b class="selected-code" x-text="items[0].sku_code"></b><span class="selected-copy"><strong x-text="items[0].name_th"></strong><small x-text="items[0].unit_name || 'หน่วยฐาน'"></small></span><span class="selected-change">เปลี่ยน</span></span></template>
            </button>
        </div>
        <div class="col-md-2" x-show="! isBill()"><label class="form-label small text-muted" x-text="type === 'mix_free' ? 'ซื้อ (ชิ้น)' : 'ซื้อครบ (จำนวน)'"></label><input type="number" step="0.0001" min="0.0001" name="min_qty" :required="! isBill()" class="form-control" value="1"></div>

        <div class="col-md-3" x-show="type === 'mix_free'" x-cloak><label class="form-label small text-muted">แถม (ชิ้นที่ถูกที่สุด)</label><input type="number" step="1" min="1" name="free_qty" :disabled="type !== 'mix_free'" class="form-control" value="1"></div>
        <div class="col-md-5" x-show="type === 'bill_addon'" x-cloak>
            <label class="form-label small text-muted">ราคาแลกซื้อ / จำนวนที่แลกได้ต่อบิล</label>
            <div class="row g-2"><div class="col-6"><div class="input-group"><input type="number" step="0.01" min="0" name="bundle_price" :disabled="type !== 'bill_addon'" class="form-control" placeholder="เช่น 19"><span class="input-group-text">บาท</span></div></div>
            <div class="col-6"><input type="number" step="1" min="1" name="free_qty" :disabled="type !== 'bill_addon'" class="form-control" value="1" placeholder="จำนวน"></div></div>
        </div>
        <div class="col-md-5" x-show="type === 'free_item' || type === 'bill_free_item'">
            <label class="form-label small text-muted">ของแถม (เลือกตัวเดียวกัน = ซื้อ 1 แถม 1)</label>
            <div class="row g-2">
                <div class="col-8">
                    <input type="hidden" name="free_product_id" x-model="items[1].product_id">
                    <button type="button" class="selected-product" :class="{ empty: !items[1].product_id }" @click="openProductPicker(1)">
                        <template x-if="!items[1].product_id"><span class="selected-empty"><i class="bi bi-search"></i> ค้นหาและเลือกของแถม</span></template>
                        <template x-if="items[1].product_id"><span class="selected-product-grid"><b class="selected-code" x-text="items[1].sku_code"></b><span class="selected-copy"><strong x-text="items[1].name_th"></strong><small x-text="items[1].unit_name || 'หน่วยฐาน'"></small></span><span class="selected-change">เปลี่ยน</span></span></template>
                    </button>
                </div>
                <div class="col-4"><input type="number" step="0.0001" min="0.0001" name="free_qty" :disabled="type !== 'free_item' && type !== 'bill_free_item'" class="form-control" placeholder="จำนวนแถม" value="1"></div>
            </div>
        </div>
        <div class="col-md-5" x-show="type === 'discount' || type === 'bill_discount'" x-cloak>
            <label class="form-label small text-muted" x-text="type === 'bill_discount' ? 'ส่วนลดต่อบิล' : 'ส่วนลดต่อชุดที่ซื้อครบ'"></label>
            <div class="row g-2">
                <div class="col-4"><input type="number" step="0.01" min="0.01" name="discount_value" class="form-control" placeholder="เช่น 50"></div>
                <div class="col-4">
                    <select name="discount_type" class="form-select">
                        <option value="baht" x-text="type === 'bill_discount' ? 'บาทต่อบิล' : 'บาทต่อชุด'"></option>
                        <option value="percent" x-text="type === 'bill_discount' ? '% ของยอดบิล' : '% ของราคาชุด'"></option>
                    </select>
                </div>
                <div class="col-4" x-show="type === 'bill_discount'"><input type="number" step="0.01" min="0.01" name="max_discount" class="form-control" placeholder="ลดสูงสุด (บาท)"></div>
            </div>
        </div>
        <div class="col-md-5" x-show="type === 'bundle_price' || type === 'mix_bundle'" x-cloak>
            <label class="form-label small text-muted">ราคาสุทธิของชุดที่ซื้อครบ</label>
            <div class="input-group">
                <input type="number" step="0.01" min="0" name="bundle_price" :disabled="type !== 'bundle_price' && type !== 'mix_bundle'" class="form-control" placeholder="เช่น 100">
                <span class="input-group-text">บาท / ชุด</span>
            </div>
            <div class="form-text">เช่น ซื้อครบ 3 ชิ้น ราคา 100 บาท ส่วนที่เกินคิดราคาปกติ</div>
        </div>

        <div class="col-12"><div class="promo-preview"><i class="bi bi-lightbulb"></i> <span x-text="currentCard().explain"></span></div></div>
        <div class="col-md-2"><label class="form-label small text-muted">เริ่ม (วันและเวลา)</label><input type="datetime-local" name="starts_at" class="form-control"></div>
        <div class="col-md-2"><label class="form-label small text-muted">สิ้นสุด (วันและเวลา)</label><input type="datetime-local" name="ends_at" class="form-control"></div>
        <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="promoActive"><label class="form-check-label" for="promoActive">ใช้งาน</label></div></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">สร้างแคมเปญ</button></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ / เงื่อนไขเพิ่มเติม"></div>
    </form>
    @include('partials.product-picker')
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">รายการแคมเปญ</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อแคมเปญ</th><th>สินค้า</th><th>เงื่อนไข</th><th>ของแถม</th><th>สาขา</th><th>ช่วงเวลา</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($promotions as $promo)
                <tr>
                    <td class="fw-semibold">{{ $promo->code }}</td>
                    <td>{{ $promo->name }}</td>
                    <td class="small">@if($promo->groupProducts->isNotEmpty()) คละ {{ $promo->groupProducts->count() }} ตัว: {{ $promo->groupProducts->pluck('sku_code')->implode(', ') }} @elseif($promo->promo_type === 'bill_addon') แลกซื้อ {{ $promo->product?->sku_code }} {{ $promo->product?->name_th }} @elseif($promo->isBillLevel()) ทั้งบิล @else {{ $promo->product?->sku_code }} {{ $promo->product?->name_th }} @endif</td>
                    <td><span class="badge {{ in_array($promo->promo_type, ['free_item', 'bill_free_item'], true) ? 'text-bg-success' : ($promo->promo_type === 'bundle_price' ? 'text-bg-primary' : 'text-bg-warning') }}">{{ $promo->label() }}</span></td>
                    <td class="small">{{ $promo->freeProduct ? $promo->freeProduct->sku_code.' '.$promo->freeProduct->name_th : '-' }}</td>
                    <td class="small">{{ $promo->branch?->name_th ?? 'ทุกสาขา' }}</td>
                    <td class="small">{{ $promo->starts_at?->format('d/m/Y H:i') ?? 'ไม่กำหนด' }} - {{ $promo->ends_at?->format('d/m/Y H:i') ?? 'ไม่กำหนด' }}</td>
                    <td><span class="badge {{ $promo->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $promo->is_active ? 'ใช้งาน' : 'ปิด' }}</span>@include('partials.safe-delete', ['type' => 'qty-promotions', 'id' => $promo->id])</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีแคมเปญซื้อครบ</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $promotions->links() }}
</div>
@endsection

@push('head')<style>
[x-cloak]{display:none!important}
.promo-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
.promo-card{display:flex;flex-direction:column;gap:4px;padding:12px 14px;border:1px solid #d6e0e6;border-radius:10px;background:#fff;text-align:left}
.promo-card strong{font-size:15px;color:#1d3b52}.promo-card small{font-size:13px;color:#627481}
.promo-card:hover{border-color:#1585c0;background:#eef4f9}
.promo-card.active{border:2px solid #1585c0;background:#eef4f9;box-shadow:0 0 0 3px rgba(21,133,192,.12)}
.promo-group{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px;border:1px dashed #b8c8d4;border-radius:8px;min-height:46px}
.promo-chip{display:inline-flex;gap:6px;align-items:center;padding:4px 8px;border-radius:999px;background:#eef4f9;font-size:13px}
.promo-chip button{border:0;background:none;font-size:16px;line-height:1;color:#c9212d}
.promo-preview{padding:10px 12px;border-left:4px solid #1585c0;background:#f3f7fb;color:#1d3b52;font-size:14px}
</style>@endpush

@push('scripts')
<script>
function qtyPromotionProductPage() {
    return {
        ...erpProductPicker(),
        type: 'free_item',
        promoCards: [
            { type: 'free_item', title: 'ซื้อ N แถม M', example: 'ซื้อ 1 แถม 1, ซื้อ 3 แถม 1', explain: 'ลูกค้าซื้อสินค้าครบจำนวน ได้ของแถมเพิ่ม (ระบบใส่ของแถมราคา 0 ให้เอง)' },
            { type: 'bundle_price', title: 'ซื้อ N ราคาชุด', example: '4 แพ็ค 100 บาท', explain: 'หยิบสินค้าเดียวกันครบชุด จ่ายราคาชุด ส่วนที่เกินคิดราคาปกติ' },
            { type: 'discount', title: 'ซื้อ N ลด', example: 'ซื้อ 2 ลด 10 บาท / 10%', explain: 'ซื้อครบชุดได้ส่วนลดต่อชุด' },
            { type: 'mix_bundle', title: 'ซื้อคละ ราคาเดียว', example: 'คละรส 3 ถุง 100 บาท', explain: 'หยิบสินค้าในกลุ่มตัวไหนก็ได้รวมกันครบจำนวน จ่ายราคาชุด (คิดจากชิ้นที่แพงก่อน)' },
            { type: 'mix_free', title: 'ซื้อคละ แถม', example: 'คละ 3 แถม 1', explain: 'หยิบสินค้าในกลุ่มครบ ซื้อ+แถม ชิ้นที่ถูกที่สุดฟรี' },
            { type: 'bill_discount', title: 'ยอดบิลครบ ลด', example: 'ครบ 1,000 ลด 50 บาท', explain: 'ยอดทั้งบิลหลังโปรสินค้าถึงขั้นต่ำ ได้ส่วนลดทั้งบิล (ลด % ตั้งเพดานได้)' },
            { type: 'bill_free_item', title: 'ยอดบิลครบ แถม', example: 'ครบ 500 แถมน้ำ 1 ขวด', explain: 'ยอดทั้งบิลถึงขั้นต่ำ ระบบใส่ของแถมราคา 0 ให้เอง' },
            { type: 'bill_addon', title: 'ยอดบิลครบ แลกซื้อ', example: 'ครบ 300 แลกซื้อน้ำมัน 19 บาท', explain: 'ยอดบิล (ไม่นับตัวที่แลก) ถึงขั้นต่ำ ลูกค้าหยิบสินค้าที่แลกได้ราคาพิเศษตามจำนวนที่กำหนด' },
        ],
        currentCard() { return this.promoCards.find((card) => card.type === this.type) || this.promoCards[0]; },
        isMix() { return this.type === 'mix_bundle' || this.type === 'mix_free'; },
        isBill() { return this.type === 'bill_discount' || this.type === 'bill_free_item' || this.type === 'bill_addon'; },
        items: [
            { product_id: '', sku_code: '', name_th: '', unit_name: '' },
            { product_id: '', sku_code: '', name_th: '', unit_name: '' },
        ],
        validateProducts(event) {
            if (this.isMix() && this.items.slice(2).filter((item) => item.product_id).length < 2) {
                event.preventDefault();
                window.erpToast?.('ซื้อคละต้องเลือกสินค้าในกลุ่มอย่างน้อย 2 ตัว', 'warning');
            } else if (((! this.isBill() && ! this.isMix()) || this.type === 'bill_addon') && ! this.items[0].product_id) {
                event.preventDefault();
                this.openProductPicker(0);
                window.erpToast?.('เลือกสินค้าที่ต้องซื้อก่อนบันทึกแคมเปญ', 'warning');
            } else if ((this.type === 'free_item' || this.type === 'bill_free_item') && ! this.items[1].product_id) {
                event.preventDefault();
                this.openProductPicker(1);
                window.erpToast?.('เลือกสินค้าแถมก่อนบันทึกแคมเปญ', 'warning');
            }
        },
    };
}
</script>
@endpush
