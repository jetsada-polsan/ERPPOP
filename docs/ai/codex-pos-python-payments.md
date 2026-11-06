# Codex: Python POS — วิธีชำระให้ครบเหมือน JET POS Vue (0.8.7)

Branch `claude/pos-python-settings-popup` ต่อจาก HEAD, commit เป็นขั้น push เมื่อผ่าน ห้าม deploy ห้ามแก้ PHP หรือ `apps/pos-desktop/`
ทุกครั้งที่รัน Qt ตั้ง `QT_QPA_PLATFORM=offscreen` ห้ามสร้าง/เขียน PIN/password/token **เรื่องเงินห้ามพลาด ใช้ Decimal**

ตอนนี้ Python มีแค่ เงินสด และ โอน/QR — Vue มี 5 แบบ (`apps/pos-desktop/src/App.vue` ~บรรทัด 860-900 และ ~1317):
`cash`, `transfer` (โอน/QR), `mixed` (เงินสด+โอน), `credit_card` (บัตรเครดิต), `cheque` (เช็ค)
ERP รับ `method in:cash,transfer,credit_card,cheque,mixed` (`app/Http/Controllers/PosController.php` checkout ~1058, ~1159-1180)

## กติกา (ตาม Vue + ERP ทุกข้อ)
- เงินสด: รับ ≥ ยอด, เงินทอน = รับ − ยอด
- โอน/QR และ จ่ายผสม: บังคับ `transfer_account_last4` 4 หลัก
- จ่ายผสม: เงินสด > 0 และ < ยอดบิล, ส่วนที่เหลือเป็นโอน; ส่ง `cash_amount`, `transfer_amount` = ยอด − เงินสด (ปัด 2 ตำแหน่ง Decimal), `change_amount` = 0
- บัตรเครดิต/เช็ค: ดูว่า Vue/ERP ต้องการ field อะไร (เลขอ้างอิง/เลขเช็ค ฯลฯ) แล้วทำตามนั้นเป๊ะ
- ใบเสร็จ: จำนวนใบตามกติกาเดิม (บิลมีโอน = 3 ใบ รวมสำเนาตรวจยอดโอน) แสดงวิธีชำระเป็นไทย
- ยอด Z/ปิดกะ แยกตามวิธีชำระถูกต้อง (cash/transfer/card/cheque; mixed แยกสองส่วน)
- ออฟไลน์: คิวบิลต้องเก็บ field ใหม่ครบ ส่งซ้ำได้ payload เดิม (idempotency เดิม)
- หน้าชำระเงิน: ปุ่มวิธีชำระ 5 ปุ่มใหญ่ พอดีจอ 1024x768 ไม่ซ้อน dialog

## เทสต์
ทุกวิธี + ขอบเขต (ผสมเงินสด 0 / เท่ายอด → ปฏิเสธ, last4 ผิด, ทศนิยม), payload ตรงกับที่ Vue ส่ง, Z แยกยอดถูก, คิวออฟไลน์
ถ้าทำได้ ต่อ e2e `apps/pos-python/e2e/` กับ Laravel ในเครื่อง (127.0.0.1 เท่านั้น) ให้ ERP รับทุกวิธีและยอดตรง
ถอดโค้ดหลักชั่วคราวแล้วเทสต์แดง; pytest ผ่านทั้งหมด; ภาพหน้าชำระเงินแต่ละวิธีใน `docs/ai/pos-python-shots/payments/`
สรุปลง `docs/ai/task.md` commit + push (ถ้า commit ไม่ได้ ให้เหลือไฟล์ไว้ใน workspace)
