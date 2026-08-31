# 08 — Database Review

> รายการนี้ทบทวนตารางสำคัญที่พบจริงในระบบ (จาก audit) พร้อมข้อเสนอ KEEP / MODIFY (additive เท่านั้น) / ADD (ตารางใหม่) — **ไม่มีข้อเสนอ DROP หรือ RENAME ตารางที่ใช้งานอยู่แม้แต่รายการเดียว** ตามกฎที่ผู้ใช้กำหนดไว้อย่างชัดเจนว่าห้ามลบ/แก้ไขข้อมูลเดิม การรวม Customer/Supplier เป็นตารางเดียว (Contact Unification) นำเสนอเป็นแค่การวิเคราะห์ข้อดี-ข้อเสีย ไม่ใช่ข้อเสนอให้ทำตอนนี้

---

## 1. ตารางหลัก: KEEP ทั้งหมด (ไม่แตะ)

| ตาราง | เหตุผลที่ต้องคงไว้ |
|---|---|
| `stock_movements` / `stock_lots` / `stock_balances` | สถาปัตยกรรม FIFO/FEFO 3 ชั้น ทำงานถูกต้องและเหนือกว่า PEAK |
| `document_sequences` | แก้ปัญหา concurrency ที่เคยมี incident จริง (84% fail rate) |
| `gl_journals` | Idempotent posting + period guard ทำงานถูกต้อง (จะเพิ่ม FK header แบบ nullable เท่านั้น ดู 07) |
| `pos_api_idempotency` | กลไก idempotent checkout ของ POS offline sync — สำคัญมาก ห้ามแตะ |
| `chart_of_accounts` (พร้อม `default_role`) | กลไก data-driven account selection ที่ยืดหยุ่นอยู่แล้ว |
| `report_definitions` | Report engine แบบ data-driven |
| `user_branch_roles` (เพิ่งสร้างโดย Codex ในงานก่อนหน้า) | เพิ่งเสร็จงาน migration ให้ User เป็นตัวตนหลัก POS/ERP — ต้องรอ test ผ่านก่อน แต่โครงสร้างถูกต้องแล้ว |
| `user_pos_credentials` (เพิ่งสร้างโดย Codex) | เช่นเดียวกับข้างต้น |
| `salesmen` | คงไว้เป็น legacy compatibility table ตามที่ผู้ใช้กำหนดชัดเจนว่าห้ามลบ |

---

## 2. ตารางที่ต้อง MODIFY (เพิ่มคอลัมน์แบบ additive เท่านั้น)

| ตาราง | สิ่งที่ต้องเพิ่ม | เหตุผล | Priority |
|---|---|---|---|
| `purchase_order_items` | `unit_id` (FK, nullable), `unit_conversion_rate` | ปัจจุบันไม่มีหน่วยนับระดับบรรทัด ไม่สมมาตรกับฝั่งขาย | P1 |
| `gl_journals` | `gl_journal_batch_id` (FK, nullable) | รองรับ journal header/batch (ดู 07) | P1 |
| `documents` | ไม่แก้ schema เดิม — เพิ่มตารางแยก `document_status_definitions` แทน | หลีกเลี่ยงการแตะ column ที่ทุก module พึ่งพาอยู่ | P1 |
| `suppliers` | `credit_limit_amount` (nullable), `deleted_at` (soft-delete) | ปิดช่องว่างความไม่สมมาตรกับ `customers` | P2 |
| `payment_vouchers` (หรือเทียบเท่าที่ใช้จริง) | `wht_rate_id` (FK, nullable), `wht_amount`, `wht_certificate_no`, `gross_up_mode` | รองรับ WHT (ดู 07) | P0 |

---

## 3. ตารางใหม่ที่ต้อง ADD

