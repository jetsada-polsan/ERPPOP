<div x-show="section==='audit'" class="content-card p-3 mb-3">
    <h2 class="h5">ตรวจสินค้าชั่งก่อนแจกเลข</h2>
    <p>เลือก SKU จริงเพียงตัวเดียวต่อสินค้า รายการ SKU เป็นเลข PLU ต้องเลือกปลายทางก่อน สินค้าปิดใช้งานจะไม่ได้เลข</p>
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <input x-model="q" class="form-control" style="max-width:320px" placeholder="ค้นหา SKU / ชื่อสินค้า" aria-label="ค้นหาสินค้าชั่ง">
        <button type="button" class="btn border" :class="!inactive?'btn-primary':'btn-light'" @click="inactive=false">เปิดใช้งาน</button>
        <button type="button" class="btn border" :class="inactive?'btn-primary':'btn-light'" @click="inactive=true">ปิดใช้งาน (เปิดในแฟ้มสินค้า)</button>
    </div>
    <form method="post" action="{{ route('scale-prices.plans.preview') }}">@csrf
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>เลือก / SKU</th><th>ชื่อ</th><th>ราคา/กก.</th><th>PLU เดิม</th><th>ปัญหา / วิธีแก้</th></tr></thead><tbody>
        @foreach($candidates as $r)
            @php($p=$r['product'])
            <tr x-show="inactive === {{ $p->is_active ? 'false' : 'true' }} && (!q || @js(mb_strtolower($p->sku_code.' '.$p->name_th)).includes(q.toLowerCase()))">
                <td style="min-width:150px">
                @if($p->is_active && !\App\Services\Inventory\ScalePluPage::isPlu($p->sku_code))
                    <input type="checkbox" name="selected[{{ $p->id }}]" value="{{ $p->id }}" aria-label="เลือก {{ $p->sku_code }}">
                @endif
                <a href="{{ route('products.show', $p) }}">{{ $p->sku_code }}</a></td>
                <td>{{ $p->name_th }}</td><td>{{ number_format($r['price'], 2) }}</td><td>{{ $p->barcodes->filter(fn ($b) => \App\Services\Inventory\ScalePluPage::isPlu($b->barcode))->pluck('barcode')->implode(', ') }}</td>
                <td style="min-width:230px">
                    @foreach($r['warnings'] as $warning)<div class="text-warning">{{ $warning }}</div>@endforeach
                    @if(!$p->is_active)<span>เปิดใช้งานในแฟ้มสินค้าก่อน</span>
                    @elseif(\App\Services\Inventory\ScalePluPage::isPlu($p->sku_code))
                        <input type="hidden" id="target-{{ $p->id }}" name="selected[{{ $p->id }}]" value="">
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="targetSource={{ $p->id }};openProductPicker(0)">เลือก SKU ปลายทาง</button>
                        <span id="target-label-{{ $p->id }}"></span>
                        <button type="button" class="btn btn-sm btn-light" @click="document.getElementById('target-{{ $p->id }}').value='';document.getElementById('target-label-{{ $p->id }}').textContent=''">ล้าง</button>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody></table></div>
        <button class="btn btn-primary">สร้างแผนเลขใหม่</button><span class="small ms-2">เรียงตามหมวดสินค้า แล้ว SKU เริ่ม 801001 ยังไม่เปลี่ยนบาร์โค้ดจริง</span>
    </form>
