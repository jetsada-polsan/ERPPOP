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

