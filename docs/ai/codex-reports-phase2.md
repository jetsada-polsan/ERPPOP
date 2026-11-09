# Codex: รายงาน ERP Phase 2 — ลงมือทำ (เจ้าของอนุมัติ 9 พ.ย. 2026)
Branch `codex/reports-phase2` (จาก main) แผนอยู่ `docs/ai/reports-inventory.md` §3 §5 §6 ทำตามนั้น
ห้ามแตะ production ห้าม deploy ห้าม push main ห้ามสร้าง PIN/รหัส/token ทุกตัวเลขเงินตรงถึงสตางค์ (DecimalMath) migration ต้องรันได้ทั้ง SQLite และ PostgreSQL

## คำตอบเจ้าของ (ใช้เป็นกติกา)
- ยอดใบจองแยก A/B = DS (มี VAT) / DSN (ไม่มี VAT) ตอนนี้แปลงใบจองแล้วแยกเป็น 2 บิลอัตโนมัติ (documents.source_document_id ชี้ใบจอง) นับตามวันที่ใบจอง ห้ามนับซ้ำใบจองกับใบขาย ใบที่ถูกยกเลิก/ถอย Confirm ไม่นับ
- IT Admin เห็นต้นทุน/กำไรได้ (เจ้าของเป็น IT เอง) — ข้อ "IT bypass เห็นการเงิน" ใน §3 ไม่ต้องแก้ ให้คงไว้
- Sales Admin ไม่เห็นต้นทุน/กำไร, Branch Manager เห็นเฉพาะสาขาตน, Executive/Owner/บัญชีเห็นทั้งหมด — ตรวจทั้งหน้าจอ API และไฟล์ export

## ลำดับงาน (ทำให้ครบทีละเฟส commit แยกเฟส)
0. สิทธิ์ + filter contract: แก้บั๊กระดับ "สูง" ใน §3 ทั้งหมด (arAging ไม่กรองสาขา, daily ส่ง cost/profit ให้คนไม่มีสิทธิ์, salesByBranch, VAT report, กำไรย้อนหลังใช้ต้นทุนปัจจุบัน ฯลฯ)
1. ยอดขายรายวัน + UI กลาง (FilterBar, KpiCard, ReportTable ตัวเลขชิดขวา comma ตัวหนังสือ ≥14px, export = หน้าจอ)
2. สต๊อกคงเหลือหน่วยหลัก+หน่วยย่อย (qty_per_base_unit จริง) + stock card drill-down
3. ใบจองเทียบใบขาย A/B (DS/DSN) ตามกติกาข้างบน
4. ลูกหนี้ (aging ต่อสาขา) 5. รับชำระ 6. จัดส่ง — ทำต่อถ้าเวลาพอ
แต่ละเฟสต้องมีเทสต์เทียบยอดกับเอกสารต้นทาง และเทสต์สิทธิ์ (คนไม่มีสิทธิ์ต้องไม่เห็นต้นทุน/สาขาอื่น ทั้งจอและ export)

## ส่งมอบ
`php artisan test` ผ่านทั้งหมด อัปเดต `docs/ai/task.md` สรุปไทย: เฟสไหนเสร็จ ไฟล์ ผลเทสต์ สิ่งที่ยังไม่ทำ ถ้า commit ไม่ได้ให้ทิ้งไว้ใน workspace
