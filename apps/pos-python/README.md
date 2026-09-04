# PopCentral POS (Python)

แอป POS หลักสำหรับ Windows แยกจาก ERP โดยสิ้นเชิง
ไม่มีส่วนใดเชื่อม PostgreSQL โดยตรง และคุยกับ Laravel ผ่าน POS API เท่านั้น

## ขอบเขต MVP

- SQLite local เปิด WAL เพื่อทนไฟดับและรองรับ backup ที่ถูกต้อง
- สินค้า, แคชเชียร์, กะขาย, เงินเข้าออกลิ้นชัก, บิล, รายการบิล, การชำระเงิน และ sync outbox
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

## SQLite offline-first

SQLite เป็นฐานข้อมูลประจำเครื่อง ไม่ใช่ฐานข้อมูลกลางแทน ERP จึงแบ่งข้อมูลเป็น 3 กลุ่ม:

- **ข้อมูลที่อ่านและขายต่อได้ออฟไลน์:** สินค้า/บาร์โค้ด/ราคา, รูปแบบเครื่องชั่ง,
  โปรไฟล์ใบเสร็จ, QR config, สาขา/เครื่อง, cashier ที่ได้รับ credential แล้ว,
  กะที่เปิดไว้ และรายงานยอดขายของเครื่อง
- **ข้อมูลที่บันทึกออฟไลน์แล้วส่งภายหลัง:** บิล, รายการสินค้า, การชำระเงิน,
  การยกเลิกบิล และ `auth_events_outbox` โดยใช้ UUID/idempotency ป้องกันการซ้ำ
- **ข้อมูลที่ต้องยืนยันกับ ERP:** การออก credential/PIN ใหม่, การเปิดกะ server,
  การเปลี่ยน master data และการตัดสินยอดรวมทุกสาขา

เมื่อเปิดโปรแกรมแบบออนไลน์จะ `ping → sync profile → sync catalog → sync cashiers`
ก่อนให้ cashier ยืนยัน PIN. ถ้าเครือข่ายล้มระหว่างทาง โปรแกรมสลับเป็น Offline Mode
และใช้ snapshot ล่าสุดใน SQLite โดยไม่ลบบิลเดิม. เมื่อเครือข่ายกลับ worker จะเปิด
connection ของตัวเองแล้วทำ `ping → sync master data → ส่งบิลและ audit ที่ค้าง`;
การ sync ซ้ำใช้ upsert และ idempotency จึงไม่สร้างบิลซ้ำ. กะที่เปิดตอนออฟไลน์จะถูก
เข้าคิวเปิดบน ERP ก่อน แล้วจึงส่งบิล เงินเข้าออก และปิดกะตามลำดับ.

ราคาขายตามเวลาเก็บใน `pos_price_schedules` ฝั่ง ERP และส่งล่วงหน้ามาพร้อม catalog
เช่น เริ่มพรุ่งนี้ 05:00 หรือ 12:00. POS แปลงเวลาเป็น UTC หลัง calibrate จาก ERP,
เก็บไว้ใน `price_versions` และเลือกช่วงราคาที่มีผล ณ ตอนกดสินค้า/สแกนบาร์โค้ด
จึงทำงานได้แม้ offline. หน้าสินค้าแสดงเวลาที่มีผลให้ IT ตั้งและยกเลิกได้ โดยไม่ต้อง
build โปรแกรมใหม่. เมื่อ reconnect จะ sync master ก่อน และ server ตรวจราคาซ้ำตอนรับบิล.

ตารางหลักของการทำงานแบบนี้คือ `local_cashiers`, `cashier_credential_history`,
`auth_events_outbox`, `sync_outbox`, `sync_state`, `sync_runs` และ `sync_logs`.
`sync_state` บอกสถานะล่าสุดแยกเป็นโปรไฟล์เครื่อง สินค้า/ราคา และผู้ใช้งาน POS;
รวมถึงคิวขายและคิว audit ที่ส่งกลับ server แล้วหรือยัง.
`sync_runs` เก็บประวัติแต่ละรอบเพื่อให้ IT ตรวจปัญหาได้โดยไม่ต้องต่อ SQLite เอง.
การ reset PIN บน ERP จะเพิ่ม `credential_version`: เครื่องที่ยังออฟไลน์ใช้ credential
เดิมได้จน `offline_valid_until`, และจะรับ PIN ใหม่เมื่อ reconnect แล้ว sync สำเร็จ.

หน้าต่างเริ่มขายแสดงรายชื่อแคชเชียร์ที่ Sync มาแล้ว เลือกชื่อและแตะ PIN บนแป้นตัวเลขได้
โดยไม่ต้องจำ username ส่วน IT ยังใช้ Device Token และ Local IT PIN แยกจากคนขาย

หน้าชำระเงินเลือกได้ระหว่าง `เงินสด` และ `โอน / QR` ค่า PromptPay มาจาก
`qr_payment_configs` ของ ERP และถูกแคชไว้ใช้ในเครื่อง QR แบบ dynamic ฝังยอดบิลและแสดง
บนจอเดียวกับการชำระ พร้อมเก็บ payload snapshot ต่อบิลเพื่อใช้ในใบเสร็จ แคชเชียร์ต้อง
ทำเครื่องหมายว่าได้ตรวจเงินเข้าครบก่อนระบบจะยอมออกบิลโอน

การเปิดดูหน้าจอหรือรายงานไม่สร้างกะ ไม่ตัดสต็อก และไม่เขียนยอดขาย การบันทึกบิลยังคง
ต้องมีแคชเชียร์ที่ยืนยันตัวตนแล้วเสมอ

## กติกาก่อนเชื่อม ERP จริง

1. เรียก Laravel API ผ่าน HTTPS เท่านั้น ไม่ต่อ PostgreSQL โดยตรง
2. ใช้ device token และ UUID เป็น idempotency key
3. sync catalog, ตารางราคา, schedule และ PIN แคชเชียร์ลงเครื่องก่อนใช้งาน offline
4. barcode เครื่องชั่งต้อง parse ตาม profile ที่ ERP ส่งมา ไม่เดาจากเลข 13 หลัก
5. backup ต้องใช้ SQLite backup API หรือปิด connection/checkpoint WAL ก่อนคัดลอกไฟล์
6. เปิดกะออนไลน์จะ sync ทันที; ถ้าเปิดกะออฟไลน์ ระบบจะเก็บ `shift_open` ไว้และเปิดกะบน ERP อัตโนมัติเมื่อ reconnect ก่อนส่งบิล

## สถานะ

เป็นช่องทาง POS หลักของ PopCentral แต่ต้องผ่าน Windows/hardware UAT และการติดตั้ง
บนเครื่องจริงก่อนเปิดขายเต็มรูปแบบ
