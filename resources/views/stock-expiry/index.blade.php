@extends('layout')

@section('title', 'สินค้าใกล้หมดอายุ - MPM Food Group')
@section('page-title', 'สินค้าใกล้หมดอายุ')
@section('page-subtitle', 'แดชบอร์ดเฝ้าระวังสินค้าใกล้หมดอายุและหมดอายุแล้วตามเกณฑ์ FEFO')

@section('content')
    {{-- KPI Cards แสดงสถานะ 4 ช่วงเวลา --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => 'expired'])) }}" class="text-decoration-none">
                <div class="content-card p-3 h-100 border-start border-4 border-danger shadow-sm {{ $window === 'expired' ? 'bg-danger-subtle' : '' }}">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-muted small fw-semibold">หมดอายุแล้ว</span>
                        <span class="badge text-bg-danger"><i class="bi bi-x-circle me-1"></i>วิกฤต</span>
                    </div>
                    <div class="fs-4 fw-bold text-danger">{{ number_format($summary['expired']['count']) }} <span class="fs-6 fw-normal text-muted">ล็อต</span></div>
                    <div class="text-muted small mt-1">
                        {{ number_format($summary['expired']['qty'], 2) }} หน่วย &middot; ฿{{ number_format($summary['expired']['cost'], 2) }}
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '3days'])) }}" class="text-decoration-none">
                <div class="content-card p-3 h-100 border-start border-4 border-danger shadow-sm {{ $window === '3days' ? 'bg-danger-subtle' : '' }}">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-muted small fw-semibold">หมดอายุใน 3 วัน</span>
                        <span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>ด่วนมาก</span>
                    </div>
                    <div class="fs-4 fw-bold text-danger">{{ number_format($summary['3days']['count']) }} <span class="fs-6 fw-normal text-muted">ล็อต</span></div>
                    <div class="text-muted small mt-1">
                        {{ number_format($summary['3days']['qty'], 2) }} หน่วย &middot; ฿{{ number_format($summary['3days']['cost'], 2) }}
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '7days'])) }}" class="text-decoration-none">
                <div class="content-card p-3 h-100 border-start border-4 border-warning shadow-sm {{ $window === '7days' ? 'bg-warning-subtle' : '' }}">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-muted small fw-semibold">หมดอายุใน 4-7 วัน</span>
                        <span class="badge text-bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>เตือนภัย</span>
                    </div>
                    <div class="fs-4 fw-bold text-warning-emphasis">{{ number_format($summary['7days']['count']) }} <span class="fs-6 fw-normal text-muted">ล็อต</span></div>
                    <div class="text-muted small mt-1">
                        {{ number_format($summary['7days']['qty'], 2) }} หน่วย &middot; ฿{{ number_format($summary['7days']['cost'], 2) }}
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '15days'])) }}" class="text-decoration-none">
                <div class="content-card p-3 h-100 border-start border-4 border-info shadow-sm {{ $window === '15days' ? 'bg-info-subtle' : '' }}">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-muted small fw-semibold">หมดอายุใน 8-15 วัน</span>
                        <span class="badge text-bg-info"><i class="bi bi-clock me-1"></i>เฝ้าระวัง</span>
                    </div>
                    <div class="fs-4 fw-bold text-dark">{{ number_format($summary['15days']['count']) }} <span class="fs-6 fw-normal text-muted">ล็อต</span></div>
                    <div class="text-muted small mt-1">
                        {{ number_format($summary['15days']['qty'], 2) }} หน่วย &middot; ฿{{ number_format($summary['15days']['cost'], 2) }}
                    </div>
                </div>
            </a>
        </div>
    </div>

    {{-- Filter bar & Tab navigation --}}
    <div class="content-card p-4 mb-4">
        <form method="get" action="{{ route('stock-expiry.index') }}" class="row g-3 align-items-end">
            <input type="hidden" name="window" value="{{ $window }}">

            <div class="col-md-3">
                <label class="form-label text-muted small fw-semibold">สาขา</label>
                <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">-- ทุกสาขา --</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label text-muted small fw-semibold">คลังสินค้า</label>
                <select name="warehouse_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">-- ทุกคลังสินค้า --</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($warehouseId == $w->id)>{{ $w->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label text-muted small fw-semibold">ค้นหา (สินค้า / SKU / Lot)</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="q" value="{{ $q }}" placeholder="พิมพ์ชื่อ, SKU หรือเลข Lot..." class="form-control">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>

            <div class="col-md-2 text-end">
                @if($branchId || $warehouseId || $q || $window !== 'all')
                    <a href="{{ route('stock-expiry.index') }}" class="btn btn-sm btn-light border text-muted">
                        <i class="bi bi-x-circle me-1"></i> ล้างตัวกรอง
                    </a>
                @endif
            </div>
        </form>

        <hr class="my-3 text-muted">

        {{-- ตัวเลือกช่วงวัน (Window Tabs) --}}
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="doc-tabs">
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => 'all'])) }}"
                   class="doc-tab text-decoration-none {{ $window === 'all' ? 'active' : '' }}">
                    ทั้งหมดที่ต้องเฝ้าระวัง
                    <span class="badge rounded-pill bg-danger ms-1">{{ number_format($summary['expired']['count'] + $summary['3days']['count'] + $summary['7days']['count'] + $summary['15days']['count']) }}</span>
                </a>
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => 'expired'])) }}"
                   class="doc-tab text-decoration-none {{ $window === 'expired' ? 'active' : '' }}">
                    หมดอายุแล้ว
                    @if($summary['expired']['count'] > 0)
                        <span class="badge rounded-pill bg-danger ms-1">{{ $summary['expired']['count'] }}</span>
                    @endif
                </a>
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '3days'])) }}"
                   class="doc-tab text-decoration-none {{ $window === '3days' ? 'active' : '' }}">
                    ภายใน 3 วัน
                    @if($summary['3days']['count'] > 0)
                        <span class="badge rounded-pill bg-danger ms-1">{{ $summary['3days']['count'] }}</span>
                    @endif
                </a>
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '7days'])) }}"
                   class="doc-tab text-decoration-none {{ $window === '7days' ? 'active' : '' }}">
                    4 - 7 วัน
                    @if($summary['7days']['count'] > 0)
                        <span class="badge rounded-pill bg-warning text-dark ms-1">{{ $summary['7days']['count'] }}</span>
                    @endif
                </a>
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => '15days'])) }}"
                   class="doc-tab text-decoration-none {{ $window === '15days' ? 'active' : '' }}">
                    8 - 15 วัน
                    @if($summary['15days']['count'] > 0)
                        <span class="badge rounded-pill bg-info ms-1">{{ $summary['15days']['count'] }}</span>
                    @endif
                </a>
                <a href="{{ route('stock-expiry.index', array_merge(request()->query(), ['window' => 'everything'])) }}"
                   class="doc-tab text-decoration-none {{ $window === 'everything' ? 'active' : '' }}">
                    ทุกล็อตในระบบ
                </a>
            </div>

            <div class="small text-muted">
                รวมมูลค่าเสี่ยงสูญเสีย: <strong class="text-danger fs-6">฿{{ number_format($summary['total_at_risk_cost'], 2) }}</strong>
            </div>
        </div>
    </div>

    {{-- ตารางแสดงรายการ Lot --}}
    <div class="content-card p-4">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">สถานะความเร่งด่วน</th>
                        <th>วันหมดอายุ</th>
                        <th>เลข Lot</th>
                        <th>สินค้า</th>
                        <th>คลัง / สาขา</th>
                        <th class="text-end">คงเหลือ</th>
                        <th class="text-end">ต้นทุน/หน่วย</th>
                        <th class="text-end">มูลค่ารวม</th>
                        <th class="text-end" style="width: 100px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lots as $lot)
                        @php
                            $days = (int) now()->startOfDay()->diffInDays($lot->expiry_date->startOfDay(), false);
                        @endphp
                        <tr class="{{ $days < 0 ? 'table-danger-subtle' : ($days <= 3 ? 'table-warning-subtle' : '') }}">
                            <td>
                                <x-lot-expiry-badge :date="$lot->expiry_date" />
                            </td>
                            <td>
                                <div class="fw-bold">{{ $lot->expiry_date->thaiDate() }}</div>
                                <div class="text-muted small">รับเข้า {{ $lot->received_date?->thaiDate() ?? '-' }}</div>
                            </td>
                            <td>
                                <a href="{{ route('products.lots.trace', [$lot->product, $lot]) }}" class="fw-semibold text-decoration-none text-primary">
                                    <i class="bi bi-box-seam me-1"></i>{{ $lot->lot_number }}
                                </a>
                            </td>
                            <td>
                                <div class="fw-bold text-dark">{{ $lot->product->name_th }}</div>
                                <div class="small text-muted font-monospace">{{ $lot->product->sku_code }}</div>
                            </td>
                            <td>
                                <div class="small fw-semibold">{{ $lot->warehouseLocation?->warehouse?->name ?? '-' }}</div>
                                <div class="small text-muted">{{ $lot->warehouseLocation?->warehouse?->branch?->name_th ?? '-' }} &middot; {{ $lot->warehouseLocation?->code ?? '-' }}</div>
                            </td>
                            <td class="text-end fw-bold">
                                {{ number_format($lot->remaining_qty, 2) }}
                                <span class="small text-muted fw-normal">{{ $lot->product->baseUnit?->cleanName() ?? 'หน่วย' }}</span>
                            </td>
                            <td class="text-end text-muted small">
                                ฿{{ number_format($lot->unit_cost, 2) }}
                            </td>
                            <td class="text-end fw-bold text-danger">
                                ฿{{ number_format($lot->remaining_qty * $lot->unit_cost, 2) }}
                            </td>
                            <td class="text-end">
                                <a href="{{ route('products.lots.trace', [$lot->product, $lot]) }}"
                                   class="btn btn-sm btn-outline-primary" title="ตรวจสอบเส้นทาง Lot">
                                    <i class="bi bi-diagram-3"></i> Trace
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-check2-circle text-success fs-1 d-block mb-2"></i>
                                ไม่พบสินค้าที่มีความเสี่ยงหมดอายุในช่วงเวลาที่เลือก
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $lots->links() }}
        </div>
    </div>
@endsection
