@extends('layout')
@section('title', 'แต้มสมาชิก - POPSTAR ERP')
@section('page-title', 'แต้มสมาชิก')
@section('page-subtitle', 'ตั้งกติกาแต้มทอง แคมเปญแต้มทวีคูณ และดูประวัติแต้ม')
@section('content')

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="content-card p-4 h-100">
            <h3 class="h6 fw-bold mb-2"><i class="bi bi-stars text-warning me-1"></i>กติกาที่ใช้อยู่ตอนนี้</h3>
            @if($activeEarnRule)
                <div class="small">
                    ซื้อครบ <strong>{{ number_format($activeEarnRule->baht_per_point, 2) }}</strong> บาท ได้ <strong>1 แต้ม</strong>
                    @if($currentMultiplier > 1)
                        <span class="badge text-bg-danger ms-1">ทวีคูณ ×{{ rtrim(rtrim(number_format($currentMultiplier, 2), '0'), '.') }}</span>
                    @endif
                    <div class="text-muted mt-1">
                        แลกแต้ม: 1 แต้ม = {{ number_format((float) $activeEarnRule->point_value_baht, 2) }} บาท
                        @if((float) $activeEarnRule->point_value_baht <= 0) (ปิดการแลกแต้ม) @endif
                    </div>
                </div>
            @else
                <p class="text-muted small mb-0">ยังไม่มีกติกาสะสมแต้มที่ใช้งาน — สร้างกติกาประเภท "สะสมแต้ม" ด้านล่างก่อน POS จึงจะเริ่มให้แต้ม</p>
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="content-card p-4 h-100" x-data="{ type: 'earn' }">
            <h3 class="h6 fw-bold mb-3">เพิ่มกติกาแต้ม</h3>
            <form method="post" action="{{ route('member-points.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3"><label class="form-label small text-muted">รหัส</label><input name="code" required class="form-control form-control-sm"></div>
                <div class="col-md-5"><label class="form-label small text-muted">ชื่อ</label><input name="name" required class="form-control form-control-sm" placeholder="เช่น แต้มทองปกติ / ทวีคูณสงกรานต์"></div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">ประเภท</label>
                    <select name="rule_type" x-model="type" class="form-select form-select-sm">
                        <option value="earn">สะสมแต้ม (แต้มทอง)</option>
                        <option value="multiplier">แต้มทวีคูณ (แคมเปญ)</option>
                    </select>
                </div>
                <div class="col-md-4" x-show="type === 'earn'"><label class="form-label small text-muted">กี่บาทได้ 1 แต้ม</label><input type="number" step="0.01" min="0.01" name="baht_per_point" class="form-control form-control-sm" placeholder="เช่น 25"></div>
                <div class="col-md-4" x-show="type === 'earn'"><label class="form-label small text-muted">1 แต้มแลกได้ (บาท)</label><input type="number" step="0.01" min="0" name="point_value_baht" class="form-control form-control-sm" placeholder="0 = ปิดแลก"></div>
                <div class="col-md-4" x-show="type === 'multiplier'" x-cloak><label class="form-label small text-muted">ตัวคูณ</label><input type="number" step="0.5" min="1" name="multiplier" class="form-control form-control-sm" placeholder="เช่น 2"></div>
                <div class="col-md-4"><label class="form-label small text-muted">วันที่เริ่ม</label><input type="date" name="starts_date" class="form-control form-control-sm"></div>
                <div class="col-md-4"><label class="form-label small text-muted">วันที่สิ้นสุด</label><input type="date" name="ends_date" class="form-control form-control-sm"></div>
                <div class="col-md-2 d-flex align-items-end"><div class="form-check mb-1"><input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="ruleActive"><label class="form-check-label small" for="ruleActive">ใช้</label></div></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-sm w-100">เพิ่ม</button></div>
            </form>
        </div>
    </div>
</div>

<div class="content-card p-4 mb-3">
    <h2 class="h5 fw-bold mb-3">กติกาแต้มทั้งหมด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>รหัส</th><th>ชื่อ</th><th>ประเภท</th><th>เงื่อนไข</th><th>ช่วงเวลา</th><th>สถานะ</th></tr></thead>
            <tbody>
            @forelse($rules as $rule)
                <tr>
                    <td class="fw-semibold">{{ $rule->code }}</td>
                    <td>{{ $rule->name }}</td>
                    <td>{{ $rule->rule_type === 'earn' ? 'สะสมแต้ม' : 'แต้มทวีคูณ' }}</td>
                    <td class="small">
                        @if($rule->rule_type === 'earn')
                            {{ number_format((float) $rule->baht_per_point, 2) }} บาท = 1 แต้ม, แลกคืน {{ number_format((float) $rule->point_value_baht, 2) }} บาท/แต้ม
                        @else
                            ×{{ rtrim(rtrim(number_format((float) $rule->multiplier, 2), '0'), '.') }}
                        @endif
                    </td>
                    <td class="small">{{ $rule->starts_date?->thaiDate() ?? 'ไม่กำหนด' }} - {{ $rule->ends_date?->thaiDate() ?? 'ไม่กำหนด' }}</td>
                    <td><span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $rule->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีกติกาแต้ม</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rules->links() }}
</div>

<div class="content-card p-4">
    <h2 class="h5 fw-bold mb-3">ประวัติแต้มล่าสุด</h2>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>วันที่</th><th>สมาชิก</th><th>รายการ</th><th class="text-end">แต้ม</th><th class="text-end">คงเหลือ</th><th>อ้างอิงบิล</th></tr></thead>
            <tbody>
            @forelse($transactions as $txn)
                <tr>
                    <td class="small text-muted">{{ $txn->created_at->thaiDate(true) }}</td>
                    <td>{{ $txn->member?->member_code }} - {{ $txn->member?->name }}</td>
                    <td>
                        <span class="badge {{ $txn->direction === 'earn' ? 'text-bg-success' : ($txn->direction === 'redeem' ? 'text-bg-warning' : 'text-bg-secondary') }}">
                            {{ $txn->direction === 'earn' ? 'สะสม' : ($txn->direction === 'redeem' ? 'แลกแต้ม' : 'ปรับปรุง') }}
                        </span>
                    </td>
                    <td class="text-end fw-bold {{ $txn->direction === 'redeem' ? 'text-danger' : 'text-success' }}">
                        {{ $txn->direction === 'redeem' ? '-' : '+' }}{{ number_format((float) $txn->points, 2) }}
                    </td>
                    <td class="text-end">{{ number_format((float) $txn->balance_after, 2) }}</td>
                    <td class="small text-muted">{{ $txn->document?->doc_number ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีประวัติแต้ม</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('head')<style>[x-cloak]{display:none!important}</style>@endpush
