@extends('layout')
@section('title', 'ตั้งค่า PLU เครื่องชั่ง')
@section('page-title', 'ตั้งค่า PLU เครื่องชั่ง')
@section('page-subtitle', 'ตรวจรหัสลูก ราคา และทดสอบป้ายก่อนขาย')
@section('content')
<div x-data="{ ...erpProductPicker(), q: '', tab: 'problem', issue: '', moving: null, items: [{}],
    section: @js(request('section', 'audit')), inactive: false, planDirty: false, targetSource: null,
    onProductPicked(i,p) { this.items[0]=p; this.items[0].product_id=p.id; if(this.targetSource) { document.getElementById('target-'+this.targetSource).value=p.id; document.getElementById('target-label-'+this.targetSource).textContent=p.sku_code+' '+p.name_th; } },
    showRow(el) { return (!this.q || el.dataset.search.includes(this.q.toLowerCase())) && (!this.issue || JSON.parse(el.dataset.issues).includes(this.issue)) && (this.tab === 'all' || (this.tab === 'problem' ? el.dataset.problem === '1' : el.dataset.problem === '0')); }
}">
    <nav class="d-flex gap-2 flex-wrap mb-3" aria-label="จัดการ PLU">
        <button type="button" class="btn border" :class="section==='audit' ? 'btn-primary' : 'btn-light'" @click="section='audit'">ตรวจสินค้าชั่ง</button>
        <button type="button" class="btn border" :class="section==='plan' ? 'btn-primary' : 'btn-light'" @click="section='plan'">แผนรันเลขใหม่</button>
        <button type="button" class="btn border" :class="section==='current' ? 'btn-primary' : 'btn-light'" @click="section='current'">PLU ปัจจุบัน</button>
    </nav>
    @include('scale-prices.renumber')
    <div x-show="section==='current'">
    <form id="all-prices" method="post" action="{{ route('scale-prices.update') }}">@csrf</form>
    <button form="all-prices" class="btn btn-success mb-3">บันทึกทั้งหมด</button>
    <div class="content-card p-3 mb-3">
        <p class="mb-2">PLU 800xxx/801xxx ต้องเป็นรหัสลูกใต้ SKU จริง ราคา ERP และเครื่องชั่งต้องเท่ากัน เพราะ POS คำนวณน้ำหนักจากราคารวม ÷ ราคา/กก.</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach($issues as $key => $label)
                <button type="button" class="btn btn-sm border" :class="issue === '{{ $key }}' ? 'btn-primary' : 'btn-light'" @click="issue = issue === '{{ $key }}' ? '' : '{{ $key }}'; tab='all'">{{ $label }} <b>{{ $counts[$key] }}</b></button>
            @endforeach
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <input class="form-control" style="max-width:320px" x-model="q" placeholder="ค้นหา PLU / SKU / ชื่อ" aria-label="ค้นหา PLU SKU ชื่อ">
            <template x-for="t in [{id:'problem',name:'มีปัญหา'},{id:'all',name:'ทั้งหมด'},{id:'normal',name:'ปกติ'}]">
                <button type="button" class="btn btn-sm" :class="tab === t.id ? 'btn-primary' : 'btn-light border'" @click="tab=t.id;issue=''" x-text="t.name"></button>
            </template>
            <button type="button" class="btn btn-sm btn-light border" @click="q=''; issue=''; tab='all'">ล้างตัวกรอง</button>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('scale-prices.export') }}">ส่งออก CSV</a>
            <span class="small text-muted">{{ $rows->count() }} แถว · รายการไม่มี PLU และยังไม่ตรวจราคาเป็นข้อมูล ไม่ใช่ข้อผิดพลาด</span>
        </div>
    </div>
    <div class="content-card p-3 mb-3">
        <div class="table-responsive">
            <table class="table align-middle scale-plu-table">
                <thead><tr><th>PLU</th><th>SKU แม่ / ชื่อ / สถานะ</th><th>ราคา ERP/กก.</th><th>ราคาเครื่องชั่ง/กก.</th><th>ปัญหา / วิธีแก้</th><th>จัดการ</th></tr></thead>
                <tbody>
                @forelse($rows as $i => $row)
                    @php($p = $row['product'])
                    @php($firstPriceRow = $rows->search(fn ($r) => $r['product']->id === $p->id) === $i)
                    <tr @input="$el.classList.add('table-warning')" x-show="showRow($el)" data-search="{{ mb_strtolower(($row['plu'] ?? '').' '.$p->sku_code.' '.$p->name_th) }}" data-issues="{{ json_encode($row['issues']) }}" data-problem="{{ $row['has_problem'] ? '1' : '0' }}">
                        <td data-label="PLU" class="fw-bold">{{ $row['plu'] ?? 'ยังไม่มี PLU' }}</td>
                        <td data-label="SKU / ชื่อ / สถานะ" style="min-width:180px"><a href="{{ route('products.show', $p) }}">{{ $p->sku_code }} · {{ $p->name_th }}</a><br><span class="badge {{ $p->is_active ? 'text-bg-success' : 'text-bg-danger' }}">{{ $p->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน' }}</span>
                        @foreach($overrides[$p->id] ?? [] as $override)
                            <div class="small text-warning">ราคาเฉพาะ {{ $override->name }}: {{ number_format($override->price, 2) }} · แก้ที่ตารางราคา</div>
                        @endforeach
                        </td>
                        <td data-label="ราคา ERP/กก."><input form="all-prices" class="form-control form-control-sm" style="min-width:110px" type="number" :disabled="{{ $firstPriceRow ? 'false' : 'true' }}" name="prices[{{ $p->id }}]" value="{{ old('prices.'.$p->id, number_format($row['price'], 2, '.', '')) }}" step="0.01" min="0" max="99999" aria-label="ราคา ERP {{ $p->sku_code }}"></td>
                        <td data-label="ราคาเครื่องชั่ง/กก.">
                            @if($row['plu'])
                            <input form="all-prices" class="form-control form-control-sm" style="min-width:110px" type="number" name="scale_prices[{{ $row['plu'] }}]" value="{{ old('scale_prices.'.$row['plu'], $row['scale_price']) }}" step="0.01" min="0" max="99999" placeholder="ยังไม่ตรวจ" aria-label="ราคาเครื่องชั่ง {{ $row['plu'] }}">
                            @else — @endif
                        </td>
                        <td data-label="ปัญหา / วิธีแก้" style="min-width:220px">
                            @forelse($row['issues'] as $key)
                                <div class="mb-1"><span class="badge {{ in_array($key, ['missing','unchecked']) ? 'text-bg-info' : (in_array($key, ['duplicate','inactive','sku_plu','mismatch']) ? 'text-bg-danger' : 'text-bg-warning') }}" style="white-space:normal;text-align:left">{{ $issues[$key] }}</span></div>
                            @empty <span class="badge text-bg-success">ปกติ</span> @endforelse
                        </td>
                        <td data-label="จัดการ" style="min-width:130px">

                            @if($row['barcode'])
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" @click="targetSource=null; moving={{ json_encode(['id' => $row['barcode']->id, 'from' => $p->id, 'plu' => $row['plu'], 'sku' => $p->sku_code], JSON_HEX_APOS | JSON_HEX_QUOT) }}; items=[{}]">ย้าย PLU / ยืนยัน SKU</button>
                            @endif
                        </td>
                    </tr>
                @empty <tr><td colspan="6">ยังไม่มีรายการเครื่องชั่ง</td></tr> @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="content-card p-3 mb-3" x-show="moving" x-cloak>
        <h3 class="h6">ย้าย PLU เดิม <span x-text="moving?.plu"></span> จาก SKU <span x-text="moving?.sku"></span></h3>
        <form method="post" action="{{ route('scale-prices.move') }}">@csrf
            <input type="hidden" name="barcode_id" :value="moving?.id"><input type="hidden" name="from_product_id" :value="moving?.from"><input type="hidden" name="product_id" :value="items[0]?.product_id">
            <button type="button" class="btn btn-outline-primary mb-2" @click="openProductPicker(0)">เลือก SKU จริงที่เปิดใช้งาน</button>
            <p x-text="(items[0]?.sku_code || '') + ' ' + (items[0]?.name_th || '')"></p>
            <label class="d-block mb-2"><input type="checkbox" name="confirmed" value="1" required> ยืนยันตรวจชื่อบนป้ายตรงกับ SKU ใหม่แล้ว (คงเลข PLU เดิม)</label>
            <button class="btn btn-primary" :disabled="!items[0]?.product_id">ยืนยันย้าย PLU</button>
            <button type="button" class="btn btn-light" @click="moving=null">ยกเลิก</button>
        </form>
    </div>

    <div class="row g-3">
        <section class="col-lg-6"><div class="content-card p-3 h-100">
            <h3 class="h6">นำเข้าราคาเครื่องชั่ง</h3>
            <p class="small text-muted">วางหรืออัปโหลด CSV สองคอลัมน์ PLU,ราคา/กก. มีหัวตารางได้ · ตรวจตัวอย่างก่อนบันทึก</p>
            <form method="post" action="{{ route('scale-prices.import-preview') }}" enctype="multipart/form-data">@csrf
                <textarea class="form-control mb-2" name="csv" rows="4" placeholder="PLU,ราคา/กก.&#10;801003,100.00">{{ old('csv') }}</textarea>
                <input class="form-control mb-2" type="file" name="file" accept=".csv,text/csv" aria-label="ไฟล์ราคา CSV">
                <button class="btn btn-outline-primary">ดูตัวอย่าง</button>
            </form>
            @if($preview)
            <div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>PLU</th><th>ราคาเครื่องชั่งเดิม</th><th>ราคาใหม่/กก.</th></tr></thead><tbody>
            @foreach($preview as $r)<tr><td>{{ $r['plu'] }}</td><td>{{ $r['old'] ?? 'ยังไม่ตรวจ' }}</td><td>{{ $r['price'] }}</td></tr>@endforeach
            </tbody></table></div>
            <form method="post" action="{{ route('scale-prices.import-save') }}">@csrf
                <label><input type="checkbox" name="confirmed" value="1" required> ยืนยันบันทึกราคาเครื่องชั่ง {{ count($preview) }} รายการ</label>
                <button class="btn btn-success">บันทึกที่ตรวจแล้ว</button>
            </form>
            @endif
        </div></section>
        <section class="col-lg-6"><div class="content-card p-3 h-100">
            <h3 class="h6">ทดสอบป้าย</h3>
            <form method="post" action="{{ route('scale-prices.test-label') }}">@csrf
                <label class="form-label">สาขา (ราคาเดียวกับ POS)</label>
                <select class="form-select mb-2" name="branch_id" required>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', auth()->user()->branch_id) == $branch->id)>{{ $branch->name_th }}</option>@endforeach</select>
                <input class="form-control mb-2" name="label" value="{{ old('label') }}" inputmode="numeric" pattern="[0-9]{13}" maxlength="13" required placeholder="บาร์โค้ดป้าย 13 หลัก" aria-label="บาร์โค้ดป้าย 13 หลัก">
                <button class="btn btn-outline-primary">ทดสอบป้าย</button>
            </form>
            @if($labelResult)
                <div class="mt-3" role="status">
                    @if($labelResult['error'] ?? null)<p class="text-danger">{{ $labelResult['error'] }}</p>@endif
                    @if(isset($labelResult['plu']))
                        <p>Profile: {{ $labelResult['profile'] }} · PLU: <b>{{ $labelResult['plu'] }}</b><br>ผ่านการถอดรหัสตาม profile และกติกา check digit ที่ตั้งไว้</p>
                        <p>สินค้า: {{ $labelResult['product']['name_th'] ?? 'ไม่พบ' }}<br>ราคา ERP/กก.: {{ number_format($labelResult['erp_price'], 2) }}<br>
                        {{ $labelResult['value_type'] === 'price' ? 'ราคารวม: '.number_format($labelResult['price'], 2).' บาท' : 'ป้ายน้ำหนัก' }}<br>น้ำหนักที่ POS ได้: {{ $labelResult['qty'] !== null ? number_format($labelResult['qty'], 8).' กก.' : 'คำนวณไม่ได้' }}</p>
                        @if($labelResult['mismatch'])<p class="text-danger fw-bold">ราคาเครื่องชั่ง {{ $labelResult['scale_price'] }} ไม่ตรงกับ ERP — น้ำหนักจากป้ายราคาจะเพี้ยน กรุณาตั้งราคาให้ตรงกัน</p>
                        @elseif($labelResult['scale_price'] === null)<p class="text-warning">ยังไม่ระบุราคาเครื่องชั่ง จึงยังยืนยันความถูกต้องของน้ำหนักไม่ได้</p>@endif
                    @endif
                </div>
            @endif
        </div></section>
    </div>
</div>
@include('partials.product-picker')
</div>
@endsection

@push('head')
<style>
@media screen and (max-width: 1024px) {
    .scale-plu-table, .scale-plu-table tbody { display: block; width: 100%; }
    .scale-plu-table thead { display: none; }
    .scale-plu-table tbody > tr { display: block; margin-bottom: 16px; border: 1px solid var(--erp-border); border-radius: 8px; padding: 8px; }
    .scale-plu-table tbody > tr > td { display: block; width: 100%; min-width: 0 !important; white-space: normal; overflow-wrap: anywhere; border: 0; }
    .scale-plu-table td[data-label]::before { content: attr(data-label); display: block; font-weight: 700; font-size: 12px; color: var(--erp-muted); margin-bottom: 4px; }
    .scale-plu-table input, .scale-plu-table button { min-width: 0 !important; max-width: 100%; white-space: normal; }
}
</style>
@endpush
