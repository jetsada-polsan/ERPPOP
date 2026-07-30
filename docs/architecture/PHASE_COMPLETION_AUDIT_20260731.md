# ERP Phase Completion Audit

วันที่ตรวจ: 2026-07-31

เอกสารนี้แยกคำว่า "ระบบทำงานและผ่าน UAT" ออกจาก "พร้อม cut-over ข้อมูลจริง" เพื่อไม่ให้การนำข้อมูล Bplus มาใช้ทำให้ยอด ERP ใหม่ผิดพลาด

| Phase | สถานะระบบ | หลักฐาน | เงื่อนไขก่อนประกาศ Go-live |
|---|---|---|---|
| 0 Discovery | In progress | mapping: 30 mapped, 202 needs review, 79 excluded | เจ้าของงานและบัญชีต้อง sign-off document/VAT mapping |
| 1 Foundation | Ready | users/RBAC, branch scope, document sequences, audit log | ตรวจสิทธิ์ผู้ใช้จริงตามตำแหน่ง |
| 2 Product & Inventory | Ready for new transactions | product, unit, barcode, price, lot/expiry, stock ledger, transfer, count, transform | ไม่ย้าย stock balance จาก Bplus ตามนโยบายปัจจุบัน; ต้องทำ cut-over opening stock/reconciliation แยก |
| 3 Procurement + AP | Ready for new transactions | supplier, PO, partial receiving, purchase/AP ledger and payment workflows | เอกสารซื้อและ AP เก่าต้อง map ตาม document type ก่อน import |
| 4 Sales + AR | Ready for new transactions | booking, quotation, cash/credit sale, delivery, return, receipt and AR controls | เอกสารขายและ AR open items เก่าต้อง reconcile ก่อน import |
| 5 POS Offline | Web/API ready; desktop frontend verified | idempotent checkout, local SQLite queue, offline auth, Vue tests and production build | ต้องรัน Windows signed installer workflow before distributing a new POS release |
| 6 Accounting + Closing | Ready for controlled use | COA, GL posting, bank reconciliation, tax/compliance, accounting period controls | ต้อง reconcile trial balance, AP/AR and VAT control totals at cut-over |
| 7 Reporting + Parallel Run | Partially complete | product master reconciliation: 0 missing SKU, 0 missing barcode | ต้อง compare document count, stock qty/value, AR/AP and trial balance at cut-off date |
| 8 Extensions | Partially complete | fixed assets, production, reporting, integrations and operational controls exist | payroll remains a future phase; enable only after HR/payroll rules are approved |

## Verification Results

- Laravel UAT: 118 tests / 1,875 assertions passed.
- Procurement/Inventory focused UAT: 31 tests / 160 assertions passed.
- Sales/POS/AR focused UAT: 44 tests / 147 assertions passed.
- POS desktop: 8 tests passed, vue-tsc --noEmit passed, vite build passed.
- Bplus product master reconciliation: SKU missing 0, duplicate SKU 0, barcode missing 0, duplicate barcode 0.

## Data Scope Enforced

- Microsoft SQL Bplus is accessed using read-only SELECT only.
- Product master, units, barcodes and selling prices are synchronized into ERP.
- stock_balances is not imported or replaced.
- Bplus technical and physical monthly tables are excluded from the normalized ERP database.
- Legacy purchase/sales/accounting transactions remain blocked from import until their document mappings and control totals are approved.

## Next Cut-over Gate

Before importing any transactional history or declaring the whole ERP live, create and approve:

1. Document type mapping for purchase, sales, return, receipt, payment and GL.
2. Cut-off date and opening balance policy.
3. Stock quantity/value reconciliation report.
4. AR/AP aging and trial balance reconciliation reports.
5. Windows POS signed installer build from GitHub Actions.
