{{--
  Partial: ตัวกรองวันที่สำหรับหน้ารายการเอกสาร
  Usage:
    ในฟอร์มตัวกรองเดิม: @include('partials.list-date-range', ['dateRange' => $dateRange])
    แบบสร้างฟอร์มครอบ: @include('partials.list-date-range', ['dateRange' => $dateRange, 'wrapForm' => true])
--}}
@php
    $todayStr = now()->toDateString();
    $yesterdayStr = now()->subDay()->toDateString();
    $sevenDaysStr = now()->subDays(6)->toDateString();
    $monthStartStr = now()->startOfMonth()->toDateString();
    $monthEndStr = now()->endOfMonth()->toDateString();

    $currentFrom = $dateRange->from ?? request('date_from');
    $currentTo = $dateRange->to ?? request('date_to');
    $isUnrestricted = $dateRange->isUnrestricted ?? false;
    $warning = $dateRange->warning ?? null;
    $label = isset($dateRange) ? $dateRange->label() : null;

    $isTodayActive = (! $isUnrestricted && $currentFrom === $todayStr && $currentTo === $todayStr);
    $isYesterdayActive = (! $isUnrestricted && $currentFrom === $yesterdayStr && $currentTo === $yesterdayStr);
    $isSevenDaysActive = (! $isUnrestricted && $currentFrom === $sevenDaysStr && $currentTo === $todayStr);
    $isMonthActive = (! $isUnrestricted && $currentFrom === $monthStartStr && in_array($currentTo, [$todayStr, $monthEndStr]));

    $wrapForm = $wrapForm ?? false;
    $otherParams = request()->except(['date_from', 'date_to', 'page']);
@endphp

@if($wrapForm)
<form method="get" class="d-flex align-items-center gap-2 flex-wrap mb-3 doc-date-filter-form">
    @foreach($otherParams as $k => $v)
        @if(is_array($v))
            @foreach($v as $subK => $subV)
                <input type="hidden" name="{{ $k }}[{{ $subK }}]" value="{{ $subV }}">
            @endforeach
        @else
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endif
    @endforeach
@endif

<div class="d-inline-flex align-items-center gap-2 flex-wrap list-date-range-container">
    <div class="input-group input-group-sm" style="width: auto;">
        <span class="input-group-text bg-white text-muted px-2" title="ช่วงวันที่"><i class="bi bi-calendar3"></i></span>
        <input type="date" name="date_from" class="form-control form-control-sm"
               value="{{ $currentFrom }}" title="ตั้งแต่วันที่" placeholder="ตั้งแต่"
               style="width: 130px;">
        <span class="input-group-text bg-white text-muted px-1">ถึง</span>
        <input type="date" name="date_to" class="form-control form-control-sm"
               value="{{ $currentTo }}" title="ถึงวันที่" placeholder="ถึง"
               style="width: 130px;">
        <button type="submit" class="btn btn-outline-secondary btn-sm" title="กรองตามช่วงวันที่">
            <i class="bi bi-funnel"></i>
        </button>
    </div>

    <div class="btn-group btn-group-sm" role="group" aria-label="ช่วงวันด่วน">
        <button type="button" class="btn {{ $isTodayActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                onclick="setListDateRange(this, '{{ $todayStr }}', '{{ $todayStr }}')"
                title="แสดงเฉพาะวันนี้">วันนี้</button>
        <button type="button" class="btn {{ $isYesterdayActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                onclick="setListDateRange(this, '{{ $yesterdayStr }}', '{{ $yesterdayStr }}')"
                title="แสดงเฉพาะเมื่อวาน">เมื่อวาน</button>
        <button type="button" class="btn {{ $isSevenDaysActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                onclick="setListDateRange(this, '{{ $sevenDaysStr }}', '{{ $todayStr }}')"
                title="ย้อนหลัง 7 วัน">7 วัน</button>
        <button type="button" class="btn {{ $isMonthActive ? 'btn-primary' : 'btn-outline-secondary' }}"
                onclick="setListDateRange(this, '{{ $monthStartStr }}', '{{ $monthEndStr }}')"
                title="เดือนนี้">เดือนนี้</button>
    </div>

    @if($label)
        <span class="badge {{ $isUnrestricted ? 'text-bg-info text-dark' : ($isTodayActive ? 'text-bg-primary' : 'text-bg-light border text-secondary') }} py-1 px-2 d-inline-flex align-items-center"
              title="ช่วงวันที่กำลังแสดง">
            <i class="bi bi-clock-history me-1"></i> {{ $label }}
        </span>
    @endif

    @if(!empty($warning))
        <div class="alert alert-warning py-1 px-2 mb-0 small d-inline-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>
            <span>{{ $warning }}</span>
        </div>
    @endif
</div>

@if($wrapForm)
</form>
@endif

@once
<script>
function setListDateRange(btn, from, to) {
    const form = btn.closest('form');
    if (!form) return;
    const fromInput = form.querySelector('input[name="date_from"]');
    const toInput = form.querySelector('input[name="date_to"]');
    if (fromInput) fromInput.value = from;
    if (toInput) toInput.value = to;
    form.submit();
}
</script>
@endonce
