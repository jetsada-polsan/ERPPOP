@extends('layout')

@section('title', "{$product->sku_code} - สินค้า - POPSTAR ERP")
@section('page-title', 'รายละเอียดสินค้า')
@section('page-subtitle', $product->sku_code)

@section('content')
    @php
        $pluCodes = $product->barcodes
            ->filter(fn ($barcode) => $barcode->is_active && preg_match('/^\d{6}$/', (string) $barcode->barcode))
            ->values();
        $eanCodes = $product->barcodes
            ->filter(fn ($barcode) => $barcode->is_active && preg_match('/^\d{13}$/', (string) $barcode->barcode))
            ->values();
        $otherBarcodes = $product->barcodes
            ->reject(fn ($barcode) => preg_match('/^\d{6}$/', (string) $barcode->barcode) || preg_match('/^\d{13}$/', (string) $barcode->barcode))
            ->values();
        $primaryPlu = $pluCodes->first()?->barcode ?: (preg_match('/^\d{6}$/', (string) $product->sku_code) ? $product->sku_code : null);
        $allProductPrices = $productPrices->flatten(1)->sortBy(fn ($price) => ($price->priceTable?->code ?? '').'|'.($price->unit?->name ?? ''))->values();
        $defaultTablePrices = $defaultPriceTable ? $productPrices->get($defaultPriceTable->id, collect()) : collect();
        $defaultPosPrice = $defaultTablePrices->firstWhere('unit_id', null)?->price ?? $product->default_price ?? 0;
    @endphp

    <div x-data="productShow()" x-cloak>
        @if(request()->boolean('popup'))
        <section class="compact-product-window">
            <div class="compact-menu"><strong>สินค้า-{{ $product->sku_code }}</strong></div>
            <div class="compact-tools">
                <button type="button" @click="compactTab='detail'"><i class="bi bi-card-checklist"></i> รายละเอียด</button>
                <button type="button" @click="compactTab='price'"><i class="bi bi-tags"></i> ราคาขาย</button>
                <button type="button" @click="compactTab='barcode'"><i class="bi bi-upc-scan"></i> บาร์โค้ด</button>
                <button type="button" @click="compactTab='stock'"><i class="bi bi-boxes"></i> สต๊อก</button>
            </div>
            <nav class="compact-tabs">
                <button :class="compactTab==='detail' && 'active'" @click="compactTab='detail'">รายละเอียด</button>
                <button :class="compactTab==='price' && 'active'" @click="compactTab='price'">ราคาขายสินค้า</button>
                <button :class="compactTab==='barcode' && 'active'" @click="compactTab='barcode'">รหัสสินค้า / บาร์โค้ด</button>
                <button :class="compactTab==='stock' && 'active'" @click="compactTab='stock'">คลัง / ผู้จำหน่าย</button>
            </nav>

            <div class="compact-body" x-show="compactTab==='detail'">
                <form method="post" action="{{ route('products.update', $product) }}" class="compact-form">@csrf @method('PUT')<input type="hidden" name="popup" value="1">
                    <label>รหัสสินค้า<input name="sku_code" value="{{ $product->sku_code }}" required></label>
                    <label class="wide">ชื่อสินค้า<input name="name_th" value="{{ $product->name_th }}" required></label>
                    <label>ชื่ออังกฤษ<input name="name_en" value="{{ $product->name_en }}"></label>
                    <label>ประเภทสินค้า<select name="product_category_id"><option value="">ไม่กำหนด</option>@foreach($categories as $item)<option value="{{ $item->id }}" @selected($item->id===$product->product_category_id)>{{ $item->name_th }}</option>@endforeach</select></label>
                    <label>แผนก<select name="product_department_id"><option value="">ไม่กำหนด</option>@foreach($departments as $item)<option value="{{ $item->id }}" @selected($item->id===$product->product_department_id)>{{ $item->name_th }}</option>@endforeach</select></label>
                    <label>ยี่ห้อ<select name="product_brand_id"><option value="">ไม่กำหนด</option>@foreach($brands as $item)<option value="{{ $item->id }}" @selected($item->id===$product->product_brand_id)>{{ $item->name_th }}</option>@endforeach</select></label>
                    <label>หน่วยหลัก<select name="base_unit_id" required>@foreach($units as $item)<option value="{{ $item->id }}" @selected($item->id===$product->base_unit_id)>{{ $item->displayLabel() }}</option>@endforeach</select></label>
                    <label>ราคาขายเริ่มต้น<input type="number" step="0.01" min="0" name="default_price" value="{{ $product->default_price }}"></label>
                    <label>ขายเมื่อสต๊อกไม่พอ<select name="negative_stock_policy"><option value="allow" @selected($product->negative_stock_policy==='allow')>เตือนแล้วอนุญาต</option><option value="block" @selected($product->negative_stock_policy==='block')>ห้ามขายเกินสต๊อก</option></select></label>
                    <label>จุดสั่งซื้อ<input type="number" step="0.0001" min="0" name="reorder_point" value="{{ $product->reorder_point }}"></label>
                    <label>สต๊อกต่ำสุด<input type="number" step="0.0001" min="0" name="minimum_stock" value="{{ $product->minimum_stock }}"></label>
                    <label>สต๊อกสูงสุด<input type="number" step="0.0001" min="0" name="maximum_stock" value="{{ $product->maximum_stock }}"></label>
                    <label class="wide">หมายเหตุ<textarea name="note" rows="3">{{ $product->note }}</textarea></label>
                    <div class="compact-checks wide"><label><input type="checkbox" name="is_active" value="1" @checked($product->is_active)> ใช้งาน</label><label><input type="checkbox" name="is_vat" value="1" @checked($product->is_vat)> คิด VAT</label><label><input type="checkbox" name="tracks_expiry" value="1" @checked($product->tracks_expiry)> ควบคุม Lot/หมดอายุ</label></div>
                    <div class="compact-save wide"><button><i class="bi bi-check-lg"></i> บันทึก</button></div>
                </form>
            </div>
            <div class="compact-body" x-show="compactTab==='price'">
                <table class="compact-table compact-edit-table"><thead><tr><th>ตารางราคา</th><th>หน่วย</th><th class="num">ราคาขาย</th><th class="num">ทุน</th><th>สาขาที่ใช้</th><th></th></tr></thead><tbody>@forelse($allProductPrices as $price)<tr><td>{{ $price->priceTable?->name_th ?? $price->priceTable?->code }}</td><td>{{ $price->unit?->displayLabel() ?? 'ทั่วไป' }}</td><td><input form="price-{{ $price->id }}" class="num" type="number" step="0.01" min="0" name="price" value="{{ number_format((float) $price->price, 2, '.', '') }}" required></td><td><input form="price-{{ $price->id }}" class="num" type="number" step="0.01" min="0" name="cost_price" value="{{ number_format((float) $price->cost_price, 2, '.', '') }}"></td><td>-</td><td><form id="price-{{ $price->id }}" method="post" action="{{ route('products.prices.upsert', $product) }}">@csrf<input type="hidden" name="price_table_id" value="{{ $price->price_table_id }}"><input type="hidden" name="unit_id" value="{{ $price->unit_id }}"><input type="hidden" name="popup" value="1"><input type="hidden" name="compact_form" value="1"><button class="compact-row-save"><i class="bi bi-check-lg"></i> บันทึก</button></form></td></tr>@empty<tr><td colspan="6">ยังไม่มีราคาพิเศษ — ใช้ราคาเริ่มต้น {{ number_format($product->default_price,2) }}</td></tr>@endforelse</tbody></table>
            </div>
            <div class="compact-body" x-show="compactTab==='barcode'">
                <div class="compact-section-head"><strong>รหัสสินค้า / บาร์โค้ด</strong><button @click="barcodeOpen=true">+ เพิ่มรหัส</button></div>
                <table class="compact-table compact-edit-table"><thead><tr><th>บาร์โค้ด</th><th>หน่วย</th><th class="num">ตัวคูณ</th><th class="num">ราคา</th><th>สถานะ</th><th></th></tr></thead><tbody>@forelse($product->barcodes as $barcode)<tr class="{{ $barcode->is_active ? '' : 'barcode-disabled' }}"><td><input form="barcode-{{ $barcode->id }}" name="barcode" value="{{ $barcode->barcode }}" required></td><td><select form="barcode-{{ $barcode->id }}" name="unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected($unit->id===$barcode->unit_id)>{{ $unit->displayLabel() }}</option>@endforeach</select></td><td><input form="barcode-{{ $barcode->id }}" class="num" type="number" step="0.0001" min="0.0001" name="unit_factor" value="{{ number_format((float) $barcode->unit_factor, 4, '.', '') }}" required></td><td><input form="barcode-{{ $barcode->id }}" class="num" type="number" step="0.01" min="0" name="price" value="{{ $barcode->price === null ? '' : number_format((float) $barcode->price, 2, '.', '') }}"></td><td><input form="barcode-{{ $barcode->id }}" type="hidden" name="is_active" value="0"><label class="barcode-state {{ $barcode->is_active ? 'is-on' : 'is-off' }}"><input form="barcode-{{ $barcode->id }}" type="checkbox" name="is_active" value="1" @checked($barcode->is_active)><span>{{ $barcode->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span></label></td><td><form id="barcode-{{ $barcode->id }}" method="post" action="{{ route('products.barcodes.update', [$product, $barcode]) }}">@csrf @method('PUT')<input type="hidden" name="popup" value="1"><button class="compact-row-save"><i class="bi bi-check-lg"></i> บันทึก</button></form></td></tr>@empty<tr><td colspan="6">ยังไม่มีบาร์โค้ด</td></tr>@endforelse</tbody></table>
            </div>
            <div class="compact-body" x-show="compactTab==='stock'">
                <div class="compact-columns"><div><h6>สต๊อกคงเหลือ</h6><table class="compact-table"><thead><tr><th>คลัง / ที่เก็บ</th><th class="num">คงเหลือ</th></tr></thead><tbody>@forelse($product->stockBalances as $stock)<tr><td>{{ $stock->warehouseLocation?->warehouse?->name_th ?? $stock->warehouseLocation?->code ?? '-' }}</td><td class="num">{{ number_format($stock->qty_on_hand,4) }}</td></tr>@empty<tr><td colspan="2">ไม่มีสต๊อกคงเหลือ</td></tr>@endforelse</tbody></table></div><div><h6>ผู้จำหน่าย</h6><table class="compact-table"><thead><tr><th>รหัส</th><th>ชื่อผู้จำหน่าย</th></tr></thead><tbody>@forelse($product->suppliers as $link)<tr><td>{{ $link->supplier?->code }}</td><td>{{ $link->supplier?->name_th }}</td></tr>@empty<tr><td colspan="2">ยังไม่กำหนดผู้จำหน่าย</td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>
        @endif
        <a href="{{ route('products.index') }}" class="text-decoration-none small d-inline-block mb-3">
            <i class="bi bi-arrow-left me-1"></i> กลับไปรายการสินค้า
        </a>

        <div class="product-hero content-card mb-4">
            <div class="product-hero-main">
                <div class="product-icon"><i class="bi bi-box-seam"></i></div>
                <div class="min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                        <span class="product-status {{ $product->is_active ? 'is-on' : 'is-off' }}">
                            {{ $product->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                        </span>
                        <span class="product-soft-pill">{{ $product->category?->name_th ?? 'ไม่ระบุหมวด' }}</span>
                        <span class="product-soft-pill">{{ $product->brand?->name_th ?? 'ไม่ระบุยี่ห้อ' }}</span>
                    </div>
                    <h2 class="product-name">{{ $product->name_th }}</h2>
                    <div class="product-subline">
                        SKU: <strong>{{ $product->sku_code }}</strong>
                        <span>&middot;</span>
                        หน่วยหลัก: <strong>{{ $product->baseUnit?->displayLabel() ?? '-' }}</strong>
                        <span>&middot;</span>
                        ราคาเริ่มต้น: <strong>฿{{ number_format($product->default_price ?? 0, 2) }}</strong>
                    </div>
                    @if($product->note)
                        <div class="small text-muted mt-2"><i class="bi bi-journal-text me-1"></i>{{ $product->note }}</div>
                    @endif
                </div>
            </div>

            <div class="product-identity-grid">
                <div class="identity-card primary">
                    <div class="identity-label">รหัสสินค้า 6 หลัก</div>
                    <div class="identity-value">{{ $primaryPlu ?? '-' }}</div>
                    <div class="identity-help">ใช้กับเครื่องชั่ง / ยิงขาย POS / PLU</div>
                </div>
                <div class="identity-card">
                    <div class="identity-label">EAN-13</div>
                    <div class="identity-value small">{{ $eanCodes->first()?->barcode ?? '-' }}</div>
                    <div class="identity-help">บาร์โค้ดสินค้าทั่วไป</div>
                </div>
                <div class="identity-card">
                    <div class="identity-label">จำนวนรหัสทั้งหมด</div>
                    <div class="identity-value small">{{ number_format($product->barcodes->count()) }}</div>
                    <div class="identity-help">รวมทุกหน่วยและทุกบาร์โค้ด</div>
                </div>
            </div>

            <div class="product-hero-actions">
                <button type="button" class="btn btn-light border" @click="editOpen = true">
                    <i class="bi bi-pencil me-1"></i> แก้ไขสินค้า
                </button>
            </div>
        </div>


        {{-- ═══ Price Tables Section ═══════════════════════════════════ --}}
        <div class="content-card p-4 mb-4" x-data="priceTableSection()">
            <div class="alert alert-primary d-flex gap-2 align-items-start py-2 px-3 mb-3">
                <i class="bi bi-info-circle-fill mt-1"></i>
                <div class="small"><strong>ราคาปกติใช้ทุก POS ทุกสาขา</strong> ระบบจะตรวจราคาลดและนาทีทองตามช่วงวันที่ก่อน หากไม่มีหรือหมดช่วงแล้วจึงกลับมาใช้ราคาปกตินี้ทันที</div>
            </div>
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h3 class="h6 fw-bold mb-0">ราคาขายตามตารางราคา</h3>
                    <div class="text-muted" style="font-size:11px;margin-top:2px">
                        สาขาที่ไม่มีราคากำหนดไว้ จะใช้
                        <strong>{{ $defaultPriceTable?->name ?? 'ราคาเริ่มต้น' }}</strong>
                        (ราคา {{ number_format($product->default_price, 2) }} บาท)
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-primary" data-price-add @click="openAdd()">
                    <i class="bi bi-plus-lg me-1"></i>กำหนดราคา
                </button>
            </div>

            <div class="table-responsive">
                <table class="table align-middle table-sm">
                    <thead>
                        <tr>
                            <th>ตารางราคา</th>
                            <th>หน่วย</th>
                            <th class="text-end">ราคาขาย</th>
                            <th class="text-end">ทุน</th>
                            <th class="text-end">GP%</th>
                            <th>สาขาที่ใช้</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($priceTables as $pt)
                        @php
                            $prices = $productPrices->get($pt->id, collect());
                            $branches = $branchesByTable->get($pt->id, collect());
                            $isDefault = $defaultPriceTable?->id === $pt->id;
                        @endphp
                        @if($prices->isNotEmpty() || $isDefault)
                        @forelse($prices as $pp)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $pt->name }}</span>
                                @if($isDefault)<span class="badge text-bg-warning ms-1" style="font-size:10px">ค่าเริ่มต้น</span>@endif
                            </td>
                            <td class="text-muted">{{ $pp->unit?->displayLabel() ?? 'ทั่วไป' }}</td>
                            <td class="text-end fw-bold text-success">{{ number_format($pp->price, 2) }}</td>
                            <td class="text-end text-muted">{{ $pp->cost_price > 0 ? number_format($pp->cost_price, 2) : '-' }}</td>
                            <td class="text-end text-muted small">
                                @if($pp->cost_price > 0 && $pp->price > 0)
                                    {{ number_format((($pp->price - $pp->cost_price) / $pp->price) * 100, 1) }}%
                                @else -
                                @endif
                            </td>
                            <td>
                                @foreach($branches as $b)
                                    <span class="badge text-bg-light border me-1" style="font-size:10px">{{ $b->code }}</span>
                                @endforeach
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-xs btn-light border"
                                    style="font-size:11px;padding:2px 8px"
                                    @click="openEdit({{ $pt->id }}, '{{ addslashes($pt->name) }}', {{ $pp->unit_id ?? 'null' }}, '{{ addslashes($pp->unit?->displayLabel() ?? 'ทั่วไป') }}', {{ $pp->price }}, {{ $pp->cost_price }})">
                                    แก้ไข
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr class="table-light">
                            <td>
                                <span class="text-muted">{{ $pt->name }}</span>
                                @if($isDefault)<span class="badge text-bg-warning ms-1" style="font-size:10px">ค่าเริ่มต้น</span>@endif
                            </td>
                            <td colspan="4" class="text-muted small">ใช้ราคา {{ $defaultPriceTable?->name ?? 'เริ่มต้น' }}: {{ number_format($product->default_price, 2) }}</td>
                            <td>
                                @foreach($branches as $b)
                                    <span class="badge text-bg-primary me-1" style="font-size:10px">{{ $b->code }}</span>
                                @endforeach
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-xs btn-outline-primary border"
                                    style="font-size:11px;padding:2px 8px"
                                    @click="openAdd({{ $pt->id }}, '{{ addslashes($pt->name) }}')">
                                    + กำหนด
                                </button>
                            </td>
                        </tr>
                        @endforelse
                        @endif
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มีตารางราคา — ไปที่ <a href="{{ route('price-tables.index') }}">จัดการตารางราคา</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div x-show="saveMsg" class="mt-2 text-success small" x-text="saveMsg"></div>

            {{-- Inline price modal --}}
            <div class="booking-modal-backdrop" x-show="priceOpen" x-transition.opacity @keydown.escape.window="priceOpen=false">
                <div class="booking-modal" style="width:min(460px,100%)" @click.outside="priceOpen=false" x-transition>
                    <div class="modal-header border-0 px-4 pt-4 pb-2">
                        <div>
                            <h3 class="h6 fw-bold mb-0" x-text="priceTableName ? 'กำหนดราคา: ' + priceTableName : 'กำหนดราคา'"></h3>
                            <div class="text-muted small">{{ $product->sku_code }} – {{ $product->name_th }}</div>
                        </div>
                        <button type="button" class="btn btn-light rounded-circle btn-sm" @click="priceOpen=false"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted small fw-semibold">ตารางราคา</label>
                                <select x-model="priceTableId" class="form-select">
                                    @foreach($priceTables as $pt)
                                        <option value="{{ $pt->id }}">{{ $pt->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small fw-semibold">หน่วยนับ</label>
                                <select x-model="priceUnitId" class="form-select">
                                    <option value="">ทั่วไป (ไม่ระบุ)</option>
                                    @foreach($units as $u)
                                        <option value="{{ $u->id }}">{{ $u->displayLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small fw-semibold">ราคาขาย <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" x-model.number="priceVal"
                                    @focus="$el.select()" class="form-control fw-bold">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small fw-semibold">ทุน (ไม่บังคับ)</label>
                                <input type="number" step="0.01" min="0" x-model.number="costVal"
                                    @focus="$el.select()" class="form-control">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="border rounded-3 p-2 w-100 text-center bg-light">
                                    <div class="text-muted" style="font-size:11px">GP%</div>
                                    <div class="fw-bold text-success" x-text="gpPct()"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="priceOpen=false">ยกเลิก</button>
                        <button type="button" class="btn btn-success px-4" @click="savePrice()" :disabled="saving">
                            <span x-show="!saving"><i class="bi bi-check-circle me-1"></i>บันทึกราคา</span>
                            <span x-show="saving">กำลังบันทึก...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="content-card p-4 mb-4">
                    <h3 class="h6 fw-bold mb-3">สต็อกคงเหลือ</h3>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr><th>คลัง / ที่เก็บ</th><th class="text-end">คงเหลือ</th><th class="text-end">กันไว้</th></tr>
                            </thead>
                            <tbody>
                                @forelse($product->stockBalances as $balance)
                                <tr>
                                    <td>{{ $balance->warehouseLocation->warehouse->name }} / {{ $balance->warehouseLocation->name ?? $balance->warehouseLocation->code }}</td>
                                    <td class="text-end {{ $balance->on_hand_qty < 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($balance->on_hand_qty, 2) }}</td>
                                    <td class="text-end">{{ number_format($balance->reserved_qty, 2) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีข้อมูลสต็อก</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="barcode-modern content-card p-4">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1">รหัสสินค้า / บาร์โค้ด</h3>
                            <div class="text-muted small">เลข 6 หลักคือรหัสสินค้า ใช้ยิงขายและเครื่องชั่ง</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-light border" @click="barcodeOpen = true">
                            <i class="bi bi-plus-lg me-1"></i> เพิ่ม
                        </button>
                    </div>

                    <div class="barcode-group primary">
                        <div class="barcode-group-head">
                            <span>PLU / รหัสสินค้า 6 หลัก</span>
                            <span class="barcode-count">{{ number_format($pluCodes->count() + ($primaryPlu && $pluCodes->where('barcode', $primaryPlu)->isEmpty() ? 1 : 0)) }}</span>
                        </div>
                        @if($primaryPlu)
                            <div class="plu-big">{{ $primaryPlu }}</div>
                        @endif
                        <div class="barcode-list">
                            @forelse($pluCodes as $barcode)
                                <div class="barcode-row is-plu">
                                    <div>
                                        <div class="barcode-code">{{ $barcode->barcode }}</div>
                                        <div class="barcode-note">{{ $barcode->unit?->cleanName() ?? '-' }} · ตัวคูณ {{ number_format((float) $barcode->unit_factor, 4) }}</div>
                                    </div>
                                    <div class="barcode-price">{{ $barcode->price !== null ? '฿'.number_format($barcode->price, 2) : 'ใช้ราคาหลัก' }}</div>
                                </div>
                            @empty
                                <div class="barcode-empty">ยังไม่มี PLU 6 หลัก</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="barcode-group">
                        <div class="barcode-group-head">
                            <span>EAN-13 / บาร์โค้ดสินค้า</span>
                            <span class="barcode-count">{{ number_format($eanCodes->count()) }}</span>
                        </div>
                        <div class="barcode-list">
                            @forelse($eanCodes as $barcode)
                                <div class="barcode-row">
                                    <div>
                                        <div class="barcode-code">{{ $barcode->barcode }}</div>
                                        <div class="barcode-note">{{ $barcode->unit?->cleanName() ?? '-' }} · ตัวคูณ {{ number_format((float) $barcode->unit_factor, 4) }}</div>
                                    </div>
                                    <div class="barcode-price">{{ $barcode->price !== null ? '฿'.number_format($barcode->price, 2) : 'ใช้ราคาหลัก' }}</div>
                                </div>
                            @empty
                                <div class="barcode-empty">ยังไม่มี EAN-13</div>
                            @endforelse
                        </div>
                    </div>

                    @if($otherBarcodes->isNotEmpty())
                        <div class="barcode-group mb-0">
                            <div class="barcode-group-head">
                                <span>รหัสอื่น</span>
                                <span class="barcode-count">{{ number_format($otherBarcodes->count()) }}</span>
                            </div>
                            <div class="barcode-list">
                                @foreach($otherBarcodes as $barcode)
                                    <div class="barcode-row">
                                        <div>
                                            <div class="barcode-code">{{ $barcode->barcode }}</div>
                                            <div class="barcode-note">{{ $barcode->unit?->cleanName() ?? '-' }} · ตัวคูณ {{ number_format((float) $barcode->unit_factor, 4) }}</div>
                                        </div>
                                        <div class="barcode-price">{{ $barcode->price !== null ? '฿'.number_format($barcode->price, 2) : 'ใช้ราคาหลัก' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div class="content-card p-4 legacy-barcode-table">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h3 class="h6 fw-bold mb-0">บาร์โค้ด</h3>
                        <button type="button" class="btn btn-sm btn-light border" @click="barcodeOpen = true">
                            <i class="bi bi-plus-lg me-1"></i> เพิ่ม
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle table-sm">
                            <thead>
                                <tr><th>บาร์โค้ด</th><th>หน่วย</th><th class="text-end">ราคา</th></tr>
                            </thead>
                            <tbody>
                                @forelse($product->barcodes as $barcode)
                                <tr>
                                    <td>{{ $barcode->barcode }}</td>
                                    <td>{{ $barcode->unit?->displayLabel() ?? '-' }}</td>
                                    <td class="text-end">{{ $barcode->price !== null ? number_format($barcode->price, 2) : '-' }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีบาร์โค้ด</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="content-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h3 class="h6 fw-bold mb-1">ข้อมูลควบคุมสินค้าและผู้จำหน่าย</h3>
                    <div class="text-muted small">รายละเอียดประกอบแฟ้มสินค้า ใช้ช่วยสั่งซื้อและควบคุมสต๊อก</div>
                </div>
                <div class="d-flex gap-2 flex-wrap small">
                    <span class="badge {{ $product->negative_stock_policy === 'block' ? 'text-bg-danger' : 'text-bg-warning' }}">{{ $product->negative_stock_policy === 'block' ? 'ห้ามสต๊อกติดลบ' : 'เตือนก่อนขายเกิน' }}</span>
                    <span class="badge text-bg-light border">จุดสั่งซื้อ {{ $product->reorder_point !== null ? number_format((float)$product->reorder_point, 4) : '-' }}</span>
                    <span class="badge text-bg-light border">ต่ำสุด {{ $product->minimum_stock !== null ? number_format((float)$product->minimum_stock, 4) : '-' }}</span>
                    <span class="badge text-bg-light border">สูงสุด {{ $product->maximum_stock !== null ? number_format((float)$product->maximum_stock, 4) : '-' }}</span>
                </div>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>ผู้จำหน่าย</th><th>รหัสสินค้าฝั่งผู้ขาย</th><th class="text-end">ราคาซื้อล่าสุด</th><th class="text-end">ขั้นต่ำสั่งซื้อ</th><th class="text-end">Lead time</th><th>หมายเหตุ</th><th></th></tr></thead>
                    <tbody>
                    @forelse($product->suppliers as $link)
                        <tr>
                            <td>@if($link->is_primary)<span class="badge text-bg-primary me-1">หลัก</span>@endif{{ $link->supplier?->code }} - {{ $link->supplier?->name_th }}</td>
                            <td>{{ $link->supplier_sku ?: '-' }}</td>
                            <td class="text-end">{{ $link->last_purchase_price !== null ? number_format((float)$link->last_purchase_price, 4) : '-' }}</td>
                            <td class="text-end">{{ $link->minimum_order_qty !== null ? number_format((float)$link->minimum_order_qty, 4) : '-' }}</td>
                            <td class="text-end">{{ $link->lead_time_days !== null ? number_format($link->lead_time_days).' วัน' : '-' }}</td>
                            <td class="small text-muted">{{ $link->note ?: '-' }}</td>
                            <td class="text-end"><form method="post" action="{{ route('products.suppliers.destroy', [$product, $link]) }}" onsubmit="return confirm('นำผู้จำหน่ายรายนี้ออกจากแฟ้มสินค้า?')">@csrf @method('DELETE')<button class="btn btn-sm btn-light text-danger border"><i class="bi bi-trash"></i></button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่ได้กำหนดผู้จำหน่ายของสินค้านี้</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <form method="post" action="{{ route('products.suppliers.upsert', $product) }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-3"><label class="form-label small text-muted">ผู้จำหน่าย</label><select name="supplier_id" required class="form-select form-select-sm"><option value="">-- เลือก --</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} - {{ $supplier->name_th }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label small text-muted">รหัสสินค้าผู้ขาย</label><input name="supplier_sku" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label small text-muted">ราคาซื้อล่าสุด</label><input type="number" step="0.0001" min="0" name="last_purchase_price" class="form-control form-control-sm"></div>
                <div class="col-md-1"><label class="form-label small text-muted">ขั้นต่ำ</label><input type="number" step="0.0001" min="0" name="minimum_order_qty" class="form-control form-control-sm"></div>
                <div class="col-md-1"><label class="form-label small text-muted">Lead day</label><input type="number" min="0" name="lead_time_days" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label small text-muted">หมายเหตุ</label><input name="note" class="form-control form-control-sm"></div>
                <div class="col-md-1"><div class="form-check mb-2"><input type="checkbox" name="is_primary" value="1" class="form-check-input" id="supplierPrimary"><label class="form-check-label small" for="supplierPrimary">หลัก</label></div><button class="btn btn-sm btn-primary w-100">บันทึก</button></div>
            </form>
        </div>

        @if($product->tracks_expiry || $product->stockLots->isNotEmpty())
        <div class="content-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h6 fw-bold mb-0">Lot และวันหมดอายุ</h3>
                <span class="badge text-bg-info">FIFO ตาม Lot</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>เลข Lot</th><th>คลัง</th><th>วันที่รับ</th><th>วันหมดอายุ</th><th class="text-end">คงเหลือ</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    @forelse($product->stockLots as $lot)
                        @php
                            $daysLeft = $lot->expiry_date ? now()->startOfDay()->diffInDays($lot->expiry_date, false) : null;
                        @endphp
                        <tr>
                            <td class="fw-semibold">{{ $lot->lot_number }}</td>
                            <td>{{ $lot->warehouseLocation?->warehouse?->name_th ?? $lot->warehouseLocation?->code ?? '-' }}</td>
                            <td>{{ $lot->received_date?->format('d/m/Y') }}</td>
                            <td>{{ $lot->expiry_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="text-end">{{ number_format($lot->remaining_qty, 4) }}</td>
                            <td>
                                @if($daysLeft === null)<span class="badge text-bg-light border">ไม่ระบุ</span>
                                @elseif($daysLeft < 0)<span class="badge text-bg-danger">หมดอายุแล้ว</span>
                                @elseif($daysLeft <= 30)<span class="badge text-bg-warning">เหลือ {{ $daysLeft }} วัน</span>
                                @else<span class="badge text-bg-success">ปกติ</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">ยังไม่มี Lot คงเหลือ</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Edit product modal --}}
        <div class="booking-modal-backdrop" x-show="editOpen" x-transition.opacity @keydown.escape.window="editOpen = false">
            <div class="booking-modal" style="width: min(720px, 100%);" @click.outside="editOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">แก้ไขสินค้า</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="editOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('products.update', $product) }}">
                    @csrf @method('PUT')
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสสินค้า (SKU)</label>
                                <input type="text" name="sku_code" value="{{ $product->sku_code }}" required class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">หน่วยหลัก</label>
                                <select name="base_unit_id" required class="form-select">
                                    @foreach($units as $unit)
                                        <option value="{{ $unit->id }}" @selected($unit->id === $product->base_unit_id)>{{ $unit->displayLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อสินค้า (ไทย)</label>
                                <input type="text" name="name_th" value="{{ $product->name_th }}" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อสินค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" value="{{ $product->name_en }}" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">หมายเหตุสินค้า / ข้อมูลช่วยจำ</label>
                                <textarea name="note" rows="2" maxlength="2000" class="form-control" placeholder="เช่น วิธีเก็บรักษา รุ่น สี หรือเงื่อนไขที่พนักงานควรทราบ">{{ $product->note }}</textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-muted small">ขายเมื่อสต๊อกไม่พอ</label>
                                <select name="negative_stock_policy" class="form-select" required>
                                    <option value="allow" @selected($product->negative_stock_policy === 'allow')>เตือนแล้วอนุญาต</option>
                                    <option value="block" @selected($product->negative_stock_policy === 'block')>ห้ามขายเกินสต๊อก</option>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="form-label text-muted small">จุดสั่งซื้อ</label><input type="number" step="0.0001" min="0" name="reorder_point" value="{{ $product->reorder_point }}" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label text-muted small">สต๊อกขั้นต่ำ</label><input type="number" step="0.0001" min="0" name="minimum_stock" value="{{ $product->minimum_stock }}" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label text-muted small">สต๊อกสูงสุด</label><input type="number" step="0.0001" min="0" name="maximum_stock" value="{{ $product->maximum_stock }}" class="form-control"></div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">หมวดหมู่</label>
                                <select name="product_category_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" @selected($category->id === $product->product_category_id)>{{ $category->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">แผนก</label>
                                <select name="product_department_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}" @selected($department->id === $product->product_department_id)>{{ $department->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">ยี่ห้อ</label>
                                <select name="product_brand_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}" @selected($brand->id === $product->product_brand_id)>{{ $brand->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">ราคาเริ่มต้น</label>
                                <input type="number" step="0.01" min="0" name="default_price" value="{{ $product->default_price }}" class="form-control">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_vat" value="1" @checked($product->is_vat) class="form-check-input" id="editProductVat">
                                    <label class="form-check-label" for="editProductVat">คิด VAT</label>
                                </div>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="tracks_expiry" value="1" @checked($product->tracks_expiry) class="form-check-input" id="editProductExpiry">
                                    <label class="form-check-label" for="editProductExpiry">ควบคุม Lot และวันหมดอายุ</label>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" @checked($product->is_active) class="form-check-input" id="editProductActive">
                                    <label class="form-check-label" for="editProductActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="editOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Add barcode modal --}}
        <div class="booking-modal-backdrop" x-show="barcodeOpen" x-transition.opacity @keydown.escape.window="barcodeOpen = false">
            <div class="booking-modal" style="width: min(480px, 100%);" @click.outside="barcodeOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0">เพิ่มบาร์โค้ด</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="barcodeOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('products.barcodes.store', $product) }}">
                    @csrf
                    <input type="hidden" name="popup" value="{{ request()->boolean('popup') ? 1 : 0 }}">
                    <input type="hidden" name="barcode_mode" x-model="barcodeMode">
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted small">บาร์โค้ด</label>
                                <input type="text" name="barcode" class="form-control" x-ref="bcInput" x-model="barcodeValue"
                                    :required="barcodeMode === 'manual'" :readonly="barcodeMode === 'auto_ean13'"
                                    :placeholder="barcodeMode === 'auto_ean13' ? 'ระบบจะสร้างเลข EAN-13 เมื่อบันทึก' : 'ยิงหรือพิมพ์บาร์โค้ด'">
                                <div class="d-grid gap-2 mt-2" style="grid-template-columns:1fr 1fr">
                                    <button type="button" class="btn btn-sm btn-primary" @click="barcodeMode='auto_ean13'; barcodeValue=''">
                                        <i class="bi bi-upc-scan me-1"></i> สร้าง EAN-13 อัตโนมัติ
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border"
                                        @click="barcodeMode='manual'; barcodeValue='{{ $nextScalePlu }}'; $nextTick(() => $refs.bcInput.focus())">
                                        <i class="bi bi-speedometer me-1"></i> PLU เครื่องชั่ง {{ $nextScalePlu }}
                                    </button>
                                </div>
                                <button type="button" class="btn btn-sm btn-link px-0 mt-1" @click="barcodeMode='manual'; barcodeValue=''; $nextTick(() => $refs.bcInput.focus())">กรอกหรือยิงบาร์โค้ดเดิมเอง</button>
                                <div class="form-text">EAN-13 จะรันไม่ซ้ำพร้อมคำนวณเลขตรวจสอบหลักที่ 13 หน่วยชิ้น แพ็ค และกล่องต้องสร้างแยกคนละรหัส</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">หน่วย</label>
                                <select name="unit_id" required class="form-select">
                                    @foreach($units as $unit)
                                        <option value="{{ $unit->id }}" @selected($unit->id === $product->base_unit_id)>{{ $unit->displayLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">ตัวคูณหน่วย</label>
                                <input type="number" step="0.0001" min="0.0001" name="unit_factor" value="1" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ราคา (ไม่บังคับ)</label>
                                <input type="number" step="0.01" min="0" name="price" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="barcodeOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('head')
<style>
    body.erp-popup-page .app-header,
    body.erp-popup-page .app-sidebar { display:none !important; }
    body.erp-popup-page .app-main { margin:0 !important; min-height:100vh; }
    body.erp-popup-page .app-content { padding:5px !important; }
    body.erp-popup-page .container-fluid { max-width:none !important; padding:0 !important; }
    body.erp-popup-page div[x-data="productShow()"] > :not(.compact-product-window):not(.booking-modal-backdrop) { display:none !important; }
    body.erp-popup-page { background:#ececec; }
    .compact-product-window { min-height:calc(100vh - 10px); border:1px solid #8d9297; background:#ececec; color:#151515; font:10px Tahoma,"Noto Sans Thai",sans-serif; }
    .compact-menu { height:24px; display:flex; align-items:center; justify-content:flex-end; padding:0 9px; border-bottom:1px solid #aaa; background:#f4f4f4; }
    .compact-tools { height:38px; display:flex; align-items:stretch; gap:1px; padding:2px 5px; border-bottom:1px solid #aaa; background:#e4e4e4; }
    .compact-tools button { min-width:72px; border:1px solid transparent; background:transparent; font-size:9px; }
    .compact-tools button:hover { border-color:#9aa0a6; background:#f8f8f8; }
    .compact-tools i { display:block; color:#1677a8; font-size:13px; }
    .compact-tabs { display:flex; gap:1px; padding:4px 7px 0; border-bottom:1px solid #8d9298; background:#ddd; }
    .compact-tabs button { padding:3px 8px; border:1px solid #999; border-bottom:0; background:#e8e8e8; font-size:9px; }
    .compact-tabs button.active { position:relative; top:1px; background:#fff; color:#b42318; font-weight:700; }
    .compact-body { height:calc(100vh - 101px); overflow:auto; padding:8px; background:#f2f2f2; }
    .compact-form { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px 9px; max-width:100%; padding:9px; border:1px solid #aaa; background:#eee; }
    .compact-form label { display:grid; grid-template-columns:90px minmax(0,1fr); align-items:center; gap:7px; margin:0; white-space:nowrap; }
    .compact-form label.wide,.compact-form .wide { grid-column:span 3; }
    .compact-form input:not([type=checkbox]),.compact-form select,.compact-form textarea { width:100%; min-height:21px; padding:1px 4px; border:1px solid #8f9499; border-radius:0; background:#fff; font:10px Tahoma,"Noto Sans Thai",sans-serif; }
    .compact-form textarea { resize:vertical; }
    .compact-checks { display:flex; gap:22px; padding:8px 0; border-top:1px solid #bbb; }
    .compact-checks label { display:flex; gap:5px; }
    .compact-save { display:flex; justify-content:flex-end; }
    .compact-save button,.compact-section-head button { min-width:86px; padding:5px 12px; border:1px solid #888; background:linear-gradient(#fff,#ddd); font-weight:700; }
    .compact-section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .compact-table { width:100%; border-collapse:collapse; background:#fff; }
    .compact-table th,.compact-table td { padding:3px 5px; border:1px solid #aeb3b7; text-align:left; }
    .compact-table th { background:linear-gradient(#f5f5f5,#d8d8d8); font-weight:700; }
    .compact-table tr:nth-child(even) td { background:#f5f8fa; }
    .compact-table .num { text-align:right; }
    .compact-table .code { color:#075985; font-weight:700; }
    .compact-edit-table input,.compact-edit-table select { width:100%; min-height:23px; padding:1px 4px; border:1px solid #92979c; border-radius:0; background:#fff; font:11px Tahoma,"Noto Sans Thai",sans-serif; }
    .compact-edit-table td { padding:3px; }
    .compact-edit-table tr.barcode-disabled td { background:#f3f4f6; color:#7b8790; }
    .barcode-state { display:inline-flex; align-items:center; gap:4px; margin:0; padding:2px 6px; border-radius:3px; white-space:nowrap; font-weight:700; cursor:pointer; }
    .barcode-state.is-on { background:#dcfce7; color:#166534; }
    .barcode-state.is-off { background:#e5e7eb; color:#4b5563; }
    .barcode-state input { width:auto; min-height:0; margin:0; }
    .compact-row-save { white-space:nowrap; padding:3px 8px; border:1px solid #888; background:linear-gradient(#fff,#ddd); font:700 11px Tahoma,"Noto Sans Thai",sans-serif; }
    .compact-columns { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    body.erp-popup-page .booking-modal { width:min(520px,calc(100vw - 30px)) !important; border-radius:3px; font-size:12px; }
    @media(max-width:700px){.compact-form{grid-template-columns:1fr}.compact-form label.wide,.compact-form .wide{grid-column:span 1}.compact-columns{grid-template-columns:1fr}.compact-form label{grid-template-columns:105px minmax(0,1fr)}}
    [x-cloak] { display: none !important; }
    .legacy-product-summary,
    .legacy-barcode-table { display: none !important; }
    .bplus-setup {
        padding: 0;
        overflow: hidden;
        border: 1px solid #d7e4ef;
        box-shadow: 0 12px 34px rgba(15, 23, 42, .08);
    }
    .bplus-window-tabs {
        display: flex;
        gap: 2px;
        padding: 10px 16px 0;
        background: linear-gradient(180deg, #f8fafc, #eef6fb);
        border-bottom: 1px solid #dbe6ef;
    }
    .bplus-window-tabs span {
        display: inline-flex;
        align-items: center;
        min-height: 36px;
        padding: 0 16px;
        border: 1px solid transparent;
        border-bottom: 0;
        border-radius: 10px 10px 0 0;
        color: #64748b;
        font-size: 13px;
        font-weight: 900;
    }
    .bplus-window-tabs span.active {
        background: #fff;
        border-color: #dbe6ef;
        color: #0f766e;
        transform: translateY(1px);
    }
    .bplus-detail-grid {
        display: grid;
        grid-template-columns: 160px minmax(240px, 1fr) 180px 180px;
        gap: 12px;
        padding: 18px 18px 12px;
        background: #fff;
    }
    .bplus-detail-grid .span-2 { grid-column: span 2; }
    .bplus-detail-grid label {
        display: block;
        margin-bottom: 5px;
        color: #64748b;
        font-size: 11px;
        font-weight: 900;
    }
    .bplus-input {
        min-height: 38px;
        display: flex;
        align-items: center;
        padding: 8px 10px;
        border: 1px solid #dbe6ef;
        border-radius: 8px;
        background: #f8fafc;
        color: #0f172a;
        font-weight: 800;
        line-height: 1.35;
    }
    .bplus-input.strong { color: #0f766e; font-size: 18px; letter-spacing: 0; }
    .bplus-input.price { color: #059669; font-size: 19px; justify-content: flex-end; }
    .bplus-split {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr);
        gap: 14px;
        padding: 0 18px 18px;
        background: #fff;
    }
    .bplus-panel {
        border: 1px solid #dbe6ef;
        border-radius: 10px;
        overflow: hidden;
        min-width: 0;
        background: #fff;
    }
    .bplus-panel-title {
        min-height: 48px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 12px;
        background: #f8fafc;
        border-bottom: 1px solid #dbe6ef;
        color: #0f172a;
        font-weight: 950;
    }
    .bplus-table-wrap { overflow: auto; max-height: 320px; }
    .bplus-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 13px;
        color: #0f172a;
    }
    .bplus-table th {
        position: sticky;
        top: 0;
        z-index: 1;
        padding: 9px 10px;
        background: #ecfeff;
        color: #0f766e;
        border-bottom: 1px solid #ccfbf1;
        font-size: 11px;
        font-weight: 950;
        white-space: nowrap;
    }
    .bplus-table td {
        padding: 9px 10px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }
    .bplus-table tr:nth-child(even) td { background: #f8fafc; }
    .bplus-table tr.is-plu-row td {
        background: #f0fdfa;
        border-bottom-color: #ccfbf1;
    }
    .bplus-code {
        color: #0f172a;
        font-weight: 950;
        font-size: 15px;
        letter-spacing: 0;
    }
    .is-plu-row .bplus-code { color: #0f766e; font-size: 18px; }
    .bplus-note {
        display: inline-block;
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        margin-top: 2px;
    }
    .bplus-price-code {
        display: inline-grid;
        place-items: center;
        min-width: 34px;
        min-height: 24px;
        padding: 0 8px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0369a1;
        font-weight: 950;
    }
    .product-hero {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(340px, .85fr) auto;
        gap: 18px;
        align-items: stretch;
        padding: 20px;
        border: 1px solid #dbe6ef;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .08);
    }
    .product-hero-main { display: flex; gap: 14px; align-items: flex-start; min-width: 0; }
    .product-icon {
        width: 54px;
        height: 54px;
        border-radius: 12px;
        background: linear-gradient(135deg, #0ea5e9, #14b8a6);
        color: #fff;
        display: grid;
        place-items: center;
        font-size: 26px;
        flex: 0 0 auto;
    }
    .product-name {
        margin: 0;
        color: #0f172a;
        font-weight: 950;
        font-size: clamp(24px, 2.1vw, 34px);
        line-height: 1.18;
        letter-spacing: 0;
    }
    .product-subline { color: #64748b; font-size: 13px; display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .product-subline strong { color: #0f172a; }
    .product-status,
    .product-soft-pill {
        display: inline-flex;
        align-items: center;
        min-height: 24px;
        border-radius: 999px;
        padding: 3px 10px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }
    .product-status.is-on { background: #dcfce7; color: #047857; }
    .product-status.is-off { background: #e2e8f0; color: #475569; }
    .product-soft-pill { background: #f1f5f9; color: #475569; }
    .product-identity-grid { display: grid; grid-template-columns: 1.1fr 1fr .72fr; gap: 10px; }
    .identity-card {
        border: 1px solid #dbe6ef;
        border-radius: 10px;
        padding: 12px;
        background: #f8fafc;
        min-width: 0;
    }
    .identity-card.primary {
        border-color: #99f6e4;
        background: linear-gradient(180deg, #ecfeff, #f0fdfa);
    }
    .identity-label { color: #64748b; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    .identity-value {
        color: #0f766e;
        font-size: 36px;
        font-weight: 950;
        line-height: 1;
        margin-top: 7px;
        letter-spacing: 0;
        word-break: break-word;
    }
    .identity-value.small { color: #0f172a; font-size: 20px; line-height: 1.2; }
    .identity-help { color: #64748b; font-size: 11px; margin-top: 7px; }
    .product-hero-actions { display: flex; align-items: flex-start; justify-content: flex-end; }
    .barcode-modern { border: 1px solid #dbe6ef; box-shadow: 0 10px 28px rgba(15, 23, 42, .08); }
    .barcode-group {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 12px;
        background: #fff;
    }
    .barcode-group.primary { border-color: #99f6e4; }
    .barcode-group-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        background: #f8fafc;
        color: #334155;
        font-size: 12px;
        font-weight: 900;
    }
    .barcode-group.primary .barcode-group-head { background: #ecfeff; color: #0f766e; }
    .barcode-count {
        min-width: 26px;
        height: 22px;
        border-radius: 999px;
        display: inline-grid;
        place-items: center;
        background: #e2e8f0;
        color: #334155;
        font-size: 12px;
        padding: 0 8px;
    }
    .plu-big {
        padding: 14px 12px 4px;
        font-size: 42px;
        line-height: 1;
        font-weight: 950;
        color: #0f766e;
        letter-spacing: 0;
    }
    .barcode-list { display: grid; }
    .barcode-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
        padding: 10px 12px;
        border-top: 1px solid #eef2f7;
    }
    .barcode-row.is-plu { background: #fbfffe; }
    .barcode-code {
        color: #0f172a;
        font-size: 18px;
        font-weight: 850;
        letter-spacing: 0;
        word-break: break-all;
    }
    .barcode-row.is-plu .barcode-code { color: #0f766e; font-size: 20px; }
    .barcode-note { color: #64748b; font-size: 12px; margin-top: 2px; }
    .barcode-price { color: #0f172a; font-weight: 850; text-align: right; white-space: nowrap; }
    .barcode-empty { padding: 14px 12px; color: #94a3b8; font-size: 13px; text-align: center; border-top: 1px solid #eef2f7; }
    .booking-modal-backdrop {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(15, 23, 42, .42);
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .booking-modal {
        max-height: calc(100vh - 48px); overflow: auto;
        background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
    @media (max-width: 1180px) {
        .product-hero { grid-template-columns: 1fr; }
        .product-hero-actions { justify-content: flex-start; }
        .bplus-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .bplus-split { grid-template-columns: 1fr; }
    }
    @media (max-width: 720px) {
        .product-identity-grid { grid-template-columns: 1fr; }
        .product-hero { padding: 16px; }
        .identity-value { font-size: 32px; }
        .plu-big { font-size: 36px; }
        .bplus-window-tabs { overflow-x: auto; padding-left: 12px; }
        .bplus-detail-grid { grid-template-columns: 1fr; padding: 14px; }
        .bplus-detail-grid .span-2 { grid-column: span 1; }
        .bplus-split { padding: 0 14px 14px; }
    }

    div[x-data="productShow()"] > a.text-decoration-none.small.d-inline-block.mb-3,
    .product-hero {
        display: none !important;
    }
    .legacy-product-window {
        max-width: 980px;
        margin: 0 auto 22px;
        border: 1px solid #8f969d;
        background: #ececec;
        color: #111827;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .12);
        font-size: 13px;
    }
    .legacy-product-titlebar {
        min-height: 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 4px 10px;
        background: #fbfbfb;
        border-bottom: 1px solid #aab0b7;
        font-weight: 800;
    }
    .legacy-window-name {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }
    .legacy-window-name span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .legacy-window-name i { color: #15803d; }
    .legacy-window-controls {
        display: flex;
        align-items: center;
        gap: 18px;
        flex: 0 0 auto;
    }
    .legacy-window-controls span {
        width: 14px;
        height: 14px;
        border: 1px solid #111827;
        background: #fff;
    }
    .legacy-window-controls span:first-child {
        height: 2px;
        align-self: flex-end;
        margin-bottom: 3px;
    }
    .legacy-window-controls span:last-child {
        position: relative;
        border: 0;
        background: transparent;
    }
    .legacy-window-controls span:last-child::before,
    .legacy-window-controls span:last-child::after {
        content: "";
        position: absolute;
        left: 6px;
        top: 0;
        width: 2px;
        height: 16px;
        background: #111827;
    }
    .legacy-window-controls span:last-child::before { transform: rotate(45deg); }
    .legacy-window-controls span:last-child::after { transform: rotate(-45deg); }
    .legacy-product-menubar {
        min-height: 31px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 3px 8px;
        background: #e7e7e7;
        border-bottom: 1px solid #b6bcc3;
    }
    .legacy-menu-group,
    .legacy-tool-group {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    .legacy-menu-group span {
        padding: 4px 10px;
        border: 1px solid transparent;
        font-weight: 800;
    }
    .legacy-menu-group span:hover {
        border-color: #9ca3af;
        background: #f8fafc;
    }
    .legacy-tool-group button,
    .legacy-search-tools button,
    .legacy-action-tools button,
    .legacy-action-tools a {
        min-height: 28px;
        border: 2px outset #f8fafc;
        background: #efefef;
        color: #0f172a;
        font-weight: 800;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 3px 10px;
        line-height: 1;
    }
    .legacy-tool-group button {
        width: 34px;
        padding: 0;
        color: #0b7fb8;
    }
    .legacy-tool-group button:active,
    .legacy-search-tools button:active,
    .legacy-action-tools button:active,
    .legacy-action-tools a:active {
        border-style: inset;
    }
    .legacy-save i { color: #15803d; font-size: 18px; }
    .legacy-cancel i { color: #111827; font-size: 16px; }
    .legacy-product-window .bplus-setup {
        border: 0;
        border-radius: 0;
        box-shadow: none;
        background: #ececec;
    }
    .legacy-product-window .bplus-window-tabs {
        gap: 0;
        padding: 6px 8px 0;
        background: #ececec;
        border-bottom: 1px solid #aeb4bb;
    }
    .legacy-product-window .bplus-window-tabs span {
        min-height: 25px;
        padding: 2px 13px;
        border: 1px solid #aeb4bb;
        border-bottom: 0;
        border-radius: 0;
        background: #e9e9e9;
        color: #111827;
        font-size: 12px;
        font-weight: 900;
    }
    .legacy-product-window .bplus-window-tabs span.active {
        background: #f7f7f7;
        color: #e11d48;
        transform: none;
    }
    .legacy-product-detail {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 190px;
        gap: 18px;
        padding: 12px 46px 8px;
        background: #ececec;
        border-bottom: 1px solid #c6cbd1;
    }
    .legacy-form-main { min-width: 0; }
    .legacy-row {
        display: grid;
        grid-template-columns: 92px minmax(110px, 142px) 76px 24px 72px 24px;
        align-items: center;
        gap: 5px 7px;
        margin-bottom: 4px;
    }
    .legacy-row.wide {
        grid-template-columns: 92px minmax(0, 1fr);
    }
    .legacy-row label,
    .legacy-stack-row label {
        color: #1d4ed8;
        font-weight: 900;
        white-space: nowrap;
    }
    .legacy-field,
    .legacy-select,
    .bplus-input {
        min-height: 24px;
        display: flex;
        align-items: center;
        padding: 2px 6px;
        border: 2px inset #f8fafc;
        border-radius: 0;
        background: #fff;
        color: #111827;
        font-weight: 800;
        line-height: 1.2;
        overflow: hidden;
    }
    .legacy-field.is-selected {
        background: #1d9bf0;
        color: #fff;
    }
    .legacy-field.muted { color: #4b5563; font-weight: 700; }
    .legacy-select {
        position: relative;
        padding-right: 22px;
        color: #1d4ed8;
    }
    .legacy-select::after {
        content: "";
        position: absolute;
        right: 5px;
        top: 50%;
        transform: translateY(-30%);
        border-left: 5px solid transparent;
        border-right: 5px solid transparent;
        border-top: 6px solid #111827;
    }
    .legacy-check {
        width: 16px;
        height: 16px;
        border: 2px inset #f8fafc;
        background: #fff;
        position: relative;
    }
    .legacy-check.is-on::after {
        content: "";
        position: absolute;
        left: 3px;
        top: 0;
        width: 7px;
        height: 11px;
        border-right: 2px solid #111827;
        border-bottom: 2px solid #111827;
        transform: rotate(38deg);
    }
    .legacy-group-grid {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr);
        gap: 7px 16px;
        margin-top: 14px;
    }
    .legacy-group-grid fieldset {
        min-width: 0;
        border: 1px solid #aeb4bb;
        padding: 13px 9px 8px;
        margin: 0;
        background: #ececec;
    }
    .legacy-group-grid legend {
        float: none;
        width: auto;
        margin: 0;
        padding: 0 5px;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 950;
    }
    .legacy-stack-row {
        display: grid;
        grid-template-columns: 94px minmax(0, 1fr);
        align-items: center;
        gap: 7px;
        margin-top: 5px;
    }
    .legacy-radio-line {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-top: 7px;
        font-weight: 800;
    }
    .legacy-radio {
        width: 13px;
        height: 13px;
        border: 1px solid #6b7280;
        border-radius: 50%;
        background: #fff;
        box-shadow: inset 0 0 0 2px #fff;
    }
    .legacy-radio.is-on { background: #111827; }
    .legacy-photo-box {
        width: 170px;
        height: 120px;
        display: grid;
        place-items: center;
        align-self: start;
        background: #fff;
        color: #111827;
        font-weight: 900;
    }
    .legacy-product-window .bplus-detail-grid {
        grid-template-columns: 170px 1fr 170px 150px;
        gap: 7px;
        padding: 10px 16px;
        background: #ececec;
        border-bottom: 1px solid #b8bec5;
    }
    .legacy-product-window .bplus-detail-grid label {
        margin-bottom: 2px;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 950;
    }
    .legacy-product-window .bplus-input.strong,
    .legacy-product-window .bplus-input.price {
        color: #111827;
        font-size: 13px;
        justify-content: flex-start;
    }
    .legacy-product-window .bplus-split {
        grid-template-columns: 1fr;
        gap: 9px;
        padding: 0 12px 8px;
        background: #ececec;
    }
    .legacy-product-window .bplus-panel {
        border: 1px solid #9ca3af;
        border-radius: 0;
        background: #fff;
    }
    .legacy-product-window .bplus-panel-title {
        min-height: 27px;
        padding: 2px 7px;
        background: #ececec;
        border-bottom: 1px solid #9ca3af;
        color: #1d4ed8;
        font-size: 12px;
    }
    .legacy-product-window .bplus-panel-title .btn {
        min-height: 24px;
        padding: 1px 7px;
        border-radius: 0;
        font-size: 12px;
    }
    .legacy-product-window .bplus-table-wrap {
        max-height: 248px;
        background: #fff;
    }
    .legacy-product-window .bplus-table {
        border-collapse: collapse;
        font-size: 12px;
    }
    .legacy-product-window .bplus-table th,
    .legacy-product-window .bplus-table td {
        border: 1px solid #aeb4bb;
        padding: 2px 5px;
        height: 22px;
    }
    .legacy-product-window .bplus-table th {
        background: #e9e9e9;
        color: #111827;
        font-size: 12px;
    }
    .legacy-product-window .bplus-table tr:nth-child(even) td,
    .legacy-product-window .bplus-table tr.is-plu-row td {
        background: #c9fbfb;
    }
    .legacy-product-window .bplus-code,
    .legacy-product-window .is-plu-row .bplus-code {
        color: #111827;
        font-size: 12px;
        font-weight: 800;
    }
    .legacy-product-window .bplus-note {
        display: none;
    }
    .legacy-product-window .bplus-price-code {
        min-width: 0;
        min-height: 0;
        padding: 0;
        border-radius: 0;
        background: transparent;
        color: #111827;
    }
    .legacy-bottom-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-height: 40px;
        padding: 5px 12px;
        border-top: 1px solid #9ca3af;
        background: #efefef;
    }
    .legacy-search-tools,
    .legacy-action-tools {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .legacy-search-tools span {
        min-width: 72px;
        font-weight: 900;
        text-decoration: underline;
    }
    @media (max-width: 860px) {
        .legacy-product-window { max-width: none; }
        .legacy-product-detail {
            grid-template-columns: 1fr;
            padding: 10px;
        }
        .legacy-row,
        .legacy-row.wide,
        .legacy-stack-row,
        .legacy-product-window .bplus-detail-grid {
            grid-template-columns: 1fr;
        }
        .legacy-group-grid {
            grid-template-columns: 1fr;
        }
        .legacy-photo-box {
            width: 100%;
            height: 92px;
        }
        .legacy-bottom-bar {
            align-items: stretch;
            flex-direction: column;
        }
        .legacy-action-tools,
        .legacy-search-tools {
            justify-content: flex-end;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    function productShow() {
        return { editOpen: false, barcodeOpen: false, compactTab: 'detail', barcodeMode: 'auto_ean13', barcodeValue: '' };
    }

    function priceTableSection() {
        return {
            priceOpen: false, saving: false, saveMsg: '',
            priceTableId: '{{ $priceTables->first()?->id ?? '' }}',
            priceTableName: '',
            priceUnitId: '',
            priceVal: {{ $product->default_price ?? 0 }},
            costVal: 0,

            openAdd(tableId = null, tableName = null) {
                this.priceTableId = tableId ? String(tableId) : '{{ $priceTables->first()?->id ?? '' }}';
                this.priceTableName = tableName || '';
                this.priceUnitId = '';
                this.priceVal = {{ $product->default_price ?? 0 }};
                this.costVal = 0;
                this.priceOpen = true;
            },

            openEdit(tableId, tableName, unitId, unitName, price, cost) {
                this.priceTableId = String(tableId);
                this.priceTableName = tableName;
                this.priceUnitId = unitId ? String(unitId) : '';
                this.priceVal = price;
                this.costVal = cost;
                this.priceOpen = true;
            },

            gpPct() {
                if (!this.priceVal || !this.costVal) return '-';
                const gp = ((this.priceVal - this.costVal) / this.priceVal) * 100;
                return gp.toFixed(1) + '%';
            },

            async savePrice() {
                if (!this.priceTableId || this.priceVal === '' || this.priceVal < 0) return;
                this.saving = true;
                try {
                    const res = await fetch('{{ route('products.prices.upsert', $product) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            price_table_id: this.priceTableId,
                            unit_id: this.priceUnitId || null,
                            price: this.priceVal,
                            cost_price: this.costVal || 0,
                        }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.saveMsg = `บันทึกราคา ${Number(data.price).toLocaleString('th-TH', {minimumFractionDigits:2})} บาท แล้ว ✓`;
                        this.priceOpen = false;
                        setTimeout(() => { this.saveMsg = ''; location.reload(); }, 600);
                    }
                } finally {
                    this.saving = false;
                }
            },
        };
    }
</script>
@endpush
