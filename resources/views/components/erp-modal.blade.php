@props([
    'title' => '',
    'showModeToggle' => true,
    'openVar' => 'modalOpen',
    'width' => '720px',
])

<div class="booking-modal-backdrop" x-show="{{ $openVar }}" x-transition.opacity @keydown.escape.window="{{ $openVar }} = false" x-cloak>
    <div class="booking-modal erp-peak-modal" style="width: min({{ $width }}, 100%);" @click.outside="{{ $openVar }} = false" x-data="{ formMode: 'basic' }" :data-erp-form-mode="formMode" x-transition>
        <div class="modal-header border-0 px-4 pt-4 pb-2">
            <div>
                <h3 class="h4 fw-bold mb-1 modal-title">{{ $title }}</h3>
                @if($showModeToggle)
                    <div class="erp-modal-mode-toggle" role="radiogroup" aria-label="รูปแบบฟอร์ม">
                        <label class="erp-mode-option" :class="formMode === 'basic' && 'is-active'">
                            <input type="radio" class="form-check-input" value="basic" x-model="formMode">
                            <span>พื้นฐาน</span>
                        </label>
                        <label class="erp-mode-option" :class="formMode === 'advanced' && 'is-active'">
                            <input type="radio" class="form-check-input" value="advanced" x-model="formMode">
                            <span>ขั้นสูง</span>
                        </label>
                    </div>
                @endif
            </div>
            @if(isset($headerRight))
                <div class="ms-auto me-2">{{ $headerRight }}</div>
            @endif
            <button type="button" class="btn btn-light rounded-circle" @click="{{ $openVar }} = false" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
