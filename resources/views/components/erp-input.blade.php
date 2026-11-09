@props([
    'label' => '',
    'name' => null,
    'id' => null,
    'type' => 'text',
    'value' => '',
    'required' => false,
    'maxlength' => null,
    'placeholder' => '',
    'hint' => null,
])

@php
    $fieldId = $id ?? ($name ? 'erp-fld-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $name) : null);
    $initLen = mb_strlen((string) $value);
@endphp

<div class="erp-float-group" @if($maxlength) x-data="{ charCount: {{ $initLen }}, maxLen: {{ (int) $maxlength }} }" @endif>
    @if($label !== '')
        <label @if($fieldId) for="{{ $fieldId }}" @endif class="form-label erp-float-label">
            <span class="erp-fl-text">{{ $label }}</span>
            <span class="erp-fl-meta">
                @if($maxlength)
                    <span class="erp-fl-counter" x-text="Math.max(0, maxLen - charCount)">{{ max(0, (int) $maxlength - $initLen) }}</span>
                @endif
                @if($required)
                    <span class="erp-fl-req" aria-hidden="true">*</span>
                @endif
            </span>
        </label>
    @endif

    @if($type === 'textarea')
        <textarea
            @if($name) name="{{ $name }}" @endif
            @if($fieldId) id="{{ $fieldId }}" @endif
            @if($required) required @endif
            @if($maxlength) maxlength="{{ $maxlength }}" @input="charCount = $el.value.length" @endif
            placeholder="{{ $placeholder }}"
            {{ $attributes->merge(['class' => 'form-control erp-float-control']) }}
        >{{ $value ?: $slot }}</textarea>
    @else
        <input
            type="{{ $type }}"
            @if($name) name="{{ $name }}" @endif
            @if($fieldId) id="{{ $fieldId }}" @endif
            value="{{ $value }}"
            @if($required) required @endif
            @if($maxlength) maxlength="{{ $maxlength }}" @input="charCount = $el.value.length" @endif
            placeholder="{{ $placeholder }}"
            {{ $attributes->merge(['class' => 'form-control erp-float-control']) }}
        >
    @endif

    @if($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
