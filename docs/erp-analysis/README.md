# ERP/POS Architecture Analysis — jeterp (POPSTAR ERP)

> เอกสารชุดนี้จัดทำโดยใช้ **PEAK Account** (SaaS บัญชี SME ของไทย) เป็นแนวคิดอ้างอิง (benchmark) เท่านั้น — **ไม่มีการ clone หรือคัดลอก UI ใด ๆ จาก PEAK** ทุกข้อสรุปยึดหลักฐานจากการอ่าน codebase จริงของ jeterp เป็นหลัก ไม่ใช่ทฤษฎี ERP ทั่วไป

## รายการเอกสาร

| ไฟล์ | เนื้อหา |
|---|---|
| [01-current-system-audit.md](01-current-system-audit.md) | Audit ระบบปัจจุบันแบบละเอียด (Auth/RBAC, Product, Purchase/AP, Document, Stock, Accounting, Tax, Reporting, POS, Infra) พร้อมหลักฐานอ้างอิง file:line |
| [02-peak-benchmark.md](02-peak-benchmark.md) | สรุปแนวคิด PEAK Account ที่เกี่ยวข้อง (Setup, RBAC, Sales/AR, Purchase/AP, WHT) |
| [03-gap-analysis.md](03-gap-analysis.md) | ตาราง Gap เปรียบเทียบ PEAK vs jeterp ทุกโมดูล พร้อม Priority P0-P3 |
| [04-target-architecture.md](04-target-architecture.md) | สถาปัตยกรรมเป้าหมาย (Mermaid diagrams) — วิวัฒนาการ ไม่ rewrite |
| [05-document-flow.md](05-document-flow.md) | Flow เอกสาร Sales/AR และ Purchase/AP พร้อม cardinality |
| [06-inventory-architecture.md](06-inventory-architecture.md) | สถาปัตยกรรมสต็อก — โฟกัสที่การแก้ Reservation Enforcement |
| [07-accounting-architecture.md](07-accounting-architecture.md) | Posting Rule, Journal Batch, WHT, Document State Model |
| [08-database-review.md](08-database-review.md) | ตารางที่ KEEP/MODIFY/ADD พร้อม Composite Index แนะนำ |
| [09-implementation-roadmap.md](09-implementation-roadmap.md) | แผนงาน Phase 0-3 เรียงตามความเสี่ยงจริง |

---

## สรุปสำหรับผู้บริหาร/เจ้าของระบบ (Executive Summary)

### 1. สิ่งที่ระบบปัจจุบันทำได้ดีอยู่แล้ว (จุดแข็งที่ยืนยันจาก codebase จริง)

- **Stock Ledger 3 ชั้น** (movement/lot/balance) พร้อม **FIFO costing และ FEFO** สำหรับสินค้าหมดอายุ — สถาปัตยกรรมนี้เหนือกว่า PEAK อย่างชัดเจน เพราะ PEAK ไม่มี concept คลังสินค้าเชิงลึกเลย
- **POS Offline-First** พร้อม idempotent sync (`Idempotency-Key` + `pos_api_idempotency`) และ device-bound cashier/PIN security — เหมาะกับร้านค้าที่เน็ตหลุดได้จริง
- **Document Numbering แบบ concurrency-safe** (`document_sequences` + row lock) ที่พิสูจน์แล้วว่าแก้ปัญหาจริงที่เคยเกิดขึ้น (COUNT(*)+1 เดิม fail 84% ภายใต้โหลดพร้อมกัน)
- **Barcode ชั่งน้ำหนัก (Scale Barcode)** รองรับ EAN-13 จริง — จำเป็นสำหรับสินค้าแช่แข็ง/ชั่งน้ำหนักที่ PEAK ไม่รองรับเลย
- **Accounting Engine** (`GlPostingService`) พร้อม idempotent posting, period-close guard, และ Financial Statements (Trial Balance/P&L/Balance Sheet) ที่คำนวณจริงจาก GL
- **Health/Readiness commands** (`erp:health`/`erp:readiness`) ตรวจ invariant ของระบบได้จริง — เป็นสิ่งที่ SaaS ปิดอย่าง PEAK ไม่จำเป็นต้องมี แต่ ERP self-hosted จำเป็นมาก

### 2. สิ่งที่ควรคงไว้ (ไม่ต้องแก้ ไม่ต้อง rewrite)

Blade + Alpine.js เป็น frontend หลักที่ทำงานได้ดีและทีมคุ้นเคย, สถาปัตยกรรม stock ledger ทั้งหมด, dual costing (moving-average + FIFO), POS offline sync mechanism, document numbering engine — ดูรายการเต็มใน `04-target-architecture.md` §6

### 3. สิ่งที่ออกแบบผิดพลาดและต้องแก้ไข (พบจาก codebase จริง ไม่เกี่ยวกับ PEAK)

