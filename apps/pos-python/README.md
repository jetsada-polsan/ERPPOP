# POPSTAR Python POS (ทดลองแยก)

โมดูลทดลอง POS สำหรับ Windows ที่แยกจาก `apps/pos-desktop` โดยสิ้นเชิง
ไม่มีส่วนใดเชื่อม PostgreSQL โดยตรง และยังไม่กระทบ POS Vue/Tauri ที่ใช้อยู่

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

ไฟล์ SQLite และใบเสร็จทดลองอยู่ใต้ `storage/` ซึ่ง git ไม่เก็บ

## กติกาก่อนเชื่อม ERP จริง

1. เรียก Laravel API ผ่าน HTTPS เท่านั้น ไม่ต่อ PostgreSQL โดยตรง
2. ใช้ device token และ UUID เป็น idempotency key
3. sync catalog, ตารางราคา, schedule และ PIN แคชเชียร์ลงเครื่องก่อนใช้งาน offline
4. barcode เครื่องชั่งต้อง parse ตาม profile ที่ ERP ส่งมา ไม่เดาจากเลข 13 หลัก
5. backup ต้องใช้ SQLite backup API หรือปิด connection/checkpoint WAL ก่อนคัดลอกไฟล์
6. ต้องเปิดกะขณะออนไลน์อย่างน้อยหนึ่งครั้ง เพื่อเก็บ `server_shift_id`; บิลที่ขาย offline จะยังไม่ sync หากไม่มีค่านี้

## สถานะ

เป็น prototype สำหรับทดสอบ architecture เท่านั้น ยังไม่แจกติดตั้งหรือใช้ขายจริง
