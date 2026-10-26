@extends('layout')

@section('title', 'นโยบายสต๊อกติดลบ - POPSTAR ERP')
@section('page-title', 'นโยบายสต๊อกติดลบรายสาขา')
@section('page-subtitle', 'กำหนดว่าสาขาไหน "ห้ามขายติดลบ" และสาขาไหน "ยอมให้ติดลบ"')

@section('content')
<div class="content-card p-4">
    <div class="alert alert-light border d-flex gap-3 align-items-start">
        <i class="bi bi-info-circle-fill fs-5" style="color:var(--fa-blue)"></i>
        <div class="small">
            <div class="fw-semibold mb-1">ห้ามขายติดลบ = ตัดสต๊อกเกินยอดคงเหลือไม่ได้เลย</div>
            ใช้กับสาขาที่ลงยอดยกมาครบแล้ว เพื่อไม่ให้มูลค่าสินค้าคงเหลือใน GL 1140-00 ติดลบอีก<br>
            สาขาที่ยังลงยอดยกมาไม่ครบให้คง "ยอมให้ติดลบ" ไว้ก่อน แล้วใช้
            <code>php artisan erp:stock-negative-report --fix-opening</code> ตั้งยอดยกมาก่อนค่อยเปลี่ยนเป็นห้าม
        </div>
    </div>

    <form method="post" action="{{ route('settings.stock-policies.update') }}">
        @csrf
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>สาขา</th>
                        <th>คลังเริ่มต้น</th>
                        <th class="text-end">รายการที่ติดลบอยู่</th>
                        <th style="width:260px">นโยบาย</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($branches as $branch)
                        @php($negative = $negativeCounts[$branch->id] ?? 0)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $branch->code }}</span> - {{ $branch->name_th }}
                                @unless($branch->is_active)
                                    <span class="badge text-bg-secondary ms-1">ปิดใช้งาน</span>
                                @endunless
                            </td>
                            <td class="small text-muted">
                                {{ $branch->defaultWarehouseLocation?->name ?? '— ยังไม่ได้ตั้ง —' }}
                            </td>
                            <td class="text-end">
                                @if($negative > 0)
                                    <span class="badge text-bg-danger">{{ number_format($negative) }} รายการ</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <select name="policies[{{ $branch->id }}]" class="form-select form-select-sm">
                                    <option value="allow" @selected($branch->negative_stock_policy !== 'block')>ยอมให้ติดลบ (เตือนอย่างเดียว)</option>
                                    <option value="block" @selected($branch->negative_stock_policy === 'block')>ห้ามขายติดลบ</option>
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-5 text-center text-muted">ยังไม่มีสาขา</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check2-circle me-1"></i> บันทึกนโยบาย
            </button>
        </div>
    </form>
</div>
@endsection
