@extends('layout')
@section('title', 'ศูนย์ควบคุม POS - PopCentral')
@section('page-title', 'ศูนย์ควบคุม POS')
@section('page-subtitle', 'ตรวจยอดขาย ปิดกะ เงินสด และ QR/โอนก่อนส่งให้การเงิน')

@push('head')
<style>
    .pc-shell{display:grid;gap:14px}.pc-panel{background:#fff;border:1px solid #dbe6ed;border-radius:8px}.pc-filter{display:flex;align-items:end;gap:10px;padding:13px;flex-wrap:wrap}.pc-field label{display:block;margin-bottom:4px;color:#647a8b;font-size:11px;font-weight:800}.pc-field input,.pc-field select{height:38px;padding:0 10px;border:1px solid #cbd9e2;border-radius:6px;color:#213f54;font-size:12px}.pc-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr))}.pc-stat{padding:15px;border-right:1px solid #e7eef2}.pc-stat:last-child{border:0}.pc-stat span{display:block;color:#718596;font-size:10px;font-weight:900}.pc-stat strong{display:block;margin-top:4px;color:#16384f;font-size:22px}.pc-stat.warn strong{color:#ba2636}.pc-section{padding:15px}.pc-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:11px}.pc-head h2{margin:0;color:#173c55;font-size:16px;font-weight:900}.pc-note{padding:11px 13px;border-left:4px solid #bd2836;background:#fff8f8;color:#5e6f7c;font-size:11px;line-height:1.55}.pc-table-wrap{overflow:auto}.pc-table{width:100%;min-width:1020px;border-collapse:collapse}.pc-table th{padding:9px 10px;background:#f4f8fa;color:#61798b;border-bottom:1px solid #dce7ed;font-size:10px;font-weight:900;white-space:nowrap}.pc-table td{padding:10px;color:#36566b;border-bottom:1px solid #ebf1f4;font-size:11px;vertical-align:middle}.pc-table tr:last-child td{border:0}.pc-pill{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:10px;font-weight:900}.pc-open,.pc-pending{background:#fff1d5;color:#936008}.pc-closed,.pc-ok{background:#dff7eb;color:#167346}.pc-diff{background:#ffe2e5;color:#b42331}.pc-amount{text-align:right;font-weight:800}.pc-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}@media(max-width:1000px){.pc-stats{grid-template-columns:repeat(3,1fr)}.pc-stat:nth-child(3){border-right:0}}@media(max-width:600px){.pc-stats{grid-template-columns:1fr}.pc-stat{border-right:0;border-bottom:1px solid #e7eef2}.pc-filter{display:grid;grid-template-columns:1fr}.pc-field input,.pc-field select{width:100%}}
</style>
@endpush

@section('content')
<main class="pc-shell">
    <form class="pc-panel pc-filter" method="get">
        <div class="pc-field"><label>วันที่ขาย/เปิดกะ</label><input type="date" name="date" value="{{ $date }}"></div>
        <div class="pc-field"><label>สาขา</label><select name="branch_id"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId===$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
        <button class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>แสดงข้อมูล</button>
        <a class="btn btn-outline-secondary" href="{{ route('monthly-accounting.index', ['period'=>substr($date,0,7), 'branch_id'=>$branchId]) }}"><i class="bi bi-bank me-1"></i>ไปตรวจ Statement</a>
    </form>

    <section class="pc-panel pc-stats">
        <div class="pc-stat"><span>บิล POS สำเร็จ</span><strong>{{ number_format((int) $receiptStats->bill_count) }} บิล</strong></div>
        <div class="pc-stat"><span>ยอดขายสุทธิ</span><strong>฿{{ number_format((float) $receiptStats->net_sales,2) }}</strong></div>
        <div class="pc-stat"><span>ยอดเฉลี่ยต่อบิล</span><strong>฿{{ number_format((float) $receiptStats->average_bill,2) }}</strong></div>
        <div class="pc-stat"><span>เงินสด / QR-โอน</span><strong>฿{{ number_format((float) ($paymentTotals['cash'] ?? 0),2) }} / ฿{{ number_format((float) ($paymentTotals['transfer'] ?? 0) + (float) ($paymentTotals['qr'] ?? 0) + (float) ($paymentTotals['bank'] ?? 0),2) }}</strong></div>
        <div class="pc-stat {{ $unmatchedTransfers->isNotEmpty() ? 'warn' : '' }}"><span>QR/โอนรอตรวจ Statement</span><strong>{{ number_format($unmatchedTransfers->count()) }} รายการ</strong></div>
    </section>

    <section class="pc-panel pc-section">
        <div class="pc-head"><h2><i class="bi bi-safe2 me-1"></i>กะขายและเงินสด</h2><span class="text-muted small">ตรวจทุกกะก่อนปิดวัน</span></div>
        <div class="pc-note mb-3"><strong>ลำดับทำงาน:</strong> ปิดกะจากเครื่อง POS → ตรวจเงินสดนับจริงกับเงินสดที่ควรมี → เปิด Z Report → ตรวจ QR/โอนในตารางล่างกับ Statement. กะเปิดค้างหรือเงินขาด/เกินต้องเคลียร์ก่อนส่งบัญชี.</div>
        <div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>กะ / เวลา</th><th>สาขา / เครื่อง</th><th>แคชเชียร์</th><th>สถานะ</th><th class="text-end">บิล</th><th class="text-end">เงินสดที่ควรมี</th><th class="text-end">นับจริง</th><th class="text-end">ขาด / เกิน</th><th></th></tr></thead><tbody>
            @forelse($shifts as $shift)
                @php($difference = $shift->cash_difference === null ? null : (float) $shift->cash_difference)
                <tr><td><strong>{{ $shift->shift_no }}</strong><div class="text-muted">{{ $shift->opened_at?->format('H:i') }} - {{ $shift->closed_at?->format('H:i') ?? 'ยังไม่ปิด' }}</div></td><td>{{ $shift->branch?->code }} · {{ $shift->branch?->name_th }}<div class="text-muted">{{ $shift->terminal?->code ?? '-' }}</div></td><td>{{ $shift->cashier?->name ?? '-' }}</td><td><span class="pc-pill {{ $shift->status === 'open' ? 'pc-open' : 'pc-closed' }}">{{ $shift->status === 'open' ? 'เปิดอยู่' : 'ปิดแล้ว' }}</span></td><td class="pc-amount">{{ number_format($shift->receipt_count) }}</td><td class="pc-amount">{{ number_format((float) $shift->expected_cash,2) }}</td><td class="pc-amount">{{ $shift->counted_cash === null ? '-' : number_format((float) $shift->counted_cash,2) }}</td><td class="pc-amount">@if($difference === null)<span class="text-muted">รอปิดกะ</span>@elseif(abs($difference) < 0.005)<span class="pc-pill pc-ok">ตรง</span>@else<span class="pc-pill pc-diff">{{ number_format($difference,2) }}</span>@endif</td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ route('pos.shift.z-report',$shift) }}"><i class="bi bi-printer"></i> Z Report</a></td></tr>
            @empty<tr><td colspan="9" class="text-center text-muted py-4">ไม่พบกะ POS ของวันที่เลือก</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="pc-panel pc-section">
        <div class="pc-head"><h2><i class="bi bi-qr-code-scan me-1"></i>QR / โอนที่รอจับคู่ Statement</h2><span class="{{ $unmatchedTransfers->isEmpty() ? 'text-success' : 'text-danger' }} small">{{ $unmatchedTransfers->isEmpty() ? 'ไม่มีรายการค้างตรวจ' : 'ต้องตรวจและแนบหลักฐาน' }}</span></div>
        <div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>เวลา</th><th>สาขา</th><th>ใบเสร็จ</th><th>ช่องทาง</th><th>เลขอ้างอิง</th><th class="text-end">ยอด</th></tr></thead><tbody>@forelse($unmatchedTransfers as $payment)<tr><td>{{ \Carbon\Carbon::parse($payment->receipt_date)->format('H:i') }}</td><td>{{ $payment->branch_code }} · {{ $payment->branch_name }}</td><td><strong>{{ $payment->receipt_no }}</strong></td><td>{{ ['transfer'=>'โอน','qr'=>'QR','bank'=>'ธนาคาร'][$payment->method] ?? $payment->method }}</td><td>{{ $payment->payment_reference ?: '-' }}</td><td class="pc-amount">{{ number_format((float)$payment->amount,2) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i>ไม่มีรายการ QR/โอนค้างตรวจ</td></tr>@endforelse</tbody></table></div>
    </section>
</main>
@endsection
