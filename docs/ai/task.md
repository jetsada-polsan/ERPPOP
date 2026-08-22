# Handoff — 2026-08-22 รอบที่ 3 (Claude)

## Commit

```
711d133 Put the report menu under executive control
2da5222 Catalogue the first twenty reports
e9a20a5 Record the report governance handoff
```

ต่อจาก `8a7677f` (Define ERP report discovery and UAT scope)

## ทำอะไรไป

งานระยะที่ 2A ตามหัวข้อ "Report Discovery" — ทำ **catalog ก่อน แล้วค่อยทำหน้าจอ** ตามที่สั่ง
รอบนี้จึงยังไม่มีหน้ารายงานใหม่สักหน้า มีแต่ catalog + ระบบควบคุมรายงาน

### 1. ทะเบียนรายงาน `report_definitions` + ระบบเปิด/ปิด (`711d133`)

- ย้ายรายการรายงานออกจาก array ที่ hardcode ใน `ReportController` มาเป็นตาราง
  (`code, category, name, view_permission, owner_role, frequency, priority, status, enabled, sort_order, legacy_code, metadata`)
- หน้า **ตั้งค่า → ทะเบียนรายงาน** (`/settings/reports`, ต้องมี `settings.manage`)
  เปิด/ปิดรายงานได้เอง ไม่ต้องแก้โค้ด
- **ปิด = ซ่อนจากเมนูเท่านั้น** definition ยังอยู่ ประวัติที่ผู้ใช้ดึงไปแล้วไม่ถูกแตะ
  และไม่มีปุ่มลบรายงานในหน้านี้เลย
- ทุกครั้งที่เปิด/ปิดเขียน `audit_logs` (`report_enabled` / `report_disabled`)
- รายงานที่ `status != 'available'` **เปิดไม่ได้** — เป็นตัวบังคับกติกา "P0 ห้ามเปิดก่อน mapping + UAT ผ่าน"
- แยกสิทธิ์เป็นสามใบตาม brief:
  | สิทธิ์ | ทำอะไรได้ |
  |---|---|
  | `view_permission` ของแต่ละรายงาน | เห็นรายงานนั้นในเมนู |
  | `reports.export` (ใหม่) | เห็นปุ่มดาวน์โหลด/CSV |
  | `reports.all_branches` (ใหม่) | เลือกสาขาอื่นได้ ไม่มีสิทธิ์ = ล็อกที่สาขาตัวเองเสมอ ส่ง `branch_id=all` มาก็ไม่หลุด |

### 2. Report Catalog (`2da5222`)

`docs/ai/report-catalog.md` — 20 รายงานชุดแรก แต่ละตัวมี: ความถี่, เจ้าของ, ใช้ตัดสินใจอะไร,
ข้อมูล/สูตรที่ต้องใช้, และระบบใหม่มีแล้วหรือยัง พร้อม `docs/ai/uat/REPORT_UAT_TEMPLATE.md`

## สิ่งที่พบ — รายงาน P0 สามตัวติดที่ schema ไม่ใช่ที่ query

1. **ใบจองครบกำหนด/เกินกำหนดส่ง ทำไม่ได้เลย** — `sale_bookings` มีแค่
   `document_id, salesman_id, sales_area_id, status, confirmed_at, confirmed_document_id`
   **ไม่มีฟิลด์วันครบกำหนดส่ง** และ `documents` ก็ไม่มี (`due_date` มีเฉพาะใน `customer_open_items`
   ซึ่งเป็นวันครบกำหนด*ชำระเงิน* คนละเรื่อง) ต้อง migration + ช่องกรอกในหน้าใบจองก่อน
2. **AP aging ทำไม่ได้** — ฝั่งลูกหนี้มี `customer_open_items` (due_date/paid/balance/status ครบ)
   แต่ฝั่งเจ้าหนี้ไม่มีตารางคู่ขนาน มีแค่ `supplier_ledger` ที่เป็นสมุดเดินบัญชี ทำ aging ต่อใบไม่ได้
3. **สมุดเงินสดไม่มีวันตรงยอด** — `cash_books` มีโครงสร้างครบแต่มีที่เขียนเข้าที่เดียวคือฟอร์มกรอกมือ
   ใน `BplusOperationController` ไม่มีเอกสารใดเดินรายการเข้าอัตโนมัติ

