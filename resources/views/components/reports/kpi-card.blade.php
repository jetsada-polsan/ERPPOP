@props(['label', 'value'])
<div style="font-size:14px"><span>{{ $label }}</span><strong class="d-block text-end">{{ $value === null ? 'ไม่ทราบ' : number_format((float) $value, 2) }}</strong></div>
