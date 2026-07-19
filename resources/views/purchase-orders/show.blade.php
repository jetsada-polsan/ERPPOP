@extends('layout')
@section('title', "ใบขอซื้อ {$purchaseOrder->doc_number} - POPSTAR ERP")
@section('page-title', 'ใบขอซื้อ / ใบสั่งซื้อ ' . $purchaseOrder->doc_number)
@section('page-subtitle', $purchaseOrder->statusLabel())
@section('content')
<div x-data="{ orderOpen: false, receiveOpen: false }">
    <a href="{{ route('purchase-orders.index') }}" class="text-decoration-none small d-inline-block mb-3"><i class="bi bi-arrow-left me-1"></i>กลับรายการ</a>

    {{-- แถบสถานะ workflow --}}
    <div class="content-card p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            @foreach(['requested' => 'ขอซื้อ', 'approved' => 'อนุมัติ', 'ordered' => 'สั่งซื้อ', 'received' => 'รับของ'] as $st => $label)
                @php($states = ['requested', 'approved', 'ordered', 'received'])
                @php($curIdx = array_search($purchaseOrder->status, $states))
                @php($thisIdx = array_search($st, $states))
                @php($done = $curIdx !== false && $thisIdx <= $curIdx)
                <span class="badge rounded-pill px-3 py-2 {{ $done ? 'text-bg-success' : 'text-bg-light border' }}">
                    @if($done)<i class="bi bi-check-circle-fill me-1"></i>@endif{{ $label }}
                </span>
                @if(!$loop->last)<i class="bi bi-chevron-right text-muted"></i>@endif
            @endforeach
            @if($purchaseOrder->status === 'cancelled')<span class="badge text-bg-danger ms-2">ยกเลิกแล้ว</span>@endif

            <div class="ms-auto d-flex gap-2">
                @if($purchaseOrder->status === 'requested')
                    @if(auth()->user()->hasPermission('purchasing.approve') && $purchaseOrder->requested_by !== auth()->id())
                    <form method="post" action="{{ route('purchase-orders.approve', $purchaseOrder) }}" onsubmit="return confirm('อนุมัติใบขอซื้อนี้?')">@csrf<button class="btn btn-info text-white"><i class="bi bi-check2-circle me-1"></i>อนุมัติ</button></form>
                    @else
                    <span class="badge text-bg-light border align-self-center">รอผู้อนุมัติคนอื่น</span>
                    @endif
                @elseif($purchaseOrder->status === 'approved')
                    <button type="button" class="btn btn-primary" @click="orderOpen = true"><i class="bi bi-cart-check me-1"></i>ยืนยันสั่งซื้อ</button>
                @elseif($purchaseOrder->status === 'ordered')
                    <button type="button" class="btn btn-success" @click="receiveOpen = true"><i class="bi bi-box-arrow-in-down me-1"></i>รับของเข้าคลัง</button>
                @endif
                @if(!in_array($purchaseOrder->status, ['received', 'cancelled']))
                    <form method="post" action="{{ route('purchase-orders.cancel', $purchaseOrder) }}" onsubmit="return confirm('ยกเลิกใบขอซื้อนี้?')">@csrf<button class="btn btn-light border text-danger">ยกเลิก</button></form>
                @endif
                @if(in_array($purchaseOrder->status, ['ordered', 'received']))
                    <a href="{{ route('purchase-orders.print', $purchaseOrder) }}" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>พิมพ์ใบสั่งซื้อ (A4)</a>
                @endif
                @if($purchaseOrder->receivedDocument)
                    <a href="{{ route('purchases.show', $purchaseOrder->received_document_id) }}" class="btn btn-outline-success"><i class="bi bi-receipt me-1"></i>ดูใบซื้อ {{ $purchaseOrder->receivedDocument->doc_number }}</a>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="content-card p-3 h-100">
            <div class="small"><span class="text-muted">ซัพพลายเออร์:</span> <strong>{{ $purchaseOrder->supplier?->name_th ?? 'ยังไม่ระบุ' }}</strong></div>
            <div class="small"><span class="text-muted">สาขา:</span> {{ $purchaseOrder->branch->name_th }}</div>
            <div class="small"><span class="text-muted">ต้องการภายใน:</span> {{ $purchaseOrder->need_by_date?->thaiDate() ?? '-' }}</div>
            @if($purchaseOrder->note)<div class="small text-muted mt-1">หมายเหตุ: {{ $purchaseOrder->note }}</div>@endif
        </div></div>
        <div class="col-md-6"><div class="content-card p-3 h-100">
            <div class="small"><span class="text-muted">ผู้ขอซื้อ:</span> {{ $purchaseOrder->requester?->name ?? '-' }}</div>
            <div class="small"><span class="text-muted">ผู้อนุมัติ:</span> {{ $purchaseOrder->approver?->name ?? '-' }} @if($purchaseOrder->approved_at)({{ $purchaseOrder->approved_at->thaiDate(true) }})@endif</div>
            <div class="small"><span class="text-muted">การชำระ:</span> {{ $purchaseOrder->is_credit ? 'เครดิต (ตั้งหนี้)' : 'เงินสด' }}</div>
        </div></div>
    </div>

    <div class="content-card overflow-hidden">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>รหัส</th><th>สินค้า</th><th>หน่วย</th><th class="text-end">จำนวน</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th></tr></thead>
            <tbody>
                @foreach($purchaseOrder->items as $item)
                <tr>
                    <td class="fw-semibold">{{ $item->product->sku_code }}</td>
                    <td>{{ $item->product->name_th }}</td>
                    <td class="text-muted">{{ $item->product->baseUnit?->cleanName() ?? '-' }}</td>
                    <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                    <td class="text-end">{{ $item->unit_price > 0 ? number_format($item->unit_price, 2) : '-' }}</td>
                    <td class="text-end fw-semibold">{{ $item->unit_price > 0 ? number_format($item->qty * $item->unit_price, 2) : '-' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">รวมทั้งสิ้น</td><td class="text-end">{{ number_format($purchaseOrder->total_amount, 2) }}</td></tr></tfoot>
        </table>
    </div>

    {{-- Order modal: ระบุซัพพลายเออร์ + ราคา --}}
    <div class="po-backdrop" x-show="orderOpen" x-cloak x-transition.opacity @keydown.escape.window="orderOpen = false">
        <div class="po-modal" @click.outside="orderOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <h3 class="h5 fw-bold mb-0">ยืนยันสั่งซื้อ</h3>
                <button type="button" class="btn btn-light rounded-circle" @click="orderOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('purchase-orders.order', $purchaseOrder) }}">
                @csrf
                <div class="px-4 pb-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small text-muted">ซัพพลายเออร์</label>
                            <select name="supplier_id" required class="form-select">
                                <option value="">-- เลือกซัพพลายเออร์ --</option>
                                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($s->id === $purchaseOrder->supplier_id)>{{ $s->code }} - {{ $s->name_th }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check"><input type="hidden" name="is_credit" value="0"><input type="checkbox" name="is_credit" value="1" class="form-check-input" id="poCredit" {{ $purchaseOrder->is_credit ? 'checked' : '' }}><label class="form-check-label small" for="poCredit">ซื้อเชื่อ (ตั้งหนี้)</label></div>
                        </div>
                    </div>
                    <table class="table table-sm align-middle">
                        <thead><tr><th>สินค้า</th><th class="text-end">จำนวน</th><th class="text-end" style="width:150px">ราคา/หน่วย</th></tr></thead>
                        <tbody>
                            @foreach($purchaseOrder->items as $item)
                            <tr>
                                <td class="small">{{ $item->product->sku_code }} {{ $item->product->name_th }}</td>
                                <td class="text-end">{{ number_format($item->qty, 2) }}</td>
                                <td><input type="number" step="0.01" min="0" name="unit_price[{{ $item->id }}]" value="{{ $item->unit_price }}" required class="form-control form-control-sm text-end"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border px-4" @click="orderOpen = false">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary px-5"><i class="bi bi-cart-check me-1"></i>ยืนยันสั่งซื้อ</button>
                </div>
            </form>
        </div>
    </div>

    <div class="po-backdrop" x-show="receiveOpen" x-cloak x-transition.opacity @keydown.escape.window="receiveOpen = false">
        <div class="po-modal" @click.outside="receiveOpen = false" x-transition>
            <div class="d-flex justify-content-between align-items-center px-4 pt-4 pb-2">
                <h3 class="h5 fw-bold mb-0">รับสินค้าเข้าคลัง</h3>
                <button type="button" class="btn btn-light rounded-circle" @click="receiveOpen = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <form method="post" action="{{ route('purchase-orders.receive', $purchaseOrder) }}">
                @csrf
                <div class="px-4 pb-3">
                    <p class="small text-muted">ระบุ Lot และวันของสินค้าที่ควบคุมอายุ ระบบจะคำนวณวันหมดอายุให้อัตโนมัติเมื่อสินค้าได้ตั้งอายุไว้</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>สินค้า</th><th>Lot</th><th>วันผลิต</th><th>วันหมดอายุ</th></tr></thead>
                            <tbody>
                            @foreach($purchaseOrder->items as $item)
                                <tr>
                                    <td class="small">{{ $item->product->sku_code }}<br>{{ $item->product->name_th }} @if($item->product->tracks_expiry)<span class="badge text-bg-warning">คุมอายุ</span>@endif</td>
                                    <td><input name="lots[{{ $item->id }}][lot_number]" class="form-control form-control-sm"></td>
                                    <td><input type="date" name="lots[{{ $item->id }}][manufacture_date]" class="form-control form-control-sm"></td>
                                    <td><input type="date" name="lots[{{ $item->id }}][expiry_date]" class="form-control form-control-sm" @required($item->product->tracks_expiry && !$item->product->shelf_life_days)></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 px-4 pb-4">
                    <button type="button" class="btn btn-light border" @click="receiveOpen = false">ยกเลิก</button>
                    <button class="btn btn-success" onclick="return confirm('ยืนยันรับสินค้าเข้าคลังและสร้างเจ้าหนี้?')"><i class="bi bi-check2-circle me-1"></i>ยืนยันรับสินค้า</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .po-backdrop { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.42); display: flex; align-items: center; justify-content: center; padding: 24px; }
    .po-modal { width: min(720px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.24); }
</style>
@endpush