เจอเพิ่มระหว่างตรวจ: ตาราง staging ของการนำเข้า POS เก่ายังค้างอยู่ด้วย —
`imported_payments` **37,412 แถว**, `imported_receipts`, `import_batches` ไม่มีโค้ดอ้างถึงแล้ว
(ต่อเนื่องจาก P0-1 ใน `erp-readiness-audit.md`)

## ทดสอบไปแล้วแค่ไหน

- `php artisan test` → **143 passed / 1,927 assertions** (เดิม 137 เพิ่ม 6 จาก `ReportGovernanceTest`)
- เทสต์ครอบ: ปิดรายงานแล้วหายจากเมนูแต่ definition ยังอยู่, เปิดรายงาน `planned` ไม่ได้,
  toggle เขียน audit log, ไม่มี `reports.all_branches` แล้วขอ `branch_id=all` ก็ยังเห็นแค่สาขาตัวเอง,
  มีสิทธิ์แล้วเห็นทุกสาขา, ปุ่ม export โผล่เฉพาะคนที่มี `reports.export`
- `php artisan migrate` รันผ่านบนเครื่อง (SQLite/Postgres local)

## ยังไม่ทดสอบ / ความเสี่ยง

- ⚠️ **การล็อกสาขาเปลี่ยนพฤติกรรมของผู้ใช้เดิม** — บน production มีผู้ใช้ active ที่ไม่มีสาขา 5 คน
  (CASHIER, DELIVERY, IT_MGR, MARKETING) ในจำนวนนี้ IT_MGR ได้ `reports.all_branches` จาก migration แล้ว
  แต่ **MARKETING จะเห็นรายงานเป็นค่าว่าง** จนกว่าจะกำหนดสาขาหรือให้สิทธิ์ — หน้าจอขึ้นข้อความบอกวิธีแก้แล้ว
  ควรตัดสินใจก่อน deploy ว่าจะให้สิทธิ์หรือกำหนดสาขา
- migration seed สิทธิ์ใหม่ให้ role: GM, IT_MGR, ACC_MGR, ACC (ทั้งสองสิทธิ์),
  BRANCH_MGR + PURCHASING (เฉพาะ export) — **ยังไม่ได้ยืนยันกับเจ้าของว่าตรงกับที่ต้องการ**
- ยังไม่ได้เปิดหน้า `/settings/reports` ดูด้วยตาจริง (ทดสอบผ่าน HTTP test เท่านั้น ไม่ได้ดู layout)
- ยังไม่มี Excel/PDF export ฝั่ง server — ตอนนี้ยังเป็น CSV ฝั่ง browser เหมือนเดิม (ข้อ 6 ของ
  "สถาปัตยกรรมรายงานใหม่" ยังไม่ครบ)
- ยังไม่ได้ทำหน้ารายงานใหม่สักหน้า ตามที่สั่งให้ทำ catalog ก่อน

## Deploy

**ยังไม่ deploy** — ค้างสะสม 3 รอบแล้ว: `2b1dfa9..645b916` (POS), `5107003` (รายงานนับซ้ำ),
`711d133` (ทะเบียนรายงาน) รอบนี้มี migration ใหม่ `2026_08_22_000143_create_report_definitions_table`
ต้องรัน `php artisan migrate` บน production ด้วย

## งานถัดไป

1. เจ้าของยืนยัน **ความถี่จริง + ชื่อเจ้าของรายงาน** ของทั้ง 20 ตัว และส่งตัวอย่างผลลัพธ์จริง
   พร้อมช่วงวันที่/สาขา เพื่อเริ่ม UAT
2. ตัดสินใจ 3 เรื่อง schema ข้างบน (วันครบกำหนดส่งใบจอง / supplier open items / ตัวเดินสมุดเงินสด)
3. ตัดสินใจเรื่องข้อมูล POS เก่า 16,537 ใบ + staging 37,412 แถว เพราะรายงานยอดขายทุกตัวผิดจนกว่าจะจบเรื่องนี้
4. เมื่อได้ 1–3 แล้วจึงทำหน้ารายงาน P0 ทีละตัว ตัวไหน UAT ผ่านค่อยเปิดในทะเบียน
