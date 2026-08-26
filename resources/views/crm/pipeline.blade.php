@extends('layout')

@section('title', 'Pipeline งานขาย - PopCentral')
@section('page-title', 'Pipeline งานขาย')
@section('page-subtitle', 'มองเห็นโอกาสการขายตั้งแต่เริ่มติดต่อจนปิดการขาย')

@section('content')
    <div class="pipeline-page">
        <div class="pipeline-toolbar">
            <form method="get" class="pipeline-search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="{{ $q }}" placeholder="ค้นหาโอกาสการขายหรือลูกค้า..." aria-label="ค้นหา Pipeline">
                @if($stage !== 'all')<input type="hidden" name="stage" value="{{ $stage }}">@endif
            </form>
            <a href="{{ route('crm.index') }}" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i> Customer 360</a>
            <details class="pipeline-add">
                <summary class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> เพิ่มโอกาสการขาย</summary>
                <div class="pipeline-add-popover">
                    <h2>เพิ่มโอกาสการขาย</h2>
                    <p>เก็บงานขายที่กำลังติดตามไว้ใน Pipeline</p>
                    <form method="post" action="{{ route('crm.opportunities.store') }}" class="pipeline-form">
                        @csrf
                        <label>ลูกค้า<select name="customer_id" required><option value="">เลือกลูกค้า...</option>@foreach($pipelineCustomers as $customer)<option value="{{ $customer->id }}">{{ $customer->code }} - {{ $customer->name_th }}</option>@endforeach</select></label>
                        <label>ชื่อโอกาสการขาย<input name="title" required maxlength="200" placeholder="เช่น ขยายออเดอร์สาขาใหม่"></label>
                        <div class="pipeline-form-two"><label>สถานะ<select name="stage" required>@foreach($stages as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label><label>มูลค่าคาดการณ์<input type="number" name="expected_amount" step="0.01" min="0" placeholder="0.00"></label></div>
                        <div class="pipeline-form-two"><label>คาดว่าจะปิดวันที่<input type="date" name="expected_close_date"></label>@if(auth()->user()->hasPermission('sales.assign'))<label>ผู้รับผิดชอบ<select name="assigned_to"><option value="">ฉัน</option>@foreach($salesUsers as $salesUser)<option value="{{ $salesUser->id }}">{{ $salesUser->name }}</option>@endforeach</select></label>@endif</div>
                        <label>รายละเอียด<textarea name="note" rows="3" maxlength="2000" placeholder="บันทึกความต้องการหรือขั้นตอนถัดไป"></textarea></label>
                        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>บันทึกโอกาสการขาย</button>
                    </form>
                </div>
            </details>
        </div>

        <div class="pipeline-summary">
            <div><span class="pipeline-summary-icon"><i class="bi bi-kanban-fill"></i></span><span><small>โอกาสการขายทั้งหมด</small><strong>{{ number_format($totalOpportunities) }}</strong></span></div>
            <div class="pipeline-stage-filter"><a href="{{ route('crm.pipeline', ['q' => $q]) }}" class="{{ $stage === 'all' ? 'active' : '' }}">ทั้งหมด</a>@foreach($stages as $key => $label)<a href="{{ route('crm.pipeline', ['q' => $q, 'stage' => $key]) }}" class="{{ $stage === $key ? 'active' : '' }}">{{ $label }} <b>{{ $opportunitiesByStage[$key]->count() }}</b></a>@endforeach</div>
        </div>

        <div class="pipeline-board">
            @foreach($stages as $stageKey => $stageLabel)
                <section class="pipeline-column pipeline-column-{{ $stageKey }}">
                    <header class="pipeline-column-head"><span><i class="bi bi-circle-fill"></i>{{ $stageLabel }}</span><b>{{ $opportunitiesByStage[$stageKey]->count() }}</b></header>
                    <div class="pipeline-column-body">
                        @forelse($opportunitiesByStage[$stageKey] as $opportunity)
                            <article class="pipeline-card">
                                <div class="pipeline-card-top"><span class="pipeline-card-code">{{ $opportunity->customer?->code ?? '-' }}</span><span class="pipeline-card-amount">฿{{ number_format((float) $opportunity->expected_amount, 2) }}</span></div>
                                <h3>{{ $opportunity->title }}</h3>
                                <a href="{{ route('customers.show', $opportunity->customer) }}" class="pipeline-customer"><span class="pipeline-avatar">{{ mb_substr($opportunity->customer?->name_th ?? '-', 0, 1) }}</span><span>{{ $opportunity->customer?->name_th ?? '-' }}</span></a>
                                <div class="pipeline-card-meta"><span><i class="bi bi-calendar3 me-1"></i>{{ $opportunity->expected_close_date?->thaiDate() ?? 'ไม่กำหนดวันปิด' }}</span><span><i class="bi bi-person me-1"></i>{{ $opportunity->salesUser?->name ?? '-' }}</span></div>
                                @if($opportunity->note)<p class="pipeline-card-note">{{ $opportunity->note }}</p>@endif
                                <form method="post" action="{{ route('crm.opportunities.stage', $opportunity) }}" class="pipeline-stage-form">
                                    @csrf @method('PATCH')
                                    <label>ย้ายสถานะ<select name="stage" onchange="this.form.submit()">@foreach($stages as $key => $label)<option value="{{ $key }}" @selected($key === $opportunity->stage)>{{ $label }}</option>@endforeach</select></label>
                                    @if($stageKey === 'lost')<input name="lost_reason" value="{{ $opportunity->lost_reason }}" maxlength="500" placeholder="เหตุผลที่ไม่สำเร็จ">@endif
                                </form>
                            </article>
                        @empty
                            <div class="pipeline-empty"><i class="bi bi-inbox"></i><span>ยังไม่มีรายการ</span></div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>
@endsection

@push('head')
<style>
    .pipeline-page{--pipeline-line:var(--erp-border);--pipeline-soft:var(--erp-primary-soft);color:var(--erp-text)}
    .pipeline-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px}.pipeline-search{display:flex;align-items:center;gap:8px;flex:1;max-width:620px;height:40px;padding:0 13px;background:var(--erp-surface);border:1px solid var(--pipeline-line);border-radius:6px}.pipeline-search i{color:var(--erp-muted)}.pipeline-search input{border:0;outline:0;background:transparent;width:100%;font:inherit;color:var(--erp-text)}.pipeline-add{position:relative}.pipeline-add summary{list-style:none;cursor:pointer}.pipeline-add summary::-webkit-details-marker{display:none}.pipeline-add-popover{position:absolute;right:0;top:48px;z-index:20;width:min(440px,calc(100vw - 32px));padding:18px;background:var(--erp-surface);border:1px solid var(--pipeline-line);border-radius:8px;box-shadow:0 18px 42px rgba(27,58,83,.18)}.pipeline-add-popover h2{font-size:16px;color:var(--erp-primary-dark);margin:0}.pipeline-add-popover p{font-size:11px;color:var(--erp-muted);margin:3px 0 14px}.pipeline-form{display:grid;gap:10px}.pipeline-form label,.pipeline-stage-form label{display:grid;gap:4px;color:var(--erp-muted);font-size:11px;font-weight:600}.pipeline-form input,.pipeline-form select,.pipeline-form textarea,.pipeline-stage-form select,.pipeline-stage-form input{width:100%;border:1px solid var(--pipeline-line);border-radius:5px;padding:8px 9px;background:var(--erp-surface);color:var(--erp-text);font:inherit;font-size:12px}.pipeline-form-two{display:grid;grid-template-columns:1fr 1fr;gap:9px}
    .pipeline-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;margin-bottom:14px;background:var(--erp-surface);border:1px solid var(--pipeline-line);border-radius:6px}.pipeline-summary>div:first-child{display:flex;align-items:center;gap:10px}.pipeline-summary-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:8px;background:var(--pipeline-soft);color:var(--erp-primary);font-size:15px}.pipeline-summary small,.pipeline-summary strong{display:block}.pipeline-summary small{font-size:10px;color:var(--erp-muted)}.pipeline-summary strong{font-size:18px;color:var(--erp-primary-dark)}.pipeline-stage-filter{display:flex;gap:3px;overflow:auto;padding:3px;background:var(--erp-surface-2,#f8fbfd);border:1px solid var(--pipeline-line);border-radius:5px}.pipeline-stage-filter a{padding:5px 8px;border-radius:3px;white-space:nowrap;text-decoration:none;color:var(--erp-muted);font-size:10px}.pipeline-stage-filter a.active{background:var(--erp-surface);color:var(--erp-primary-dark);font-weight:700;box-shadow:0 1px 2px rgba(29,59,82,.1)}.pipeline-stage-filter b{font-size:9px;color:var(--erp-muted)}
    .pipeline-board{display:grid;grid-template-columns:repeat(7,minmax(220px,1fr));gap:10px;overflow-x:auto;padding-bottom:8px;align-items:start}.pipeline-column{min-width:220px;background:var(--erp-surface-2,#f8fbfd);border:1px solid var(--pipeline-line);border-radius:7px;overflow:hidden}.pipeline-column-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 12px;background:var(--erp-surface);border-bottom:2px solid var(--erp-primary)}.pipeline-column-head span{display:flex;align-items:center;gap:7px;color:var(--erp-primary-dark);font-size:12px;font-weight:700}.pipeline-column-head i{font-size:8px;color:var(--erp-primary)}.pipeline-column-head b{display:grid;place-items:center;min-width:22px;height:22px;padding:0 5px;border-radius:999px;background:var(--pipeline-soft);color:var(--erp-primary-dark);font-size:10px}.pipeline-column-contacted .pipeline-column-head{border-color:#4b9cbb}.pipeline-column-qualified .pipeline-column-head{border-color:#b98b2c}.pipeline-column-quotation .pipeline-column-head{border-color:#8272b8}.pipeline-column-waiting .pipeline-column-head{border-color:#d08038}.pipeline-column-won .pipeline-column-head{border-color:#29936f}.pipeline-column-lost .pipeline-column-head{border-color:#bc5c68}.pipeline-column-body{display:grid;gap:9px;padding:9px;min-height:170px}.pipeline-card{padding:12px;background:var(--erp-surface);border:1px solid var(--pipeline-line);border-radius:6px;box-shadow:0 1px 2px rgba(29,59,82,.05)}.pipeline-card-top{display:flex;justify-content:space-between;gap:8px}.pipeline-card-code{font-size:10px;color:var(--erp-muted)}.pipeline-card-amount{font-size:11px;color:var(--erp-primary-dark);font-weight:700;white-space:nowrap}.pipeline-card h3{margin:6px 0 9px;font-size:13px;line-height:1.35;color:var(--erp-text)}.pipeline-customer{display:flex;align-items:center;gap:7px;color:var(--erp-text);font-size:11px;text-decoration:none}.pipeline-customer:hover{color:var(--erp-primary)}.pipeline-avatar{display:grid;place-items:center;flex:0 0 24px;width:24px;height:24px;border-radius:6px;background:var(--pipeline-soft);color:var(--erp-primary-dark);font-size:10px;font-weight:700}.pipeline-card-meta{display:grid;gap:3px;margin-top:10px;padding-top:9px;border-top:1px solid var(--erp-border-soft,var(--pipeline-line));color:var(--erp-muted);font-size:10px}.pipeline-card-note{margin:9px 0 0;color:var(--erp-muted);font-size:10px;line-height:1.45}.pipeline-stage-form{display:grid;gap:6px;margin-top:10px}.pipeline-stage-form select{padding:6px 7px;font-size:10px}.pipeline-stage-form input{padding:6px 7px;font-size:10px}.pipeline-empty{display:grid;place-items:center;gap:5px;min-height:140px;color:var(--erp-muted);font-size:11px}.pipeline-empty i{font-size:18px}
    @media(max-width:900px){.pipeline-toolbar{flex-wrap:wrap}.pipeline-search{flex-basis:100%;max-width:none}.pipeline-summary{align-items:flex-start;flex-direction:column}.pipeline-stage-filter{max-width:100%;width:100%}}
</style>
@endpush
