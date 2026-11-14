<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Z Report {{ $shift->shift_no }} — MPM Food Group</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&family=TH+Sarabun+New&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef3f6;
            color: #0f172a;
            font-family: 'Sarabun', 'TH Sarabun New', Tahoma, sans-serif;
            font-size: 11.5px;
            line-height: 1.35;
        }
        .toolbar {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 16px;
            background: #fff;
            border-bottom: 1px solid #dbe5ec;
        }
        button {
            border: 0;
            border-radius: 6px;
            padding: 10px 18px;
            background: #0284c7;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(2,132,199,0.25);
        }
        button.secondary {
            background: #64748b;
        }
        .report {
            width: 80mm;
            min-height: 150mm;
            margin: 20px auto 30px;
            padding: 8mm 6mm;
            background: #fff;
            box-shadow: 0 8px 28px rgba(30, 55, 70, .15);
        }
        .logo-wrap {
            text-align: center;
            margin-bottom: 6px;
        }
        .logo-wrap img {
            max-height: 48px;
            max-width: 150px;
            object-fit: contain;
            display: inline-block;
        }
        h1 {
            margin: 0;
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }
        .subtitle {
            margin: 2px 0 10px;
            text-align: center;
            font-size: 11px;
            color: #475569;
        }
        .copy-badge {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }
        .line {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
            padding: 2.5px 0;
            font-size: 11px;
        }
        .line span {
            color: #334155;
        }
        .line strong {
            text-align: right;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
            font-weight: 700;
        }
        .section {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #94a3b8;
        }
        .section-title {
            margin-bottom: 5px;
            font-size: 11px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            justify-content: space-between;
        }
        .total {
            margin-top: 5px;
            padding: 6px 0;
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
            font-size: 12px;
            font-weight: 800;
        }
        .total strong {
            font-size: 12.5px;
        }
        .difference {
            font-size: 12px;
            font-weight: 800;
            color: {{ (float) $shift->cash_difference === 0.0 ? '#15803d' : '#b91c1c' }};
        }
        .difference strong {
            color: {{ (float) $shift->cash_difference === 0.0 ? '#15803d' : '#b91c1c' }};
        }
        .movement {
            padding: 4px 0;
            border-bottom: 1px dotted #cbd5dc;
            font-size: 10px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .movement .desc {
            color: #334155;
        }
        .movement .amt {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
        }
        .movement small {
            display: block;
            margin-top: 1px;
            color: #64748b;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 24px;
            text-align: center;
            font-size: 10px;
        }
        .signature {
            padding-top: 22px;
            border-top: 1px solid #475569;
            color: #334155;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .report {
                margin: 0;
                box-shadow: none;
                break-after: page;
                page-break-after: always;
                padding: 6mm 4mm;
            }
            .report:last-child {
                break-after: auto;
                page-break-after: auto;
            }
            @page { size: 80mm auto; margin: 0; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">พิมพ์ Z Report</button>
    <button type="button" class="secondary" onclick="window.close()">ปิดหน้าต่าง</button>
</div>

@foreach(['ใบนำส่ง', 'ใบเก็บ'] as $copy)
<main class="report">
    <div class="copy-badge">[{{ $copy }}]</div>
    <div class="logo-wrap">
        <img src="{{ asset('images/logos/mpm-food-group.jpg') }}" alt="MPM Food Group">
    </div>
    <h1>MPM Food Group</h1>
    <div class="subtitle">รายงานปิดกะ (Z-Report / Shift Close)</div>

    <div class="line"><span>เลขกะ</span><strong>{{ $shift->shift_no }}</strong></div>
    <div class="line"><span>สาขา</span><strong>{{ $shift->branch?->code }} {{ $shift->branch?->name_th }}</strong></div>
    <div class="line"><span>จุดขาย / เครื่อง</span><strong>{{ $shift->terminal?->code ?? '-' }}</strong></div>
    <div class="line"><span>แคชเชียร์</span><strong>{{ $shift->cashierUser?->name ?? $shift->cashier?->name ?? '-' }} @if($shift->cashier?->code)<small>({{ $shift->cashier->code }})</small>@endif</strong></div>
    <div class="line"><span>เวลาเปิดกะ</span><strong>{{ $shift->opened_at?->format('d/m/Y H:i:s') }}</strong></div>
    <div class="line"><span>เวลาปิดกะ</span><strong>{{ $shift->closed_at?->format('d/m/Y H:i:s') ?? 'ยังไม่ปิด' }}</strong></div>

    <section class="section">
        <div class="section-title">สรุปยอดขายตามช่องทางชำระเงิน</div>
        <div class="line"><span>จำนวนบิลสำเร็จ</span><strong>{{ number_format($totals['receipt_count']) }} บิล</strong></div>
        <div class="line"><span>จำนวนยกเลิก</span><strong>{{ $shift->receipts()->where('status', 'void')->count() }} บิล</strong></div>
        <div class="line"><span>จำนวนรับคืน</span><strong>{{ DB::table('pos_receipt_returns')->where('pos_shift_id', $shift->id)->where('status', 'completed')->count() }} บิล</strong></div>
        <div class="line"><span>ส่วนลดรวม</span><strong>-{{ number_format($shift->receipts()->where('status', 'completed')->sum('discount_amount'), 2) }}</strong></div>
        <div class="line"><span>เงินสด (Cash)</span><strong>{{ number_format($totals['cash'], 2) }}</strong></div>
        <div class="line"><span>QR พร้อมเพย์ / เงินโอน</span><strong>{{ number_format($totals['transfer'], 2) }}</strong></div>
        <div class="line"><span>บัตรเครดิต (Credit Card)</span><strong>{{ number_format($totals['credit_card'], 2) }}</strong></div>
        <div class="line"><span>เช็ค / ช่องทางอื่น</span><strong>{{ number_format($totals['cheque'], 2) }}</strong></div>
        <div class="line total"><span>ยอดขายสุทธิรวมทุกช่องทาง</span><strong>{{ number_format($totals['cash'] + $totals['transfer'] + $totals['credit_card'] + $totals['cheque'], 2) }}</strong></div>
    </section>

    <section class="section">
        <div class="section-title">กระทบยอดเงินสดในลิ้นชัก (Cash Reconciliation)</div>
        <div class="line"><span>เงินทอนตั้งต้น (Opening Float)</span><strong>{{ number_format($shift->opening_cash, 2) }}</strong></div>
        <div class="line"><span>ยอดขายเงินสดสุทธิ (+)</span><strong>{{ number_format($totals['cash'], 2) }}</strong></div>
        <div class="line"><span>เงินเพิ่มเข้าลิ้นชัก (+)</span><strong>{{ number_format($cashMovements['cash_in'], 2) }}</strong></div>
        <div class="line"><span>นำส่งเงินระหว่างกะ (-)</span><strong>-{{ number_format($cashMovements['drop'], 2) }}</strong></div>
        <div class="line"><span>เบิกจ่ายจากลิ้นชัก (-)</span><strong>-{{ number_format($cashMovements['payout'], 2) }}</strong></div>
        <div class="line total"><span>เงินสดที่ควรมีในลิ้นชัก (=)</span><strong>{{ number_format($shift->expected_cash, 2) }}</strong></div>
        <div class="line"><span>เงินสดนับจริง (Actual Cash)</span><strong>{{ number_format($shift->counted_cash, 2) }}</strong></div>
        <div class="line difference"><span>ผลต่าง เงินขาด / เกิน</span><strong>{{ number_format($shift->cash_difference, 2) }}</strong></div>
    </section>

    <section class="section">
        <div class="section-title">การส่งมอบเงินและซองนำส่ง</div>
        <div class="line"><span>เงินทอนยกมา</span><strong>{{ $shift->carried_float === null ? '-' : number_format($shift->carried_float, 2) }}</strong></div>
        <div class="line"><span>ส่วนต่างตอนรับกะ</span><strong>{{ $shift->opening_variance === null ? '-' : number_format($shift->opening_variance, 2) }}</strong></div>
        <div class="line"><span>เหตุผลตอนรับกะ</span><strong>{{ $shift->opening_note ?? '-' }}</strong></div>
        <div class="line"><span>เงินนำส่งปิดกะ</span><strong>{{ $shift->deposit_amount === null ? '-' : number_format($shift->deposit_amount, 2) }}</strong></div>
        <div class="line"><span>เลขซอง</span><strong>{{ $shift->deposit_reference ?? '-' }}</strong></div>
        <div class="line"><span>เงินทอนเก็บในลิ้นชัก</span><strong>{{ $shift->float_kept === null ? '-' : number_format($shift->float_kept, 2) }}</strong></div>
    </section>

    @if($shift->cashMovements->isNotEmpty())
        <section class="section">
            <div class="section-title">รายการเงินเข้า-ออกระหว่างกะ</div>
            @foreach($shift->cashMovements as $movement)
                <div class="movement">
                    <div class="desc">
                        <strong>{{ ['cash_in' => 'เงินเพิ่มเข้า', 'drop' => 'นำส่งเงิน', 'payout' => 'เบิกจ่าย'][$movement->movement_type] ?? $movement->movement_type }}</strong>
                        <small>{{ $movement->created_at?->format('H:i:s') }} · {{ $movement->reason }}{{ $movement->reference_no ? ' · '.$movement->reference_no : '' }}</small>
                    </div>
                    <div class="amt">{{ number_format($movement->amount, 2) }}</div>
                </div>
            @endforeach
        </section>
    @endif

    @if($shift->closing_note)
        <section class="section">
            <div class="section-title">หมายเหตุการปิดกะ</div>
            <div style="font-size:10px;color:#334155">{{ $shift->closing_note }}</div>
        </section>
    @endif

    <div class="signatures">
        <div class="signature">ผู้ส่ง</div>
        <div class="signature">ผู้รับ (หัวหน้า)</div>
    </div>
</main>
@endforeach
</body>
</html>
