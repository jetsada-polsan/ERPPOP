@extends('layout')
@section('title', 'การผลิต - POPSTAR ERP')
@section('page-title', 'การผลิต')
@section('page-subtitle', 'สูตรผลิต ใบสั่งผลิต และสถานะงานผลิต')
@section('content')
<div x-data="{ openRecipe: null }" x-init="const m = location.hash.match(/^#recipe-(\d+)/); if (m) openRecipe = parseInt(m[1])" x-cloak>
<div class="row g-3 mb-3">
    <div class="col-12 col-xl-5">
        <div class="content-card p-4 h-100">
            <h2 class="h5 fw-bold mb-3">เพิ่มสูตรผลิต / แปรรูป</h2>
            <form method="post" action="{{ route('production.recipes.store') }}" class="row g-3">
                @csrf
                <div class="col-md-4"><label class="form-label small text-muted">รหัสสูตร</label><input name="code" required class="form-control"></div>
                <div class="col-md-8"><label class="form-label small text-muted">ชื่อสูตร</label><input name="name" required class="form-control"></div>
                <div class="col-12">
                    <label class="form-label small text-muted">สินค้าสำเร็จรูป</label>
                    <select name="finished_product_id" required class="form-select">
                        <option value="">-- เลือกสินค้า --</option>
                        @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label small text-muted">จำนวนที่ได้</label><input type="number" step="0.0001" min="0.0001" name="output_qty" value="1" required class="form-control"></div>
                <div class="col-md-8"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control"></div>
                <div class="col-12 d-flex justify-content-between align-items-center">
                    <label class="form-check"><input type="checkbox" name="is_active" value="1" checked class="form-check-input"> ใช้งาน</label>
                    <button class="btn btn-primary px-4"><i class="bi bi-plus-lg me-1"></i> เพิ่มสูตร</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="content-card p-4 h-100">
            <h2 class="h5 fw-bold mb-3">เปิดใบสั่งผลิต</h2>
            <form method="post" action="{{ route('production.orders.store') }}" class="row g-3">
                @csrf
                <div class="col-md-4"><label class="form-label small text-muted">เลขที่เอกสาร</label><input name="doc_no" value="PD{{ now()->format('ymdHis') }}" required class="form-control"></div>
                <div class="col-md-4"><label class="form-label small text-muted">วันที่</label><input type="date" name="doc_date" value="{{ now()->toDateString() }}" required class="form-control"></div>
                <div class="col-md-4"><label class="form-label small text-muted">สถานะ</label><select name="status" class="form-select"><option value="planned">วางแผน</option><option value="released">เปิดงาน</option><option value="completed">เสร็จแล้ว</option></select></div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">สูตรผลิต</label>
                    <select name="production_recipe_id" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($recipes as $recipe)<option value="{{ $recipe->id }}">{{ $recipe->code }} - {{ $recipe->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">สินค้าสำเร็จรูป</label>
                    <select name="finished_product_id" required class="form-select">
                        <option value="">-- เลือกสินค้า --</option>
                        @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku_code }} - {{ $product->name_th }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">สาขา</label>
                    <select name="branch_id" class="form-select"><option value="">-- ไม่ระบุ --</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>@endforeach</select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">รับเข้าที่เก็บ</label>
                    <select name="warehouse_location_id" class="form-select"><option value="">-- ไม่ระบุ --</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->warehouse?->code }} / {{ $location->code }} - {{ $location->name }}</option>@endforeach</select>
                </div>
                <div class="col-md-2"><label class="form-label small text-muted">แผนผลิต</label><input type="number" step="0.0001" min="0.0001" name="planned_qty" value="1" required class="form-control"></div>
                <div class="col-md-2"><label class="form-label small text-muted">ผลิตแล้ว</label><input type="number" step="0.0001" min="0" name="produced_qty" value="0" class="form-control"></div>
                <div class="col-12"><input name="note" class="form-control" placeholder="หมายเหตุ"></div>
                <div class="col-12 text-end"><button class="btn btn-primary px-4">เปิดใบสั่งผลิต</button></div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">สูตรผลิต</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>รหัส</th><th>สูตร</th><th>สินค้า</th><th class="text-end">จำนวนที่ได้</th><th>สถานะ</th><th>วัตถุดิบ</th></tr></thead>
                    <tbody>
                    @forelse($recipes as $recipe)
                        <tr>
                            <td class="fw-semibold">{{ $recipe->code }}</td>
                            <td>{{ $recipe->name }}</td>
                            <td>{{ $recipe->finishedProduct?->sku_code }}</td>
                            <td class="text-end">{{ number_format((float) $recipe->output_qty, 4) }}</td>
                            <td><span class="badge {{ $recipe->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $recipe->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                            <td><button type="button" class="btn btn-sm btn-light border" @click="openRecipe = {{ $recipe->id }}"><i class="bi bi-list-check me-1"></i>{{ $recipe->items_count }} รายการ</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีสูตรผลิต</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $recipes->links() }}
        </div>
    </div>
    <div class="col-12 col-xl-7">
        <div class="content-card p-4">
            <h2 class="h5 fw-bold mb-3">ใบสั่งผลิต</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>เลขที่</th><th>วันที่</th><th>สินค้า</th><th class="text-end">แผน</th><th class="text-end">ผลิตแล้ว</th><th>สถานะ</th><th style="width:190px">รับเข้าคลัง (IP)</th></tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td class="fw-semibold">{{ $order->doc_no }}</td>
                            <td>{{ $order->doc_date?->thaiDate() }}</td>
                            <td>{{ $order->finishedProduct?->sku_code }} - {{ $order->finishedProduct?->name_th }}</td>
                            <td class="text-end">{{ number_format((float) $order->planned_qty, 4) }}</td>
                            <td class="text-end">{{ number_format((float) $order->produced_qty, 4) }}</td>
                            <td><span class="badge {{ $order->status === 'completed' ? 'text-bg-success' : 'text-bg-light border' }}">{{ $order->status === 'completed' ? 'ปิดงานแล้ว' : $order->status }}</span></td>
                            <td>
                                @if($order->status !== 'completed')
                                <form method="post" action="{{ route('production.orders.receive', $order) }}" class="d-flex gap-1"
                                    onsubmit="return confirm('รับสินค้าเข้าคลังตามจำนวนที่ระบุ?')">
                                    @csrf
                                    <input type="number" step="0.0001" min="0.0001" name="qty" class="form-control form-control-sm text-end"
                                        value="{{ max(0, (float) $order->planned_qty - (float) $order->produced_qty) }}" style="width:90px" required>
                                    <button class="btn btn-sm btn-success text-nowrap"><i class="bi bi-box-arrow-in-down"></i> รับ</button>
                                </form>
                                @else
                                <span class="text-muted small">ครบแล้ว</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีใบสั่งผลิต</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        </div>
    </div>