| ตารางใหม่ | วัตถุประสงค์ | Priority |
|---|---|---|
| `wht_rates` | อัตราหัก ณ ที่จ่ายต่อประเภทรายจ่าย | P0 |
| `supplier_credit_notes` (+ `supplier_credit_note_lines`) | ใบลดหนี้จากผู้ขาย ใช้ pattern เดียวกับ `credit_debit_notes` ที่มีอยู่ | P1 |
| `posting_rules` (+ `posting_rule_lines`) | Optional layer เหนือ `GlPostingService` เดิม (ดู 07) | P1 |
| `gl_journal_batches` | Journal header/batch | P1 |
| `document_status_definitions` | Mapping layer สำหรับ unified state model โดยไม่แตะค่าเดิม | P1 |
| `route_permission_audit` (หรือเทียบเท่า) | เก็บผลการ scan route ↔ permission mapping อัตโนมัติ เพื่อป้องกัน fail-open by omission | P0 |

---

## 4. Contact Unification (Customer/Supplier) — วิเคราะห์เท่านั้น ไม่ implement ตอนนี้

### ข้อดีถ้ารวมเป็นตารางเดียว (unified `contacts`)

- ลด logic ซ้ำซ้อนระหว่าง Customer/Supplier (credit control, soft-delete, CRM notes จะใช้ร่วมกันได้)
- รองรับกรณีที่ contact เดียวเป็นทั้งลูกค้าและผู้ขาย (พบได้ในธุรกิจค้าส่งจริง เช่น แลกเปลี่ยนสินค้า)
- ตรงกับแนวคิด PEAK ที่ unified contact ตั้งแต่ต้น

### ข้อเสียถ้ารวมตอนนี้

- **ต้นทุนสูงมาก**: พบว่ามี ~24 FK column อ้างอิง `customers`/`suppliers` กระจายอยู่ใน ~20 migration ทั่วระบบ (ยืนยันจาก subagent audit)
- ความเสี่ยง regression สูง เพราะต้องแก้ query/relationship ในหลายสิบจุดพร้อมกัน
- ผลตอบแทนระยะสั้นต่ำ — ปัญหาจริงที่ต้องแก้ (WHT, reservation, approval gap) ไม่เกี่ยวกับ contact unification เลย

### ข้อสรุป

**ไม่แนะนำให้ unify ตอนนี้** — แนะนำแค่เติมฟีเจอร์ที่ `suppliers` ขาด (credit limit, soft-delete) แบบ additive (ดูข้อ 2) ให้พิจารณา contact unification เป็นโปรเจกต์แยกในอนาคตไกล ๆ เมื่อมี business requirement ที่จำเป็นจริง (เช่น ต้องรองรับ contact ที่เป็นทั้งลูกค้าและผู้ขายพร้อมกันจริง ๆ)

---

## 5. Composite Index Recommendations

จาก pattern การ query จริงที่พบในระบบ (branch-scoped ทุกจุด, document listing filter ตาม date/status บ่อย) เสนอเพิ่ม composite index ดังนี้ (ตรวจสอบว่ามีอยู่แล้วหรือไม่ก่อนเพิ่มจริงในแต่ละตาราง):

| ตาราง | Composite Index ที่เสนอ | เหตุผล |
|---|---|---|
| `stock_movements` | `(branch_id, warehouse_id, product_id, created_at)` | Query ยอดคงเหลือ/ประวัติเคลื่อนไหวต่อสาขา+คลัง+สินค้าเรียงตามเวลาบ่อยที่สุด |
| `stock_balances` | `(warehouse_id, product_id)` unique | ป้องกัน duplicate cache row + เร่ง lookup |
| เอกสารทุกประเภท (SO/PO/Invoice ฯลฯ) | `(branch_id, doc_date, status)` | หน้ารายการเอกสารกรองตามสาขา+ช่วงวันที่+สถานะเป็น pattern หลักของหน้า listing ทุกโมดูล |
| `documents` (หรือเทียบเท่า) | `(doc_number)` unique ต่อ branch/ปี ถ้ายังไม่มี | ป้องกันเลขที่เอกสารซ้ำข้าม concurrent request |
| `gl_journals` | `(account_code, created_at)` | รายงานบัญชีแยกประเภท (General Ledger) ต้องดึงตามบัญชี+ช่วงเวลาเสมอ |

**หมายเหตุ**: ก่อนเพิ่ม index จริงในแต่ละตาราง ต้องตรวจสอบ migration history ว่ามี index ที่คล้ายกันอยู่แล้วหรือไม่ เพื่อไม่ให้สร้างซ้ำซ้อนโดยไม่จำเป็น
