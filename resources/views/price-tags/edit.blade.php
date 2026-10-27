@extends('layout')
@section('title', 'แก้ไขป้ายราคา - PopCentral')
@section('page-title', 'แก้ไขป้ายราคา')
@section('content')
<div class="content-card p-4">
    <a href="{{ route('price-tags.index') }}" class="btn btn-light border mb-3">กลับรายการป้ายราคา</a>
    <h2 class="h5">{{ $priceTag->code }} — {{ $priceTag->name }}</h2>
    @include('price-tags.form')
</div>
@endsection
