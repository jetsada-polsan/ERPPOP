        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap');

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
            font-family: 'IBM Plex Sans Thai', 'Sarabun', Tahoma, sans-serif !important;
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
            min-height: 297mm;
            margin: 18px auto !important;
            padding: 16mm 14mm 12mm !important;
            background: #fff !important;
            color: var(--doc-ink) !important;
            position: relative;
            box-shadow: 0 18px 55px rgba(8,32,51,.10) !important;
            border-radius: 0 !important;
            overflow: visible !important;
        }

        /* เอา ribbon และมุมสีฟ้าสามเหลี่ยมที่บังหัวเอกสารออกอย่างถาวร ทั้งบนจอและตอนสั่งพิมพ์ */
        .sheet::before,
        .sheet::after,
        .page::before,
        .page::after,
        .print-sheet::before,
        .print-sheet::after,
        .ribbon {
            display: none !important;
            content: none !important;
            border: none !important;
            width: 0 !important;
            height: 0 !important;
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
            padding-right: 0 !important;
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

        @media screen {
            html,
            body {
                min-height: 100%;
            }

            body:not(.layout-fixed) {
                padding: 0 16px 24px !important;
                overflow-x: auto !important;
            }

            body:not(.layout-fixed) .toolbar,
            body:not(.layout-fixed) .no-print {
                max-width: min(210mm, calc(100vw - 32px)) !important;
                margin: 14px auto 8px !important;
            }

            body:not(.layout-fixed) .sheet,
            body:not(.layout-fixed) .page,
            body:not(.layout-fixed) .print-sheet {
                width: min(210mm, calc(100vw - 32px)) !important;
                min-height: auto !important;
                margin: 8px auto 18px !important;
            }
        }

        @media screen and (min-width: 980px) and (max-height: 980px) {
            body:not(.layout-fixed) .toolbar,
            body:not(.layout-fixed) .no-print {
                min-width: 210mm;
                zoom: .86;
            }

            body:not(.layout-fixed) .sheet,
            body:not(.layout-fixed) .page,
            body:not(.layout-fixed) .print-sheet {
                width: 210mm !important;
                min-height: 297mm !important;
                zoom: .72;
            }
        }

        @media screen and (min-width: 980px) and (max-height: 860px) {
            body:not(.layout-fixed) .sheet,
            body:not(.layout-fixed) .page,
            body:not(.layout-fixed) .print-sheet {
                zoom: .64;
            }
        }

        @media screen and (max-width: 760px) {
            body:not(.layout-fixed) { padding: 0 8px 18px !important; }
            body:not(.layout-fixed) .toolbar,
            body:not(.layout-fixed) .no-print {
                width: calc(100vw - 16px) !important;
                max-width: calc(100vw - 16px) !important;
                overflow-x: auto !important;
                justify-content: flex-start !important;
            }

            body:not(.layout-fixed) .toolbar a,
            body:not(.layout-fixed) .toolbar button,
            body:not(.layout-fixed) .no-print a,
            body:not(.layout-fixed) .no-print button {
                min-height: 38px !important;
                padding: 0 14px !important;
                font-size: 13px !important;
                white-space: nowrap !important;
            }

            body:not(.layout-fixed) .sheet,
            body:not(.layout-fixed) .page,
            body:not(.layout-fixed) .print-sheet {
                width: calc(100vw - 16px) !important;
                padding: 16mm 7mm 10mm !important;
                overflow-x: hidden !important;
            }

            body:not(.layout-fixed) .head,
            body:not(.layout-fixed) .doc-head,
            body:not(.layout-fixed) .doc-header,
            body:not(.layout-fixed) .bill-head,
            body:not(.layout-fixed) .meta-grid,
            body:not(.layout-fixed) .parties,
            body:not(.layout-fixed) .info-row,
            body:not(.layout-fixed) .totals,
            body:not(.layout-fixed) .totals-block,
            body:not(.layout-fixed) .qc-row,
            body:not(.layout-fixed) .signs,
            body:not(.layout-fixed) .signatures,
            body:not(.layout-fixed) .sign-row,
            body:not(.layout-fixed) .bill-sign {
                display: block !important;
            }

            body:not(.layout-fixed) .doc-title,
            body:not(.layout-fixed) .doc-title-block {
                text-align: left !important;
                padding-right: 0 !important;
                margin-top: 12px !important;
            }

            body:not(.layout-fixed) .box,
            body:not(.layout-fixed) .party-block,
            body:not(.layout-fixed) .info-box,
            body:not(.layout-fixed) .bill-customer,
            body:not(.layout-fixed) .sum,
            body:not(.layout-fixed) .totals-table {
                width: 100% !important;
                min-width: 0 !important;
                margin-top: 10px !important;
            }
        }

        @media print {
            html,
            body {
                width: auto !important;
                height: auto !important;
                background: #fff !important;
                color: #000 !important;
                font-family: 'IBM Plex Sans Thai', 'Sarabun', Tahoma, sans-serif !important;
                font-size: 11pt !important;
                line-height: 1.4 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .toolbar,
            .no-print { display: none !important; }
            .sheet,
            .page,
            .print-sheet {
                margin: 0 !important;
                width: auto !important;
                min-height: 0 !important;
                height: auto !important;
                box-sizing: border-box !important;
                box-shadow: none !important;
                padding: 8mm 8mm !important;
                overflow: visible !important;
                zoom: 1 !important;
                color: #000 !important;
            }

            /* Decorative screen-only corner must never consume printable area. */
            .sheet::before,
            .sheet::after,
            .page::before,
            .page::after,
            .print-sheet::before,
            .print-sheet::after,
            .ribbon { display: none !important; content: none !important; }

            /* Bootstrap responsive tables clip overflowing rows unless reset for paper. */
            .table-responsive,
            .table-wrap,
            .content-card,
            main,
            .app-main {
                max-height: none !important;
                overflow: visible !important;
            }

            /* ── ข้อกำหนดการพิมพ์กระดาษ: สีดำคมชัด ≥ 11pt ไม่ใช้สีพื้นหลังเปลืองหมึก ── */
            *, *::before, *::after {
                color: #000 !important;
                text-shadow: none !important;
            }

            .head, .doc-head, .doc-header, .bill-head {
                border-bottom: 0.75pt solid #000 !important;
                padding-bottom: 6pt !important;
                margin-bottom: 8pt !important;
            }

            .co-name, .company .name, .company-block .company-name {
                font-size: 13pt !important;
                font-weight: 700 !important;
                color: #000 !important;
            }

            .muted, .company .sub, .company-info, .party-info, .doc-type-sub, .orig,
            .small, .footnote, .doc-footer, .text-muted, .doc-meta {
                font-size: 11pt !important;
                color: #000 !important;
                line-height: 1.35 !important;
            }

            .doc-title h1, .doc-type, .doc-title-block .doc-type, .h4, .doc-title {
                font-size: 18pt !important;
                font-weight: 700 !important;
                color: #000 !important;
                line-height: 1.2 !important;
            }

            .box, .party-block, .info-box, .bill-customer {
                border: 0.75pt solid #000 !important;
                border-radius: 4px !important;
                padding: 6pt 8pt !important;
                background: transparent !important;
            }

            .box-label, .party-block h4 {
                font-size: 10.5pt !important;
                font-weight: 700 !important;
                color: #000 !important;
                margin-bottom: 2pt !important;
            }

            .meta-row {
                font-size: 11pt !important;
                padding: 1.5pt 0 !important;
            }

            /* ตารางพิมพ์: หัวตาราง 10.5pt ตัวหนา ไม่มีพื้นหลังเข้ม เส้นบาง 0.5-0.75pt */
            table.items, .print-sheet table, .table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-top: 8pt !important;
            }

            table.items thead th, table.items th, .print-sheet table thead th, .table thead th,
            table.items thead tr th, .summary-table th {
                background: transparent !important;
                color: #000 !important;
                border-top: 0.75pt solid #000 !important;
                border-bottom: 0.75pt solid #000 !important;
                border-left: 0 !important;
                border-right: 0 !important;
                padding: 4pt 6pt !important;
                font-size: 10.5pt !important;
                font-weight: 700 !important;
            }

            table.items tbody td, table.items td, .print-sheet table td, .table tbody td {
                background: transparent !important;
                color: #000 !important;
                border-bottom: 0.5pt solid #000 !important;
                border-top: 0 !important;
                border-left: 0 !important;
                border-right: 0 !important;
                padding: 4pt 6pt !important;
                font-size: 11pt !important;
            }

            .table td.text-end, .table th.text-end, table.items td.r, table.items th.r,
            .text-end, td.r, th.r {
                text-align: right !important;
                font-variant-numeric: tabular-nums !important;
            }

            /* ยอดรวมสุทธิ: ≥ 13pt ตัวหนา */
            .sum-row.grand, .totals-table .grand-row td, .totals .val, .grand,
            tfoot tr.fw-bold td, .totals .sum-row.grand {
                background: transparent !important;
                color: #000 !important;
                font-size: 13.5pt !important;
                font-weight: 700 !important;
                border-top: 0.75pt solid #000 !important;
                border-bottom: 1.5pt double #000 !important;
                padding: 4pt 6pt !important;
            }

            .baht-text {
                border: 0.75pt dashed #000 !important;
                background: transparent !important;
                color: #000 !important;
                font-size: 11pt !important;
                padding: 5pt 8pt !important;
            }

            .sign .line, .sig-line, .sign-box .line {
                border-bottom: 0.75pt dotted #000 !important;
            }

            .sign, .sig-block, .sign-box {
                color: #000 !important;
                font-size: 11pt !important;
            }

            .sheet table thead,
            .page table thead,
            .print-sheet table thead { display: table-header-group !important; }

            .sheet table tfoot,
            .page table tfoot,
            .print-sheet table tfoot { display: table-footer-group !important; }

            .sheet table tr,
            .page table tr,
            .print-sheet table tr,
            .totals,
            .totals-block,
            .qc-row,
            .signs,
            .signatures,
            .sign-row,
            .bill-sign {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .doc-title,
            .doc-title-block { white-space: nowrap !important; }

            .doc-title h1,
            .doc-title-block .doc-type { font-size: 18pt !important; }
        }
