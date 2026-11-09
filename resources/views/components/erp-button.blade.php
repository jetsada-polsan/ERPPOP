@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'size' => 'md',
])

@php
    $variantClass = match ($variant) {
        'primary' => 'btn-primary',
        'secondary', 'outline' => 'btn-outline-primary',
        'cancel', 'gray', 'light' => 'btn-light border erp-btn-cancel',
        'success' => 'btn-success',
        'danger' => 'btn-danger',
        default => 'btn-primary',
    };
    $sizeClass = match ($size) {
        'sm' => 'btn-sm',
        'lg' => 'btn-lg',
        default => '',
    };
    $classes = trim("btn {$variantClass} {$sizeClass} rounded-pill erp-capsule-btn");
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if($icon)<i class="bi {{ $icon }}"></i>@endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if($icon)<i class="bi {{ $icon }}"></i>@endif
        <span>{{ $slot }}</span>
    </button>
@endif
