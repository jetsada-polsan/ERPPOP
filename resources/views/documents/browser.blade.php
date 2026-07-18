@extends('layout')
@section('title', 'เอกสารย้อนหลัง - POPSTAR ERP')
@section('page-title', 'เอกสารย้อนหลัง')
@section('page-subtitle', 'ดูเอกสารทุกใบย้อนหลัง แยกประเภท ปี เดือน และกรองรายวันได้เหมือนระบบเดิม')
@section('content')

<form method="get" id="docFilter">
    {{-- แถบกรองด้านบน --}}
    <div class="doc-filter-bar mb-3">
        <input type="hidden" name="type" value="{{ $filters['type'] }}">
        <input type="hidden" name="year" value="{{ $filters['year'] }}">
        <input type="hidden" name="month" value="{{ $filters['month'] }}">
        <input type="hidden" name="legacy_type" value="{{ $filters['legacy_type'] ?? '' }}">

        <span class="text-muted small fw-bold text-nowrap"><i class="bi bi-calendar3 me-1"></i>ดูรายวัน</span>
        @if(! empty($filters['legacy_type']))
            <span class="badge text-bg-info border">
                BPlus {{ $filters['legacy_type'] }}
                <a class="text-decoration-none text-dark ms-1" href="{{ route('documents.browser', ['year' => $filters['year'], 'month' => $filters['month'], 'q' => $filters['q']]) }}">x</a>
            </span>
        @endif

        <input type="date" name="day" value="{{ $filters['day'] }}" class="form-control form-control-sm" style="width:150px"
            onchange="this.form.year.value='';this.form.month.value='';this.form.submit()">

        <span class="text-muted small text-nowrap">หรือช่วง</span>
        <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm" style="width:150px">
        <span class="text-muted small">ถึง</span>
        <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm" style="width:150px">

        <a href="{{ route('documents.browser', ['type' => $filters['type'], 'day' => now()->toDateString()]) }}" class="btn btn-sm btn-light border">วันนี้</a>
        <a href="{{ route('documents.browser', ['type' => $filters['type'], 'day' => now()->subDay()->toDateString()]) }}" class="btn btn-sm btn-light border">เมื่อวาน</a>
        <a href="{{ route('documents.browser', ['type' => $filters['type'], 'year' => now()->year, 'month' => now()->month]) }}" class="btn btn-sm btn-light border">เดือนนี้</a>

        <div class="ms-auto d-flex gap-2">
            <input type="text" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" style="width:220px"
                placeholder="เลขที่เอกสาร / ลูกค้า / อ้างอิง">
            <button class="btn btn-sm btn-primary px-3" onclick="this.form.day.value=''"><i class="bi bi-funnel-fill me-1"></i>กรอง</button>
        </div>
    </div>
</form>

