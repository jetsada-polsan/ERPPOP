# 09 — Implementation Roadmap

> Roadmap นี้จัดลำดับตาม **ความเสี่ยงจริงที่พบในระบบปัจจุบัน** ไม่ใช่ตามฟีเจอร์ใหม่ที่อยากมี — Phase 0 คือสิ่งที่ต้องทำก่อนเปิดใช้งานจริงอย่างเต็มรูปแบบ เพราะเป็นบั๊ก/ช่องโหว่ในของที่มีอยู่แล้ว ไม่ใช่งานออกแบบใหม่

---

## Phase 0 — Stabilize (ก่อน Production เต็มรูปแบบ)

เป้าหมาย: ปิดช่องโหว่ P0 ทั้งหมดที่พบจาก audit ก่อนเพิ่มฟีเจอร์ใหม่ใด ๆ

| # | งาน | อ้างอิง | หมายเหตุ |
|---|---|---|---|
| 1 | **แก้ Reservation Enforcement** — `FifoStockService::issue()` ต้องอ่าน/หัก `reserved_qty` จริง + cap ตอนจอง | 06-inventory-architecture.md | ใช้ row-lock pattern เดียวกับ `document_sequences` |
| 2 | **แก้ `SaleReturnService`** ให้ผ่าน `pending_approval` เหมือน `CreditDebitNoteService` | 07-accounting-architecture.md §5 | Bug fix ตรงไปตรงมา ใช้ pattern ที่มีอยู่แล้ว |
| 3 | **เช็ค `RoutePermissions` ครบทุก route** (แก้ fail-open by omission) | 03-gap-analysis.md §A | เขียน test/command scan route ทั้งหมด |
| 4 | **Implement ESC/POS printer จริง** (ปัจจุบันเป็นแค่ mock เขียน .txt) | 03-gap-analysis.md §I | บล็อกการใช้งานจริงหน้าร้าน — priority สูงสุดถ้าจะเปิดร้านจริง |
| 5 | **แก้ปุ่ม Void ใน POS UI** ให้เรียก backend void API ที่มีอยู่แล้ว (ปัจจุบันผูกกับลบบรรทัดในตะกร้า) | 03-gap-analysis.md §I | UI wiring bug เท่านั้น backend พร้อมแล้ว |
| 6 | **เสร็จงาน User-as-POS-identity migration ที่ค้างอยู่**: รัน `php artisan test` (Feature: `UserManagementTest`, `BookingSalesAreaTest`) + Python POS test ที่เกี่ยวข้อง ให้ผ่านทั้งหมด แล้วขออนุญาต commit/push จากเจ้าของระบบ | docs/ai/task.md (Handoff section) | งานนี้ Codex+Claude แก้โค้ดเสร็จแล้วแต่ **ยังไม่เคยรันเทสต์เลย** เพราะ sandbox ไม่มี PHP/Node — ต้องรันจริงก่อน commit |

---

## Phase 1 — Core ERP Gaps (P1)

เป้าหมาย: ปิดช่องว่างที่กระทบการทำงานประจำวันของบัญชี/จัดซื้อ

| # | งาน | อ้างอิง |
|---|---|---|
| 1 | เพิ่ม WHT rate table + gross-up calculation + posting rule | 07 §4 |
| 2 | เพิ่ม `unit_id` ใน `purchase_order_items` | 05, 08 §2 |
| 3 | สร้าง Supplier Credit/Debit Note (pattern เดียวกับฝั่งลูกค้า) | 03 §D, 08 §3 |
| 4 | เพิ่ม `posting_rules` เป็น optional layer เหนือ `GlPostingService` (เริ่มจาก WHT) | 07 §1 |
| 5 | เพิ่ม `gl_journal_batches` (journal header) | 07 §2 |
| 6 | เพิ่ม document state model mapping layer (ไม่แตะค่าเดิม) | 07 §3 |
| 7 | แก้ `allow_negative_stock` ให้ config-based แทน hardcode true | 03 §I |
| 8 | Refactor `request()` singleton ใน `PosController` เป็น dependency injection (ทำก่อนเริ่มใช้ queue jobs) | 03 §J |

---

## Phase 2 — Important, ไม่เร่งด่วน (P2)

| # | งาน | อ้างอิง |
|---|---|---|
| 1 | เพิ่ม credit limit + soft-delete ให้ `suppliers` | 03 §C, 08 §2 |
| 2 | สร้าง AP Aging Report (pattern เดียวกับ AR ที่มีอยู่) | 03 §C |
| 3 | ทำความสะอาดไฟล์ `.bak` ที่หลงเหลือ | 03 §E |
| 4 | เริ่มใช้ Laravel Queue (database driver ที่มีอยู่แล้ว) กับงานหนักที่เหมาะสม (bulk sync, report หนัก) | 03 §J |
| 5 | ย้าย cache/session ไป Redis เมื่อ scale โตขึ้น | 03 §J |
| 6 | เพิ่ม UI ตั้งค่า document numbering prefix ให้ครบทุกประเภท | 03 §A |
| 7 | พิจารณา Stock-in-Transit account สำหรับ transfer ข้ามวัน | 06 §6 |

---

## Phase 3 — Future (P3)

| # | งาน | อ้างอิง |
|---|---|---|
| 1 | Multi-Company/Tenant (ถ้ามี requirement ชัดเจนในอนาคต — ปัจจุบันเป็น single-tenant โดยตั้งใจ) | 03 §A |
| 2 | Self-service user invite/onboarding | 03 §A |
| 3 | Realtime (Laravel Reverb) สำหรับ dashboard ข้ามสาขาแบบ real-time | 03 §J |
| 4 | Location ระดับ bin/shelf ภายใน warehouse | 06 §5 |
| 5 | Contact Unification (Customer/Supplier) — ประเมินใหม่เมื่อมี business case ชัดเจน | 08 §4 |

---

## หลักการสำคัญของ Roadmap นี้

1. **ไม่มีเฟสไหนเสนอ "เขียนโมดูลใหม่ทั้งหมด"** — ทุกงานคือการเติม/แก้ของเดิมที่มีอยู่แล้ว (additive migration + bug fix + optional layer)
2. **Phase 0 ทั้งหมดไม่เกี่ยวกับ PEAK เลย** — เป็นบั๊ก/ช่องโหว่ในโค้ดของ jeterp เองที่ตรวจพบจาก audit ตรง ๆ ต้องแก้ก่อนไม่ว่าจะ benchmark กับ PEAK หรือไม่ก็ตาม
3. **งาน User-as-POS-identity migration ที่ทำค้างไว้ก่อนหน้านี้ต้องปิดให้จบก่อน** (รันเทสต์ + ขออนุญาต commit) เพราะเป็นความเสี่ยงเปิดค้างอยู่ในระบบจริงตอนนี้
4. **ห้าม deploy ทุกเฟสโดยไม่ได้รับอนุญาตจากเจ้าของระบบอย่างชัดเจน** ตามกฎที่กำหนดไว้ตั้งแต่ต้น
