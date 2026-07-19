@extends('layout')
@section('title', 'ปิดบัญชีรายเดือน - POPSTAR ERP')
@section('page-title', 'ศูนย์ปิดบัญชีรายเดือน')
@section('page-subtitle', 'ตรวจ Statement สลิป ค่าใช้จ่าย VAT/WHT และส่งข้อมูลให้สำนักงานบัญชี')

@push('head')
<style>
    [x-cloak]{display:none!important}.ma-shell{display:grid;gap:14px}.ma-panel{background:#fff;border:1px solid #dbe7ef;border-radius:8px}.ma-filter{display:flex;gap:10px;align-items:end;padding:13px;flex-wrap:wrap}.ma-field label{display:block;margin-bottom:4px;color:#667f90;font-size:11px;font-weight:800}.ma-field input,.ma-field select,.ma-field textarea{border:1px solid #cbdbe5;border-radius:6px;color:#274b63;font-size:12px}.ma-field input,.ma-field select{height:38px;padding:0 9px}.ma-field textarea{width:100%;min-height:70px;padding:9px}.ma-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}.ma-stat{padding:14px 16px;border-right:1px solid #e6edf2}.ma-stat:last-child{border:0}.ma-stat span{display:block;color:#718696;font-size:10px;font-weight:900}.ma-stat strong{color:#1d3b52;font-size:20px}.ma-tabs{display:flex;gap:5px;padding:8px;border-bottom:1px solid #dbe7ef;overflow-x:auto}.ma-tab{height:35px;padding:0 13px;border:0;border-radius:6px;background:transparent;color:#61798a;font-size:12px;font-weight:900;white-space:nowrap}.ma-tab.active{color:#fff;background:#315f80}.ma-section{padding:15px}.ma-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.ma-head h2{margin:0;color:#183c54;font-size:16px;font-weight:900}.expense-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-4{grid-column:span 4}.ma-table-wrap{overflow-x:auto}.ma-table{width:100%;min-width:980px;border-collapse:collapse}.ma-table th{padding:9px 10px;background:#f6f9fb;color:#61798a;border-bottom:1px solid #dbe7ef;font-size:10px;font-weight:900}.ma-table td{padding:9px 10px;color:#425f73;border-bottom:1px solid #edf2f5;font-size:11px;vertical-align:middle}.ma-table tr:last-child td{border:0}.status-pill{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:10px;font-weight:900}.status-matched{color:#146c43;background:#d1fae5}.status-mismatch{color:#a61b27;background:#fee2e2}.status-pending{color:#8a5a00;background:#fef3c7}.reconcile-form{display:grid;grid-template-columns:130px 130px 130px 1fr auto;gap:7px;align-items:end;padding:10px;background:#f8fafb;border-radius:6px}.export-band{display:grid;grid-template-columns:1fr auto;gap:15px;align-items:center;padding:16px;border-left:4px solid #1599d3}.export-band h3{margin:0 0 4px;color:#183c54;font-size:15px;font-weight:900}.export-band p{margin:0;color:#6b8190;font-size:11px}.ma-note{padding:11px 13px;color:#6b8190;background:#f6fafc;border:1px solid #dbe7ef;border-radius:6px;font-size:11px;line-height:1.5}@media(max-width:900px){.ma-stats{grid-template-columns:repeat(2,1fr)}.ma-stat:nth-child(2){border-right:0}.expense-form{grid-template-columns:repeat(2,1fr)}.span-4{grid-column:span 2}.reconcile-form{grid-template-columns:1fr 1fr}.reconcile-form .wide{grid-column:span 2}}@media(max-width:560px){.ma-stats,.expense-form{grid-template-columns:1fr}.ma-stat{border-right:0;border-bottom:1px solid #e6edf2}.span-2,.span-4{grid-column:span 1}.export-band{grid-template-columns:1fr}.reconcile-form{grid-template-columns:1fr}.reconcile-form .wide{grid-column:span 1}}
</style>
@endpush

@section('content')
<div class="ma-shell" x-data="{ tab: 'expenses', reconcileId: null }" x-cloak>
    @if($errors->any())<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>@endif
    @if(session('success'))<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>@endif

    <form class="ma-panel ma-filter" method="get">
        <div class="ma-field"><label>เดือนบัญชี</label><input type="month" name="period" value="{{ $period }}"></div>
        <div class="ma-field"><label>สาขา</label><select name="branch_id"><option value="">ทุกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId==$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
        <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>แสดงข้อมูล</button>
    </form>

    <section class="ma-panel ma-stats">
        <div class="ma-stat"><span>ค่าใช้จ่าย</span><strong>฿{{ number_format($stats['expense_total'],2) }}</strong></div>
        <div class="ma-stat"><span>ภาษีหัก ณ ที่จ่าย</span><strong>฿{{ number_format($stats['withholding_total'],2) }}</strong></div>
        <div class="ma-stat"><span>Statement</span><strong>{{ number_format($stats['statement_count']) }} รายการ</strong></div>
        <div class="ma-stat"><span>ยังไม่ตรง/ยังไม่ตรวจ</span><strong style="color:{{ $stats['unreconciled_count'] ? '#a61b27':'#146c43' }}">{{ number_format($stats['unreconciled_count']) }} รายการ</strong></div>
    </section>

    <section class="ma-panel">
        <div class="ma-tabs">
            <button class="ma-tab" :class="tab==='expenses'&&'active'" @click="tab='expenses'" type="button"><i class="bi bi-receipt me-1"></i>ค่าใช้จ่ายสาขา</button>
            <button class="ma-tab" :class="tab==='bank'&&'active'" @click="tab='bank'" type="button"><i class="bi bi-bank me-1"></i>Statement / สลิป</button>
            <button class="ma-tab" :class="tab==='export'&&'active'" @click="tab='export'" type="button"><i class="bi bi-file-earmark-zip me-1"></i>ส่งสำนักงานบัญชี</button>
        </div>

        <div class="ma-section" x-show="tab==='expenses'">
            <div class="ma-head"><h2>บันทึกค่าใช้จ่ายและภาษี</h2><span class="text-muted small">บันทึกแล้วลง GL อัตโนมัติ</span></div>
            <form method="post" action="{{ route('monthly-accounting.expenses.store') }}" enctype="multipart/form-data" class="expense-form">@csrf
                <div class="ma-field"><label>วันที่ค่าใช้จ่าย</label><input type="date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" required></div>
                <div class="ma-field"><label>สาขา</label><select name="branch_id" required><option value="">เลือกสาขา</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id',$branchId)==$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
                <div class="ma-field span-2"><label>บัญชีค่าใช้จ่าย</label><select name="expense_account_id" required><option value="">เลือกบัญชี</option>@foreach($expenseAccounts as $account)<option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name_th }}</option>@endforeach</select></div>
                <div class="ma-field span-2"><label>ผู้ขาย/ผู้รับเงิน</label><input name="supplier_name" list="supplier-list" value="{{ old('supplier_name') }}" required style="width:100%"><datalist id="supplier-list">@foreach($suppliers as $supplier)<option value="{{ $supplier->name_th }}">{{ $supplier->tax_id }}</option>@endforeach</datalist></div>
                <div class="ma-field"><label>เลขผู้เสียภาษี 13 หลัก</label><input name="supplier_tax_id" value="{{ old('supplier_tax_id') }}"></div>
                <div class="ma-field"><label>สาขาภาษี</label><input name="tax_branch" value="{{ old('tax_branch','00000') }}"></div>
                <div class="ma-field"><label>เลขใบกำกับ/ใบเสร็จ</label><input name="tax_invoice_no" value="{{ old('tax_invoice_no') }}"></div>
                <div class="ma-field"><label>วันที่ใบกำกับ</label><input type="date" name="tax_invoice_date" value="{{ old('tax_invoice_date') }}"></div>
                <div class="ma-field"><label>มูลค่าก่อน VAT</label><input type="number" step="0.01" min="0.01" name="base_amount" value="{{ old('base_amount') }}" required></div>
                <div class="ma-field"><label>VAT</label><input type="number" step="0.01" min="0" name="vat_amount" value="{{ old('vat_amount',0) }}"></div>
                <div class="ma-field"><label>อัตราหัก ณ ที่จ่าย %</label><input type="number" step="0.01" min="0" max="100" name="withholding_rate" value="{{ old('withholding_rate',0) }}"></div>
                <div class="ma-field"><label>แบบ WHT</label><select name="withholding_form"><option value="">ไม่มี</option><option value="PND3">ภ.ง.ด.3 บุคคลธรรมดา</option><option value="PND53">ภ.ง.ด.53 นิติบุคคล</option></select></div>
                <div class="ma-field"><label>วิธีชำระ</label><select name="payment_method" required><option value="cash">เงินสด</option><option value="transfer">โอนธนาคาร</option></select></div>
                <div class="ma-field"><label>บัญชีธนาคาร</label><select name="bank_account_id"><option value="">-</option>@foreach($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->bank_name }} · {{ $account->account_no }}</option>@endforeach</select></div>
                <div class="ma-field"><label>เลขอ้างอิงการจ่าย</label><input name="payment_reference" value="{{ old('payment_reference') }}"></div>
                <div class="ma-field"><label>หลักฐาน PDF/JPG/PNG</label><input type="file" name="evidence" accept=".pdf,.jpg,.jpeg,.png"></div>
                <div class="ma-field span-4"><label>รายละเอียดค่าใช้จ่าย</label><textarea name="description" required>{{ old('description') }}</textarea></div>
                <div class="span-4 text-end"><button class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i>บันทึกและลงบัญชี</button></div>
            </form>
            <div class="ma-table-wrap mt-4"><table class="ma-table"><thead><tr><th>วันที่/เลขที่</th><th>สาขา</th><th>ผู้ขาย</th><th>รายการ</th><th>ใบกำกับ</th><th class="text-end">ก่อน VAT</th><th class="text-end">VAT</th><th class="text-end">WHT</th><th class="text-end">รวม</th></tr></thead><tbody>@forelse($expenses as $e)<tr><td>{{ $e->expense_date->thaiDate() }}<div class="text-muted">{{ $e->document->doc_number }}</div></td><td>{{ $e->branch->code }}</td><td>{{ $e->supplier_name }}<div class="text-muted">{{ $e->supplier_tax_id }}</div></td><td>{{ $e->description }}</td><td>{{ $e->tax_invoice_no ?: '-' }}</td><td class="text-end">{{ number_format((float)$e->base_amount,2) }}</td><td class="text-end">{{ number_format((float)$e->vat_amount,2) }}</td><td class="text-end">{{ number_format((float)$e->withholding_amount,2) }}</td><td class="text-end fw-bold">{{ number_format((float)$e->total_amount,2) }}</td></tr>@empty<tr><td colspan="9" class="text-center text-muted py-4">ยังไม่มีค่าใช้จ่ายเดือนนี้</td></tr>@endforelse</tbody></table></div>{{ $expenses->links() }}
        </div>

        <div class="ma-section" x-show="tab==='bank'">
            <div class="ma-head"><h2>นำเข้าและตรวจ Statement</h2></div>
            <div class="ma-note mb-3">ไฟล์ CSV ต้องมีหัวคอลัมน์ <strong>date, description, amount, balance</strong> หรือชื่อไทย <strong>วันที่, รายละเอียด, จำนวนเงิน, คงเหลือ</strong> โดยยอดเงินเข้าเป็นบวกและเงินออกเป็นลบ</div>
            <form method="post" action="{{ route('monthly-accounting.statements.import') }}" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap align-items-end mb-4">@csrf
                <div class="ma-field"><label>บัญชีธนาคาร</label><select name="bank_account_id" required><option value="">เลือกบัญชี</option>@foreach($bankAccounts as $account)<option value="{{ $account->id }}">{{ $account->bank_name }} · {{ $account->account_no }}</option>@endforeach</select></div>
                <div class="ma-field"><label>Statement CSV</label><input type="file" name="statement_file" accept=".csv,.txt" required></div><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>นำเข้า</button>
            </form>
            <div class="ma-table-wrap"><table class="ma-table"><thead><tr><th>วันที่</th><th>บัญชี</th><th>รายละเอียด</th><th class="text-end">Statement</th><th>สถานะ</th><th>ตรวจเทียบหลักฐาน</th></tr></thead><tbody>@forelse($statements as $s)@php($status=$s->reconciliation?->status??'pending')<tr><td>{{ $s->statement_date->thaiDate() }}</td><td>{{ $s->bankAccount->bank_name }}<div class="text-muted">{{ $s->bankAccount->account_no }}</div></td><td>{{ $s->description }}</td><td class="text-end fw-bold">{{ number_format((float)$s->amount,2) }}</td><td><span class="status-pill status-{{ $status }}">{{ ['matched'=>'ตรงแล้ว','mismatch'=>'ยอดต่าง','pending'=>'รอตรวจ'][$status]??$status }}</span></td><td><button type="button" class="btn btn-sm btn-light border" @click="reconcileId=reconcileId==={{ $s->id }}?null:{{ $s->id }}"><i class="bi bi-search me-1"></i>ตรวจ</button></td></tr><tr x-show="reconcileId==={{ $s->id }}"><td colspan="6"><form method="post" action="{{ route('monthly-accounting.statements.reconcile',$s) }}" enctype="multipart/form-data" class="reconcile-form">@csrf<div class="ma-field"><label>ประเภท</label><select name="match_type"><option value="pos_transfer">ยอดโอนจาก POS</option><option value="expense">ค่าใช้จ่าย</option><option value="payment">รับ/จ่ายชำระ</option><option value="other">อื่น ๆ</option></select></div><div class="ma-field"><label>ยอดตามหลักฐาน</label><input type="number" step="0.01" min="0" name="expected_amount" value="{{ abs((float)$s->reconciliation?->expected_amount ?: (float)$s->amount) }}" required></div><div class="ma-field"><label>เลขอ้างอิง</label><input name="reference" value="{{ $s->reconciliation?->reference }}"></div><div class="ma-field wide"><label>สลิป/หลักฐาน</label><input type="file" name="slip" accept=".pdf,.jpg,.jpeg,.png"></div><input type="hidden" name="branch_id" value="{{ $s->bankAccount->branch_id }}"><button class="btn btn-primary">บันทึกผล</button></form></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มี Statement เดือนนี้</td></tr>@endforelse</tbody></table></div>
        </div>

        <div class="ma-section" x-show="tab==='export'">
            <div class="export-band ma-panel">
                <div><h3>สร้างชุดส่งสำนักงานบัญชี</h3><p>ZIP ประกอบด้วย Summary, Bank Reconciliation, ภาษีขาย, ภาษีซื้อ, ค่าใช้จ่าย, WHT, GL, manifest และไฟล์หลักฐาน</p></div>
                <form method="post" action="{{ route('monthly-accounting.export') }}">@csrf<input type="hidden" name="period" value="{{ $period }}">@if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif<button class="btn btn-primary" @disabled($stats['unreconciled_count']>0)><i class="bi bi-file-earmark-zip me-1"></i>สร้างและดาวน์โหลด</button></form>
            </div>
            @if($stats['unreconciled_count']>0)<div class="alert alert-warning mt-3 mb-3"><i class="bi bi-exclamation-triangle me-2"></i>ต้องตรวจ Statement ให้ตรงครบก่อนสร้างชุดส่งออก</div>@endif
            <div class="ma-note mt-3">ชุด ZIP นี้เป็นข้อมูลส่งมอบให้สำนักงานบัญชี ไม่ใช่ไฟล์ยื่นกรมสรรพากรโดยตรง สำนักงานบัญชีต้องตรวจและนำข้อมูลไปจัดทำ ภ.พ.30, ภ.ง.ด.3/53 หรือระบบ e-Tax ตามสถานะการจดทะเบียนของบริษัท</div>
            <div class="ma-table-wrap mt-3"><table class="ma-table"><thead><tr><th>เวลาส่งออก</th><th>เดือน</th><th>ไฟล์</th><th>SHA-256</th><th>ขนาด</th><th></th></tr></thead><tbody>@forelse($exportRuns as $run)<tr><td>{{ $run->exported_at->thaiDate(true) }}</td><td>{{ $run->period }}</td><td>{{ basename($run->file_name) }}</td><td><code>{{ substr($run->file_hash,0,16) }}…</code></td><td>{{ number_format($run->file_size/1024,1) }} KB</td><td><a class="btn btn-sm btn-light border" href="{{ route('monthly-accounting.exports.download',$run) }}"><i class="bi bi-download"></i></a></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่เคยส่งออกเดือนนี้</td></tr>@endforelse</tbody></table></div>
        </div>
    </section>
</div>
@endsection
