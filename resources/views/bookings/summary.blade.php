<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ใบรวมจอง {{ $date }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        body { font-family: Tahoma, sans-serif; font-size: 13px; color: #000; margin: 0; }
        .bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 12px; background: #eef2f7; }
        .bar select, .bar input, .bar button, .bar a { height: 36px; font-size: 14px; }
        .sheet { padding: 12mm; page-break-after: always; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #000; padding: 6px; }
        .num { text-align: right; }
        .tick { width: 56px; text-align: center; }
        .bill { margin-top: 14px; break-inside: avoid; }
        @media print { .bar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
<form method="get" class="bar">
    <input type="date" name="date" value="{{ $date }}">
    <select name="branch_id"><option value="">ทุกสาขา</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->code }} {{ $b->name_th }}</option>@endforeach</select>
    <select name="sales_area_id"><option value="">ทุกสาย</option>@foreach($salesAreas as $a)<option value="{{ $a->id }}" @selected($salesAreaId === $a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach</select>
    <button>แสดง</button>
    <button type="button" onclick="window.print()">พิมพ์</button>
    <a href="{{ route('bookings.index') }}">กลับ</a>
</form>
@forelse($groups as $group)
    <section class="sheet">
        <h1>ใบรวมจอง: เช็คขึ้นของทีละบิล</h1>
        <div>วันที่ {{ $date }} · {{ $group->branch?->name_th }} · สาย {{ $group->area?->name ?? 'ไม่ระบุ' }} · {{ $group->bookings->count() }} ใบ</div>
        @foreach($group->bookings as $booking)
            <div class="bill">
                <strong>{{ $booking->document->doc_number }}</strong> · {{ $booking->document->customer?->code }} {{ $booking->document->customer?->name_th }}
                <table>
                    <thead><tr><th>#</th><th>สินค้า</th><th>หน่วย</th><th class="num">จำนวน</th><th class="num">ราคา</th><th class="num">รวม</th><th class="tick">ขึ้นแล้ว</th></tr></thead>
                    <tbody>
                    @foreach($booking->document->stockDocument?->items ?? [] as $item)
                        <tr><td>{{ $loop->iteration }}</td><td>{{ $item->product?->name_th }}</td><td>{{ $item->product?->baseUnit?->name }}</td><td class="num">{{ number_format((float) $item->qty, 2) }}</td><td class="num">{{ number_format((float) $item->unit_price, 2) }}</td><td class="num">{{ number_format((float) $item->qty * (float) $item->unit_price, 2) }}</td><td class="tick">☐</td></tr>
                    @endforeach
                        <tr><td colspan="5" class="num"><strong>รวม</strong></td><td class="num"><strong>{{ number_format((float) $booking->document->total_amount, 2) }}</strong></td><td></td></tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </section>
    <section class="sheet">
        <h1>ใบเช็คส่งของประจำสาย: ส่งบัญชีตัดยอด</h1>
        <div>วันที่ {{ $date }} · {{ $group->branch?->name_th }} · สาย {{ $group->area?->name ?? 'ไม่ระบุ' }}</div>
        <table>
            <thead><tr><th>เลขที่ใบจอง</th><th>ลูกค้า</th><th class="num">ยอด</th><th class="tick">ส่งครบ</th><th class="tick">ไม่ส่ง</th><th>หมายเหตุ</th></tr></thead>
            <tbody>
            @foreach($group->bookings as $booking)
                <tr><td>{{ $booking->document->doc_number }}</td><td>{{ $booking->document->customer?->name_th }}</td><td class="num">{{ number_format((float) $booking->document->total_amount, 2) }}</td><td class="tick">☐</td><td class="tick">☐</td><td></td></tr>
            @endforeach
            </tbody>
        </table>
        <p style="margin-top:32px">ผู้ส่งของ ____________________ &nbsp; ผู้ตรวจรับ (บัญชี) ____________________</p>
    </section>
@empty
    <p style="padding:16px">ไม่มีใบจองค้างของวันที่เลือก</p>
@endforelse
</body>
</html>