</div>
<div x-show="section==='plan'" class="content-card p-3 mb-3">
    <h2 class="h5">แผนรันเลขใหม่</h2>
    <p>สร้างแผนจากแท็บตรวจสินค้าชั่ง แก้เลขแล้วกดบันทึกตัวอย่างก่อนใช้แผน ล็อกเลขเพื่อคงเลขนั้นเมื่อเรียงใหม่</p>
    <div class="d-flex gap-2 flex-wrap mb-3">@foreach($plans as $history)
        <a class="btn btn-sm border" href="{{ route('scale-prices.index', ['plan'=>$history->id, 'section'=>'plan']) }}">ชุด {{ $history->id }} · {{ ['preview'=>'ตัวอย่าง','applied'=>'ใช้แล้ว','rolled_back'=>'ยกเลิกแล้ว'][$history->status] ?? \App\Support\DocumentStatus::label($history->status) }}</a>
    @endforeach</div>
    @if($plan)
    <input x-model="q" class="form-control mb-3" style="max-width:320px" placeholder="ค้นหาในแผน" aria-label="ค้นหาในแผน">
    <h3 class="h6">ชุด {{ $plan->id }} · สร้าง {{ $plan->created_at }} · ผู้ใช้ #{{ $plan->user_id }}</h3>
    <form method="post" action="{{ route('scale-prices.plans.edit', $plan->id) }}" @input="planDirty=true">@csrf
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>เลขใหม่ / ล็อก</th><th>PLU เดิม</th><th>SKU</th><th>ชื่อ</th><th>ราคา/กก.</th><th>ธงปัญหา</th></tr></thead><tbody>
        @foreach($plan->rows as $r)
        <tr x-show="!q || @js(mb_strtolower($r['sku'].' '.$r['name'].' '.$r['plu'])).includes(q.toLowerCase())" @input="$el.classList.add('table-warning')"><td style="min-width:145px"><input class="form-control form-control-sm" name="numbers[{{ $r['product_id'] }}]" value="{{ $r['plu'] }}" inputmode="numeric" pattern="801[0-9]{3}" required aria-label="เลขใหม่ {{ $r['sku'] }}" @disabled($plan->status!=='preview')>
        <label><input type="checkbox" name="locked[{{ $r['product_id'] }}]" value="1" @checked($r['locked']) @disabled($plan->status!=='preview')> ล็อกเลข</label></td>
        <td>{{ implode(', ', $r['old']) }}</td><td>{{ $r['sku'] }}</td><td>{{ $r['name'] }}</td><td>{{ number_format($r['price'],2) }}</td><td>@foreach($r['warnings'] as $warning)<div class="text-warning">{{ $warning }}</div>@endforeach</td></tr>
        @endforeach
        </tbody></table></div>
        @if($plan->status==='preview')<button class="btn btn-outline-primary">บันทึกตัวอย่างเลขที่แก้</button> <button class="btn btn-outline-secondary" name="reorder" value="1">เรียงใหม่ (คงเลขล็อก)</button>@endif
    </form>
    @if($plan->status==='preview')
    <div class="alert alert-warning mt-3">เลขเก่า 800/801 ของสินค้าที่เลือกจะถูกปิด ป้ายเก่าที่ไม่ได้ใช้เลขซ้ำจะยิงไม่ได้ หากเลขใหม่ซ้ำเลขเดิม ป้ายเก่าเลขนั้นยังอาจยิงติดหรือชี้สินค้าใหม่ ต้องเก็บป้ายเก่าออกทั้งหมด ตั้งเครื่องชั่งใหม่ และซิงก์ POS ก่อนขาย SKU ที่เป็นเลข PLU และเลือกย้ายจะถูกปิดใช้งานเพื่อหยุดการค้นด้วย SKU เดิม (คืนได้เมื่อยกเลิกแผน)</div>
    <p x-show="planDirty" class="text-danger">มีเลขที่ยังไม่บันทึก กรุณาบันทึกตัวอย่างก่อนยืนยัน</p>
    <form method="post" action="{{ route('scale-prices.plans.apply', $plan->id) }}">@csrf
        <input type="hidden" name="revision" value="{{ hash('sha256', json_encode($plan->rows)) }}">
        <label class="d-block"><input type="checkbox" name="accept_warnings" value="1"> ยอมรับธงปัญหาที่ยังไม่แก้ และตรวจเลือกสินค้าเดียวต่อชื่อซ้ำแล้ว</label>
        <label class="d-block mb-2"><input type="checkbox" name="confirmed" value="1" required> ยืนยันเปลี่ยนเลขทั้งชุดและรับทราบผลต่อป้ายเก่าแล้ว</label>
        <button class="btn btn-danger" :disabled="planDirty">ยืนยันใช้แผน</button>
    </form>
    @elseif($plan->status==='applied')
    <div class="d-flex gap-2 my-3"><a class="btn btn-primary" href="{{ route('scale-prices.plans.export', $plan->id) }}">ดาวน์โหลดไฟล์ตั้งเครื่องชั่ง CSV</a><a class="btn btn-outline-primary" target="_blank" href="{{ route('scale-prices.plans.labels', $plan->id) }}">ป้ายทดสอบ HTML / พิมพ์</a></div>
    <p>รหัสเครื่อง 3 หลักเก็บศูนย์นำหน้า เช่น 001 · รหัสป้าย 5 หลัก เช่น 01001 · ราคาเป็นราคาตอนสร้างแผน หากราคาเปลี่ยนให้สร้างชุดใหม่</p>
    <form method="post" action="{{ route('scale-prices.plans.rollback', $plan->id) }}">@csrf
        <label><input type="checkbox" name="confirmed" value="1" required> คืนสถานะเลขเดิม ปิดเลขใหม่ และจะตั้งเครื่องชั่ง/ซิงก์ POS กลับด้วย</label> <button class="btn btn-outline-danger">ยกเลิกชุดแผน</button>
    </form>
    @else <p>ยกเลิกชุดแผนแล้ว ดูเลขและประวัติได้จากตารางนี้</p> @endif
    @endif
</div>
