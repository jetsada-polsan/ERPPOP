# Handoff — 2026-08-23 (Claude)

## Commit

```
ce81e2b Give bookings a delivery due date of their own
2af30a4 Track payables per invoice so AP can be aged
dc2a853 Post the cash book from the documents that move cash
dec1c48 Apply the agreed report ownership and access policy
b4a0523 Quarantine the legacy POS import tables
```

ต่อจาก `058acba` — ทำตามคำตัดสินใจ 8 ข้อของเจ้าของโครงการ 2026-08-23

## ทำอะไรไป (เรียงตามข้อที่สั่ง)

### 1) ใบจองครบกำหนดส่ง — `ce81e2b`

เพิ่มใน `sale_bookings`: `fulfillment_type` (pickup/delivery), `delivery_due_at`,
`delivered_at`, `delivery_status` (pending/partial/delivered/cancelled)

- ใบจองแบบ delivery **ต้องมี `delivery_due_at`** ทั้งตอนสร้างและตอนยืนยันเป็นใบขาย
  (กันสองชั้น: validation ที่ controller + guard ใน `BookingService` และ `CreditSaleService`)
- ใบจองแบบ pickup ไม่ต้องมี และใบจองเดิมทั้งหมดถือเป็น pickup โดยอัตโนมัติ
- **ไม่ได้แตะ `customer_open_items.due_date`** ตามที่สั่ง เพราะเป็นกำหนดชำระเงินคนละเรื่อง
- หน้าใบจองมีช่องเลือกวิธีรับของ + กำหนดส่ง (บังคับเฉพาะเมื่อเลือกจัดส่ง)

### 2) เจ้าหนี้คงค้างและ AP aging — `2af30a4`

สร้าง `supplier_open_items` คู่ขนาน `customer_open_items` ครบทุกคอลัมน์ที่สั่ง
(`supplier_id, source_document_id, document_no, document_date, due_date, original_amount,
paid_amount, balance_amount, status, payment_terms, cleared_at`)

- เปิดยอดอัตโนมัติเมื่อยืนยัน**ใบซื้อเชื่อ** (ซื้อเงินสดไม่เปิด)
- ลดลงอัตโนมัติเมื่อ**จ่ายชำระเจ้าหนี้** ตัดใบเก่าก่อน (FIFO ตาม due_date → document_date → id)
- `supplier_ledger` ยังเดินต่อเหมือนเดิมในฐานะสมุดเดินบัญชี **ไม่ได้ใช้คำนวณ aging**
- รายงาน `ap.outstanding_detail` และ `ap.aging` **ยังเป็น `planned`** ตามที่สั่ง — เปิดไม่ได้จนกว่าจะมีข้อมูลจริง

### 3) สมุดเงินสด auto-posting — `dc2a853`

สร้าง `CashBookPostingService` เป็นทางเดินรายการเดียวของทั้งระบบ และสร้าง `cash_books` ใหม่
(`cash_in`, `cash_out`, `running_balance`, `source_type`, `source_id`, `source_key`,
`pos_terminal_id`, `pos_shift_id`, `reason`, `created_by`, `approved_by`, `approved_at`, timestamps)

| แหล่ง | สถานะ |
|---|---|
| ปิดกะ POS (ยอดขายเงินสด) + เงินสดขาด/เกิน (แยกบรรทัด) | ✅ ต่อแล้ว |
| ขายสดหลังบ้านที่รับเงินสด | ✅ ต่อแล้ว |
| รับชำระลูกหนี้ด้วยเงินสด | ✅ ต่อแล้ว |
| ค่าใช้จ่ายเงินสด (ยอดหลังหัก ณ ที่จ่าย) | ✅ ต่อแล้ว |
| ปรับเงินสด (กรอกมือ) | ✅ ต้องมี `finance.manage` + เหตุผล + ผู้อนุมัติ |
| **ถอน/ฝากเงินสดเข้าธนาคาร** | ❌ **ยังไม่ต่อ** — ระบบยังไม่มีเอกสารฝาก/ถอน/โอนภายใน ต้องสร้างก่อน |

