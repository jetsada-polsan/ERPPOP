# Codex: Python POS — เงินทอนลิ้นชัก/ยกยอด/นับไม่เห็นยอด/เงินนำส่ง/Z 2 ใบ (0.8.6)

Branch `claude/pos-python-settings-popup` ต่อจาก HEAD, commit เป็นขั้น push เมื่อผ่าน ห้าม deploy ห้ามแก้ PHP หรือ `apps/pos-desktop/`
ทุกครั้งที่รัน Qt ตั้ง `QT_QPA_PLATFORM=offscreen` ห้ามสร้าง/เขียน PIN/password/token
**เรื่องเงิน ห้ามพลาดแม้สตางค์เดียว** ใช้ Decimal เท่านั้น ห้าม float

ERP ฝั่งนี้ทำเสร็จแล้วใน branch `claude/shift-float` (PR ยังไม่ merge) — สเปก API อยู่ใน `docs/ai/shift-float-api-for-python.md`
อ้างอิงพฤติกรรม Vue ได้จาก `git show origin/claude/shift-float:apps/pos-desktop/src/App.vue` (modal `shift`, `closeShift`, Z)
และ ERP `git show origin/claude/shift-float:app/Http/Controllers/PosController.php` (openShift/closeShift/floatSettings)

## ต้องทำ (ใช้ TouchDialog/NumPad เดิม)
1. เปิดกะ: ดึง `carried_float`/`default_float` จาก `/api/pos/shift`; carried != null → ใส่เป็นค่าเริ่ม, ถ้ายอดนับต่างต้องกรอกเหตุผล (`opening_note`); carried == null → เสนอ default_float ไม่บังคับเหตุผล
2. ปิดกะแบบ blind: ห้ามแสดง expected ก่อนกรอกยอดนับ; ช่วยนับตามชนิดธนบัตร/เหรียญ (1000/500/100/50/20/10/5/2/1/0.50/0.25) หรือยอดรวม → เรียก `preview` → แสดง expected, ส่วนต่าง, เงินทอนเก็บ (แก้ได้ ≤ ยอดนับ, ค่าเริ่ม default_float), เงินนำส่ง + เลขซอง → ยืนยันปิดจริง; เปลี่ยนยอดใดๆ ต้องทิ้ง preview เก่า
3. ผู้มีสิทธิ์จัดการเงินสด (ERP บอกผ่าน flag) เห็น expected ได้ตามสเปก
4. เตือนนำส่งเมื่อเงินสดคาดว่ามีในลิ้นชัก ≥ `cash_warning_threshold`
5. Z พิมพ์ 2 ใบ ช่องครบตามสเปก (ยกมา+ส่วนต่างรับกะ, นำส่งระหว่างกะ, ควรมี/นับได้/ขาดเกิน, เงินนำส่ง+เลขซอง, เงินทอนเก็บ, ลายเซ็นผู้ส่ง/ผู้รับ)
6. ทำงานกับ ERP รุ่นเก่า (ไม่มี default_float) ได้แบบเดิม และ **ห้ามส่ง preview ไป ERP เก่า** (เสี่ยงปิดกะจริง) — ตรวจ capability ก่อน
7. ออฟไลน์: ปิดกะออฟไลน์ไม่ได้ preview จาก ERP → บอกชัดว่าต้องต่อเน็ตเพื่อปิดกะ (หรือทำตามที่ระบบเดิมรองรับ ห้ามคำนวณ deposit เองแล้วส่งเป็นค่าจริง)

## เทสต์
fake API: carried/null, เหตุผลบังคับ, blind ไม่รั่ว expected, preview แล้วเปลี่ยนยอดต้องขอใหม่, deposit ตรงสตางค์ (15,432.75−2,000=13,432.75), ERP เก่าไม่ส่ง preview, Z ครบ 2 ใบ
ถอดโค้ดหลักชั่วคราวแล้วเทสต์แดง; pytest ผ่านทั้งหมด; ภาพ offscreen 1024x768 ของเปิดกะ/ปิดกะ/Z ใน `docs/ai/pos-python-shots/shift-float/`
สรุปลง `docs/ai/task.md` commit + push
