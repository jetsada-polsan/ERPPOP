@props(['date', 'compact' => false])

@php
    $days = null;
    if ($date instanceof \Carbon\CarbonInterface) {
        $days = (int) now()->startOfDay()->diffInDays($date->startOfDay(), false);
    } elseif ($date) {
        try {
            $days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($date)->startOfDay(), false);
        } catch (\Throwable $e) {
            $days = null;
        }
    }
@endphp

@if($days !== null)
    @if($days < 0)
        <span class="badge text-bg-danger ms-1" title="หมดอายุแล้ว {{ abs($days) }} วัน">
            <i class="bi bi-x-circle me-1"></i>หมดอายุ{{ $compact ? '' : ' (' . abs($days) . ' วัน)' }}
        </span>
    @elseif($days === 0)
        <span class="badge text-bg-danger ms-1" title="หมดอายุวันนี้">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>หมดอายุวันนี้
        </span>
    @elseif($days <= 3)
        <span class="badge text-bg-danger ms-1" title="หมดอายุใน {{ $days }} วัน">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>เหลือ {{ $days }} วัน
        </span>
    @elseif($days <= 7)
        <span class="badge text-bg-warning text-dark ms-1" title="หมดอายุใน {{ $days }} วัน">
            <i class="bi bi-exclamation-triangle me-1"></i>เหลือ {{ $days }} วัน
        </span>
    @elseif($days <= 15)
        <span class="badge text-bg-warning-subtle text-warning-emphasis border ms-1" title="หมดอายุใน {{ $days }} วัน">
            <i class="bi bi-clock me-1"></i>เหลือ {{ $days }} วัน
        </span>
    @endif
@endif
