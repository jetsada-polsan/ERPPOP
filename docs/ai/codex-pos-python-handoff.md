# งานต่อสำหรับ Codex: JET POS Python (UAT)

เจ้าของอนุมัติหน้าตาแล้ว งานอยู่บน branch `claude/pos-python-settings-popup` (ต่อจาก `codex/jet-pos-python`)

## Claude แก้อะไรไป
1. `pos_python/ui.py` เคยมีโค้ดหน้าจอซ้อน 3 ชุด Python ใช้ชุดสุดท้ายซึ่งเก่า งานที่แก้ชุดแรกเลยไม่ทำงาน
   ตอนนี้เหลือชุดเดียว (ใหม่สุด) **ห้ามคัดลอกคลาสทั้งก้อนซ้ำอีก** แก้ที่เดิมเท่านั้น
2. หน้าตั้งค่าเป็น popup กลางจอ
3. หน้าขายเป็นแบบ JET POS (Vue) พอดีจอ 1024×768: แถบบนปุ่มลัด, การ์ดสินค้า, บิลมี − / + / ✕,
   ยอดรวมน้ำเงิน, ปุ่มรับเงินเขียว (F12), แป้นตัวเลขย้ายไปอยู่ใน popup "แก้จำนวน / ราคา / ส่วนลด"
4. เทสต์ `QT_QPA_PLATFORM=offscreen python3 -m unittest discover -s tests` ผ่าน 253 ข้อ

## ให้ Codex ทำ
1. checkout `claude/pos-python-settings-popup` ทำงานต่อบน branch นี้ (หรือ merge เข้า `codex/jet-pos-python`)
2. รันเทสต์ให้ผ่าน
3. build ตัวติดตั้ง UAT ด้วย workflow ตัวติดตั้ง Python POS แยกช่อง UAT ที่มีอยู่ (ไม่ทับ JET POS ตัว Vue)
4. ทดสอบบนเครื่อง Windows จอ 1024×768: เปิดกะ ขาย สแกน แก้จำนวน ส่วนลด รับเงิน พิมพ์ใบเสร็จ ปิดกะ
5. สรุปผลลง `docs/ai/task.md` แล้วแจ้งเจ้าของ

## ห้าม
- ห้าม deploy production ห้ามแตะ `apps/pos-desktop`
- ห้ามสร้าง PIN, password, device token
