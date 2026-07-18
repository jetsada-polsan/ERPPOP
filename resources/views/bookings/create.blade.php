@extends('layout')

@section('title', 'สร้างใบจอง - POPSTAR ERP')

@section('content')
    <a href="{{ route('bookings.index') }}" class="text-sm text-blue-600 hover:underline">&larr; กลับไปรายการใบจอง</a>

    <h1 class="text-2xl font-bold my-4">สร้างใบจอง</h1>

    <form method="post" action="{{ route('bookings.store') }}" x-data="bookingForm()" @submit="onSubmit">
        @csrf

        <div class="bg-white rounded-xl shadow p-5 mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm text-gray-500 mb-1">สาขา</label>
                <select name="branch_id" required class="w-full border rounded px-2 py-1.5 text-sm">
                    @foreach($branches as $br)
                    <option value="{{ $br->id }}">{{ $br->code }} - {{ $br->name_th }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-500 mb-1">พนักงานขาย (ไม่บังคับ)</label>
                <select name="salesman_id" class="w-full border rounded px-2 py-1.5 text-sm">
                    <option value="">-- ไม่ระบุ --</option>
                    @foreach($salesmen as $s)
                    <option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="relative">
                <label class="block text-sm text-gray-500 mb-1">ลูกค้า</label>
                <input type="text" x-model="customerQuery" @input.debounce.300ms="searchCustomers()"
                    placeholder="ค้นหารหัส/ชื่อลูกค้า..." required
                    class="w-full border rounded px-2 py-1.5 text-sm">
                <input type="hidden" name="customer_id" x-model="customerId" required>
                <div x-show="customerResults.length" x-cloak
                    class="absolute z-10 bg-white border rounded shadow mt-1 w-full max-h-60 overflow-auto">
                    <template x-for="c in customerResults" :key="c.id">
                        <div @click="selectCustomer(c)" class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer"
                            x-text="c.code + ' - ' + c.name_th"></div>
                    </template>
                </div>
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm text-gray-500 mb-1">หมายเหตุ</label>
                <input type="text" name="remark" class="w-full border rounded px-2 py-1.5 text-sm">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-5 mb-6">
            <h2 class="font-semibold mb-3">รายการสินค้า</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 w-1/2">สินค้า</th>
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
                                <input type="text" x-model="item.productQuery" @input.debounce.300ms="searchProducts(idx)"
                                    placeholder="ค้นหารหัส/ชื่อสินค้า..." class="w-full border rounded px-2 py-1.5">
                                <input type="hidden" :name="`items[${idx}][product_id]`" x-model="item.product_id">
                                <div x-show="item.results.length" x-cloak
                                    class="absolute z-10 bg-white border rounded shadow mt-1 w-full max-h-60 overflow-auto">
                                    <template x-for="p in item.results" :key="p.id">
                                        <div @click="selectProduct(idx, p)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer"
                                            x-text="p.sku_code + ' - ' + p.name_th"></div>
                                    </template>
                                </div>
                            </td>
                            <td class="text-right">
                                <input type="number" step="0.0001" min="0.0001" :name="`items[${idx}][qty]`"
                                    x-model.number="item.qty" required class="w-20 border rounded px-2 py-1.5 text-right">
                            </td>
                            <td class="text-right">
                                <input type="number" step="0.01" min="0" :name="`items[${idx}][unit_price]`"
                                    x-model.number="item.unit_price" required class="w-28 border rounded px-2 py-1.5 text-right">
                            </td>
                            <td class="text-right" x-text="(item.qty * item.unit_price).toFixed(2)"></td>
                            <td class="text-center">
                                <button type="button" @click="removeItem(idx)" class="text-red-500 hover:underline" x-show="items.length > 1">ลบ</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="3" class="text-right py-2">รวมทั้งสิ้น</td>
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
        return {
            customerQuery: '', customerId: '', customerResults: [],
            items: [{ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [] }],

            async searchCustomers() {
                if (this.customerQuery.length < 1) { this.customerResults = []; return; }
                const res = await fetch(`{{ route('search.customers') }}?q=${encodeURIComponent(this.customerQuery)}`);
                this.customerResults = await res.json();
            },
            selectCustomer(c) {
                this.customerId = c.id;
                this.customerQuery = `${c.code} - ${c.name_th}`;
                this.customerResults = [];
            },
            addItem() {
                this.items.push({ product_id: '', productQuery: '', qty: 1, unit_price: 0, results: [] });
            },
            removeItem(idx) {
                this.items.splice(idx, 1);
            },
            async searchProducts(idx) {
                const q = this.items[idx].productQuery;
                if (q.length < 1) { this.items[idx].results = []; return; }
                const res = await fetch(`{{ route('search.products') }}?q=${encodeURIComponent(q)}`);
                this.items[idx].results = await res.json();
            },
            selectProduct(idx, p) {
                this.items[idx].product_id = p.id;
                this.items[idx].productQuery = `${p.sku_code} - ${p.name_th}`;
                this.items[idx].unit_price = p.default_price ?? 0;
                this.items[idx].results = [];
            },
            get totalAmount() {
                return this.items.reduce((sum, i) => sum + (parseFloat(i.qty) || 0) * (parseFloat(i.unit_price) || 0), 0);
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
                }
            },
        };
    }
</script>
@endpush
