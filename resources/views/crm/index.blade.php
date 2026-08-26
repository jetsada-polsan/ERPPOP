@extends('layout')

@section('title', 'ลูกค้าสัมพันธ์ - PopCentral')
@section('page-title', 'ลูกค้าสัมพันธ์')
@section('page-subtitle', 'Customer 360 · ลูกค้า งานขาย และยอดค้างชำระในที่เดียว')

@section('content')
    <div class="crm-page">
        <div class="crm-toolbar">
            <form method="get" class="crm-search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="{{ $q }}" placeholder="ค้นหาลูกค้า รหัส หรือชื่อ..." aria-label="ค้นหาลูกค้า">
                <input type="hidden" name="status" value="{{ $status }}">
            </form>
            <a href="{{ route('customers.index') }}" class="btn btn-light border"><i class="bi bi-people me-1"></i> ทะเบียนลูกค้า</a>
            <a href="{{ route('bookings.index') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> เปิดงานขาย</a>
        </div>

        <div class="crm-kpis">
            <div class="crm-kpi crm-kpi-primary"><span class="crm-kpi-icon"><i class="bi bi-people-fill"></i></span><div><small>ลูกค้าที่ใช้งาน</small><strong>{{ number_format($counts['active']) }}</strong><span>ราย</span></div></div>
            <div class="crm-kpi"><span class="crm-kpi-icon"><i class="bi bi-person-lines-fill"></i></span><div><small>ยังไม่กำหนดผู้ดูแล</small><strong>{{ number_format($counts['unassigned']) }}</strong><span>ราย</span></div></div>
            <div class="crm-kpi crm-kpi-warning"><span class="crm-kpi-icon"><i class="bi bi-wallet2"></i></span><div><small>ยอดค้างชำระรวม</small><strong>{{ number_format($outstandingBalance, 2) }}</strong><span>บาท</span></div></div>
            <div class="crm-kpi"><span class="crm-kpi-icon"><i class="bi bi-person-dash-fill"></i></span><div><small>พักการใช้งาน</small><strong>{{ number_format($counts['inactive']) }}</strong><span>ราย</span></div></div>
        </div>

        <div class="crm-grid crm-grid-top">
            <section class="crm-panel">
                <div class="crm-panel-head"><div><h2>ลูกค้าที่มียอดขายสูงสุด</h2><p>จากเอกสารขายที่บันทึกในระบบ</p></div><i class="bi bi-graph-up-arrow"></i></div>
                <div class="crm-top-list">
                    @forelse($topCustomers as $customer)
                        <a class="crm-top-row" href="{{ route('customers.show', $customer) }}"><span class="crm-avatar">{{ mb_substr($customer->name_th, 0, 1) }}</span><span class="crm-top-name"><strong>{{ $customer->name_th }}</strong><small>{{ $customer->code }} · {{ $customer->salesUser?->name ?? 'ยังไม่ระบุผู้ดูแล' }}</small></span><b>{{ number_format((float) ($customer->sales_total ?? 0), 2) }} <small>บาท</small></b></a>
                    @empty
                        <div class="crm-empty">ยังไม่มีข้อมูลยอดขาย</div>
                    @endforelse
                </div>
            </section>
            <section class="crm-panel">
                <div class="crm-panel-head"><div><h2>เอกสารล่าสุด</h2><p>รายการขายของลูกค้าที่คุณมีสิทธิ์เห็น</p></div><i class="bi bi-clock-history"></i></div>
                <div class="crm-doc-list">
                    @forelse($recentDocuments as $document)
                        <a class="crm-doc-row" href="{{ route('documents.browser') }}"><span class="crm-doc-icon"><i class="bi bi-file-earmark-text"></i></span><span><strong>{{ $document->customer?->name_th ?? '-' }}</strong><small>{{ $document->documentType?->name_th ?? '-' }} · {{ $document->doc_number }}</small></span><b>{{ number_format((float) $document->total_amount, 2) }}</b></a>
                    @empty
                        <div class="crm-empty">ยังไม่มีเอกสารลูกค้า</div>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="crm-grid crm-activity-grid">
            <section class="crm-panel">
                <div class="crm-panel-head"><div><h2>งานติดตามลูกค้า</h2><p>งานค้างเรียงตามกำหนดติดตาม</p></div><i class="bi bi-list-check"></i></div>
                <div class="crm-activity-list">
                    @forelse($activities as $activity)
                        <div class="crm-activity-row"><span class="crm-activity-type"><i class="bi bi-{{ ['call'=>'telephone','visit'=>'person-walking','line'=>'chat-dots','email'=>'envelope','note'=>'sticky-note'][$activity->activity_type] ?? 'check2' }}"></i></span><span class="crm-activity-copy"><strong>{{ $activity->subject }}</strong><small>{{ $activity->customer?->name_th ?? '-' }} · {{ $activityTypes[$activity->activity_type] ?? $activity->activity_type }} · {{ $activity->assignedTo?->name ?? '-' }}</small></span><span class="crm-activity-date {{ $activity->due_at && $activity->due_at->isPast() ? 'overdue' : '' }}">{{ $activity->due_at?->thaiDate(true) ?? 'ไม่กำหนดวัน' }}</span><form method="post" action="{{ route('crm.activities.complete', $activity) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-light border" title="ปิดงาน"><i class="bi bi-check2"></i></button></form></div>
                    @empty
                        <div class="crm-empty">ไม่มีงานติดตามค้างอยู่</div>
                    @endforelse
                </div>
            </section>
            <section class="crm-panel crm-activity-form-panel">
                <div class="crm-panel-head"><div><h2>เพิ่มงานติดตาม</h2><p>บันทึกงานครั้งถัดไปไว้กับลูกค้า</p></div><i class="bi bi-plus-circle"></i></div>
                <form method="post" action="{{ route('crm.activities.store') }}" class="crm-activity-form">@csrf
                    <label>ลูกค้า<select name="customer_id" required><option value="">เลือกลูกค้า...</option>@foreach($activityCustomers as $customer)<option value="{{ $customer->id }}">{{ $customer->code }} - {{ $customer->name_th }}</option>@endforeach</select></label>
                    <label>หัวข้องาน<input name="subject" required maxlength="200" placeholder="เช่น โทรติดตามใบเสนอราคา"></label>
                    <div class="crm-form-two"><label>ประเภท<select name="activity_type" required>@foreach($activityTypes as $type => $label)<option value="{{ $type }}">{{ $label }}</option>@endforeach</select></label><label>กำหนดติดตาม<input type="datetime-local" name="due_at"></label></div>
                    @if(auth()->user()->hasPermission('sales.assign'))<label>ผู้รับผิดชอบ<select name="assigned_to"><option value="">ฉัน</option>@foreach($activityUsers as $activityUser)<option value="{{ $activityUser->id }}">{{ $activityUser->name }}</option>@endforeach</select></label>@endif
                    <label>รายละเอียด<textarea name="note" rows="2" maxlength="2000" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea></label><button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> เพิ่มงาน</button>
                </form>
            </section>
        </div>

        <section class="crm-panel crm-customer-panel">
            <div class="crm-panel-head crm-panel-head-filter"><div><h2>ลูกค้าของฉันและสาขา</h2><p>เลือกดูข้อมูลลูกค้าและเปิด Customer 360 ได้ทันที</p></div><div class="crm-tabs"><a href="{{ route('crm.index', ['q' => $q, 'status' => 'active']) }}" class="{{ $status === 'active' ? 'active' : '' }}">ใช้งาน {{ number_format($counts['active']) }}</a><a href="{{ route('crm.index', ['q' => $q, 'status' => 'inactive']) }}" class="{{ $status === 'inactive' ? 'active' : '' }}">พักไว้ {{ number_format($counts['inactive']) }}</a><a href="{{ route('crm.index', ['q' => $q, 'status' => 'all']) }}" class="{{ $status === 'all' ? 'active' : '' }}">ทั้งหมด</a></div></div>
            <div class="table-responsive"><table class="table crm-table align-middle mb-0"><thead><tr><th>ลูกค้า</th><th>สาขา / ผู้ดูแล</th><th>ช่องทางติดต่อ</th><th class="text-end">ค้างชำระ</th><th>สถานะ</th><th></th></tr></thead><tbody>
                @forelse($customers as $customer)
                    <tr><td><a href="{{ route('customers.show', $customer) }}" class="crm-customer-link"><span class="crm-avatar crm-avatar-sm">{{ mb_substr($customer->name_th, 0, 1) }}</span><span><strong>{{ $customer->name_th }}</strong><small>{{ $customer->code }}</small></span></a></td><td><span>{{ $customer->branch?->name_th ?? '-' }}</span><small class="d-block text-muted">{{ $customer->salesUser?->name ?? 'ยังไม่ระบุผู้ดูแล' }}</small></td><td><span class="crm-count"><i class="bi bi-person"></i>{{ $customer->contacts_count }}</span><span class="crm-count"><i class="bi bi-geo-alt"></i>{{ $customer->addresses_count }}</span></td><td class="text-end {{ ($customer->outstanding_balance ?? 0) > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format((float) ($customer->outstanding_balance ?? 0), 2) }}</td><td><span class="crm-status {{ $customer->is_active ? 'active' : 'inactive' }}">{{ $customer->is_active ? 'ใช้งาน' : 'พักไว้' }}</span></td><td class="text-end"><a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-light border" title="เปิด Customer 360"><i class="bi bi-arrow-up-right"></i></a></td></tr>
                @empty
                    <tr><td colspan="6" class="crm-empty py-5">ไม่พบลูกค้าที่ตรงกับเงื่อนไข</td></tr>
                @endforelse
            </tbody></table></div><div class="p-3">{{ $customers->links() }}</div>
        </section>
    </div>