- **Reservation ไม่มีผลบังคับใช้จริงทั้งระบบ** — `FifoStockService::issue()` ไม่เคยเช็ค `reserved_qty` เลย ทำให้สต็อกถูกจองซ้ำได้ (double-claim) — **ความเสี่ยงสูงสุดในระบบตอนนี้**
- **`SaleReturnService` ข้ามขั้นตอนอนุมัติ** โพสต์กลับรายได้/สต็อกทันที ขัดกับกฎที่ทีมเขียนไว้เอง (ในขณะที่ `CreditDebitNoteService` ทำถูกต้อง)
- **POS Printer/Cash-Drawer เป็นแค่ mock** — ยังพิมพ์ใบเสร็จจริงไม่ได้เลย บล็อกการเปิดหน้าร้านจริง
- **ปุ่ม Void ใน POS UI ผูกผิดจุด** — backend พร้อมแล้วแต่ UI เรียกผิด function
- **`RoutePermissions` เสี่ยง fail-open by omission** — route ที่ไม่ได้ map ไว้เข้าได้โดยไม่เช็คสิทธิ์

### 4. แนวคิดจาก PEAK ที่ควรนำมาปรับใช้

- Withholding Tax (WHT) rate table + gross-up calculation 2 โหมด + เลขที่หนังสือรับรอง
- แนวคิด document flow ที่แยกเอกสารชัดเจนตามหลักบัญชี (ใบเสนอราคา≠ใบสั่งขาย≠ใบแจ้งหนี้≠ใบเสร็จ) สำหรับสายขายส่ง
- โครงสร้าง Role/Permission ที่ชัดเจนตาม 8 บทบาทมาตรฐาน (ใช้เป็นแนวทางตั้งชื่อ role ให้สอดคล้องธุรกิจ ไม่ใช่ copy ทั้งหมด)

### 5. สิ่งที่ PEAK มีแต่ jeterp "ไม่จำเป็นต้องมี" ตอนนี้

- Multi-Company/Tenant switching — jeterp ออกแบบมาเป็น single-tenant โดยตั้งใจสำหรับกิจการเดียวหลายสาขา
- Self-service email invite — ธุรกิจขนาดนี้ admin สร้าง user เองได้เพียงพอ

### 6. จุดที่ jeterp ต้อง "เหนือกว่า PEAK" เสมอ (ต้องรักษาและพัฒนาต่อ ไม่ใช่ gap)

**POS, Offline-first, Warehouse/Lot/FEFO, Multi-Branch, Barcode ชั่งน้ำหนัก, ขายส่ง (Wholesale), สินค้าแช่แข็ง (Frozen Food), และ Internal Operation** — ทั้งหมดนี้ PEAK แทบไม่รองรับเลยหรือรองรับผิวเผิน เพราะ PEAK ออกแบบมาสำหรับ SME ทั่วไปที่เน้นบัญชี ไม่ใช่ค้าปลีก-ส่งสินค้าแช่แข็งแบบ jeterp

### 7. 10 สิ่งที่ควรทำก่อนขึ้น Production เต็มรูปแบบ

1. แก้ Reservation Enforcement ให้ `available = on_hand - reserved` มีผลบังคับใช้จริง
2. แก้ `SaleReturnService` ให้ผ่านการอนุมัติก่อนโพสต์บัญชี/สต็อก
3. Implement เครื่องพิมพ์ใบเสร็จจริง (ESC/POS) แทน mock
4. แก้ปุ่ม Void ใน POS UI ให้เรียก backend API ที่ถูกต้อง
5. Scan และปิดช่องโหว่ `RoutePermissions` fail-open by omission
6. รันเทสต์ทั้งหมดของงาน User-as-POS-identity migration ที่ทำค้างไว้ ก่อน commit/push
7. เพิ่ม WHT rate table + gross-up calculation (ข้อกำหนดทางกฎหมาย)
8. เพิ่ม unit conversion ในฝั่ง Purchase Order
9. สร้าง Supplier Credit/Debit Note
10. แก้ `allow_negative_stock` ให้ config-based แทน hardcode true

### 8. Roadmap แนะนำ (สรุปย่อ — รายละเอียดเต็มใน 09-implementation-roadmap.md)

**Phase 0 (Stabilize)** → ปิดช่องโหว่ P0 ทั้งหมดข้างต้น ก่อนเพิ่มฟีเจอร์ใหม่ใด ๆ
**Phase 1 (Core ERP Gaps)** → WHT, Supplier Credit Note, Posting Rule layer, Journal Batch, Document State Model
**Phase 2 (Important)** → AP Aging, Queue adoption, Redis, numbering setup UI
**Phase 3 (Future)** → Multi-tenant, Realtime, Contact Unification (ประเมินใหม่เมื่อมี business case)

---

## ข้อควรระวังสำคัญ

งานทั้งหมดในเอกสารชุดนี้เป็น **การวิเคราะห์และออกแบบเท่านั้น (audit + benchmark + gap + architecture + database proposal + roadmap)** ยังไม่มีการเขียนโค้ดฟีเจอร์ใหม่ใด ๆ ตามที่ผู้ใช้กำหนดไว้อย่างชัดเจนว่ารอบนี้ห้ามเริ่มเขียนฟีเจอร์ใหม่ทันที การ implement ตาม roadmap ต้องรอการอนุมัติและจัดลำดับความสำคัญจากเจ้าของระบบก่อนเริ่มในรอบถัดไป และทุกการ deploy ต้องได้รับอนุญาตอย่างชัดเจนเสมอ
