@props([
    'product' => null,
    'qty' => null,
    'unitId' => null,
    'item' => null,
])
@php
    $prod = $product ?? $item?->product;
    $amount = $qty !== null ? $qty : ($item?->qty ?? 0);
    $selectedUnitId = $unitId ?? $item?->unit_id;
@endphp
{{ \App\Support\UnitQty::format($prod, $amount, $selectedUnitId) }}