@endsection

@push('head')
<style>
    .crm-page{--crm-blue:var(--erp-primary);--crm-ink:var(--erp-primary-dark);--crm-soft:var(--erp-primary-soft);--crm-line:var(--erp-border);color:var(--erp-text)}
    .crm-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px}.crm-search{display:flex;align-items:center;gap:8px;flex:1;max-width:620px;height:40px;padding:0 13px;background:var(--erp-surface);border:1px solid var(--crm-line);border-radius:6px}.crm-search i{color:var(--erp-muted)}.crm-search input{border:0;outline:0;background:transparent;width:100%;font:inherit;color:var(--erp-text)}
    .crm-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.crm-kpi{display:flex;align-items:center;gap:12px;min-height:92px;padding:16px;background:var(--erp-surface);border:1px solid var(--crm-line);border-radius:6px;box-shadow:0 1px 2px rgba(29,59,82,.05)}.crm-kpi-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:8px;background:var(--crm-soft);color:var(--crm-blue);font-size:19px}.crm-kpi small,.crm-kpi span{display:block;color:var(--erp-muted);font-size:11px}.crm-kpi strong{display:inline-block;color:var(--crm-ink);font-size:22px;line-height:1.2;margin-right:5px}.crm-kpi-warning .crm-kpi-icon{background:#fff5df;color:#b7791f}.crm-kpi-warning strong{color:#9a6717}
    .crm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.crm-panel{background:var(--erp-surface);border:1px solid var(--crm-line);border-radius:6px;box-shadow:0 1px 2px rgba(29,59,82,.05);overflow:hidden}.crm-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--crm-line)}.crm-panel-head h2{margin:0;color:var(--crm-ink);font-size:15px;font-weight:700}.crm-panel-head p{margin:3px 0 0;color:var(--erp-muted);font-size:11px}.crm-panel-head>i{color:var(--crm-blue);font-size:18px}.crm-top-row,.crm-doc-row{display:flex;align-items:center;gap:10px;padding:11px 18px;text-decoration:none;color:inherit;border-bottom:1px solid var(--erp-border-soft,var(--crm-line))}.crm-top-row:last-child,.crm-doc-row:last-child{border-bottom:0}.crm-top-row:hover,.crm-doc-row:hover{background:var(--crm-soft)}.crm-avatar{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:8px;background:var(--crm-soft);color:var(--crm-ink);font-weight:700;font-size:14px}.crm-avatar-sm{width:30px;height:30px;flex-basis:30px;font-size:12px}.crm-top-name,.crm-doc-row>span:nth-child(2){min-width:0;flex:1}.crm-top-name strong,.crm-doc-row strong{display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crm-top-name small,.crm-doc-row small,.crm-customer-link small{display:block;color:var(--erp-muted);font-size:10.5px;margin-top:2px}.crm-top-row>b,.crm-doc-row>b{color:var(--crm-ink);font-size:12px;white-space:nowrap}.crm-top-row>b small{font-weight:400;color:var(--erp-muted);font-size:10px}.crm-doc-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:7px;background:#edf7f4;color:#20836a}.crm-empty{text-align:center;color:var(--erp-muted);font-size:12px;padding:24px}.crm-panel-head-filter{align-items:center}.crm-tabs{display:flex;gap:3px;padding:3px;background:var(--erp-surface-2,#f8fbfd);border:1px solid var(--crm-line);border-radius:5px}.crm-tabs a{padding:5px 9px;border-radius:3px;color:var(--erp-muted);font-size:11px;text-decoration:none}.crm-tabs a.active{background:var(--erp-surface);color:var(--crm-ink);box-shadow:0 1px 2px rgba(29,59,82,.1);font-weight:700}.crm-table{font-size:12px}.crm-table thead th{background:var(--crm-soft);color:var(--crm-ink);font-size:11px;font-weight:700;border-bottom:1px solid var(--crm-line);white-space:nowrap}.crm-table td{border-color:var(--erp-border-soft,var(--crm-line))}.crm-customer-link{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--erp-text)}.crm-customer-link strong{display:block}.crm-count{display:inline-flex;align-items:center;gap:3px;color:var(--erp-muted);font-size:11px;margin-right:9px}.crm-count i{color:var(--crm-blue)}.crm-status{display:inline-block;padding:3px 7px;border-radius:4px;font-size:10px;font-weight:700}.crm-status.active{background:#eaf7f1;color:#168058}.crm-status.inactive{background:var(--erp-surface-2,#f3f5f7);color:var(--erp-muted)}.crm-activity-row{display:flex;align-items:center;gap:10px;padding:11px 18px;border-bottom:1px solid var(--erp-border-soft,var(--crm-line))}.crm-activity-row:last-child{border-bottom:0}.crm-activity-type{display:grid;place-items:center;width:30px;height:30px;border-radius:7px;background:var(--crm-soft);color:var(--crm-blue)}.crm-activity-copy{min-width:0;flex:1}.crm-activity-copy strong,.crm-activity-copy small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crm-activity-copy strong{font-size:12px}.crm-activity-copy small{font-size:10.5px;color:var(--erp-muted);margin-top:2px}.crm-activity-date{font-size:10.5px;color:var(--erp-muted);white-space:nowrap}.crm-activity-date.overdue{color:#b74646;font-weight:700}.crm-activity-form{display:grid;gap:9px;padding:16px 18px}.crm-activity-form label{display:grid;gap:4px;color:var(--erp-muted);font-size:11px;font-weight:600}.crm-activity-form input,.crm-activity-form select,.crm-activity-form textarea{width:100%;border:1px solid var(--crm-line);border-radius:4px;padding:7px 9px;background:var(--erp-surface);color:var(--erp-text);font:inherit;font-size:12px}.crm-form-two{display:grid;grid-template-columns:1fr 1fr;gap:9px}.crm-activity-form button{justify-self:start}
    @media(max-width:991.98px){.crm-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.crm-grid{grid-template-columns:1fr}}@media(max-width:575.98px){.crm-toolbar{flex-wrap:wrap}.crm-search{max-width:none;flex-basis:100%}.crm-kpis{grid-template-columns:1fr 1fr}.crm-panel-head-filter{align-items:flex-start;flex-direction:column}.crm-table{min-width:700px}}
</style>
@endpush
