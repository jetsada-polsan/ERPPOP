# Codex: เงินทอนประจำลิ้นชัก, ยกยอดข้ามกะ, เงินนำส่ง, นับแบบไม่เห็นยอด, ใบปิดกะครบ (ERP + JET POS Vue)

Branch `claude/shift-float` (จาก origin/main) commit เป็นขั้น push ได้ **ห้าม deploy ห้าม release ห้ามแตะ production**
migration ต้องรันได้ทั้ง SQLite และ PostgreSQL (เพิ่มคอลัมน์ nullable/default เท่านั้น ห้ามแก้ข้อมูลเก่า)
**เรื่องเงิน ห้ามพลาดแม้สตางค์เดียว** ทุกตัวเลขคำนวณที่ ERP ไม่เชื่อค่าที่เครื่องส่งมา (ยกเว้นยอดที่นับได้)
ฝั่ง Python POS จะทำทีหลังในอีก branch — รอบนี้ทำ ERP + `apps/pos-desktop` (Vue) เท่านั้น และเขียนสเปก API ให้ Python ใช้ต่อลง `docs/ai/task.md`

## กติกาที่เจ้าของเลือก (ค่าเริ่มต้น แก้ได้ในตั้งค่า)
1. **เงินทอนประจำลิ้นชัก (float)** ตั้งต่อเครื่อง POS (pos_terminals) ค่าเริ่มต้น 2,000 บาท แก้ได้ในหน้า ERP จัดการเครื่อง POS
2. **เข้ากะ**: ERP คืน `carried_float` = เงินทอนที่กะก่อนของเครื่องนี้เก็บไว้ในลิ้นชัก (ไม่มีกะก่อน = float ของเครื่อง)
   แคชเชียร์นับแล้วยืนยัน `opening_cash`; ถ้า ≠ carried_float ต้องใส่เหตุผล (บันทึก `opening_variance`, `opening_note`) และแสดงใน Z
3. **ระหว่างกะ**: นำส่งเงิน (cash drop) มีอยู่แล้ว + เตือนเมื่อเงินสดคาดว่ามีในลิ้นชักเกินเกณฑ์ (ตั้งได้ ค่าเริ่ม 10,000)
4. **ปิดกะแบบนับไม่เห็นยอด (blind count)** เปิดเป็นค่าเริ่มต้น (ตั้งปิดได้): หน้าปิดกะไม่แสดง expected จนกว่าจะกรอกยอดนับ; API ไม่ส่ง expected ก่อนยืนยัน (ยกเว้นผู้มีสิทธิ์ผู้จัดการ)
   ช่วยนับ: กรอกจำนวนธนบัตร/เหรียญแต่ละชนิด (1000/500/100/50/20/10/5/2/1/0.50/0.25) หรือยอดรวม
   หลังกรอก: แสดง expected, ส่วนต่าง (เกิน/ขาด สี) → ยืนยัน
5. **แยกยอดตอนปิดกะ**: `float_kept` = เงินทอนเก็บในลิ้นชัก (ค่าเริ่ม = float ของเครื่อง, แก้ได้ ≤ ยอดนับ) และ `deposit_amount` = นับได้ − float_kept (ERP คำนวณ) + `deposit_reference` เลขซอง
   ถ้านับได้ < float → เก็บเท่าที่มี deposit = 0 และเตือน
   float_kept ของกะนี้ = carried_float ของกะถัดไปของเครื่องเดียวกัน
   สมุดเงินสด/บัญชี: เงินนำส่งลงตามเดิมของระบบ ส่วน float ไม่ใช่รายได้ (ตรวจ CashBookPostingService ว่าไม่นับซ้ำ/ไม่หาย — เขียนเทสต์กระทบยอด)
6. **ใบปิดกะ (Z) พิมพ์ 2 ใบ** (ใบนำส่ง + ใบเก็บ) มี: ยอดขายแยกวิธีชำระ, จำนวนบิล/ยกเลิก/รับคืน, ส่วนลดรวม, เงินทอนยกมา+ส่วนต่างตอนรับ, นำส่งระหว่างกะ, เงินที่ควรมี/นับได้/ขาดเกิน, **เงินนำส่ง + เลขซอง**, **เงินทอนเก็บในลิ้นชัก**, ช่องเซ็น "ผู้ส่ง" "ผู้รับ (หัวหน้า)"
   ทั้ง Z บนเครื่อง Vue (receipt template) และหน้า Z ใน ERP (`zReport`)

## ไฟล์ที่เกี่ยว
ERP: `app/Http/Controllers/PosController.php` (openShift ~723, closeShift ~763, zReport ~959, cashMovementTotals ~1607), `app/Http/Controllers/Api/PosApiController.php` (shift endpoints), `pos_shifts`/`pos_terminals` migrations, หน้า pos-admin
Vue: `apps/pos-desktop/src/App.vue` modal `shift`, `closeShift`, Z print; `src/lib/*`
ตรวจ Vue: `cd apps/pos-desktop && ./node_modules/.bin/vue-tsc --noEmit` (node จาก `/Applications/Codex.app/Contents/Resources/cua_node/bin`) และ vitest ถ้ามี; `vite build` รันบนเครื่องนี้ไม่ได้ ไม่ต้องรัน
Vue รองรับ ERP เก่า/ใหม่ได้ (ถ้า API ไม่มี carried_float ให้ทำงานแบบเดิม)

## เทสต์ (ต้องผ่าน `php -d memory_limit=2G vendor/bin/phpunit`)
- ยกยอด: กะ1 ปิด float_kept 2,000 → กะ2 carried_float 2,000; เปิดด้วย 1,950 ไม่มีเหตุผล = 422, มีเหตุผล = ผ่าน + variance −50 ใน Z
- deposit = counted − float_kept ทุกสตางค์ (เช่น 15,432.75 − 2,000 = 13,432.75), counted < float, float_kept > counted = 422
- blind: API ปิดกะไม่ส่ง expected ก่อนยืนยันสำหรับแคชเชียร์; ผู้จัดการเห็น
- ไม่มีการเชื่อ expected/deposit จาก client (ส่งค่าปลอมแล้วต้องถูกคำนวณทับ)
- กระทบยอด: `uat:reconcile` ยังผ่าน, สมุดเงินสดไม่นับ float เป็นรายได้และไม่นับซ้ำข้ามกะ
- Z แสดงทุกช่องข้อ 6
- ถอดโค้ดหลักชั่วคราวแล้วเทสต์แดง
- `enforcedCashierId()` อ่าน request() ตัวกลาง — เทสต์ API ต้อง `$this->app->instance('request', $request)` (ดู CLAUDE.md)

## ส่งมอบ
สรุป + สเปก API สำหรับ Python POS ลง `docs/ai/task.md`, ภาพหน้าจอ Vue ถ้าทำได้, commit + push