</div>

{{-- โมดัลรายการวัตถุดิบต่อสูตร (BOM) --}}
@foreach($recipes as $recipe)
    @php
        $recipeCost = $recipe->items->sum(fn ($i) => (float) $i->qty * (float) ($i->product->average_cost ?? 0));
        $unitCost = (float) $recipe->output_qty > 0 ? $recipeCost / (float) $recipe->output_qty : 0;
    @endphp
<div class="booking-modal-backdrop" x-show="openRecipe === {{ $recipe->id }}" x-transition.opacity @keydown.escape.window="openRecipe = null" @click.self="openRecipe = null">
    <div class="booking-modal recipe-bom-modal" style="width:min(680px,100%)" @click.outside="openRecipe = null">
        <div class="modal-header border-0 px-4 pt-4 pb-3">
            <div class="d-flex align-items-center gap-3">
                <span class="recipe-modal-icon"><i class="bi bi-egg-fried"></i></span>
                <div>
                    <h3 class="h5 fw-bold mb-1">วัตถุดิบในสูตร {{ $recipe->code }}</h3>
                    <span class="recipe-output-chip">
                        <i class="bi bi-box-seam-fill me-1"></i>
                        ผลิต <strong>{{ $recipe->finishedProduct?->sku_code }}</strong> {{ $recipe->finishedProduct?->name_th }}
                        ได้ {{ number_format((float) $recipe->output_qty, 4) }} หน่วย/รอบ
                    </span>
                </div>
            </div>
            <button type="button" class="btn btn-light rounded-circle" @click="openRecipe = null"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body px-4 pb-4">
            <div class="table-responsive recipe-bom-table mb-3">
                <table class="table align-middle mb-0">
                    <thead><tr><th>วัตถุดิบ</th><th class="text-end" style="width:110px">จำนวน/รอบ</th><th class="text-end" style="width:110px">ต้นทุน</th><th>เงื่อนไขของเสีย</th><th style="width:44px"></th></tr></thead>
                    <tbody>
                    @forelse($recipe->items as $item)
                        @php($lineCost = (float) $item->qty * (float) ($item->product->average_cost ?? 0))
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="recipe-item-dot"></span>
                                    <div>
                                        <div class="fw-semibold">{{ $item->product?->sku_code }}</div>
                                        <div class="text-muted small">{{ $item->product?->name_th }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $item->qty, 4) }}</td>
                            <td class="text-end small {{ $lineCost > 0 ? 'text-muted' : 'text-secondary opacity-50' }}">{{ $lineCost > 0 ? '฿'.number_format($lineCost, 2) : '-' }}</td>
                            <td class="small text-muted">{{ $item->scrap_policy ?: '-' }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('production.recipes.items.destroy', $item) }}" onsubmit="return confirm('ลบวัตถุดิบนี้ออกจากสูตร?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-light text-danger rounded-circle" title="ลบ"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีวัตถุดิบในสูตรนี้</td></tr>
                    @endforelse
                    </tbody>
                    @if($recipe->items->isNotEmpty())
                    <tfoot>
                        <tr class="recipe-bom-total">
                            <td class="fw-bold">ต้นทุนวัตถุดิบรวม/รอบ</td>
                            <td></td>
                            <td class="text-end fw-bold text-success">{{ $recipeCost > 0 ? '฿'.number_format($recipeCost, 2) : '-' }}</td>
                            <td colspan="2" class="small text-muted">{{ $recipeCost > 0 ? '≈ ฿'.number_format($unitCost, 2).'/หน่วยผลผลิต' : 'ยังไม่มีต้นทุนวัตถุดิบ (ยังไม่เคยรับของเข้า)' }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>

            <div class="recipe-add-panel" x-data="{ query: '', productId: '', open: false, results: [], timer: null,
                search() {
                    this.productId = '';
                    clearTimeout(this.timer);
                    const q = this.query.trim();
                    if (!q) { this.results = []; this.open = false; return; }
                    this.timer = setTimeout(async () => {
                        try {
                            const response = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(q)}`);
                            const products = response.ok ? await response.json() : [];
                            this.results = products.filter(p => Number(p.id) !== {{ (int) $recipe->finished_product_id }}).slice(0, 8);
                            this.open = true;
                        } catch (_) { this.results = []; this.open = false; }
                    }, 200);
                },
                pick(p) { this.productId = p.id; this.query = p.sku_code + ' - ' + p.name_th; this.open = false; }
            }">
                <div class="recipe-add-panel-title"><i class="bi bi-plus-circle-fill me-1"></i>เพิ่มวัตถุดิบ</div>
                <form method="post" action="{{ route('production.recipes.items.store', $recipe) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-12 col-md-6 position-relative">
                        <label class="form-label small text-muted">วัตถุดิบ</label>
                        <input type="hidden" name="product_id" x-model="productId">
                        <input type="text" x-model="query" @input="search()" @focus="open = results.length > 0"
                            placeholder="ค้นหารหัส/ชื่อสินค้า" class="form-control form-control-sm" autocomplete="off" required>
                        <div class="typeahead-list" x-show="open && results.length" @click.outside="open = false" x-transition>
                            <template x-for="p in results" :key="p.id">
                                <button type="button" class="typeahead-item" @click="pick(p)">
                                    <span class="fw-semibold" x-text="p.sku_code"></span><span x-text="p.name_th"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small text-muted">จำนวน/รอบ</label>
                        <input type="number" step="0.0001" min="0.0001" name="qty" required class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-3">
                        <button class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg"></i> เพิ่ม</button>
                    </div>
                    <div class="col-12">
                        <input type="text" name="scrap_policy" class="form-control form-control-sm" placeholder="เงื่อนไขของเสีย (ไม่บังคับ) เช่น หัก 5%">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endforeach
</div>

@endsection

@push('head')
<style>
    /* หน้านี้ยังไม่เคยใช้ booking-modal มาก่อน - layout.blade.php มีแค่สีพื้นหลัง/เงา
       ต้องประกาศ position:fixed เองที่นี่ (ตามแบบที่ stock-transforms/bookings ทำไว้) */
    .booking-modal-backdrop { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .booking-modal { max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }

    .recipe-modal-icon {
        width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center;
        border-radius: 12px; font-size: 19px; color: #fff;
        background: linear-gradient(135deg, #1a9bdc, #20a67a);
        box-shadow: 0 8px 18px rgba(26,155,220,.28);
    }
    .recipe-output-chip {
        display: inline-flex; align-items: center; margin-top: 2px;
        padding: 4px 10px; border-radius: 999px; background: #eef7fc; color: #1b6f97;
        font-size: 12px; font-weight: 600;
    }
    .recipe-bom-table { border: 1px solid #e7eef3; border-radius: 12px; overflow: hidden; }
    .recipe-bom-table .table { margin-bottom: 0; }
    .recipe-item-dot { width: 8px; height: 8px; border-radius: 50%; background: #20a67a; flex: 0 0 8px; }
    .recipe-bom-total td { background: #f7fbfe; border-top: 2px solid #dbe7ef; padding-top: 10px; padding-bottom: 10px; }
    .recipe-add-panel {
        border: 1px dashed #bcdcee; border-radius: 12px; background: #fbfeff; padding: 14px 16px;
    }
    .recipe-add-panel-title { font-size: 13px; font-weight: 800; color: #1b6f97; margin-bottom: 10px; }
    .recipe-bom-modal .typeahead-list {
        position: absolute; z-index: 2050; left: 0; right: 0; top: calc(100% + 4px); max-height: 220px; overflow: auto;
        background: #fff; border: 1px solid #dbe1ea; border-radius: 12px; box-shadow: 0 14px 36px rgba(15,23,42,.14); padding: 6px;
    }
    .recipe-bom-modal .typeahead-item {
        width: 100%; border: 0; background: transparent; border-radius: 9px; padding: 9px 10px;
        display: flex; align-items: center; gap: 10px; text-align: left;
    }
    .recipe-bom-modal .typeahead-item:hover { background: #f2f6ff; }
</style>
@endpush
