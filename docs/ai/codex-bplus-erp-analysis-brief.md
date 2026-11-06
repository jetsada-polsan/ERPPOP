# Codex: วิเคราะห์ Bplus ERP เทียบกับ ERP/POS ของเรา (เอกสารเท่านั้น ห้ามแก้โค้ด)

เจ้าของดูคลิป Bplus ERP (https://www.youtube.com/watch?v=XU7vllpI8TI) แล้วให้ master prompt ด้านล่าง
งานรอบนี้: **อ่าน repo จริงแล้วเขียนเอกสารวิเคราะห์ ห้ามแก้โค้ด ห้าม migration ห้ามแตะ production ห้าม deploy**
ผลลัพธ์: `docs/ai/bplus-erp-analysis.md` (ภาษาไทย) commit + push บน branch `claude/bplus-erp-analysis`

## ข้อเท็จจริงของ repo ที่ต้องตรวจเองก่อนเขียน (อย่าเดา)
- ERP: Laravel 13, PHP 8.3, PostgreSQL production / SQLite ในเทสต์ — ดู `composer.json`, `database/migrations`, `app/`, `routes/`, `tests/`
- POS: `apps/pos-desktop` (Tauri + Vue, ใช้ขายจริง 1.8.1) และ `apps/pos-python` (PySide6, UAT 0.8.x) — offline queue SQLite, idempotency key, PosPricingGuard
- Bplus เดิมของร้านอยู่ใน MSSQL (SELECT only) — ดู `docs/` และ commands `Bplus*`
- เอกสาร/บันทึกเดิม: `docs/ai/PROJECT_MEMORY.md`, `docs/ai/task.md`, `docs/OPERATIONS.md`, รายงาน UAT ใน `docs/ai/`
- บริการสำคัญ: `app/Services/Accounting/*` (GlPosting, CashBook, TaxCompliance), `app/Services/Sales/*`, `app/Services/Inventory/*` (FIFO, costing), `PosController`, `PosApiController`

## โจทย์ (master prompt ของเจ้าของ — ทำตามทุกหัวข้อ)
ทำผลวิเคราะห์ 15 หัวข้อ:
1. Executive Summary
2. Bplus ERP Feature Breakdown จากคลิป (แยก "ข้อเท็จจริงจากคลิป" / "การตีความ" / "ข้อเสนอใหม่" ให้ชัด; ถ้าเปิดคลิปไม่ได้ให้ใช้สรุปของเจ้าของด้านล่างและแจ้งข้อจำกัด)
3. **Gap Analysis เทียบ repo จริง** — มีแล้ว / มีแต่ไม่สมบูรณ์ / ยังไม่มี / เสี่ยงต่อสต๊อก-ต้นทุน-บัญชี-ภาษี / ควรทำก่อน **อ้างไฟล์:บรรทัดจริงทุกข้อ**
4. ERP Module Dependency Map
5. End-to-End Business Workflow (PR→PO→GR→Supplier Invoice→AP→Payment, Quotation→SO→Delivery→Invoice→AR→Collection, partial, returns, promotion, wholesale/customer pricing, credit limit) พร้อมผลต่อ Inventory/Tax/Accounting แต่ละขั้น
6. Database Architecture / ERD (อิง schema จริง ระบุตารางที่มี และที่ต้องเพิ่ม)
7. Transaction Posting Rules (Double entry พร้อมตัวอย่างตัวเลขจริง ระบุชัดว่าตัวเลขเป็นตัวอย่าง)
8. Inventory & Costing Specs (multi-warehouse/branch, lot/expiry/serial, unit conversion, FIFO, weighted average, landed cost, negative stock, counting, adjustment approval, transfer in transit, assembly, transformation, catch weight, weight loss, yield — สูตร + ตัวอย่าง รวมกรณีรับ/ขาย/คืน/ย้อนวันที่/โอน)
9. Accounting & Tax Specs (ไทย: CoA, GL, AR/AP, VAT in/out, รายงานภาษีซื้อ/ขาย, หัก ณ ที่จ่าย, ภ.ง.ด.3/53, ภ.พ.30, TB/BS/P&L/Cash flow, bank rec, fixed assets, period close, profit center) — **ห้ามเดากฎภาษี** ถ้าตรวจกฎหมายปัจจุบันไม่ได้ให้ระบุว่า "ต้องให้ผู้สอบบัญชี/ที่ปรึกษาภาษียืนยัน" และแยกจุดรับรู้ภาษีออกจากการออกเอกสาร/รับเงิน
10. POS Offline Sync Architecture (เทียบสิ่งที่มีจริงใน `apps/pos-desktop/src/lib/sync.ts`, `apps/pos-python/pos_python/sync_service.py`, ERP checkout/idempotency) — local vs ต้องรอ central
11. API Design
12. UI/UX Screen Requirements
13. Security & Audit Requirements
14. Automated Testing / Acceptance Criteria
15. Implementation Roadmap แบ่ง Phase ตามความสำคัญ (ธุรกิจอาหารสด/แช่แข็งหลายสาขา)

ทุกโมดูลในข้อ 4–14 ระบุ: Business Objective, Inputs, Outputs, Business Rules, Database Tables, API Endpoints, Permission Rules, Validation, Transaction Boundaries, Error Handling, Test Cases, Acceptance Criteria (สั้นกระชับ ใช้ตาราง)

## สรุปคลิปจากเจ้าของ (ใช้เป็นฐาน)
- ผลิตภัณฑ์: Bplus ERP, POS (โปร/สมาชิก/แต้ม), Mobile Sales, HRM
- ERP core: Purchasing, Inventory, Sales, Financial, Accounting, MRP, E-commerce, e-Tax Invoice, FS, Profit Center, Consolidation, Cash Flow, Bank Rec
- Architecture: แอปบางส่วนผ่าน web server เป็นตัวกลาง mobile ไม่ต่อ DB ตรง เน้นสิทธิ์/ความปลอดภัย (คลิปไม่เปิดเผยรายละเอียดจริง)
- Document control: ความถูกต้องครบถ้วน หน้าที่ ป้องกันทุจริต วงเงินอนุมัติ อนุมัติเอกสาร ผู้อนุมัติแทน
- Inventory: PR, PO, GR, partial, transfer, issue, adjust, assembly, transformation, manufacturing, WMS, MRP, offline counting, PDA barcode
- Finance: AR, AP, payment, collection, petty cash, bank, fixed assets, depreciation, reports, tax, dashboard
- Auto processing: รับสินค้ากระทบสต๊อก/เจ้าหนี้ตามประเภทเอกสาร, ต้นทุน, ค่าใช้จ่ายเพิ่มในการรับเข้า, ลิงก์บัญชี+ภาษี, VAT in/out, WHT, ABC+EOQ, FIFO, weighted average, lot/serial cost, นับ offline/online, สรุปผลต่างก่อนยืนยันปรับยอด
- Financial ops: AR/AP aging, credit limit, payment/collection scheduling, cash flow forecast, bank statement, bank rec, depreciation, profit center, consolidation
- Executive dashboard: performance, รายงานแยกหน่วยงาน/สาขา

## ข้อกำหนด
- เริ่มจาก business rules และความถูกต้องของข้อมูล ไม่ใช่หน้าจอ
- ห้ามคัดลอก/อ้างองค์ประกอบกรรมสิทธิ์ของ Bplus — ใช้ระดับกระบวนการธุรกิจเท่านั้น
- เอกสาร Posted แก้ได้ด้วย reversal เท่านั้น ห้ามแก้สต๊อก/บัญชีตรงโดยไม่มีเอกสาร
- ความเสี่ยงที่เจอใน repo ให้จัดลำดับ (สูง/กลาง/ต่ำ) และบอกผลกระทบเป็นเงิน/สต๊อก/ภาษี
- ความยาวพอเหมาะให้เจ้าของอ่านได้ — มีสรุปผู้บริหาร 1 หน้าแรก แล้วรายละเอียดตามหัวข้อ