<div class="doc-browser">
    {{-- ต้นไม้ ประเภท -> ปี -> เดือน แบบ BPlus --}}
    <div class="doc-tree-panel" x-data="{ openType: @js($filters['type'] ?? '') }">
        <div class="doc-tree-head"><i class="bi bi-diagram-3-fill me-1"></i>เอกสารทั้งหมด</div>
        <a href="{{ route('documents.browser', ['day' => now()->toDateString()]) }}"
           class="doc-tree-type {{ ! $filters['type'] ? 'active' : '' }}">
            <i class="bi bi-folder2-open"></i> ทุกประเภท (วันนี้)
        </a>
        @foreach($types as $type)
            @php($typeTree = $tree[$type->id] ?? [])
            @if($typeTree === [])
                @continue
            @endif
            <div>
                <button type="button" class="doc-tree-type {{ $filters['type'] === $type->code ? 'active' : '' }}"
                    @click="openType = openType === '{{ $type->code }}' ? '' : '{{ $type->code }}'">
                    <i class="bi" :class="openType === '{{ $type->code }}' ? 'bi-folder2-open' : 'bi-folder2'"></i>
                    {{ $type->code === 'CREDIT_SALE' ? 'DS.' : '' }}{{ $type->name_th }}
                    <span class="doc-tree-count">{{ number_format(collect($typeTree)->flatten()->sum()) }}</span>
                </button>
                <div x-show="openType === '{{ $type->code }}'" class="doc-tree-years">
                    @php($typeBooks = $booksByType[$type->id] ?? collect())
                    @if($typeBooks->count() > 1)
                        <div class="doc-book-row">
                            @foreach($typeBooks as $book)
                                <a href="{{ route('documents.browser', ['book' => $book->id]) }}"
                                   class="doc-book-chip {{ (string) $filters['book'] === (string) $book->id ? 'active' : '' }}">
                                    <i class="bi bi-journal"></i> {{ $book->code }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @foreach($typeTree as $year => $months)
                        <div class="doc-tree-year">
                            <a href="{{ route('documents.browser', ['type' => $type->code, 'year' => $year]) }}"
                               class="doc-tree-link {{ $filters['type'] === $type->code && $filters['year'] == $year && ! $filters['month'] ? 'active' : '' }}">
                                <i class="bi bi-calendar4-range"></i> ปี {{ $year + 543 }}
                                <span class="doc-tree-count">{{ number_format(array_sum($months)) }}</span>
                            </a>
                            <div class="doc-tree-months">
                                @foreach($months as $month => $count)
                                    <a href="{{ route('documents.browser', ['type' => $type->code, 'year' => $year, 'month' => $month]) }}"
                                       class="doc-tree-link {{ $filters['type'] === $type->code && $filters['year'] == $year && $filters['month'] == $month ? 'active' : '' }}">
                                        <i class="bi bi-file-earmark-text"></i> {{ $month }}/{{ $year + 543 }}
                                        <span class="doc-tree-count">{{ number_format($count) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- ตารางเอกสาร --}}
    <div class="doc-list-panel">
        <div class="content-card overflow-hidden">
            <div class="d-flex align-items-center gap-2 flex-wrap px-3 py-2 border-bottom">
                <span class="fw-bold">
                    @if($filters['day']) เอกสารวันที่ {{ \Illuminate\Support\Carbon::parse($filters['day'])->thaiDate() }}
                    @elseif($filters['year'] && $filters['month']) เดือน {{ $filters['month'] }}/{{ $filters['year'] + 543 }}
                    @elseif($filters['year']) ปี {{ $filters['year'] + 543 }}
                    @elseif($filters['from'] || $filters['to']) ช่วงที่เลือก
                    @else ทั้งหมด @endif
                </span>
                <span class="badge text-bg-light border">{{ number_format($summary['count']) }} ใบ</span>
                <span class="badge text-bg-success">รวม ฿{{ number_format($summary['amount'], 2) }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 doc-grid">
                    <thead>
                        <tr>
                            <th>เลขที่เอกสาร</th>
                            <th>ประเภท</th>
                            <th>วันที่</th>
                            <th>ลูกค้า / คู่ค้า</th>
                            <th>สาขา</th>
                            <th class="text-end">รายการ</th>
                            <th class="text-end">ยอดรวม</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($documents as $doc)
                        @php($url = \App\Http\Controllers\DocumentBrowserController::detailUrl($doc))
                        <tr @if($url) style="cursor:pointer" onclick="window.location='{{ $url }}'" @endif>
                            <td class="fw-semibold" style="color:#0284c7">
                                {{ $doc->doc_number }}
                                @if($doc->documentBook && ! $doc->documentBook->is_default)<span class="badge text-bg-light border ms-1">{{ $doc->documentBook->code }}</span>@endif
                            </td>
                            <td class="small">{{ $doc->documentType->name_th }}</td>
                            <td class="text-nowrap">{{ $doc->doc_date->thaiDate() }}</td>
                            <td class="small">{{ $doc->customer?->name_th ?? $doc->supplier?->name_th ?? '-' }}</td>
                            <td class="small">{{ $doc->branch->name_th }}</td>
                            <td class="text-end">{{ number_format($doc->total_items) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($doc->total_amount, 2) }}</td>
                            <td>
                                <span class="badge {{ $doc->cancelled_at ? 'text-bg-danger' : 'text-bg-success' }}">
                                    {{ $doc->cancelled_at ? 'ยกเลิก' : 'ปกติ' }}
                                </span>
                            </td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                @if($url)<a href="{{ $url }}" class="btn btn-sm btn-light border">ดู</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-5">ไม่พบเอกสารตามเงื่อนไข — เลือกปี/เดือนจากต้นไม้ซ้ายมือ หรือเปลี่ยนวันที่</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $documents->links() }}</div>
        </div>

        @if($legacyDocuments && $legacyDocuments->count())
        <div class="content-card overflow-hidden mt-3">
            <div class="d-flex align-items-center gap-2 flex-wrap px-3 py-2 border-bottom">
                <span class="fw-bold">เอกสารเก่า BPlus</span>
                <span class="badge text-bg-info border">{{ number_format($legacySummary['count']) }} ใบ</span>
                <span class="badge text-bg-success">รวม ฿{{ number_format($legacySummary['amount'], 2) }}</span>
                <span class="text-muted small">อ่านย้อนหลังจาก legacy.dbo__docinfo / transtkh / transtkd</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 doc-grid">
                    <thead>
                        <tr>
                            <th>เลขที่เดิม</th>
                            <th>ประเภท</th>
                            <th>วันที่</th>
                            <th>ลูกค้า</th>
                            <th class="text-end">รายการ</th>
                            <th class="text-end">จำนวน</th>
                            <th class="text-end">ยอดรวม</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($legacyDocuments as $legacy)
                        <tr style="cursor:pointer" onclick="window.location='{{ route('documents.legacy-show', $legacy->di_key) }}'">
                            <td class="fw-semibold" style="color:#0284c7">{{ $legacy->doc_number }}</td>
                            <td>
                                <div class="fw-semibold">{{ $legacy->legacy_type_code }}</div>
                                <div class="text-muted small">{{ $legacy->legacy_type_name }}</div>
                            </td>
                            <td class="text-nowrap">{{ \Illuminate\Support\Carbon::parse($legacy->doc_date)->thaiDate() }}</td>
                            <td class="small">
                                {{ $legacy->customer_name ?: '-' }}
                                @if($legacy->customer_code)
                                    <div class="text-muted">{{ $legacy->customer_code }}</div>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format((float) $legacy->item_count) }}</td>
                            <td class="text-end">{{ number_format((float) $legacy->total_qty, 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $legacy->total_amount, 2) }}</td>
                            <td class="text-end" onclick="event.stopPropagation()">
                                <a href="{{ route('documents.legacy-show', $legacy->di_key) }}" class="btn btn-sm btn-light border">ดู</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $legacyDocuments->links() }}</div>
        </div>
        @endif
    </div>
