# PopCentral POS (Python)

แอป POS หลักสำหรับ Windows แยกจาก ERP โดยสิ้นเชิง
ไม่มีส่วนใดเชื่อม PostgreSQL โดยตรง และคุยกับ Laravel ผ่าน POS API เท่านั้น

## ขอบเขต MVP

- SQLite local เปิด WAL เพื่อทนไฟดับและรองรับ backup ที่ถูกต้อง
- สินค้า, แคชเชียร์, กะขาย, บิล, รายการบิล, การชำระเงิน และ sync outbox
- UUID ต่อบิล: retry/sync ซ้ำได้โดยไม่เกิดบิลซ้ำ
- transaction เดียวสำหรับบิล, รายการ, payment และ outbox
- mock receipt เขียนเป็นไฟล์ text เพื่อทดสอบก่อนต่อ ESC/POS จริง
- PySide6 UI เป็น optional; core และ test รันได้ด้วย Python มาตรฐาน
- Laravel API client และ sync outbox ที่ใช้ `Idempotency-Key: sale_uuid`
- สินค้าชั่ง: PLU 6 หลัก + ยอดเงิน 6 หลัก + EAN check digit, คำนวณด้วย Decimal

## รัน core test

```bash
cd apps/pos-python
python3 -m unittest discover -s tests -v
```

## รันตัวอย่างขาย

```bash
python3 main.py --demo
```

บน Windows ไฟล์ SQLite และใบเสร็จอยู่ที่
`%LOCALAPPDATA%\\PopCentral\\POS` จึงไม่ถูกทับเมื่อติดตั้งหรืออัปเดตโปรแกรมใหม่
ส่วน macOS/Linux ใช้ `storage/` ใต้โมดูล ซึ่ง git ไม่เก็บ

## ติดตั้ง Windows UAT

GitHub Actions จะสร้าง `PopCentral-POS-UAT-<version>-setup.exe` เป็น artifact
สำหรับทดสอบติดตั้งเท่านั้น ไม่อัปโหลดทับ POS Vue/Tauri และยังไม่มี auto-update
หรือ code-signing. Windows SmartScreen อาจเตือนเพราะเป็นไฟล์ทดสอบที่ยังไม่ได้
เซ็นลายเซ็น ต้องดาวน์โหลดจาก GitHub Actions ของ repository นี้เท่านั้น

## โฟลว์เปิดเครื่องและเริ่มขาย

1. IT ผูก Device Token ครั้งเดียว โปรแกรม sync สาขา เครื่อง สินค้า ราคา และแคชเชียร์ลง SQLite
2. ครั้งถัดไปโปรแกรมเปิดหน้าขายทันที ไม่บังคับล็อกอินก่อนดูสินค้า ตะกร้า หรือยอดวันนี้
3. ปุ่มรูปเฟืองใช้ Local IT PIN; ครั้งแรกต้องยืนยันผู้ดูแล ERP เพื่อตั้ง PIN ของเครื่อง
4. แคชเชียร์ยืนยันรหัสและ POS PIN ตอนกด `เริ่มขาย` หรือ `รับชำระเงิน` เท่านั้น
5. ระบบจึงเปิดกะและผูกบิลถัดไปกับแคชเชียร์ สาขา และเครื่อง POS ที่ถูกต้อง
6. ถ้าออฟไลน์ ระบบใช้ข้อมูลแคชเชียร์ใน SQLite ตามอายุสิทธิ์ offline เดิม และส่ง audit ภายหลัง

การเปิดดูหน้าจอหรือรายงานไม่สร้างกะ ไม่ตัดสต็อก และไม่เขียนยอดขาย การบันทึกบิลยังคง
ต้องมีแคชเชียร์ที่ยืนยันตัวตนแล้วเสมอ

## กติกาก่อนเชื่อม ERP จริง

1. เรียก Laravel API ผ่าน HTTPS เท่านั้น ไม่ต่อ PostgreSQL โดยตรง
2. ใช้ device token และ UUID เป็น idempotency key
3. sync catalog, ตารางราคา, schedule และ PIN แคชเชียร์ลงเครื่องก่อนใช้งาน offline
4. barcode เครื่องชั่งต้อง parse ตาม profile ที่ ERP ส่งมา ไม่เดาจากเลข 13 หลัก
5. backup ต้องใช้ SQLite backup API หรือปิด connection/checkpoint WAL ก่อนคัดลอกไฟล์
6. ต้องเปิดกะขณะออนไลน์อย่างน้อยหนึ่งครั้ง เพื่อเก็บ `server_shift_id`; บิลที่ขาย offline จะยังไม่ sync หากไม่มีค่านี้

## สถานะ

เป็นช่องทาง POS หลักของ PopCentral แต่ต้องผ่าน Windows/hardware UAT และการติดตั้ง
บนเครื่องจริงก่อนเปิดขายเต็มรูปแบบ