- **idempotent** ด้วย `source_key` unique — ปิดกะซ้ำ/บิลถูก retry ก็ลงแถวเดียว (มีเทสต์)
- `running_balance` คิดตอนบันทึกโดย **ล็อกแถวล่าสุดของสาขา** กันสองรายการอ่านยอดยกมาเดียวกัน
- **เงินสดจาก POS ลงตอนปิดกะครั้งเดียว ไม่ลงตอนขายแต่ละบิล** เพราะทุกบิล POS สร้างเอกสารขายสดผูกไว้
  ถ้าลงทั้งสองที่จะนับเงินซ้ำ — กับดักเดียวกับที่เจอในรายงานรอบก่อน (มีเทสต์คุมทั้งสองทาง)
- สมุดเงินสดยังเป็น `planned` จนกว่า UAT กับยอดขายและปิดกะจะผ่าน

### 4) ธนาคาร/รับชำระ/ลูกหนี้ — คงสถานะ `planned` ตามสั่ง ไม่ได้แตะ ไม่ได้ import อะไรกลับ

### 5) ตาราง staging POS เก่า — `b4a0523`

**ไม่ลบ** ตามสั่ง — เขียน `docs/architecture/LEGACY_POS_IMPORT_QUARANTINE.md` บันทึกว่าเลิกใช้แล้ว
และเพิ่ม `LegacyPosImportQuarantineTest` ที่จะ**แดงทันที**ถ้ามีไฟล์ใน `app/`, `routes/`, `resources/views/`
อ้างถึง `import_batches`, `import_files`, `import_errors`, `imported_receipts`,
`imported_receipt_items`, `imported_payments` (migration ยกเว้น เพราะเป็นประวัติการสร้างตาราง)

### 6) สิทธิ์และสาขา — `dec1c48`

| Role | view | export | all_branches |
|---|---|---|---|
| GM, IT_MGR, ACC_MGR, EXECUTIVE | ✅ | ✅ | ✅ |
| ACC (พนักงานบัญชี) | ✅ | ✅ | ❌ ถอนออกตามสั่ง |
| BRANCH_MGR, PURCHASING | ✅ | ✅ | ❌ |
| MARKETING | ✅ | ❌ | ❌ (และไม่มี finance.manage) |
| CASHIER, DELIVERY | ❌ | ❌ | ❌ |

ผู้ใช้ที่ไม่มีสาขาและไม่มี `reports.all_branches` → กรองด้วยสาขาที่ไม่มีอยู่จริง ได้ผลลัพธ์ว่าง
พร้อมข้อความบอกวิธีแก้ (มีเทสต์)

> ⚠️ **เจอบั๊กของตัวเองตอนเขียนเทสต์**: เดิมผมใช้ `'0'` เป็นค่าสาขาสำหรับผู้ใช้ที่ไม่มีสาขา
> แต่ `applyBranch()` เช็คด้วย `empty()` ซึ่งมองว่า `'0'` คือ "ไม่กรอง" → ผู้ใช้เห็นทุกสาขาแทนที่จะไม่เห็นอะไรเลย
> เปลี่ยนเป็น `-1` แล้ว เทสต์จับได้ก่อนขึ้น production

### 7) Owner และความถี่ — `dec1c48`

ใช้ **ตำแหน่งงาน** เป็น owner ตามสั่ง (ฝ่ายขาย / คลัง / การเงิน / บัญชี / ผู้จัดการสาขา / จัดซื้อ / ผู้บริหาร)
ผู้บริหารแก้เองได้จากทะเบียนรายงาน

- P0 = 41 รายงาน (เปิด 31, planned 10)
- **P1/P2 = 29 รายงาน ถูกปิดตามนโยบาย** — definition ยังอยู่ครบ เปิดกลับได้คลิกเดียว
  ที่ปิดไปรวมถึง `gross_margin`, กลุ่ม `loss_*`, `purchase_*`, `vat_sales`, `vat_purchase`,
  `stock_movements`, `top_products` — **ถ้าฝ่ายบัญชีต้องใช้ VAT ตอนปิดเดือน ให้เปิดกลับก่อน**
