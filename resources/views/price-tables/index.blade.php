@extends('layout')
@section('title', 'ตารางราคา - POPSTAR ERP')
@section('page-title', 'ตารางราคา')
@section('page-subtitle', 'จัดการตารางราคาแต่ละสาขา — สาขาต่างกันขายราคาต่างกันได้')
@section('content')
    <div x-data="priceTablePage()" x-cloak>
        <section class="price-command mb-4">
            <div class="price-command-head">
                <div>
                    <div class="price-kicker">PRICE CENTER</div>
                    <h2>ศูนย์จัดการราคาขาย</h2>
                    <p>เลือกงานที่ต้องการ ไม่ต้องจำชื่อตารางราคาแบบเดิม</p>
                </div>
                <div class="price-status"><i class="bi bi-check-circle-fill"></i> POS ใช้กติกาอัตโนมัติ</div>
            </div>

            <div class="price-work-grid">
                <a href="#normal-price-tables" class="price-work-card blue">
                    <span class="price-work-icon"><i class="bi bi-shop"></i></span>
                    <span><strong>ราคาปกติทุก POS</strong><small>ราคากลางหนึ่งชุด ใช้ทุกเครื่องและทุกสาขา</small></span>
                    <b>{{ number_format($pricingSummary['normal_tables']) }} ตาราง</b>
                </a>
                <a href="{{ route('promotions.index') }}" class="price-work-card violet">
                    <span class="price-work-icon"><i class="bi bi-tags-fill"></i></span>
                    <span><strong>สินค้าโปรโมชั่น</strong><small>ลดบาท ลดเปอร์เซ็นต์ หรือมีเงื่อนไขยอดซื้อ</small></span>
                    <b>{{ number_format($pricingSummary['active_promotions']) }} ใช้งาน</b>
                </a>
                <a href="{{ route('flash-sales.index') }}" class="price-work-card red">
                    <span class="price-work-icon"><i class="bi bi-lightning-charge-fill"></i></span>
                    <span><strong>นาทีทอง</strong><small>ราคาพิเศษตามสาขา วันที่ วันในสัปดาห์ และช่วงเวลา</small></span>
                    <b>{{ number_format($pricingSummary['active_flash_sales']) }} แคมเปญ</b>
                </a>
                <a href="{{ route('scale-prices.index') }}" class="price-work-card green">
                    <span class="price-work-icon"><i class="bi bi-speedometer2"></i></span>
                    <span><strong>ราคาเครื่องชั่ง</strong><small>จัดการ PLU และราคาต่อกิโลกรัม พร้อมส่งออกไฟล์</small></span>
                    <b>{{ number_format($pricingSummary['scale_products']) }} สินค้า</b>
                </a>
            </div>

            <div class="price-rule">
                <div class="price-rule-title"><i class="bi bi-diagram-3-fill"></i> ลำดับราคาที่ POS เลือกใช้</div>
                <div class="price-rule-flow">
                    <span class="hot">1 ราคาลด / นาทีทองที่อยู่ในช่วงวันที่</span><i class="bi bi-chevron-right"></i>
                    <span>2 ราคาปกติกลางทุก POS</span><i class="bi bi-chevron-right"></i>
                    <span>3 ราคาสินค้า</span>
                </div>
            </div>
        </section>

        <div id="normal-price-tables" class="price-section-title">
            <div><span>ราคาปกติ POS / ราคาสาขา</span><small>หนึ่งสาขาเลือกตารางหลักหนึ่งชุด หากสินค้าไม่มีราคาเฉพาะจะถอยไปใช้ราคาหลักอัตโนมัติ</small></div>
            <span class="badge text-bg-primary">กำหนดแล้ว {{ $pricingSummary['assigned_branches'] }}/{{ $pricingSummary['total_branches'] }} สาขา</span>
        </div>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">รายการตารางราคา</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อตาราง'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 d-none" @click="openCreate()">
                <i class="bi bi-plus-lg me-1"></i> เพิ่มตารางราคา
            </button>
        </div>

        {{-- Branch assignment overview --}}
        @if(false)
        <div class="content-card p-4 mb-4">
            <h3 class="h6 fw-bold mb-3">ตารางราคาต่อสาขา</h3>
            <div class="row g-2">
                @foreach($branches as $branch)
                <div class="col-md-4 col-lg-3">
                    <div class="border rounded-3 p-3 {{ $branch->priceTable ? 'border-success bg-success bg-opacity-10' : 'border-dashed bg-light' }}">
                        <div class="fw-bold small">{{ $branch->code }} - {{ $branch->name_th }}</div>
                        <div class="text-muted" style="font-size:12px">
                            @if($branch->priceTable)
                                <span class="badge text-bg-success">{{ $branch->priceTable->name }}</span>
                            @else
                                <span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>ยังไม่กำหนด</span>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr><th>รหัส</th><th>ชื่อตาราง</th><th>คำอธิบาย</th><th class="text-end">จำนวนราคา</th><th>ค่าเริ่มต้น</th><th>สถานะ</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($tables as $t)
                        <tr>
                            <td class="fw-semibold">{{ $t->code }}</td>
                            <td><a href="{{ route('price-tables.show', $t) }}" class="text-decoration-none fw-semibold">{{ $t->name }}</a></td>
                            <td class="text-muted small">{{ $t->description }}</td>
                            <td class="text-end">{{ number_format($t->product_prices_count) }}</td>
                            <td>@if($t->is_default)<span class="badge text-bg-warning">ค่าเริ่มต้น</span>@endif</td>
                            <td><span class="badge {{ $t->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $t->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                            <td class="text-end">
                                <a href="{{ route('price-tables.show', $t) }}" class="btn btn-sm btn-light border me-1">จัดการราคา</a>
                                <button type="button" class="btn btn-sm btn-light border"
                                    @click="openEdit({{ $t->id }}, '{{ $t->code }}', '{{ addslashes($t->name) }}', '{{ addslashes($t->description ?? '') }}', {{ $t->is_default ? 'true' : 'false' }}, {{ $t->is_active ? 'true' : 'false' }})">
                                    แก้ไข
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="py-5 text-center text-muted">ยังไม่มีตารางราคา — กด "เพิ่มตารางราคา" เพื่อสร้าง</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $tables->links() }}</div>
        </div>

        {{-- Create/Edit modal --}}
        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width:min(520px,100%)" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขตารางราคา' : 'เพิ่มตารางราคา'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-5">
                                <label class="form-label text-muted small">รหัส</label>
                                <input type="text" name="code" x-model="code" required class="form-control">
                            </div>
                            <div class="col-7">
                                <label class="form-label text-muted small">ชื่อตารางราคา</label>
                                <input type="text" name="name" x-model="name" required class="form-control" placeholder="เช่น ราคาขายหน้าร้าน, ราคาส่ง">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">คำอธิบาย</label>
                                <input type="text" name="description" x-model="description" class="form-control">
                            </div>
                            <div class="col-12 d-flex gap-4">
                                <div class="form-check">
                                    <input type="checkbox" name="is_default" value="1" x-model="isDefault" class="form-check-input" id="ptDefault">
                                    <label class="form-check-label" for="ptDefault">ตารางราคาเริ่มต้น (สาขาที่ไม่ได้กำหนด)</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" x-model="isActive" class="form-check-input" id="ptActive">
                                    <label class="form-check-label" for="ptActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@push('head')<style>[x-cloak]{display:none!important}.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:24px}.booking-modal{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24);max-height:calc(100vh - 48px);overflow:auto}.border-dashed{border-style:dashed!important}</style>@endpush
