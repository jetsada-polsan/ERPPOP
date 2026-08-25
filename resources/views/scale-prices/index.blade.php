@extends('layout')
@section('title', 'ราคาเครื่องชั่ง - PopCentral')
@section('page-title', 'ราคาสินค้าเครื่องชั่ง')
@section('page-subtitle', 'ราคา/กก. ของสินค้าขายชั่งทั้งหมด — ต้องตั้งให้ตรงกับเครื่องชั่งเสมอ')

@section('content')
<div x-data="{ q: '', onlyUnpriced: false, pq: '', results: [], loading: false,
    async doSearch() {
        if (this.pq.trim().length < 1) { this.results = []; return; }
        this.loading = true;
        try {
            const r = await fetch('{{ route('search.products') }}?q=' + encodeURIComponent(this.pq));
            this.results = await r.json();
        } catch (e) { this.results = []; }
        this.loading = false;
    } }">
    <div class="content-card p-4">
        {{-- PLU เครื่องชั่งเป็นรหัสลูกจากแฟ้มสินค้าเดิม ไม่สร้างซ้ำจากหน้านี้ --}}
        <div class="mb-3 p-3 rounded" style="background:#f0fdfa;border:1px solid #99f6e4">
            <div class="fw-bold"><i class="bi bi-upc-scan me-1"></i> PLU เครื่องชั่งมาจากแฟ้มสินค้าเดิม</div>
            <div class="text-muted small mt-1">รหัส `801xxx` เป็นรหัสลูกของสินค้าและเครื่องชั่ง ระบบนี้แสดงและใช้ตามที่ผูกไว้เท่านั้น ไม่สร้างเลขใหม่ ไม่รันเลขทับ และไม่แก้ฉลากเดิม</div>
            <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-box-seam me-1"></i> ตรวจสอบ PLU ที่แฟ้มสินค้า</a>
        </div>

        <div class="list-toolbar mb-3">
            <div class="list-toolbar-left">
                <h3 class="h6 fw-bold mb-0">สินค้าเครื่องชั่ง (PLU ช่วงเลข 8)</h3>
                <input type="text" x-model="q" class="form-control form-control-sm" style="max-width:260px"
                       placeholder="กรอง PLU / รหัส / ชื่อ...">
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small">{{ $products->count() }} รายการ</span>
                @if($notPriced > 0)
                    <span class="badge text-bg-warning">ยังไม่ตั้งราคา {{ $notPriced }}</span>
                @endif
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="onlyUnpriced" x-model="onlyUnpriced">
                    <label class="form-check-label small" for="onlyUnpriced">เฉพาะที่ยังไม่ตั้งราคา</label>
                </div>
                <a href="{{ route('scale-prices.export') }}" class="btn btn-sm btn-light border">
                    <i class="bi bi-download me-1"></i> CSV ตั้งเครื่องชั่ง
                </a>
            </div>
        </div>

        <div class="alert alert-info py-2 px-3 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            ป้ายเครื่องชั่งฝัง "ราคารวม" — POS คำนวณน้ำหนักย้อนกลับจากราคา/กก. ในหน้านี้
            ดังนั้น <b>ราคาที่นี่กับที่ตั้งในเครื่องชั่งต้องเท่ากันเสมอ</b> (แก้ที่นี่แล้วไปแก้ที่เครื่องชั่งด้วย)
            · แถวสีเหลือง = ราคายังไม่ถูกตั้ง (ค้างจาก BPlus)
        </div>

        <form method="post" action="{{ route('scale-prices.update') }}">
            @csrf
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width:120px">PLU เครื่องชั่ง</th>
                            <th style="width:110px">รหัสสินค้า</th>
                            <th>ชื่อสินค้า</th>
                            <th class="text-end" style="width:170px">ราคา/กก. (ตารางหลัก)</th>
                            <th style="width:210px">Override รายสาขา</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $p)
                            @php
                                $current = (float) ($prices[$p->id] ?? $p->default_price ?? 0);
                                $isPlaceholder = $current <= 1;
                            @endphp
                            <tr x-show="(!q || $el.dataset.s.includes(q.toLowerCase())) && (!onlyUnpriced || $el.dataset.ph === '1')"
                                data-s="{{ strtolower(($p->scale_plu ?? '') . ' ' . $p->sku_code . ' ' . $p->name_th) }}"
                                data-ph="{{ $isPlaceholder ? '1' : '0' }}"
                                @if($isPlaceholder) style="background:#fff8e1" @endif>
                                <td class="fw-bold">
                                    @if($p->scale_plu)
                                        <span class="badge text-bg-primary" style="font-size:13px">{{ $p->scale_plu }}</span>
                                    @else
                                        <a href="{{ route('products.show', $p) }}" class="small" title="ยังไม่มี PLU — ไปเพิ่มบาร์โค้ด 800xxx ที่หน้าสินค้า">+ เพิ่ม PLU</a>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $p->sku_code }}</td>
                                <td>
                                    <a href="{{ route('products.show', $p) }}" class="text-decoration-none text-body">{{ $p->name_th }}</a>
                                    @if($isPlaceholder)<span class="badge text-bg-warning ms-1">ยังไม่ตั้งราคา</span>@endif
                                </td>
                                <td class="text-end">
                                    <div class="input-group input-group-sm justify-content-end" style="max-width:150px;margin-left:auto">
                                        <span class="input-group-text">฿</span>
                                        <input type="number" name="prices[{{ $p->id }}]" value="{{ number_format($current, 2, '.', '') }}"
                                               step="0.01" min="0" max="99999" class="form-control text-end fw-bold">
                                    </div>
                                </td>
                                <td class="small">
                                    @if($overrides->has($p->id))
                                        @foreach($overrides[$p->id] as $ov)
                                            <span class="badge text-bg-warning" title="สาขาที่ใช้ตารางนี้จะเห็นราคานี้แทน — ไปแก้ที่หน้าตารางราคา">
                                                {{ $ov->name }}: ฿{{ number_format($ov->price, 2) }}
                                            </span>
                                        @endforeach
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีสินค้าเครื่องชั่ง — ลงทะเบียน PLU 800xxx ที่หน้าสินค้า</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-2">
                <button type="submit" class="btn btn-success px-4">
                    <i class="bi bi-check-lg me-1"></i> บันทึกราคาทั้งหมด
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
