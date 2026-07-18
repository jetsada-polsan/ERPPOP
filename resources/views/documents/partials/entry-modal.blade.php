@php
    $partyType = $partyType ?? 'customer';
    $partyLabel = $partyLabel ?? 'ลูกค้า';
    $partyRequired = $partyRequired ?? true;
    $showParty = $partyType !== 'none';
    $showSalesman = $showSalesman ?? false;
    $showCreditType = $showCreditType ?? false;
    $showLotFields = $showLotFields ?? false;
    $submitClass = $submitClass ?? 'doc-btn-primary';
@endphp

<div class="doc-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="closeModal()" x-cloak>
    <div class="doc-modal" @click.outside="closeModal()" x-transition>
        <div class="doc-titlebar">
            <div>
                <div class="doc-title">{{ $title }}</div>
                <div class="doc-subtitle">{{ $subtitle ?? 'คีย์เฉพาะข้อมูลจำเป็น แล้วบันทึกเอกสารได้ทันที' }}</div>
            </div>
            <button type="button" class="doc-close" @click="closeModal()" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="doc-commandbar">
            <button type="button" @click="addItem()"><i class="bi bi-plus-circle"></i><span>เพิ่มรายการ</span></button>
            <button type="button" @click="closeModal()"><i class="bi bi-x-circle"></i><span>ปิด</span></button>
        </div>

        <form method="post" action="{{ $action }}" @submit="onSubmit">
            @csrf
            <div class="doc-body">
                <div class="doc-head-grid">
                    <div class="doc-card doc-card-pad">
                        <div class="doc-fields">
                            <div class="doc-field" style="grid-column: span 4;">
                                <label><span class="required">*</span> สาขา / คลัง</label>
                                <select name="branch_id" required class="doc-select">
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @if($showCreditType)
                                <div class="doc-field" style="grid-column: span 3;">
                                    <label>ประเภทซื้อ</label>
                                    <select name="is_credit" class="doc-select">
                                        <option value="1">ซื้อเชื่อ</option>
                                        <option value="0">ซื้อสด</option>
                                    </select>
                                </div>
                            @endif

                            @if($showSalesman)
                                <div class="doc-field" style="grid-column: span 4;">
                                    <label>พนักงานขาย</label>
                                    <select name="salesman_id" class="doc-select">
                                        <option value="">ไม่ระบุ</option>
                                        @foreach($salesmen as $salesman)
                                            <option value="{{ $salesman->id }}">{{ $salesman->code }} - {{ $salesman->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if($showParty)
                                <div class="doc-field" style="grid-column: span {{ $showCreditType || $showSalesman ? 5 : 8 }};">
                                    <label>@if($partyRequired)<span class="required">*</span>@endif {{ $partyLabel }}</label>
                                    <input type="text" x-model="partyQuery" @input.debounce.250ms="searchParty()"
                                           placeholder="ค้นหารหัส / ชื่อ{{ $partyLabel }}" class="doc-input" autocomplete="off">
                                    <input type="hidden" name="{{ $partyType === 'supplier' ? 'supplier_id' : 'customer_id' }}" x-model="partyId" @if($partyRequired) required @endif>
                                    <div class="doc-typeahead" x-show="partyResults.length" x-transition>
                                        <template x-for="party in partyResults" :key="party.id">
                                            <button type="button" class="doc-option" @click="selectParty(party)">
                                                <span class="doc-code" x-text="party.code"></span>
                                                <span x-text="party.name_th"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <details class="doc-details">
                            <summary>รายละเอียดเพิ่มเติม</summary>
                            <div class="doc-field mt-2">
                                <label>หมายเหตุ</label>
                                <input type="text" name="remark" class="doc-input" placeholder="{{ $remarkPlaceholder ?? 'เช่น เลขอ้างอิง เงื่อนไข หรือหมายเหตุภายใน' }}">
                            </div>
                        </details>
                    </div>

                    <div class="doc-card doc-card-pad">
                        <div class="doc-meta-line"><span>วันที่เอกสาร</span><strong>{{ now()->thaiDate() }}</strong></div>
                        <div class="doc-meta-line"><span>เลขที่</span><strong>ระบบออกให้อัตโนมัติ</strong></div>
                        <div class="doc-meta-line"><span>จำนวนรายการ</span><strong><span x-text="items.length"></span> รายการ</strong></div>
                        <div class="doc-meta-line"><span>จำนวนรวม</span><strong><span x-text="money(totalQty)"></span> ชิ้น</strong></div>
                        <div class="doc-meta-total">
                            <div class="doc-total-label">รวมทั้งสิ้น</div>
                            <div class="doc-total-value">฿<span x-text="money(totalAmount)"></span></div>
                        </div>
                    </div>
                </div>

                <div class="doc-card">
                    <div class="doc-items-head">
                        <strong>{{ $itemTitle ?? 'รายการสินค้า' }}</strong>
                        <button type="button" class="doc-add" @click="addItem()">
                            <i class="bi bi-plus-lg me-1"></i> เพิ่มแถว
                        </button>
                    </div>

                    <div class="doc-table-wrap">
                        <table class="doc-table">
                            <thead>
                                <tr>
                                    <th class="row-no">#</th>
                                    <th>สินค้า</th>
                                    <th class="qty text-end">จำนวน</th>
                                    <th class="price text-end">ต่อหน่วย</th>
                                    <th class="sum text-end">จำนวนเงิน</th>
                                    <th class="del"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, index) in items" :key="index">
                                    <tr>
                                        <td class="row-no" x-text="index + 1"></td>
                                        <td style="position: relative;">
                                            <input type="text" x-model="item.productQuery" @input.debounce.250ms="searchProducts(index)"
                                                   placeholder="ยิงบาร์โค้ด / ค้นหารหัส / ชื่อสินค้า" class="doc-input" autocomplete="off">
                                            <input type="hidden" :name="`items[${index}][product_id]`" x-model="item.product_id">
                                            <div class="doc-typeahead" x-show="item.results.length" x-transition>
                                                <template x-for="product in item.results" :key="product.id">
                                                    <button type="button" class="doc-option" @click="selectProduct(index, product)">
                                                        <span class="doc-code" x-text="product.sku_code"></span>
                                                        <span x-text="product.name_th"></span>
                                                        <strong class="text-success" x-text="'฿' + money(product.default_price)"></strong>
                                                    </button>
                                                </template>
                                            </div>
                                            @if($showLotFields)
                                            <div x-show="item.product_id" class="d-flex gap-1 align-items-center flex-wrap mt-1">
                                                <input type="text" :name="`items[${index}][lot_number]`" x-model="item.lot_number" class="doc-input" style="max-width:130px" placeholder="เลข Lot">
                                                <input type="date" :name="`items[${index}][expiry_date]`" x-model="item.expiry_date" class="doc-input" style="max-width:150px" :required="item.tracks_expiry" :title="item.tracks_expiry ? 'ต้องระบุวันหมดอายุ' : 'วันหมดอายุ (ถ้ามี)'">
                                                <span x-show="item.tracks_expiry" class="badge text-bg-warning">ต้องระบุวันหมดอายุ</span>
                                            </div>
                                            @endif
                                        </td>
                                        <td><input type="number" step="0.0001" min="0.0001" :name="`items[${index}][qty]`" x-model.number="item.qty" required class="doc-input text-end"></td>
                                        <td><input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.unit_price" required class="doc-input text-end"></td>
                                        <td class="doc-line-total">฿<span x-text="money(item.qty * item.unit_price)"></span></td>
                                        <td class="del">
                                            <button type="button" class="doc-delete" @click="removeItem(index)" x-show="items.length > 1">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="doc-footer">
                <div class="doc-footer-total">
                    <span class="doc-total-label">รวมสุทธิ</span>
                    <span class="doc-total-value">฿<span x-text="money(totalAmount)"></span></span>
                </div>
                <div class="doc-actions">
                    <button type="button" class="doc-btn doc-btn-secondary" @click="closeModal()">ยกเลิก</button>
                    <button type="submit" class="doc-btn {{ $submitClass }}">
                        <i class="bi bi-check2-circle me-1"></i> {{ $submitLabel ?? 'บันทึก' }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
