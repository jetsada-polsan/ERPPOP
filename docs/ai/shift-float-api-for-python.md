## สเปก API สำหรับ Python POS รอบถัดไป
ทุก endpoint ใช้ Device Token เดิมและ cashier session ที่ยืนยัน PIN แล้ว. ตัวตน/สาขา/เครื่องถูกบังคับจาก server อย่าเชื่อค่าที่ Python เก็บเอง. เงินเป็น JSON number เพื่อรองรับ client เดิม; Python ต้องใช้ `Decimal(str(value))` และส่งเป็น decimal string ได้ ห้ามใช้ binary float คำนวณหรือส่งยอดคาดหวังแทน ERP.

### 1. ก่อนเปิดกะ — GET `/api/pos/shift?branch_id=<id>&cashier_id=<salesman id>`
```json
{
  "shift": null,
  "default_float": 2000,
  "carried_float": 2000,
  "cash_warning_threshold": 10000,
  "blind_count": true
}
```
- `shift` เป็น object ถ้ามีกะเปิดของคนและเครื่องนี้; field ระดับบนใช้เตรียมรับกะครั้งถัดไป.
- ถ้าไม่เคยมีกะปิด หรือกะเก่าล่าสุดไม่มี float_kept ใช้ default_float; ถ้า float_kept=0 คืน 0.
- กะใหม่บันทึก carried_float ณ ตอนเปิดไว้ถาวร. อย่านำค่าของกะที่ปิดทีหลังไปแทน snapshot.
- ระหว่างกะ blind cashier ไม่มี `expected_cash`, `cash_sales`, `transfer_sales`, `card_sales`, `cheque_sales`, `cash_in`, `cash_drops`, `cash_payouts` ใน shift. อย่าเติมเป็นศูนย์แล้วแสดงให้ผู้ใช้เข้าใจผิด.
- Manager (`pos.cash.manage` ของคนที่ยืนยัน PIN) หรือเครื่องปิด blind เห็นยอดได้. `cash_warning` เป็น boolean จาก ERP ไม่ใช่การให้ client คำนวณยอดลิ้นชัก.

### 2. รับกะ — POST `/api/pos/shift/open`
```json
{"branch_id":1,"cashier_id":2,"opening_cash":"1950.00","opening_note":"รับเงินจริงขาด 50"}
```
- opening_cash คือยอดนับจริง. ถ้าต่างจาก carried_float แม้ 0.01 ต้องมี opening_note ที่ไม่ใช่ whitespace มิฉะนั้น 422.
- client ส่ง carried_float/opening_variance มาไม่มีผล. response `shift` มี carried_float, opening_variance, opening_note และนโยบายเครื่อง.
- เครื่องที่ยังมีกะเปิดของคนอื่นตอบ 422; เปิดซ้ำกะตนที่ยังเปิดคืนกะเดิม.

### 3. นับและตรวจยอด — POST `/api/pos/shift/close`
```json
{"shift_id":123,"counted_cash":"15432.75","float_kept":"2000.00","deposit_reference":"BAG-001","preview":true}
```
- counted_cash ต้องมี, 0 ขึ้นไป, ทศนิยม 0–2 ตำแหน่ง; ปฏิเสธเศษสตางค์แทนการปัด input โดยเงียบ.
- float_kept optional; ค่าเริ่ม min(counted_cash, default_float); ถ้ามีต้อง 0 ≤ float_kept ≤ counted_cash. เกินยอดนับตอบ 422.
- deposit_reference optional string ไม่เกิน 80 ตัวอักษร; closing_note optional ไม่เกิน 500.
- response `preview:true`, `low_float:<boolean>` และ `shift` มี cash_sales/transfer_sales/card_sales/cheque_sales, expected_cash, counted_cash, cash_difference, float_kept, deposit_amount, deposit_reference ที่ ERP คำนวณ.
- สูตร expected = opening_cash + cash_sales(net of cash refunds) + cash_in − cash_drops − cash_payouts; difference=counted−expected; deposit=counted−float_kept.
- preview ไม่ persist จำนวนเงิน/ปิดกะ/ลงสมุดเงินสด. Python ต้องซ่อน expected ก่อนรับยอดนับจริง และเรียก preview หลังนับเท่านั้น. ยอดนับ/เงินเก็บ/เลขซองเปลี่ยนให้ทิ้ง preview เก่าและขอใหม่.

