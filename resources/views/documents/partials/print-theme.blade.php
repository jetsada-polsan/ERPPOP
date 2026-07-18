        :root {
            --doc-blue: #1f9bd1;
            --doc-blue-dark: #0d5d86;
            --doc-ink: #082033;
            --doc-muted: #54708a;
            --doc-line: #d6e8f4;
            --doc-soft: #e7f4fb;
            --doc-page-bg: #eaf2f8;
        }

        body {
            background: var(--doc-page-bg) !important;
            color: var(--doc-ink) !important;
            font-family: 'Noto Sans Thai', 'Sarabun', 'Segoe UI', 'Leelawadee UI', sans-serif !important;
        }

        .toolbar,
        .no-print {
            max-width: 210mm;
            margin: 16px auto 10px !important;
            padding: 0 !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 10px !important;
            background: transparent !important;
        }

        .toolbar a,
        .toolbar button,
        .no-print a,
        .no-print button {
            min-height: 42px;
            padding: 0 22px !important;
            border-radius: 9px !important;
            border: 1px solid #c7deef !important;
            background: #fff !important;
            color: #12344d !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            text-decoration: none !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            box-shadow: 0 8px 22px rgba(8,32,51,.04);
        }

        .toolbar .primary,
        .toolbar button.primary,
        .no-print button:first-of-type {
            background: var(--doc-blue) !important;
            border-color: var(--doc-blue) !important;
            color: #fff !important;
        }

        .no-print > span {
            color: var(--doc-muted) !important;
            margin-right: auto;
        }

        .sheet,
        .page,
        .print-sheet {
            width: 210mm;
            min-height: 285mm;
            margin: 18px auto !important;
            padding: 28mm 20mm 14mm !important;
            background: #fff !important;
            color: var(--doc-ink) !important;
            position: relative;
            box-shadow: 0 18px 55px rgba(8,32,51,.10) !important;
            border-radius: 0 !important;
        }

        .sheet::before,
        .page::before,
        .print-sheet::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 0;
            height: 0;
            border-top: 28mm solid var(--doc-blue);
            border-left: 28mm solid transparent;
        }

        .sheet::after,
        .page::after,
        .print-sheet::after {
            content: "1";
            position: absolute;
            top: 7mm;
            right: 5mm;
            color: #fff;
            font-size: 20px;
            font-weight: 900;
        }

        .ribbon {
            display: none !important;
        }

        .head,
        .doc-head,
        .doc-header,
        .bill-head {
            border-bottom: 0 !important;
            padding-bottom: 12px !important;
            margin-bottom: 18px !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            gap: 18px !important;
        }

        .co-name,
        .company .name,
        .company-block .company-name {
            color: var(--doc-ink) !important;
            font-size: 17px !important;
            font-weight: 900 !important;
        }

        .muted,
        .company .sub,
        .company-info,
        .party-info,
        .doc-type-sub,
        .orig {
            color: var(--doc-muted) !important;
            line-height: 1.6 !important;
        }

        .doc-title,
        .doc-title-block {
            text-align: right !important;
            padding-right: 36px !important;
        }

        .doc-title h1,
        .doc-type,
        .doc-title-block .doc-type {
            color: var(--doc-blue) !important;
            font-size: 26px !important;
            font-weight: 900 !important;
            line-height: 1.2 !important;
        }

        .meta-grid,
        .parties,
        .info-row {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) minmax(260px, .58fr) !important;
            gap: 16px !important;
            margin: 16px 0 18px !important;
        }

        .box,
        .party-block,
        .info-box,
        .bill-customer {
            border: 1px solid var(--doc-line) !important;
            border-radius: 9px !important;
            padding: 14px 18px !important;
            background: #fff !important;
        }

        .box-label,
        .party-block h4 {
            color: var(--doc-blue) !important;
            font-size: 12px !important;
            font-weight: 900 !important;
            margin-bottom: 6px !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
        }

        .meta-row {
            display: flex !important;
            justify-content: space-between !important;
            gap: 16px !important;
            padding: 4px 0 !important;
            font-size: 13px !important;
        }

        .meta-row b,
        .doc-meta .value,
        .party-name {
            color: var(--doc-ink) !important;
            font-weight: 900 !important;
        }

        table.items,
        .print-sheet table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-top: 14px !important;
        }

        table.items thead th,
        table.items th,
        .print-sheet table thead th {
            background: var(--doc-blue) !important;
            color: #fff !important;
            border: 0 !important;
            padding: 9px 12px !important;
            font-size: 13px !important;
            font-weight: 900 !important;
            text-align: left !important;
        }

        table.items thead th.r,
        table.items thead th.c,
        table.items th.right,
        table.items th.text-end,
        .print-sheet table th.text-end {
            text-align: right !important;
        }

        table.items tbody td,
        table.items td,
        .print-sheet table td {
            border-bottom: 1px solid #e8f1f7 !important;
            padding: 9px 12px !important;
            color: var(--doc-ink) !important;
            font-size: 13px !important;
            vertical-align: top !important;
        }

        table.items tbody tr:nth-child(even) td,
        .print-sheet table tbody tr:nth-child(even) td {
            background: #fbfdff !important;
        }

        .totals,
        .totals-block,
        .qc-row {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            gap: 18px !important;
            margin-top: 16px !important;
            border-top: 0 !important;
        }

        .baht-text {
            border: 1px dashed #acd4ed !important;
            border-radius: 9px !important;
            padding: 10px 14px !important;
            color: #173f5b !important;
            background: #fbfdff !important;
        }

        .sum,
        .totals-table,
        .totals {
            min-width: 300px;
        }

        .sum-row.grand,
        .totals-table .grand-row td,
        .totals .val {
            background: #dff0fa !important;
            color: var(--doc-blue-dark) !important;
            border: 0 !important;
            border-radius: 8px !important;
            font-weight: 900 !important;
        }

        .signs,
        .signatures,
        .sign-row,
        .bill-sign {
            display: flex !important;
            justify-content: space-between !important;
            gap: 18px !important;
            margin-top: 46px !important;
        }

        .sign,
        .sig-block,
        .sign-box {
            flex: 1 !important;
            text-align: center !important;
            color: #43607a !important;
        }

        .sign .line,
        .sig-line,
        .sign-box .line {
            border-bottom: 1px dotted #7fa1bd !important;
            border-top: 0 !important;
            height: 34px !important;
            margin: 0 0 6px !important;
        }

        .footnote,
        .doc-footer {
            color: #7d97ac !important;
            border-top: 0 !important;
        }

        @media print {
            body { background: #fff !important; }
            .toolbar,
            .no-print { display: none !important; }
            .sheet,
            .page,
            .print-sheet {
                margin: 0 !important;
                width: auto !important;
                min-height: auto !important;
                box-shadow: none !important;
                padding: 14mm 13mm !important;
            }
        }
