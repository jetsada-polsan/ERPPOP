@extends('layout')
@section('title', 'บัญชีครบวงจร - PopCentral')
@section('page-title', 'ศูนย์บัญชีครบวงจร')
@section('page-subtitle', 'รวม bank reconcile, ภาษี/E-Tax, audit trail, recurring documents และ OCR/AI import queue')

@push('head')
<style>
    [x-cloak]{display:none!important}.aa-shell{display:grid;gap:14px}.aa-panel{background:#fff;border:1px solid var(--erp-border);border-radius:8px}.aa-filter{display:flex;gap:10px;align-items:end;padding:13px;flex-wrap:wrap}.aa-field label{display:block;margin-bottom:4px;color:var(--erp-muted);font-size:11px;font-weight:800}.aa-field input,.aa-field select,.aa-field textarea{border:1px solid var(--erp-border);border-radius:6px;color:var(--erp-primary-dark);font-size:12px}.aa-field input,.aa-field select{height:38px;padding:0 9px}.aa-field textarea{width:100%;min-height:72px;padding:9px}.aa-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr))}.aa-stat{padding:14px;border-right:1px solid var(--erp-success-soft)}.aa-stat:last-child{border:0}.aa-stat span{display:block;color:var(--erp-muted);font-size:10px;font-weight:900}.aa-stat strong{font-size:20px;color:var(--erp-text)}.aa-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:14px}.aa-section{padding:15px}.aa-head{display:flex;justify-content:space-between;align-items:start;gap:12px;margin-bottom:12px}.aa-head h2{margin:0;color:var(--erp-primary-dark);font-size:16px;font-weight:900}.aa-actions{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}.aa-action{display:flex;align-items:center;gap:8px;padding:11px;border:1px solid var(--erp-border);border-radius:7px;text-decoration:none;color:var(--erp-primary-dark);background:var(--erp-surface-2);font-size:12px;font-weight:800}.aa-action i{color:var(--erp-primary)}.aa-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.span-2{grid-column:span 2}.span-4{grid-column:span 4}.aa-table-wrap{overflow-x:auto}.aa-table{width:100%;min-width:760px;border-collapse:collapse}.aa-table th{padding:9px;background:var(--erp-surface-2);color:var(--erp-muted);font-size:10px;font-weight:900;border-bottom:1px solid var(--erp-border)}.aa-table td{padding:9px;border-bottom:1px solid var(--erp-surface-2);font-size:11px;color:var(--erp-muted);vertical-align:middle}.aa-pill{display:inline-flex;padding:4px 7px;border-radius:5px;font-size:10px;font-weight:900}.aa-ok{background:var(--erp-success-soft);color:var(--erp-success-ink)}.aa-warn{background:var(--erp-warning-soft);color:var(--erp-warning-ink)}.aa-danger{background:var(--erp-danger-soft);color:var(--erp-danger)}.aa-note{padding:10px 12px;background:var(--erp-surface-2);border:1px solid var(--erp-border);border-radius:6px;color:var(--erp-muted);font-size:11px;line-height:1.5}@media(max-width:1100px){.aa-stats{grid-template-columns:repeat(3,1fr)}.aa-grid{grid-template-columns:1fr}.aa-actions{grid-template-columns:repeat(2,1fr)}}@media(max-width:640px){.aa-stats,.aa-form,.aa-actions{grid-template-columns:1fr}.span-2,.span-4{grid-column:span 1}}
</style>
@endpush

@section('content')
<div class="aa-shell" x-data="{ tab: 'recurring' }" x-cloak>
    @if($errors->any())<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>@endif
    @if(session('success'))<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}</div>@endif

    <form class="aa-panel aa-filter" method="get">
        <div class="aa-field"><label>เดือนควบคุม</label><input type="month" name="period" value="{{ $period }}"></div>
        <div class="aa-field"><label>สาขา</label><select name="branch_id"><option value="">ทั้งบริษัท</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($branchId==$branch->id)>{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
        <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>แสดงข้อมูล</button>
    </form>

    <section class="aa-panel aa-stats">
        <div class="aa-stat"><span>Bank ยังไม่กระทบยอด</span><strong>{{ number_format($stats['unreconciled_bank']) }}</strong></div>
        <div class="aa-stat"><span>ชุดภาษีรอดำเนินการ</span><strong>{{ number_format($stats['pending_tax']) }}</strong></div>
        <div class="aa-stat"><span>E-Tax รอติดตาม</span><strong>{{ number_format($stats['pending_etax']) }}</strong></div>
        <div class="aa-stat"><span>GL lines เดือนนี้</span><strong>{{ number_format($stats['gl_lines']) }}</strong></div>
        <div class="aa-stat"><span>Recurring ถึงกำหนด</span><strong>{{ number_format($stats['recurring_due']) }}</strong></div>
        <div class="aa-stat"><span>OCR/AI queue</span><strong>{{ number_format($stats['import_queue']) }}</strong></div>
    </section>

    <section class="aa-panel aa-section">
        <div class="aa-head">
            <div><h2>Accounting Control Flow</h2><div class="small text-muted">เปิดจุดควบคุมหลักโดยไม่ต้องไล่หาเมนูย่อย</div></div>
        </div>
        <div class="aa-actions">
            <a class="aa-action" href="{{ route('monthly-accounting.index', ['period' => $period, 'branch_id' => $branchId]) }}"><i class="bi bi-bank2"></i>Bank reconcile</a>
            <a class="aa-action" href="{{ route('tax-compliance.index', ['period' => $period, 'branch_id' => $branchId]) }}"><i class="bi bi-receipt"></i>ภาษี / E-Tax</a>
            <a class="aa-action" href="{{ route('gl-journals.index') }}"><i class="bi bi-journal-text"></i>GL audit trail</a>
            <a class="aa-action" href="{{ route('financial-statements.index') }}"><i class="bi bi-graph-up"></i>งบการเงิน</a>
            <a class="aa-action" href="{{ route('accounting-periods.index') }}"><i class="bi bi-calendar-check"></i>ปิดงวดบัญชี</a>
        </div>
    </section>

    <div class="aa-grid">
        <section class="aa-panel aa-section">
            <div class="aa-head"><div><h2>รายการเอกสารประจำ</h2><div class="small text-muted">ตั้ง schedule ก่อน ผูกสร้างเอกสารจริงผ่าน service เฉพาะในรอบถัดไป</div></div></div>
            <form method="post" action="{{ route('accounting-automation.recurring.store') }}" class="aa-form mb-4">@csrf
                <div class="aa-field"><label>ชนิด</label><select name="rule_type" required><option value="expense">ค่าใช้จ่ายประจำ</option><option value="sales_invoice">ใบแจ้งหนี้รายเดือน</option><option value="purchase">ซื้อ/บริการประจำ</option><option value="billing_note">วางบิลประจำ</option></select></div>
                <div class="aa-field"><label>สาขา</label><select name="branch_id"><option value="">ทั้งบริษัท</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
                <div class="aa-field span-2"><label>ชื่อรายการ</label><input name="name" required placeholder="เช่น ค่าเช่าสาขารายเดือน"></div>
                <div class="aa-field span-2"><label>คู่ค้า/ผู้รับเงิน</label><input name="party_name"></div>
                <div class="aa-field"><label>ยอดก่อน VAT</label><input type="number" step="0.01" min="0" name="base_amount" value="0"></div>
                <div class="aa-field"><label>VAT</label><input type="number" step="0.01" min="0" name="vat_amount" value="0"></div>
                <div class="aa-field"><label>ความถี่</label><select name="frequency" required><option value="monthly">รายเดือน</option><option value="weekly">รายสัปดาห์</option><option value="quarterly">รายไตรมาส</option><option value="yearly">รายปี</option></select></div>
                <div class="aa-field"><label>รอบถัดไป</label><input type="date" name="next_run_date" value="{{ now()->toDateString() }}" required></div>
                <div class="aa-field span-4"><label>หมายเหตุ/เงื่อนไข</label><textarea name="note" placeholder="เลขสัญญา เงื่อนไข VAT/WHT หรือวิธีตรวจเอกสาร"></textarea></div>
                <div class="span-4 text-end"><button class="btn btn-primary"><i class="bi bi-calendar-plus me-1"></i>เพิ่มรายการประจำ</button></div>
            </form>
            <div class="aa-table-wrap"><table class="aa-table"><thead><tr><th>รายการ</th><th>สาขา</th><th>รอบถัดไป</th><th class="text-end">ยอด</th><th>สถานะ</th><th></th></tr></thead><tbody>@forelse($recurringRules as $rule)<tr><td><strong>{{ $rule->name }}</strong><div>{{ $rule->party_name ?: '-' }} · {{ $rule->rule_type }}</div></td><td>{{ $rule->branch?->code ?: 'ALL' }}</td><td>{{ $rule->next_run_date->thaiDate() }}</td><td class="text-end">{{ number_format((float)$rule->base_amount + (float)$rule->vat_amount,2) }}</td><td><span class="aa-pill {{ $rule->is_active && $rule->next_run_date->lte(now()) ? 'aa-warn' : 'aa-ok' }}">{{ $rule->is_active && $rule->next_run_date->lte(now()) ? 'ถึงกำหนด' : 'รอรอบ' }}</span></td><td class="text-end"><form method="post" action="{{ route('accounting-automation.recurring.run',$rule) }}">@csrf<button class="btn btn-sm btn-light border">บันทึกทำแล้ว</button></form></td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรายการประจำ</td></tr>@endforelse</tbody></table></div>
        </section>

        <section class="aa-panel aa-section">
            <div class="aa-head"><div><h2>OCR/AI Import Queue</h2><div class="small text-muted">เก็บไฟล์และผลอ่านเบื้องต้น ต้อง review ก่อนลงบัญชีจริง</div></div></div>
            <div class="aa-note mb-3">ตอนนี้ระบบใช้ heuristic จากชื่อไฟล์เพื่อเติมวันที่/ยอด/คู่ค้าเบื้องต้น และตั้งค่าสถานะว่า needs AI review ไว้ในข้อมูล extracted JSON เมื่อเชื่อม provider OCR/AI แล้วจะเสียบ engine จริงตรง queue นี้ได้ทันที</div>
            <form method="post" action="{{ route('accounting-automation.imports.upload') }}" enctype="multipart/form-data" class="aa-form mb-4">@csrf
                <div class="aa-field span-2"><label>ประเภทไฟล์</label><select name="source_type" required><option value="purchase_tax_invoice">ใบกำกับซื้อ</option><option value="expense_receipt">ใบเสร็จค่าใช้จ่าย</option><option value="bank_statement">Statement</option><option value="sales_document">เอกสารขาย</option></select></div>
                <div class="aa-field span-2"><label>สาขา</label><select name="branch_id"><option value="">ทั้งบริษัท</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name_th }}</option>@endforeach</select></div>
                <div class="aa-field span-4"><label>ไฟล์ PDF/JPG/PNG/CSV</label><input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.csv,.txt" required style="width:100%"></div>
                <div class="aa-field span-4"><label>หมายเหตุผู้ส่ง</label><textarea name="review_note"></textarea></div>
                <div class="span-4 text-end"><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>นำเข้า Queue</button></div>
            </form>
            <div class="aa-table-wrap"><table class="aa-table"><thead><tr><th>ไฟล์</th><th>ผลอ่าน</th><th>สถานะ</th><th>ตรวจ</th></tr></thead><tbody>@forelse($imports as $batch)<tr><td><strong>{{ $batch->original_name }}</strong><div>{{ $batch->source_type }} · {{ $batch->branch?->code ?: 'ALL' }}</div></td><td>{{ $batch->suggested_date?->format('d/m/Y') ?: '-' }} · {{ $batch->suggested_party ?: '-' }}<div class="fw-bold">{{ $batch->suggested_amount ? number_format((float)$batch->suggested_amount,2) : '-' }}</div></td><td><span class="aa-pill {{ $batch->status === 'approved' ? 'aa-ok' : ($batch->status === 'rejected' ? 'aa-danger' : 'aa-warn') }}">{{ $batch->status }}</span></td><td><form method="post" action="{{ route('accounting-automation.imports.review',$batch) }}" class="d-flex gap-1">@csrf<select name="status" class="form-select form-select-sm"><option value="approved">ผ่าน</option><option value="rejected">ตีกลับ</option></select><button class="btn btn-sm btn-light border">บันทึก</button></form></td></tr>@empty<tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีไฟล์นำเข้า</td></tr>@endforelse</tbody></table></div>
        </section>
    </div>
</div>
@endsection