### 4. ยืนยันปิดกะ — POST endpoint เดิม
ส่งข้อมูลนับ/float/เลขซองเดิมโดย omit `preview` หรือ `preview:false`. ERP คำนวณใหม่ภายใน transaction; อย่าส่ง expected/deposit จาก client เป็นแหล่งความจริง. response `success:true`, `shift.status:"closed"`, `report_url`; shift มีข้อมูลครบพิมพ์ Z และ `z_report_url`.
- ปิดแล้วเรียกซ้ำตอบ 422 และไม่ลงบัญชีซ้ำ. ถ้าขาด response ให้ตรวจสถานะก่อนส่งงานต่อ ห้ามสร้างกะทดแทนโดยเดายอด.
- หลังปิดเก็บ `float_kept` เป็น carry ของกะถัดไปของเครื่องนี้. ยอดนับน้อยกว่า default เตือนผู้ใช้และ deposit=0 เมื่อเก็บทั้งหมด.
- ปิดจากต่างเครื่อง/ต่างคน/ต่างสาขาตอบ 403.

### 5. Z print และ backward compatibility
- Z payload เพิ่ม `carried_float`, `opening_variance`, `opening_note`, `float_kept`, `deposit_amount`, `deposit_reference`, `void_count`, `return_count`, `discount_total` นอกเหนือจากยอดเดิม.
- พิมพ์สองใบชัดเจนและเซ็นผู้ส่ง/ผู้รับ (หัวหน้า); ข้อมูลกะเก่าที่ field เป็น null ให้แสดงว่าไม่มีข้อมูล อย่าเดายอดเป็น float ใหม่.
- ถ้า API รุ่นเก่าไม่มี `default_float`/`carried_float` อย่าเรียก preview เพราะรุ่นเก่าจะละเลย preview และปิดกะจริง. ให้ fallback flow เดิมชัดเจน.

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=2G vendor/bin/phpunit`: 762 tests, 760 passed, 2 skipped, 6 incomplete, 6,275 assertions; no failures/errors.
- New float suite: 11 tests / 75 assertions, including real HTTP device authentication, permission checks, decimal cents, preview without persistence, cross-terminal isolation, rollback and PostgreSQL DDL compilation.
- Real checkout integration closes two shifts and verifies cashbook sales are posted once; `uat:reconcile` passes.
- `git diff --check` passes.
- Mutation checks: เปลี่ยน deposit เป็น 0 / ยกเลิก blind filtering / ยกเลิก required opening reason ทีละจุด → เทสต์แดงทั้งสาม (exit 1) แล้วคืนโค้ดครบ.
- `vue-tsc --noEmit` ผ่าน; `vitest run` เริ่มไม่ได้เพราะ Rollup native library ต่าง Team ID กับ Node ของ Codex. ไม่รัน `vite build` ตามข้อกำหนด.
- ตรวจ PostgreSQL DDL ผ่าน grammar แบบ pretend โดยบังคับ connection callback ให้ throw ถ้ามีการต่อฐานจริง. ไม่มี PostgreSQL server ในเครื่อง จึงยังไม่ได้ execute migration บน PostgreSQL จริง.
- ไม่ได้ภาพหน้าจอ Vue หรือทดสอบเครื่องพิมพ์ Windows จริง; ต้องตรวจ print preview 2 ใบและ UI บนเครื่องทดสอบก่อนใช้งาน.

## Deploy / งานถัดไป
- **ไม่ deploy, ไม่ release, ไม่แตะ production**, ไม่รัน migration บนฐานข้อมูลถาวร. ทุก database test ใช้ SQLite in-memory.
- ต้องเปิด workspace ที่เขียน Git metadata ของ worktree นี้ได้ จากนั้น pull/reconcile main โดยรักษา branch เดิมและ working changes, commit เป็นขั้น แล้วทดสอบ/push `claude/shift-float`. อย่า stage node_modules, .claude, .codex หรือ credentials.
- แนะนำแยก commit: (1) schema/models + ERP controls/accounting/tests; (2) Vue UI/API/types + Z/Admin views/routes; (3) handoff/memory. ทุกขั้นใช้ชื่อไฟล์ชัดเจน ไม่ `git add .`.
- Python ใช้สเปกด้านบนทำใน branch อื่นตามโจทย์. ยังไม่ควรเปิดใช้ blind API กับ Python รุ่นเดิมที่สมมติว่า expected_cash มีทุกครั้ง โดยไม่ปรับ client ก่อน.

---

