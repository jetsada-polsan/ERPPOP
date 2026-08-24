@extends('erp-mockup.layout')
@section('title', 'หมูสามชั้นสไลด์')
@section('content')
<div class="pop-head">
    <div>
        <p class="pop-muted" style="margin:0 0 4px"><a href="{{ route('erp-mockup.products') }}">สินค้า</a> / PORK-BELLY</p>
        <h1>หมูสามชั้นสไลด์</h1>
    </div>
    <div class="pop-head-actions">
        <button class="pop-btn"><i class="bi bi-x-lg"></i> ยกเลิก</button>
        <button class="pop-btn primary"><i class="bi bi-check-lg"></i> บันทึก</button>
    </div>
</div>

<section class="pop-card" style="margin-bottom:14px">
    <div style="display:flex;gap:2px;padding:0 12px;border-bottom:1px solid var(--pop-line);overflow-x:auto">
        @foreach(['ทั่วไป', 'บาร์โค้ด', 'ราคา', 'คลังสินค้า', 'จัดซื้อ', 'POS', 'ประวัติการแก้ไข'] as $index => $tab)
            <button class="pop-btn" style="border:0;border-bottom:3px solid {{ $index === 0 ? 'var(--pop-primary)' : 'transparent' }};border-radius:0;{{ $index === 0 ? 'color:var(--pop-primary-dark);font-weight:700' : 'color:var(--pop-muted)' }}">{{ $tab }}</button>
        @endforeach
    </div>
    <div class="pop-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:16px 24px">
        @foreach([
            ['ชื่อสินค้า', 'หมูสามชั้นสไลด์'], ['รหัสภายใน', 'PORK-BELLY'],
            ['หมวดสินค้า', 'เนื้อสัตว์'], ['หน่วยนับ', 'kg'],
            ['ราคาขาย', '189.00'], ['ต้นทุน', '142.00'],
        ] as [$label, $value])
            <label style="display:grid;grid-template-columns:120px 1fr;gap:10px;align-items:center">
                <span class="pop-muted">{{ $label }}</span>
                <input value="{{ $value }}" style="border:1px solid var(--pop-line);border-radius:7px;padding:8px 11px;font:inherit">
            </label>
        @endforeach
        <div style="grid-column:1/-1;display:flex;gap:22px;flex-wrap:wrap">
            @foreach(['ตัดสต๊อก' => true, 'ขายที่ POS' => true, 'บาร์โค้ดชั่งน้ำหนัก' => false, 'มีรหัสเครื่องชั่ง' => true] as $label => $checked)
                <label style="display:flex;gap:7px;align-items:center"><input type="checkbox" @checked($checked)> {{ $label }}</label>
            @endforeach
        </div>
    </div>
</section>

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:14px">
    <section class="pop-card">
        <div class="pop-card-head">บาร์โค้ดของสินค้านี้<span class="spacer"><button class="pop-btn"><i class="bi bi-plus-lg"></i> เพิ่มบรรทัด</button></span></div>
        <table class="pop-table">
            <thead><tr><th>ประเภท</th><th>บาร์โค้ด</th><th>หน่วย</th><th>หมายเหตุ</th></tr></thead>
            <tbody>
            @foreach([
                ['NORMAL', '8850000012345', 'kg', 'บาร์โค้ดจากผู้ผลิต'],
                ['SCALE_PLU', '801037', 'kg', 'รหัสเครื่องชั่ง 6 หลัก'],
                ['SCALE_WEIGHT', '8010370226801', 'kg', 'ฉลากจากเครื่องชั่ง'],
            ] as [$type, $code, $unit, $note])
                <tr>
                    <td><span class="pop-badge badge-grey">{{ $type }}</span></td>
                    <td style="font-variant-numeric:tabular-nums">{{ $code }}</td>
                    <td>{{ $unit }}</td>
                    <td class="pop-muted">{{ $note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="pop-card">
        <div class="pop-card-head">ประวัติการแก้ไข</div>
        <div class="pop-card-body">
            @foreach($activities as $activity)
                <div style="display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f2f4f7">
                    <div class="pop-avatar" style="background:var(--pop-primary-soft);color:var(--pop-primary)">{{ mb_substr($activity['who'], 0, 2) }}</div>
                    <div><div><strong>{{ $activity['who'] }}</strong> {{ $activity['what'] }}</div>
                    <div class="pop-muted">{{ $activity['detail'] }} · {{ $activity['time'] }}</div></div>
                </div>
            @endforeach
        </div>
    </section>
</div>
@endsection
