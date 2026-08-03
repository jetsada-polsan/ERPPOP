# POPSTAR POS Desktop

แอปขายหน้าร้าน Windows แบบ offline-first ที่ใช้ ERP เดิมเป็นแหล่งข้อมูลจริงเรื่องราคา สต๊อก กะ และเลขบิล

## การทำงาน

1. ผู้ดูแลสร้าง Device Token ที่ `ERP > ตั้งค่า > โปรแกรมหน้าร้าน` และวาง Token ตอนเปิด POS ครั้งแรก
2. POS ดาวน์โหลดสินค้า ราคา โปรโมชัน และสต๊อกสาขาลง SQLite ในเครื่อง
3. พนักงานเข้าใช้ด้วยรหัสและ PIN จาก ERP แล้วเปิดกะ
4. ทุกการขายถูกเขียนลง `checkout_queue` ก่อนเสมอ จึงไม่หายเมื่อเน็ตหลุดหรือโปรแกรมปิด
5. เมื่อออนไลน์ POS ส่งบิลไป `/api/pos/checkout` พร้อม `Idempotency-Key` เดิม เซิร์ฟเวอร์จึงไม่สร้างบิลซ้ำแม้ส่งหลายครั้ง
6. ERP ตรวจราคาอีกครั้งก่อนตัดสต๊อก ถ้าราคาเปลี่ยน บิลจะค้างพร้อมเหตุผลเพื่อให้พนักงานตรวจสอบ

Device Token เก็บด้วย Windows Credential Manager ผ่าน Rust `keyring` ไม่ถูกเขียนลง SQLite

## ตำแหน่งข้อมูลและการติดตั้งรุ่น 1.4

รุ่นติดตั้งใหม่จะเตรียมพื้นที่ `D:\POPSTAR-POS\data` ก่อนเปิดฐานข้อมูล POS บน Windows โดย:

1. ตรวจว่าไดรฟ์ D: มีอยู่และสร้างโฟลเดอร์ข้อมูลได้
2. ย้ายฐานเดิมจากรุ่นก่อนอย่างปลอดภัย โดยเก็บโฟลเดอร์ C: เดิมเป็น `th.co.popstar.pos.c-backup-<timestamp>`
3. สร้าง Windows directory junction ให้ Tauri SQL ใช้ฐานข้อมูลบน D: โดยไม่ทำให้โค้ดหน้าขายเปิดคนละไฟล์
4. แสดงตำแหน่งฐานข้อมูลจริงและคำเตือนใน **ตั้งค่าเครื่อง > สุขภาพ POS Local**

หากไม่มีไดรฟ์ D: แอปจะยังเปิดได้ในโหมดสำรองที่ C: แต่จะแสดงคำเตือนและไม่ควรนำไปใช้งานจริงจนกว่าจะติดตั้งไดรฟ์ข้อมูล การย้ายจะไม่ลบฐานเดิมและต้องตรวจ `PRAGMA integrity_check` หลังเปิดรุ่นใหม่

ข้อมูลขายยังต้องส่งขึ้น ERP ผ่าน `checkout_queue` และต้องสำรองไป Server สำรอง/Google Drive เพิ่มเติม เพราะไดรฟ์ D: ไม่ใช่ Backup เพียงชุดเดียว

รายละเอียดตารางและคอลัมน์ของ SQLite ดูที่ [`docs/POS-LOCAL-SQLITE-STRUCTURE.md`](../../docs/POS-LOCAL-SQLITE-STRUCTURE.md)

ก่อนเปิดใช้งานเครื่องจริง ให้ทำตาม [`docs/POS-GO-LIVE-UAT.md`](../../docs/POS-GO-LIVE-UAT.md) และบันทึกผลแยกตามสาขา/เครื่อง POS

## พัฒนา

```bash
pnpm install
pnpm dev
pnpm test
pnpm tauri dev
```

## สร้างและปล่อยรุ่น Windows

1. สร้าง Tauri signing key ตามเอกสาร Tauri และเก็บ GitHub Secrets: `TAURI_SIGNING_PRIVATE_KEY`, `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`, `TAURI_UPDATER_PUBLIC_KEY`
2. รัน GitHub Actions `Build POPSTAR POS` พร้อมเวอร์ชัน เช่น `1.0.1`
3. ดาวน์โหลด artifact แล้วใช้ไฟล์ `*.nsis.zip` และข้อความใน `*.nsis.zip.sig`
4. ที่ `ERP > ตั้งค่า > โปรแกรมหน้าร้าน` อัปโหลด zip, เวอร์ชัน และลายเซ็น
5. POS ตรวจ `latest.json` ตอนเปิดและติดตั้งรุ่นใหม่อัตโนมัติ ลายเซ็นป้องกันไฟล์ถูกสับเปลี่ยนระหว่างทาง

ห้ามใช้ private signing key บนเซิร์ฟเวอร์หรือเครื่องแคชเชียร์ ให้เก็บใน GitHub Actions Secrets เท่านั้น