</div>
@endsection

@push('head')
<style>
    .doc-filter-bar {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        padding: 10px 12px; box-shadow: 0 1px 4px rgba(15,23,42,.05);
    }
    .doc-browser { display: grid; grid-template-columns: 285px minmax(0, 1fr); gap: 14px; align-items: start; }
    .doc-tree-panel {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 8px; max-height: calc(100vh - 240px); overflow-y: auto;
        scrollbar-width: thin;
    }
    .doc-tree-head { font-size: 12px; font-weight: 900; color: #64748b; padding: 6px 8px; }
    .doc-tree-type {
        display: flex; align-items: center; gap: 7px; width: 100%;
        border: 0; background: transparent; text-align: left;
        padding: 7px 9px; border-radius: 8px; font-size: 13px; font-weight: 700;
        color: #334155; cursor: pointer; text-decoration: none;
    }
    .doc-book-row { display: flex; flex-wrap: wrap; gap: 5px; padding: 4px 8px 6px 14px; }
    .doc-book-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-size: 12px; font-weight: 700; text-decoration: none; }
    .doc-book-chip:hover { border-color: #0ea5e9; color: #0369a1; }
    .doc-book-chip.active { background: #0369a1; color: #fff; border-color: #0369a1; }
    .doc-tree-type:hover { background: #f1f5f9; }
    .doc-tree-type.active { background: #e0f2fe; color: #0369a1; }
    .doc-tree-years { padding-left: 14px; }
    .doc-tree-link {
        display: flex; align-items: center; gap: 6px;
        padding: 5px 9px; border-radius: 7px; font-size: 12.5px;
        color: #475569; text-decoration: none;
    }
    .doc-tree-link:hover { background: #f1f5f9; }
    .doc-tree-link.active { background: #0f172a; color: #fff; }
    .doc-tree-months { padding-left: 18px; }
    .doc-tree-count { margin-left: auto; font-size: 10.5px; color: #94a3b8; font-weight: 700; }
    .doc-tree-link.active .doc-tree-count { color: #cbd5e1; }
    .doc-grid thead th {
        position: sticky; top: 0; background: #0f172a; color: #e2e8f0;
        font-size: 12px; white-space: nowrap; z-index: 2;
    }
    .doc-grid td { font-size: 13px; }
    .doc-grid tbody tr:nth-child(even) { background: #f8fafc; }
    .doc-grid tbody tr:hover { background: #f0f9ff; }
    @media (max-width: 1100px) {
        .doc-browser { grid-template-columns: 1fr; }
        .doc-tree-panel { max-height: 300px; }
    }
</style>
@endpush
