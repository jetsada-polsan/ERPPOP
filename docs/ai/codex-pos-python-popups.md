# Codex: Python POS — ทำ popup ทุกตัวใหม่ให้เหมือน JET POS Vue (รอบ 0.8.2)

Branch: `claude/pos-python-settings-popup` (ต่อจาก HEAD), commit เป็นขั้น, push เมื่อเทสต์ผ่าน
ห้าม deploy ห้ามแก้ `apps/pos-desktop/` หรือ PHP ห้ามสร้าง/เขียน PIN/password/token

## ปัญหาที่เจ้าของเจอบนเครื่องร้านจริง (ทัชสกรีน ~1024x768)
popup ทุกตัวใน Python POS เป็นกรอบสี่เหลี่ยมเล็กๆ ไม่น่าใช้ ไม่มีแป้นตัวเลข ต้องใช้คีย์บอร์ด
เช่น เปิดกะใส่ PIN คนขาย, ปิดกะ ช่องเดียวใส่ตัวเลข ไม่มีข้อมูลอื่น

## ต้นแบบ = Vue
`apps/pos-desktop/src/App.vue` บรรทัด ~1132-1400 (ทุก `modal === '...'`) + `apps/pos-desktop/src/styles.css` (`.modal`, `.modal.compact`, `.modal-head`, `.pin-pad`, `.pin-dots`, `.modal-actions`)
ไล่ทำทุก modal ใน Vue ให้ Python มีครบ "ทั้งหน้าตาและเงื่อนไข":
cashier (PIN + pin-dots + pin-pad 3x4 ล้าง/0/ลบ, ครบ 4 หลักกดเข้าได้, พิมพ์คีย์บอร์ดได้, โหมด selection), selectCashier, changePin,
shift (เปิดกะ: เงินทอนเริ่มต้น + numpad + ปุ่มพิมพ์ Z ซ้ำ),
closeShift (เงินสดที่ควรมีจาก ERP `expected_cash` ใส่เป็นค่าเริ่ม, เงินสดที่นับได้จริง, ส่วนต่าง เกิน/ขาด แสดงสี, นำส่งเงินระหว่างกะ + เลขอ้างอิง + ปุ่มบันทึกนำส่ง, ห้ามปิดถ้ามีบิลรอส่ง, numpad),
closeApp, void/return (PIN หัวหน้า ใช้ pin-pad), weight, holdBill, heldBills, history, payment, Z report
ดู logic ใน `<script setup>` ของ App.vue ประกอบ (เช่น `closeShift`, `recordCashDrop`, `openShift`, `loginCashier`, `reconcileShiftWithErp`)
"เงินทอนส่งข้ามกะ": ตรวจว่า Vue/ERP มีการยกเงินทอนไปกะถัดไปไหม ถ้ามีให้ทำเหมือนกัน ถ้าไม่มีให้จดลง task.md ว่าไม่มีใน Vue/ERP

## กติกาออกแบบ
- คอมโพเนนต์กลางตัวเดียว (เช่น `TouchDialog` + `NumPad` + `PinPad`) ใช้ซ้ำทุก popup อย่าเขียนแยกทีละตัว
- การ์ดมุมโค้ง, header สีแบรนด์ + ไอคอน + บรรทัดรอง, ปุ่ม × ปิด, backdrop มืด (แบบ SettingsDialog ที่มีอยู่)
- compact กว้าง ~460-520px, ปุ่มแป้น ≥64x64px ตัวเลข ≥24px, ปุ่มยืนยัน ≥56px เต็มความกว้าง
- ช่องตัวเลขทุกช่องกดแล้วใช้ numpad บนจอได้ ไม่ต้องมีคีย์บอร์ด
- สีจาก `PALETTE` เท่านั้น (tests/test_ui_style.py)
- หน้าชำระเงิน: ฝั่งสรุปยอด/รายการโปรตัวหนังสือยังเล็กและชิด ขยายให้อ่านง่าย (≥16px, เว้นบรรทัด)

## เกณฑ์เสร็จ
- pytest ผ่านทั้งหมด + เทสต์ใหม่: pin-pad ใส่/ลบ/ล้าง, numpad ใส่ทศนิยม, closeShift คำนวณส่วนต่างและบล็อกเมื่อมีบิลรอส่ง, cash drop ส่ง API ถูก
- ภาพ offscreen 1024x768 ของทุก popup ใน `docs/ai/pos-python-shots/popups/` (ขยาย tools/capture_parity.py)
- ตาราง Vue modal → Python dialog → สถานะ ใน `docs/ai/task.md`
- commit + push
