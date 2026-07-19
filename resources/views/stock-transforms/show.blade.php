@extends('layout')

@section('title', "ใบแปรรูป {$document->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบแปรรูปสินค้า')
@section('page-subtitle', $document->doc_number)

@section('content')
    <a href="{{ route('stock-transforms.index') }}" class="text-decoration-none small d-inline-block mb-3">
        <i class="bi bi-arrow-left me-1"></i> กลับรายการใบแปรรูป
    </a>

    <div class="content-card p-4 mb-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="h4 fw-bold mb-1">ใบแปรรูปสินค้า {{ $document->doc_number }}</h2>
                <div class="text-muted small">{{ $document->doc_date->thaiDate() }} &middot; {{ $document->branch->name_th }}</div>
                @if($document->remark)<div class="text-muted small mt-1">หมายเหตุ: {{ $document->remark }}</div>@endif
            </div>
            <div class="text-end">
                <div class="text-muted small">มูลค่าวัตถุดิบ</div>
                <div class="h4 fw-bold text-danger mb-0">{{ number_format($document->total_amount, 2) }}</div>
            </div>
        </div>
    </div>
    @if($batch = $document->productionBatch)
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><div class="text-muted small">น้ำหนักวัตถุดิบ</div><div class="fs-5 fw-bold">{{ number_format($batch->input_weight_qty, 3) }} กก.</div></div></div>
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><div class="text-muted small">ผลผลิตจริง</div><div class="fs-5 fw-bold text-success">{{ number_format($batch->output_weight_qty, 3) }} กก.</div></div></div>
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><div class="text-muted small">สูญเสีย / ส่วนต่าง</div><div class="fs-5 fw-bold {{ $batch->loss_weight_qty > 0 ? 'text-danger' : 'text-primary' }}">{{ number_format($batch->loss_weight_qty, 3) }} กก.</div><div class="small text-muted">Yield {{ number_format($batch->yield_percent, 2) }}%</div></div></div>
        <div class="col-6 col-lg-3"><div class="content-card p-3 h-100"><div class="text-muted small">ต้นทุนผลผลิต</div><div class="fs-5 fw-bold">฿{{ number_format($batch->output_unit_cost, 2) }}/กก.</div><div class="small text-muted">รวม ฿{{ number_format($batch->total_input_cost, 2) }}</div></div></div>
    </div>
    <div class="content-card p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div><div class="text-muted small">ราคาขาย ณ วันจัดเซ็ต</div><strong>฿{{ number_format($batch->selling_unit_price,2) }}/กก.</strong> <span class="small text-muted">(ก่อน VAT ฿{{ number_format($batch->net_selling_unit_price,2) }})</span></div>
        <div class="text-end"><div class="text-muted small">กำไรขั้นต้นประมาณ</div><strong class="{{ $batch->estimated_profit_per_unit >= 0 ? 'text-primary' : 'text-danger' }}">฿{{ number_format($batch->estimated_profit_per_unit,2) }}/กก. &middot; {{ number_format($batch->estimated_margin_percent,2) }}%</strong></div>
    </div>

    <div class="content-card p-4 mb-4" x-data="packageWeights()">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div><h3 class="h6 fw-bold mb-1">แบ่งถุงและสร้างป้ายเครื่องชั่ง</h3><div class="small text-muted">PLU {{ $batch->scale_plu ?: 'ยังไม่ได้กำหนด' }} &middot; สินค้า {{ $batch->outputProduct->name_th }}</div></div>
            @if($batch->packages->isNotEmpty())<a class="btn btn-sm btn-primary" target="_blank" href="{{ route('stock-transforms.labels', $document) }}"><i class="bi bi-printer me-1"></i> พิมพ์ป้าย {{ $batch->packages->count() }} ใบ</a>@endif
        </div>
        <form method="post" action="{{ route('stock-transforms.packages.store', $document) }}">@csrf
            <div class="d-flex gap-2 flex-wrap align-items-end">
                <template x-for="(weight,index) in weights" :key="index"><label class="small text-muted">ถุงที่ <span x-text="index+1"></span> (กก.)<div class="input-group input-group-sm" style="width:150px"><input type="number" step="0.001" min="0.001" :name="`weights[${index}]`" x-model.number="weights[index]" class="form-control text-end" required><button type="button" class="btn btn-light border" @click="weights.splice(index,1)" x-show="weights.length>1"><i class="bi bi-x"></i></button></div></label></template>
                <button type="button" class="btn btn-sm btn-light border" @click="weights.push(0.5)"><i class="bi bi-plus-lg"></i> เพิ่มถุง</button>
                <button type="submit" class="btn btn-sm btn-success" @disabled(!$batch->scale_plu)><i class="bi bi-upc-scan me-1"></i> สร้างบาร์โค้ด</button>
            </div>
            <div class="small mt-2">น้ำหนักป้ายใหม่ <strong x-text="total.toFixed(3)"></strong> กก. &middot; ทำป้ายแล้ว {{ number_format($batch->packages->sum('weight_qty'),3) }} / {{ number_format($batch->output_weight_qty,3) }} กก.</div>
        </form>
        @if($batch->packages->isNotEmpty())
        <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>บาร์โค้ด</th><th class="text-end">น้ำหนัก</th><th class="text-end">ราคา/กก.</th><th class="text-end">รวม</th></tr></thead><tbody>@foreach($batch->packages as $package)<tr><td>{{ $package->seq }}</td><td class="font-monospace">{{ $package->barcode }}</td><td class="text-end">{{ number_format($package->weight_qty,3) }}</td><td class="text-end">{{ number_format($package->unit_price,2) }}</td><td class="text-end fw-bold">{{ number_format($package->total_price,2) }}</td></tr>@endforeach</tbody></table></div>
        @endif
    </div>
    @endif

    @php($rawItems = $document->stockDocument->items->where('qty', '<', 0))
    @php($outputItems = $document->stockDocument->items->where('qty', '>=', 0))

    <div class="content-card p-4 mb-4">
        <h3 class="h6 fw-bold mb-3 text-danger"><i class="bi bi-box-arrow-up me-1"></i>วัตถุดิบ (ตัดออกจากสต๊อก)</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ทุน/หน่วย</th><th class="text-end">รวม</th></tr></thead>
                <tbody>
                    @foreach($rawItems as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td class="text-end">{{ number_format(abs($item->qty), 2) }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format(abs($item->qty) * $item->unit_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-card p-4">
        <h3 class="h6 fw-bold mb-3 text-success"><i class="bi bi-box-arrow-in-down me-1"></i>ผลผลิต (รับเข้าสต๊อก)</h3>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ทุนที่ได้/หน่วย</th><th class="text-end">มูลค่ารวม</th></tr></thead>
                <tbody>
                    @foreach($outputItems as $item)
                    <tr>
                        <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                        <td>{{ $item->product->name_th }}</td>
                        <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                        <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format($item->qty * $item->unit_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>function packageWeights(){return{weights:[0.5],get total(){return this.weights.reduce((sum,value)=>sum+(Number(value)||0),0)}}}</script>
@endpush
