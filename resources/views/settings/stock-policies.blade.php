@extends('layout')

@section('title', 'นโยบายสต๊อกติดลบ - บริษัท เอ็มพีเอ็ม ฟู้ดส์ กรุ๊ป จำกัด')
@section('page-title', 'นโยบายสต๊อกติดลบ')
@section('page-subtitle', 'ตั้งค่าทุกสินค้า รายหมวด และรายสาขา')

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

    @unless($canManageNegativeStock)
        <div class="alert alert-warning">หน้านี้ดูได้ แต่การเปลี่ยนค่าทำได้เฉพาะผู้ดูแลระบบ IT</div>
    @endunless
    <form method="post" action="{{ route('settings.stock-policies.update') }}">
        @csrf
        <h5 class="mb-3">ค่ากลางสำหรับทุกสินค้า</h5>
        <div class="row mb-4">
            <div class="col-md-5">
                <select name="global_policy" class="form-select" @disabled(!$canManageNegativeStock)>
                    <option value="allow" @selected($globalPolicy === 'allow')>อนุญาตให้ขายติดลบ</option>
                    <option value="block" @selected($globalPolicy === 'block')>ห้ามขายติดลบ</option>
                </select>
            </div>
        </div>
        <h5 class="mb-3">ข้อยกเว้นรายประเภทสินค้า</h5>
        <div class="table-responsive mb-4">
            <table class="table align-middle">
                <thead><tr><th>รหัส</th><th>ประเภทสินค้า</th><th style="width:260px">นโยบาย</th></tr></thead>
                <tbody>
                @forelse($categories as $category)
                    <tr>
                        <td>{{ $category->code }}</td><td>{{ $category->name_th }}</td>
                        <td><select name="category_policies[{{ $category->id }}]" class="form-select form-select-sm" @disabled(!$canManageNegativeStock)>
                            <option value="inherit" @selected(!isset($categoryPolicies[$category->id]))>ใช้ค่ากลาง</option>
                            <option value="allow" @selected(($categoryPolicies[$category->id] ?? null) === 'allow')>อนุญาตให้ขายติดลบ</option>
                            <option value="block" @selected(($categoryPolicies[$category->id] ?? null) === 'block')>ห้ามขายติดลบ</option>
                        </select></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-muted">ยังไม่มีประเภทสินค้า</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
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
                            <select name="policies[{{ $branch->id }}]" class="form-select form-select-sm" @disabled(!$canManageNegativeStock)>
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
            <button type="submit" class="btn btn-primary px-4" @disabled(!$canManageNegativeStock)>
                <i class="bi bi-check2-circle me-1"></i> บันทึกนโยบาย
            </button>
        </div>
    </form>
</div>
@endsection