- รวม 87 รายการ เปิดอยู่ 48

## ทดสอบไปแล้วแค่ไหน

- `php artisan test` → **158 passed / 1,998 assertions** (เดิม 143 เพิ่ม 15 เคส)
- **PostgreSQL**: สร้างฐานทดสอบแยก `jeterp_migcheck` บน host → `migrate` ครบทุกตัว →
  `migrate:rollback --step=4` ผ่าน → `migrate` ใหม่ผ่าน → **drop ฐานทดสอบทิ้งแล้ว**
  ตรวจแล้วว่าฐาน `jeterp` ยังอยู่ที่ migration [88] ไม่ถูกแตะ
- **SQLite**: `migrate:fresh` → `rollback --step=3` → `migrate` ผ่านครบ
- **ตรวจหน้า `settings/reports` ด้วยตาแล้ว** — layout ปกติ, ตารางแยกตามหมวด, ปุ่ม toggle ทำงานจริง
  (กดปิดแล้วนับลดจาก 48 → 47, ขึ้นข้อความยืนยัน, เขียน audit log ถูกต้อง แล้วเปิดกลับคืน)
- แก้ header คอลัมน์ที่เขียนว่า "ลำดับ" ทั้งที่แสดง P0/P1/P2 → เปลี่ยนเป็น "ระดับ" จากการตรวจด้วยตาครั้งนี้

## ยังไม่ทดสอบ / ความเสี่ยง

- **ฝาก/ถอน/โอนเงินสดเข้าธนาคารยังไม่ต่อเข้าสมุดเงินสด** เพราะระบบยังไม่มีเอกสารประเภทนั้น
  ต้องสร้างเอกสารก่อน แล้วค่อยต่อ — ยังไม่ครบ 6 แหล่งตามที่สั่ง
- ยังไม่มีข้อมูลจริงให้ UAT ทั้งเจ้าหนี้และสมุดเงินสด (`supplier_open_items`, `cash_books` = 0 แถว)
  ทั้งคู่จึงยังเป็น `planned` — เทสต์ที่เขียนเป็นเทสต์ระบบ ไม่ใช่ UAT เทียบยอดจริง
- migration `000146` **สร้างตาราง `cash_books` ใหม่** (มีตัวกันไม่ให้รันถ้ามีข้อมูล)
  production ตอนตรวจมี 0 แถว **ต้องตรวจซ้ำก่อน deploy**
- การปิดรายงาน P1/P2 29 ตัวเป็นการเปลี่ยนสิ่งที่ผู้ใช้เห็น — กลับได้คลิกเดียวแต่ควรบอกผู้ใช้ก่อน
- ยังไม่ได้ตรวจ query plan ของ `whereNotExists` ในรายงานบน PostgreSQL ที่มีข้อมูลจริงเยอะ

## Deploy

**ยังไม่ deploy** — เขียนแผนไว้ที่ `docs/ai/deploy-plan-2026-08-23.md` ครบทั้ง
รายการ migration, ขั้นตอน backup, แผน rollback รายตัวพร้อมบอกว่าย้อนแล้วเสียอะไร
และเรื่องผู้ใช้ MARKETING ที่จะเห็นรายงานว่างเปล่า

## งานถัดไป

1. ตัดสินใจเรื่อง MARKETING (กำหนดสาขา หรือให้สิทธิ์) ก่อน deploy
2. อนุมัติ deploy → รัน migration 5 ตัว + build POS 1.5.0
3. สร้างเอกสารฝาก/ถอน/โอนเงินสดเข้าธนาคาร แล้วต่อเข้าสมุดเงินสดให้ครบ 6 แหล่ง
4. เมื่อมีข้อมูลจริงแล้วจึงทำ UAT ตาม `docs/ai/uat/REPORT_UAT_TEMPLATE.md` ทีละรายงาน
   ตัวไหนผ่านค่อยเปลี่ยน status เป็น `available` แล้วเปิดในทะเบียน