@push('head')
<style>
    .price-command { padding: 24px; border: 1px solid #dbe7f0; border-radius: 20px; background: linear-gradient(135deg,#f8fbff 0%,#fff 62%,#f0fdf4 100%); box-shadow: 0 14px 38px rgba(15,23,42,.07); }
    .price-command-head { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; margin-bottom:18px; }
    .price-kicker { color:#168dcc; font-size:11px; font-weight:950; letter-spacing:.14em; }
    .price-command h2 { margin:3px 0; color:#18364a; font-size:25px; font-weight:950; }
    .price-command p { margin:0; color:#6b8293; font-size:13px; }
    .price-status { padding:8px 13px; border-radius:999px; color:#087f5b; background:#dcfce7; font-size:12px; font-weight:850; white-space:nowrap; }
    .price-work-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
    .price-work-card { display:grid; grid-template-columns:44px minmax(0,1fr); gap:10px; min-height:142px; padding:16px; border:1px solid #dce7ef; border-radius:15px; background:#fff; color:#21394a; text-decoration:none; box-shadow:0 6px 18px rgba(15,23,42,.05); transition:.15s ease; }
    .price-work-card:hover { color:#162f40; transform:translateY(-2px); box-shadow:0 13px 28px rgba(15,23,42,.10); }
    .price-work-icon { width:42px; height:42px; display:grid; place-items:center; border-radius:12px; font-size:20px; }
    .price-work-card strong { display:block; margin-top:2px; font-size:14px; font-weight:950; }
    .price-work-card small { display:block; margin-top:6px; color:#708595; line-height:1.45; }
    .price-work-card b { grid-column:2; width:max-content; padding:3px 8px; border-radius:999px; font-size:11px; }
    .price-work-card.blue .price-work-icon,.price-work-card.blue b { color:#0878b9;background:#e0f2fe; }
    .price-work-card.violet .price-work-icon,.price-work-card.violet b { color:#7c3aed;background:#ede9fe; }
    .price-work-card.red .price-work-icon,.price-work-card.red b { color:#dc2626;background:#fee2e2; }
    .price-work-card.green .price-work-icon,.price-work-card.green b { color:#047857;background:#d1fae5; }
    .price-rule { display:flex; gap:20px; align-items:center; margin-top:15px; padding:12px 15px; border:1px dashed #bdd3e1; border-radius:12px; background:rgba(255,255,255,.78); }
    .price-rule-title { color:#36566c; font-size:12px; font-weight:900; white-space:nowrap; }
    .price-rule-flow { display:flex; align-items:center; flex-wrap:wrap; gap:7px; color:#567083; font-size:11px; font-weight:800; }
    .price-rule-flow span { padding:5px 9px; border-radius:8px; background:#eef5f9; }
    .price-rule-flow .hot { color:#b91c1c; background:#fee2e2; }
    .price-section-title { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:3px 0 14px; }
    .price-section-title span:first-child { display:block; color:#1f4056; font-size:17px; font-weight:950; }
    .price-section-title small { display:block; color:#7b91a1; margin-top:3px; }
    @media(max-width:1100px){.price-work-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:680px){.price-command{padding:16px}.price-command-head,.price-rule,.price-section-title{align-items:flex-start;flex-direction:column}.price-work-grid{grid-template-columns:1fr}.price-status{white-space:normal}}
</style>
@endpush
@push('scripts')
<script>
function priceTablePage() {
    return {
        modalOpen: false, editingId: null,
        code: '', name: '', description: '', isDefault: false, isActive: true,
        openCreate() { this.editingId=null; this.code=''; this.name=''; this.description=''; this.isDefault=false; this.isActive=true; this.modalOpen=true; },
        openEdit(id,code,name,desc,isDef,isAct) { this.editingId=id; this.code=code; this.name=name; this.description=desc; this.isDefault=isDef; this.isActive=isAct; this.modalOpen=true; },
        get formAction() { return this.editingId ? `{{ url('price-tables') }}/${this.editingId}` : `{{ route('price-tables.store') }}`; },
    };
}
</script>
@endpush
