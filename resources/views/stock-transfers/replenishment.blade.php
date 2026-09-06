<?php /* resources/views/stock-transfers/replenishment.blade.php */ ?>
@extends('layout')

@section('title', 'แนะนำเติมสินค้าระหว่างสาขา - PopCentral')
@section('page-title', 'แนะนำเติมสินค้าระหว่างสาขา')
@section('page-subtitle', 'คำนวณจาก Min/Max ต่อสาขา + ยอดขายย้อนหลัง — เลือกรายการที่ต้องการแล้วสร้างเป็นใบขอโอน รอผู้มีสิทธิ์อนุมัติเหมือนใบขอโอนปกติ')

@section('content')
<div x-data="branchReplenishmentPage()" x-cloak>

    <div class="content-card p-4 mb-3">
        <form method="get" action="{{ route('stock-transfers.replenishment.index') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">คลังต้นทาง (ส่งจาก)</label>
                <select name="source_branch_id" class="form-select" required>
                    <option value="">-- เลือกสาขาต้นทาง --</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected((string) $sourceBranchId === (string) $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">สาขาปลายทาง (เติมให้)</label>
                <select name="destination_branch_id" class="form-select" required>
                    <option value="">-- เลือกสาขาปลายทาง --</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected((string) $destinationBranchId === (string) $b->id)>{{ $b->code }} - {{ $b->name_th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">ยอดขายย้อนหลัง (วัน)</label>
                <input type="number" name="sales_days" min="1" value="{{ $salesDays }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">วันสำรอง (safety days)</label>
                <input type="number" name="safety_days" min="0" value="{{ $safetyDays }}" class="form-control">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-calculator me-1"></i> คำนวณ</button>
            </div>
        </form>
    </div>

    @if($error)
        <div class="content-card p-4 mb-3 text-center text-danger">
            <i class="bi bi-exclamation-triangle-fill fs-3 d-block mb-2"></i>{{ $error }}
        </div>
    @elseif(! $sourceBranchId || ! $destinationBranchId)
        <div class="content-card p-4 text-center text-muted">
            เลือกสาขาต้นทางและปลายทางแล้วกด "คำนวณ" เพื่อดูคำแนะนำ
        </div>
    @elseif($suggestions->isEmpty())
        <div class="content-card p-4 text-center text-muted">
            <i class="bi bi-check-circle text-success fs-3 d-block mb-2"></i>
            ไม่มีสินค้าที่ต้องเติมตอนนี้ (สต๊อกปลายทางยังไม่ถึงจุดสั่งเติม หรือยังไม่ได้ตั้งเกณฑ์ Min/Max ของสินค้านั้น)
        </div>
    @else
        <div class="content-card p-4">
            <form method="post" action="{{ route('stock-transfers.replenishment.store') }}" @submit="onSubmit">
                @csrf
                <input type="hidden" name="source_branch_id" value="{{ $sourceBranchId }}">
                <input type="hidden" name="destination_branch_id" value="{{ $destinationBranchId }}">

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 fw-bold mb-0">พบ {{ $suggestions->count() }} รายการที่ควรเติม</h2>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> สร้างใบขอโอนจากที่เลือก</button>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" class="form-check-input" @click="toggleAll($event)"></th>
                                <th>สินค้า</th>
                                <th class="text-end">คงเหลือปลายทาง</th>
                                <th class="text-end">ขาย/วัน</th>
                                <th class="text-end">จุดสั่งเติม</th>
                                <th class="text-end">เต็ม (Max)</th>
                                <th class="text-end">มีที่ต้นทาง</th>
                                <th style="width:140px;" class="text-end">จำนวนที่เติม</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($suggestions as $i => $row)
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input row-check" checked
                                            @change="onRowToggle({{ $i }}, $event.target.checked)">
                                        <input type="hidden" :name="selected.includes({{ $i }}) ? `items[{{ $i }}][product_id]` : null" value="{{ $row['product_id'] }}">
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $row['sku_code'] }}</div>
                                        <div class="text-muted small">{{ $row['name_th'] }}</div>
                                        @if($row['urgency'] === 'critical')
                                            <span class="badge text-bg-danger">ของหมดที่สาขา</span>
                                        @endif
                                        @if(! $row['has_branch_policy'])
                                            <span class="badge text-bg-secondary" title="ยังไม่ได้ตั้งเกณฑ์เฉพาะสาขานี้ ใช้ค่ากลางของสินค้าแทน">ใช้ค่ากลาง</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ number_format($row['destination_available'], 2) }} {{ $row['unit'] }}</td>
                                    <td class="text-end">{{ number_format($row['daily_sales'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['reorder_point'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['maximum_stock'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['source_available'], 2) }}</td>
                                    <td>
                                        <input type="number" step="0.0001" min="0.0001"
                                            :name="selected.includes({{ $i }}) ? `items[{{ $i }}][qty]` : null"
                                            value="{{ $row['suggested_qty'] }}" class="form-control text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    @endif
</div>
@endsection

@push('head')
<style>[x-cloak]{display:none!important}</style>
@endpush

@push('scripts')
<script>
    function branchReplenishmentPage() {
        return {
            selected: [...Array({{ $suggestions->count() }}).keys()],
            toggleAll(event) {
                const checked = event.target.checked;
                this.$el.closest('.content-card').querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
                this.selected = checked ? [...Array({{ $suggestions->count() }}).keys()] : [];
            },
            onRowToggle(index, checked) {
                if (checked && ! this.selected.includes(index)) this.selected.push(index);
                if (! checked) this.selected = this.selected.filter(i => i !== index);
            },
            onSubmit(event) {
                if (this.selected.length === 0) {
                    event.preventDefault();
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกอย่างน้อย 1 รายการ' });
                }
            },
        };
    }
</script>
@endpush
