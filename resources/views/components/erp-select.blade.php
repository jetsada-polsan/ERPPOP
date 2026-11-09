@props([
    'label' => '',
    'name' => null,
    'id' => null,
    'required' => false,
    'searchable' => false,
    'hint' => null,
])

@php
    $fieldId = $id ?? ($name ? 'erp-sel-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name) : null);
@endphp

<div class="erp-float-group">
    @if($label !== '')
        <label @if($fieldId) for="{{ $fieldId }}" @endif class="form-label erp-float-label">
            <span class="erp-fl-text">{{ $label }}</span>
            <span class="erp-fl-meta">
                @if($required)
                    <span class="erp-fl-req" aria-hidden="true">*</span>
                @endif
            </span>
        </label>
    @endif

    <select
        @if($name) name="{{ $name }}" @endif
        @if($fieldId) id="{{ $fieldId }}" @endif
        @if($required) required @endif
        @if($searchable) data-erp-searchable="true" @endif
        {{ $attributes->merge(['class' => 'form-select erp-float-control']) }}
    >
        {{ $slot }}
    </select>

    @if($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
