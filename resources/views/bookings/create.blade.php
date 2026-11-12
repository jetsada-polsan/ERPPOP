@extends('layout')

@section('title', 'สร้างใบจอง - MPM Food Group')

@section('content')
    <a href="{{ route('bookings.index') }}" class="text-sm text-blue-600 hover:underline">&larr; กลับไปรายการใบจอง</a>

    <h1 class="text-2xl font-bold my-4">สร้างใบจอง</h1>

    <form method="post" action="{{ route('bookings.store') }}" data-draft x-data="bookingForm()" @submit="onSubmit">
        @csrf

        <div class="bg-white rounded-xl shadow p-5 mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm text-gray-500 mb-1">สาขา</label>
                <select name="branch_id" x-model="branchId" required class="w-full border rounded px-2 py-1.5 text-sm">
                    @foreach($branches as $br)
                    <option value="{{ $br->id }}">{{ $br->code }} - {{ $br->name_th }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-500 mb-1">พนักงานขาย (ไม่บังคับ)</label>
                <select name="salesman_id" x-model="salesmanId" class="w-full border rounded px-2 py-1.5 text-sm">
                    <option value="">-- ไม่ระบุ --</option>
                    @foreach($salesmen as $s)
                    <option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="relative">
                <label class="block text-sm text-gray-500 mb-1">ลูกค้า</label>
                <input type="text" x-model="customerQuery" @input.debounce.250ms="searchCustomers()"
                    @keydown.arrow-down.prevent="moveCustomerActive(1)"
                    @keydown.arrow-up.prevent="moveCustomerActive(-1)"
                    @keydown.enter.prevent="selectActiveCustomer()"
                    placeholder="ค้นหารหัส/ชื่อลูกค้า..." required
                    class="w-full border rounded px-2 py-1.5 text-sm">
                <input type="hidden" name="customer_id" x-model="customerId" required>
                <div x-show="customerResults.length" x-cloak
                    class="absolute z-10 bg-white border rounded shadow mt-1 w-full max-h-60 overflow-auto">
                    <template x-for="(c, cIdx) in customerResults" :key="c.id">
                        <div @click="selectCustomer(c)"
                            :class="{ 'bg-blue-100 font-semibold': cIdx === customerActiveIndex, 'hover:bg-blue-50': cIdx !== customerActiveIndex }"
                            class="px-3 py-2 text-sm cursor-pointer"
                            x-text="c.code + ' - ' + c.name_th"></div>
                    </template>
                </div>
            </div>
            <div class="md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-500 mb-1">วิธีรับสินค้า</label>
                    <select name="fulfillment_type" x-model="fulfillment" class="w-full border rounded px-2 py-1.5 text-sm">
                        <option value="pickup">รับเองที่สาขา</option>
                        <option value="delivery">จัดส่ง</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-500 mb-1">
                        กำหนดส่ง <span x-show="fulfillment === 'delivery'" class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" name="delivery_due_at" x-model="deliveryDueAt" :required="fulfillment === 'delivery'"
                        :disabled="fulfillment !== 'delivery'" class="w-full border rounded px-2 py-1.5 text-sm disabled:bg-gray-100">
                    <p x-show="fulfillment === 'delivery'" class="text-xs text-gray-500 mt-1">
                        ใบจองแบบจัดส่งต้องมีกำหนดส่ง จึงจะยืนยันเป็นใบขายได้
                    </p>
                </div>
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm text-gray-500 mb-1">หมายเหตุ</label>
                <input type="text" name="remark" x-model="remark" class="w-full border rounded px-2 py-1.5 text-sm">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-5 mb-6">
            <h2 class="font-semibold mb-3">รายการสินค้า</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 w-1/3">สินค้า</th>
                        <th class="w-28">หน่วย</th>
                        <th class="text-right">จำนวน</th>
                        <th class="text-right">ราคา/หน่วย</th>
                        <th class="text-right">รวม</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(item, idx) in items" :key="idx">
                        <tr class="border-b last:border-0">
                            <td class="py-2 relative">
                                <input type="text" x-model="item.productQuery" @input.debounce.250ms="searchProducts(idx)"
                                    @keydown.arrow-down.prevent="moveProductActive(idx, 1)"
                                    @keydown.arrow-up.prevent="moveProductActive(idx, -1)"
                                    @keydown.enter.prevent="selectActiveProduct(idx)"
                                    placeholder="ค้นหารหัส/ชื่อสินค้า..." class="w-full border rounded px-2 py-1.5">
                                <input type="hidden" :name="`items[${idx}][product_id]`" x-model="item.product_id">
                                <div x-show="item.results.length" x-cloak
                                    class="absolute z-10 bg-white border rounded shadow mt-1 w-full max-h-60 overflow-auto">
                                    <template x-for="(p, pIdx) in item.results" :key="p.id">
                                        <div @click="selectProduct(idx, p)"
                                            :class="{ 'bg-blue-100 font-semibold': pIdx === item.activeIndex, 'hover:bg-blue-50': pIdx !== item.activeIndex }"
                                            class="px-3 py-2 cursor-pointer"
                                            x-text="p.sku_code + ' - ' + p.name_th"></div>
                                    </template>
                                </div>
                            </td>
                            <td>
                                <template x-if="item.units && item.units.length > 1">
                                    <select class="w-full border rounded px-1 py-1 text-xs" x-model="item.unit_id" @change="onUnitChanged(idx)">
                                        <template x-for="u in item.units" :key="u.unit_id">
                                            <option :value="u.unit_id" x-text="u.name + (u.factor > 1 ? ' (x' + u.factor + ')' : '')"></option>
                                        </template>
                                    </select>
                                </template>
                                <span x-show="!item.units || item.units.length <= 1" class="text-xs text-gray-500" x-text="item.unit_name || '-'"></span>
                            </td>
                            <td class="text-right">
                                <input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][unit_qty]`"
                                    x-model.number="item.unit_qty" @input="updateLine(idx)" required class="w-20 border rounded px-2 py-1.5 text-right">
                                <input type="hidden" :name="`items[${idx}][qty]`" :value="item.qty">
                                <input type="hidden" :name="`items[${idx}][unit_id]`" :value="item.unit_id || ''">
                                <input type="hidden" :name="`items[${idx}][unit_factor]`" :value="item.unit_factor || 1">
                                <div x-show="item.unit_factor > 1" class="text-xs text-blue-600 font-semibold" x-text="'= ' + (item.unit_qty * item.unit_factor) + ' ' + (item.base_unit_name || '')"></div>
                            </td>
                            <td class="text-right">
                                <input type="number" step="0.01" min="0" :name="`items[${idx}][unit_price]`"
                                    x-model.number="item.unit_price" @input="checkPrice(idx)" required class="w-28 border rounded px-2 py-1.5 text-right">
                                <div x-show="item.priceWarning" class="text-xs text-red-600 font-semibold" x-text="item.priceWarning"></div>
                            </td>
                            <td class="text-right" x-text="((item.unit_qty !== undefined ? item.unit_qty : item.qty) * item.unit_price).toFixed(2)"></td>
                            <td class="text-center">
                                <button type="button" @click="removeItem(idx)" class="text-red-500 hover:underline" x-show="items.length > 1">ลบ</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="4" class="text-right py-2">รวมทั้งสิ้น</td>
                        <td class="text-right" x-text="totalAmount.toFixed(2)"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <button type="button" @click="addItem()" class="mt-3 text-blue-600 text-sm hover:underline">+ เพิ่มรายการ</button>
        </div>

        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded font-semibold">บันทึกใบจอง</button>
    </form>
@endsection

@push('scripts')
<script>
    function bookingForm() {
        const STORAGE_KEY = 'erp:draft:booking_create:{{ auth()->id() }}';
        return {
            branchId: '{{ $branches->first()?->id ?? "" }}',
            salesmanId: '',
            fulfillment: 'pickup',
            deliveryDueAt: '',
            remark: '',
            customerQuery: '',
            customerId: '',
            customerResults: [],
            customerActiveIndex: 0,
            customerController: null,
            items: [{ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [], activeIndex: 0, controller: null }],
            submitting: false,

            init() {
                try {
                    const saved = localStorage.getItem(STORAGE_KEY);
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        if (parsed && Date.now() - parsed.time < 24 * 60 * 60 * 1000) {
                            if (window.confirm('พบข้อมูลร่างใบจองที่ยังไม่ได้บันทึก ต้องการกู้คืนหรือไม่?')) {
                                this.branchId = parsed.data.branchId || this.branchId;
                                this.salesmanId = parsed.data.salesmanId || '';
                                this.fulfillment = parsed.data.fulfillment || 'pickup';
                                this.deliveryDueAt = parsed.data.deliveryDueAt || '';
                                this.remark = parsed.data.remark || '';
                                this.customerId = parsed.data.customerId || '';
                                this.customerQuery = parsed.data.customerQuery || '';
                                if (Array.isArray(parsed.data.items) && parsed.data.items.length) {
                                    this.items = parsed.data.items.map(it => ({
                                        product_id: it.product_id || '',
                                        productQuery: it.productQuery || '',
                                        qty: Number(it.qty) || 1,
                                        unit_price: Number(it.unit_price) || 0,
                                        results: [],
                                        activeIndex: 0,
                                        controller: null,
                                    }));
                                }
                            } else {
                                localStorage.removeItem(STORAGE_KEY);
                            }
                        }
                    }
                } catch (_) {}

                this.$watch('items', () => this.saveToLocal(), { deep: true });
                this.$watch('customerId', () => this.saveToLocal());
                this.$watch('customerQuery', () => this.saveToLocal());
                this.$watch('branchId', () => this.saveToLocal());
                this.$watch('salesmanId', () => this.saveToLocal());
                this.$watch('fulfillment', () => this.saveToLocal());
                this.$watch('deliveryDueAt', () => this.saveToLocal());
                this.$watch('remark', () => this.saveToLocal());

                window.addEventListener('beforeunload', (e) => {
                    if (this.submitting) return;
                    if (this.hasUnsavedData()) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            },

            hasUnsavedData() {
                return Boolean(this.customerId || this.items.some(it => it.product_id || it.productQuery));
            },

            saveToLocal() {
                if (this.submitting) return;
                try {
                    if (this.hasUnsavedData()) {
                        const data = {
                            branchId: this.branchId,
                            salesmanId: this.salesmanId,
                            fulfillment: this.fulfillment,
                            deliveryDueAt: this.deliveryDueAt,
                            remark: this.remark,
                            customerId: this.customerId,
                            customerQuery: this.customerQuery,
                            items: this.items.map(it => ({
                                product_id: it.product_id,
                                productQuery: it.productQuery,
                                qty: it.qty,
                                unit_price: it.unit_price,
                            })),
                        };
                        localStorage.setItem(STORAGE_KEY, JSON.stringify({ time: Date.now(), data }));
                    } else {
                        localStorage.removeItem(STORAGE_KEY);
                    }
                } catch (_) {}
            },

            async searchCustomers() {
                const q = this.customerQuery.trim();
                if (q.length < 1) {
                    this.customerResults = [];
                    this.customerId = '';
                    return;
                }
                this.customerController?.abort();
                this.customerController = new AbortController();
                try {
                    const res = await fetch(`{{ route('search.customers') }}?q=${encodeURIComponent(q)}`, {
                        signal: this.customerController.signal
                    });
                    if (res.ok) {
                        this.customerResults = await res.json();
                        this.customerActiveIndex = 0;
                    }
                } catch (err) {
                    if (err.name !== 'AbortError') this.customerResults = [];
                }
            },
            moveCustomerActive(delta) {
                if (!this.customerResults.length) return;
                const next = this.customerActiveIndex + delta;
                if (next >= 0 && next < this.customerResults.length) {
                    this.customerActiveIndex = next;
                }
            },
            selectActiveCustomer() {
                const c = this.customerResults[this.customerActiveIndex];
                if (c) this.selectCustomer(c);
            },
            selectCustomer(c) {
                this.customerController?.abort();
                this.customerId = c.id;
                this.customerQuery = `${c.code} - ${c.name_th}`;
                this.customerResults = [];
            },
            addItem() {
                this.items.push({ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [], activeIndex: 0, controller: null });
            },
            removeItem(idx) {
                this.items.splice(idx, 1);
            },
            async searchProducts(idx) {
                const item = this.items[idx];
                const q = (item.productQuery || '').trim();
                if (q.length < 1) {
                    item.results = [];
                    item.product_id = '';
                    return;
                }
                item.controller?.abort();
                item.controller = new AbortController();
                try {
                    const res = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(q)}`, {
                        signal: item.controller.signal
                    });
                    if (res.ok) {
                        item.results = await res.json();
                        item.activeIndex = 0;
                    }
                } catch (err) {
                    if (err.name !== 'AbortError') item.results = [];
                }
            },
            moveProductActive(idx, delta) {
                const item = this.items[idx];
                if (!item.results.length) return;
                const next = item.activeIndex + delta;
                if (next >= 0 && next < item.results.length) {
                    item.activeIndex = next;
                }
            },
            selectActiveProduct(idx) {
                const item = this.items[idx];
                const p = item.results[item.activeIndex];
                if (p) this.selectProduct(idx, p);
            },
            selectProduct(idx, p) {
                const item = this.items[idx];
                item.controller?.abort();
                item.product_id = p.id;
                item.productQuery = `${p.sku_code} - ${p.name_th}`;
                item.units = p.units || [];
                item.base_unit_name = p.base_unit_name || p.unit_name || '';

                let selectedUnit = null;
                if (p.matched_unit_id && item.units.length) {
                    selectedUnit = item.units.find(u => Number(u.unit_id) === Number(p.matched_unit_id));
                }
                if (!selectedUnit && item.units.length) {
                    selectedUnit = item.units.find(u => Number(u.unit_id) === Number(p.base_unit_id)) || item.units[0];
                }

                if (selectedUnit) {
                    item.unit_id = selectedUnit.unit_id;
                    item.unit_name = selectedUnit.name;
                    item.unit_factor = Number(selectedUnit.factor) || 1;
                    item.unit_price = Number((Number(p.default_price || 0) * item.unit_factor).toFixed(2));
                } else {
                    item.unit_id = p.base_unit_id || '';
                    item.unit_name = p.unit_name || '';
                    item.unit_factor = 1;
                    item.unit_price = Number(p.default_price || 0);
                }

                item.unit_qty = 1;
                item.qty = item.unit_qty * item.unit_factor;
                item.priceWarning = '';
                item.results = [];
            },
            onUnitChanged(idx) {
                const item = this.items[idx];
                const selected = (item.units || []).find(u => String(u.unit_id) === String(item.unit_id));
                if (selected) {
                    const oldFactor = item.unit_factor || 1;
                    const basePrice = (Number(item.unit_price) || 0) / oldFactor;
                    item.unit_name = selected.name;
                    item.unit_factor = Number(selected.factor) || 1;
                    item.unit_price = Number((basePrice * item.unit_factor).toFixed(2));
                    item.qty = (Number(item.unit_qty) || 0) * item.unit_factor;
                }
                this.checkPrice(idx);
            },
            updateLine(idx) {
                const item = this.items[idx];
                item.qty = (Number(item.unit_qty) || 0) * (item.unit_factor || 1);
            },
            checkPrice(idx) {
                const item = this.items[idx];
                const factor = item.unit_factor || 1;
                if (factor > 1) {
                    const typed = Number(item.unit_price) || 0;
                    const perBase = Math.round((typed / factor) * 10000) / 10000;
                    if (Math.abs((perBase * factor) - typed) > 0.0001) {
                        item.priceWarning = `ราคาหาร ${factor} ไม่ลงตัวที่ 4 ทศนิยม`;
                    } else {
                        item.priceWarning = '';
                    }
                } else {
                    item.priceWarning = '';
                }
            },
            get totalAmount() {
                return this.items.reduce((sum, i) => sum + (parseFloat(i.unit_qty !== undefined ? i.unit_qty : i.qty) || 0) * (parseFloat(i.unit_price) || 0), 0);
            },
            onSubmit(e) {
                if (!this.customerId) {
                    e.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกลูกค้า' });
                    return;
                }
                if (this.items.some(i => !i.product_id)) {
                    e.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกสินค้าให้ครบทุกรายการ' });
                    return;
                }
                if (this.items.some(i => i.priceWarning)) {
                    e.preventDefault();
                    Swal.fire({ icon: 'error', title: 'ราคาต่อหน่วยหารไม่ลงตัว', text: 'กรุณาแก้ไขราคาต่อหน่วยให้หารลงตัวที่ 4 ทศนิยมก่อนบันทึก' });
                    return;
                }
                this.submitting = true;
                localStorage.removeItem(STORAGE_KEY);
            },
        };
    }
</script>
@endpush
