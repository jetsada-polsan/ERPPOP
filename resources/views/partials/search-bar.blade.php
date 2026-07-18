{{--
  Usage: @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหา...'])
--}}
@php $placeholder = $placeholder ?? 'ค้นหา...'; @endphp
<form method="get" class="erp-search">
    <i class="bi bi-search erp-search-icon"></i>
    <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="{{ $placeholder }}" autocomplete="off">
    @if(!empty($q))
        <a href="{{ request()->url() }}" class="erp-search-clear" title="ล้างการค้นหา"><i class="bi bi-x-lg"></i></a>
    @endif
</form>
