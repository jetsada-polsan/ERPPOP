<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Z Report {{ $shift->shift_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef3f6; color: #152a38; font-family: Tahoma, Arial, sans-serif; }
        .toolbar { display: flex; justify-content: center; gap: 10px; padding: 18px; }
        button { border: 0; border-radius: 6px; padding: 11px 18px; background: #0788b5; color: #fff; font-weight: 700; cursor: pointer; }
        .report { width: 80mm; min-height: 150mm; margin: 0 auto 30px; padding: 8mm 6mm; background: #fff; box-shadow: 0 8px 28px rgba(30, 55, 70, .15); }
        h1 { margin: 0; text-align: center; font-size: 18px; }
        .subtitle { margin: 4px 0 14px; text-align: center; font-size: 11px; color: #536775; }
        .line { display: flex; justify-content: space-between; gap: 10px; padding: 4px 0; font-size: 11px; }
        .section { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #8aa0ad; }
        .section-title { margin-bottom: 4px; font-size: 11px; font-weight: 800; }
        .total { margin-top: 6px; padding: 8px 0; border-top: 2px solid #152a38; border-bottom: 2px solid #152a38; font-size: 13px; font-weight: 800; }
        .difference { color: {{ (float) $shift->cash_difference === 0.0 ? '#15803d' : '#b91c1c' }}; }
        .movement { padding: 5px 0; border-bottom: 1px dotted #cbd5dc; font-size: 10px; }
        .movement small { display: block; margin-top: 2px; color: #667985; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 28px; text-align: center; font-size: 10px; }
        .signature { padding-top: 22px; border-top: 1px solid #536775; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .report { margin: 0; box-shadow: none; }
            @page { size: 80mm auto; margin: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">พิมพ์ Z Report</button>
    <button type="button" onclick="window.close()">ปิด</button>
</div>
<main class="report">
    <h1>POPSTAR SHOP</h1>
    <div class="subtitle">รายงานปิดกะ (Z Report)</div>

    <div class="line"><span>เลขกะ</span><strong>{{ $shift->shift_no }}</strong></div>
    <div class="line"><span>สาขา</span><strong>{{ $shift->branch?->code }} {{ $shift->branch?->name_th }}</strong></div>
    <div class="line"><span>เครื่อง</span><strong>{{ $shift->terminal?->code ?? '-' }}</strong></div>
    <div class="line"><span>แคชเชียร์</span><strong>{{ $shift->cashier?->code }} {{ $shift->cashier?->name }}</strong></div>
    <div class="line"><span>เปิดกะ</span><strong>{{ $shift->opened_at?->format('d/m/Y H:i:s') }}</strong></div>
    <div class="line"><span>ปิดกะ</span><strong>{{ $shift->closed_at?->format('d/m/Y H:i:s') ?? 'ยังไม่ปิด' }}</strong></div>

    <section class="section">
        <div class="section-title">ยอดขายตามช่องทาง</div>
        <div class="line"><span>จำนวนบิลสุทธิ</span><strong>{{ number_format($totals['receipt_count']) }}</strong></div>
        <div class="line"><span>เงินสดสุทธิ</span><strong>{{ number_format($totals['cash'], 2) }}</strong></div>
        <div class="line"><span>QR / โอน</span><strong>{{ number_format($totals['transfer'], 2) }}</strong></div>
        <div class="line"><span>บัตรเครดิต</span><strong>{{ number_format($totals['credit_card'], 2) }}</strong></div>
        <div class="line"><span>เช็ค</span><strong>{{ number_format($totals['cheque'], 2) }}</strong></div>
    </section>

    <section class="section">
        <div class="section-title">กระทบยอดเงินสด</div>
        <div class="line"><span>เงินทอนตั้งต้น</span><strong>{{ number_format($shift->opening_cash, 2) }}</strong></div>
        <div class="line"><span>เงินสดขายสุทธิ</span><strong>{{ number_format($totals['cash'], 2) }}</strong></div>
        <div class="line"><span>เงินเพิ่มเข้าลิ้นชัก</span><strong>{{ number_format($cashMovements['cash_in'], 2) }}</strong></div>
        <div class="line"><span>นำส่งเงิน</span><strong>-{{ number_format($cashMovements['drop'], 2) }}</strong></div>
        <div class="line"><span>เบิกจ่ายจากลิ้นชัก</span><strong>-{{ number_format($cashMovements['payout'], 2) }}</strong></div>
        <div class="line total"><span>เงินสดที่ควรมี</span><strong>{{ number_format($shift->expected_cash, 2) }}</strong></div>
        <div class="line"><span>เงินสดนับจริง</span><strong>{{ number_format($shift->counted_cash, 2) }}</strong></div>
        <div class="line difference"><span>เงินขาด / เกิน</span><strong>{{ number_format($shift->cash_difference, 2) }}</strong></div>
    </section>

    @if($shift->cashMovements->isNotEmpty())
        <section class="section">
            <div class="section-title">รายละเอียดเงินเข้าออก</div>
            @foreach($shift->cashMovements as $movement)
                <div class="movement">
                    <strong>{{ ['cash_in' => 'เงินเพิ่ม', 'drop' => 'นำส่งเงิน', 'payout' => 'เบิกจ่าย'][$movement->movement_type] ?? $movement->movement_type }}
                        {{ number_format($movement->amount, 2) }}</strong>
                    <small>{{ $movement->created_at?->format('H:i:s') }} · {{ $movement->reason }}{{ $movement->reference_no ? ' · '.$movement->reference_no : '' }}</small>
                </div>
            @endforeach
        </section>
    @endif

    @if($shift->closing_note)
        <section class="section">
            <div class="section-title">หมายเหตุ</div>
            <div style="font-size:10px">{{ $shift->closing_note }}</div>
        </section>
    @endif

    <div class="signatures">
        <div class="signature">แคชเชียร์</div>
        <div class="signature">ผู้ตรวจสอบ</div>
    </div>
</main>
</body>
</html>
