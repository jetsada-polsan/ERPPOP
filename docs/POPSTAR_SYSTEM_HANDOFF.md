# POPSTAR ERP / POS — System Handoff

เอกสารนี้สรุประบบสำหรับผู้พัฒนาใหม่ ใช้อ่านก่อนแก้โค้ดหรือเพิ่มโมดูล

ปรับปรุง: 22 ตุลาคม 2026

## 1. ภาพรวมสถาปัตยกรรม

ระบบแบ่งเป็น 3 ส่วนหลัก:

```text
ผู้ใช้หลังบ้าน / มือถือ / Web POS
              │
              │ HTTPS + Laravel Web/API
              ▼
ERP Server: Laravel 13 + PHP + PostgreSQL
              ▲
              │ HTTPS JSON API + Device Token
              │
POS Windows: Python + PySide6 + SQLite
```

### ระบบที่ใช้งานจริง

| ส่วน | เทคโนโลยี | ตำแหน่งใน repository | หน้าที่ |
|---|---|---|---|
| ERP Server | Laravel 13, PHP 8.3 production | `app/`, `routes/`, `resources/` | ระบบกลาง, master data, ขาย, stock, บัญชี, รายงาน |
| Database กลาง | PostgreSQL | Production server | เก็บข้อมูลจริงของทุกสาขา |
| POS Desktop | Python, PySide6, SQLite | `apps/pos-python` | โปรแกรมขาย Windows แบบ offline-first |
| Web POS Offline | Blade/JavaScript, PWA, IndexedDB | `public/pos-offline.js`, `public/pos-sw.js` | ขายสำรองเมื่อ Host/Internet ล่ม |
| Frontend assets | Vue 3, TypeScript, Vite, Tailwind CSS | `resources/js`, `resources/css` | Component และ UI บางส่วนของ ERP/Web POS |
| Barcode/Camera | `html5-qrcode`, `bwip-js` | `package.json`, `resources/js`, `public/` | อ่านกล้องและสร้าง barcode/label |
| Installer Windows | Inno Setup + GitHub Actions | `apps/pos-python/installer` | สร้างไฟล์ติดตั้ง POS |

### สิ่งที่ไม่ควรสับสน

- POS Desktop ปัจจุบันคือ `apps/pos-python` ไม่ใช่ Vue/Tauri
- `apps/pos-desktop` เป็น source เก่าสำหรับ rollback/เทียบ logic เท่านั้น ห้ามใช้เป็นช่องทาง POS หลักโดยไม่ได้รับอนุมัติ
- POS ห้ามต่อ PostgreSQL, Microsoft SQL หรือฐานข้อมูลกลางโดยตรง ต้องผ่าน Laravel API เท่านั้น
- ERP Production ใช้ PostgreSQL ไม่ใช่ Microsoft SQL
- Microsoft SQL/BPlus legacy ใช้เป็นแหล่งอ้างอิงแบบอ่านอย่างเดียว และห้าม `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`

## 2. เครื่องมือและคำสั่งพัฒนา

### ERP Laravel

```bash
composer install
php artisan test
php artisan erp:health
pnpm install --frozen-lockfile
pnpm run build
```

### POS Python

```bash
cd apps/pos-python
python3 -m unittest discover -s tests -v
python3 main.py --demo
```

บน Windows ใช้ GitHub Actions สร้าง `PopCentral-POS-UAT-<version>-setup.exe` สำหรับ UAT ก่อนใช้งานจริง

### หลักการ deploy

1. แก้โค้ดและทดสอบใน branch/main ตาม workflow ของทีม
2. Push ขึ้น GitHub
3. เรียก workflow `Deploy ERP` และเลือก `staging` หรือ `production`
4. Workflow จะ test, build asset, upload และเรียก deploy script
5. หลัง deploy ต้องตรวจ `erp:health` และ smoke test

ห้าม `git pull`, `reset`, `rsync --delete` หรือแก้ไฟล์ production ตรง ๆ โดยไม่ทบทวนก่อน
ห้ามใส่ password, private key, Device Token หรือ PIN ใน source, Markdown, Git หรือ log

## 3. โมดูล ERP

### ความปลอดภัยและผู้ใช้

- Login, session, เปลี่ยนรหัสผ่าน และ MFA
- Users, roles, permissions และ module control
- Audit log และ operation run
- Device/terminal และ Device Token สำหรับ POS
- Cashier identity และ PIN ตาม policy ของ POS

### Master Data

- สาขา, หน่วยงาน, คลัง และพื้นที่จัดเก็บ
- สินค้า, หน่วยสินค้า, barcode, ราคา และ scale profile
- ลูกค้า, ผู้ขาย, พนักงานขาย และพื้นที่ขาย
- ตารางราคา, ราคาตามเวลา, โปรโมชั่น, flash sale และบัตรส่วนลด
- สมุดเอกสารและเลขที่เอกสาร

### Sales / POS

- Quotation
- Booking / ใบจอง
- Cash sale / ขายสด
- Credit sale และ billing note
- Sale return / credit-debit note
- POS receipt, payment, void และ return
- Held bill, member, discount card และแต้ม

### Inventory / Warehouse

- รับซื้อ/รับสินค้า
- Purchase order
- โอนคลังและรับโอน
- Stock count, stock adjustment และ stock issue/damage
- Stock transform และ production stock
- Warehouse mobile สำหรับสแกนรับเข้า/ตรวจสินค้า
- สแกน barcode และบันทึก product scan log

### Accounting / Finance

- Chart of accounts
- GL journals และ posting จากเอกสาร
- Cash transfer, bank accounts และ cheque
- Accounting periods
- Monthly accounting
- Tax compliance
- Fixed assets และ depreciation
- Accounting automation และ approval rules

### Production / Fleet / CRM

- Production order และ production efficiency
- Fleet: รถ, เที่ยวขนส่ง, งานขนส่ง และค่าซ่อมรถ
- CRM activities/opportunities
- Members, member points และ point reconciliation
- LINE integration, webhook และ member portal

### OCR / Operations / Reports

- OCR เอกสารและรายการสินค้า
- Dashboard และ executive dashboard
- Reports และ report governance
- Operations: backup, restore verify, health check และ audit
- POS build/download center
- Settings, receipt template, QR payment และ system configuration

## 4. Database และเจ้าของข้อมูล

### PostgreSQL — แหล่งข้อมูลจริง

ข้อมูลต่อไปนี้ต้องตัดสินที่ ERP Server:

- สินค้า, barcode, ราคา และ master data
- Stock balance และต้นทุน FIFO/FEFO
- กะ, ใบเสร็จ, payment และเลขที่เอกสารจริง
- GL, VAT, ลูกหนี้/เจ้าหนี้ และรายงานรวมทุกสาขา
- โปรโมชั่นและสิทธิ์การใช้งาน

### SQLite — ฐานข้อมูลประจำเครื่อง POS

SQLite ไม่ใช่ฐานข้อมูลกลางและไม่ใช่แหล่งข้อมูลบัญชี ตารางหลักได้แก่:

- `app_state` — profile เครื่องและ session
- `products` — catalog/ราคา/barcode ที่ sync มา
- `promotions` — โปรโมชั่นที่ cache มาใช้ offline
- `offline_cashiers` — รายชื่อและ verifier สำหรับ offline
- `checkout_queue` — outbox บิลที่รอส่ง ERP
- `pos_sale_history` — ประวัติใบเสร็จในเครื่อง
- `sync_state`, `sync_runs`, `sync_logs` — สถานะและประวัติ sync ในรุ่นปัจจุบัน

Device Token ต้องเก็บใน Windows Credential Manager ไม่เก็บใน SQLite หรือ source code

## 5. Flow POS Desktop

### 5.1 เปิดโปรแกรม

1. โหลด local profile และ SQLite
2. ถ้ามี Internet เรียก `GET /api/pos/ping`
3. ตรวจ Device Token, สาขา, terminal, VAT, QR config, receipt template และ scale profile
4. Sync catalog, price schedule, promotion และ cashier ลง SQLite
5. ถ้าออนไลน์พร้อมขาย ให้แสดงสถานะ ERP connected
6. ถ้าเชื่อมไม่ได้ ให้ใช้ snapshot ล่าสุดและแสดง Offline Mode อย่างชัดเจน

`ping` ต้องผ่านก่อนจึงจะถือว่าเชื่อม ERP สำเร็จ การโหลดสินค้าไม่ผ่านต้องแสดงเป็น sync ไม่ครบ ไม่ใช่เหมารวมว่า API ใช้ไม่ได้

### 5.2 เลือกแคชเชียร์และเปิดกะ

1. โหลดรายชื่อจาก `GET /api/pos/cashiers`
2. เลือกชื่อคนขาย หรือยืนยัน PIN ตาม mode ที่ ERP กำหนด
3. เปิดกะด้วย `POST /api/pos/shift/open`
4. เก็บ `shift.id` ของ ERP เป็น server ID ใน SQLite
5. ทุกบิลต้องผูกกับสาขา, terminal, cashier และ shift

ความหมาย ID สำคัญ:

- `pos_shifts.cashier_id` = `salesmen.id` คนขาย
- `pos_receipts.cashier_id` = `users.id` บัญชีที่เรียก API
- คนขายจริงของใบเสร็จ = `pos_receipts.cashier_salesman_id`

ห้าม join `pos_shifts.cashier_id` กับ `pos_receipts.cashier_id` เพราะเป็นคนละความหมาย

### 5.3 เพิ่มสินค้า

#### สินค้าปกติ

- ยิง barcode แล้วเพิ่มจำนวน 1 หน่วย
- ยิงซ้ำจึงเพิ่มจำนวนตามกติกา
- ไม่ต้องเปิดหน้ากรอกน้ำหนักหรือจำนวนเองเป็นค่าเริ่มต้น
- ใช้ barcode/product ID ที่ sync จาก ERP

#### สินค้าชั่งน้ำหนัก

- 800/801 เป็น prefix ที่พบในระบบปัจจุบัน แต่ห้าม hardcode เป็นกฎเดียว
- ใช้ `scale_profiles` จาก ERP เป็นตัวกำหนด prefix, PLU, ความยาว, check digit และตำแหน่งน้ำหนัก/ราคา
- ถ้าสแกนฉลากจากตาชั่ง ระบบต้องอ่านสินค้าและน้ำหนักจาก barcode แล้วคำนวณยอดอัตโนมัติ
- ส่ง barcode ฉลากดิบไป ERP เพื่อให้ server ตรวจซ้ำ
- ใช้ `barcode_type: SCALE_WEIGHT`
- ถ้ากระดาษฉลากหมด ให้เลือกสินค้าชั่งจากหน้าจอแล้วกรอกน้ำหนักเอง
- `qty` ของสินค้าชั่งเป็นทศนิยมได้ เช่น `0.375`

กติกาสำคัญ: สินค้าปกติยิงแล้วขายเลย ส่วนสินค้าชั่งจะเปิดกรอกน้ำหนักเองเฉพาะกรณีเลือกสินค้าชั่งโดยไม่มี barcode ฉลาก

### 5.4 คิดเงินและบันทึกบิล

1. POS สร้าง UUID ของบิล
2. บันทึกบิล, รายการ, payment และ outbox ใน SQLite transaction เดียว
3. พิมพ์ใบเสร็จตามสถานะที่ระบบกำหนด
4. ถ้าออนไลน์ ส่ง `POST /api/pos/checkout`
5. ใช้ `Idempotency-Key` เดิมทุกครั้งที่ retry
6. ERP ตรวจราคา, stock, กะ, สิทธิ์, payment และลงบัญชี
7. สำเร็จแล้วเก็บ `receipt_no` ของ ERP ใน SQLite

วิธีชำระเงินที่รองรับ: เงินสด, โอน/QR, บัตรเครดิต, cheque และ mixed payment

### 5.5 Offline และ Sync

```text
สร้างบิล local
    │
    ├── ออนไลน์: ส่ง ERP ทันที
    │
    └── ออฟไลน์: pending → syncing → synced
                              └→ failed → retry ด้วย UUID เดิม
```

- Network error, 5xx และ 409 ให้ retry ตาม policy
- 422 ที่เป็น business rejection ห้าม retry ไม่จำกัด ให้เปลี่ยนเป็น `needs_review`
- บิล `needs_review` ต้องให้ผู้จัดการตรวจและแก้สาเหตุก่อนส่งใหม่
- ห้ามสร้าง UUID ใหม่ตอน retry เพราะจะเกิดบิลซ้ำ
- หลัง reconnect ต้อง sync master ก่อน แล้วส่งกะ, บิล, cash movement และ audit ตามลำดับ

## 6. Web POS Offline (PWA)

Web POS ใช้หน้าเว็บและ IndexedDB ไม่ต้องติดตั้งฐานข้อมูลแยก:

1. ออนไลน์: โหลดสินค้า/ราคา/โปรโมชั่นลง IndexedDB
2. Host ล่มหรือ Internet หลุด: Service Worker เปิดหน้า POS และค้นสินค้าจาก local cache
3. ขายได้ด้วย barcode/SKU จากข้อมูลล่าสุด
4. บิล offline ได้เลขชั่วคราว `OFF-...` และเข้า queue
5. เมื่อกลับออนไลน์ส่ง `POST /pos/offline-sales/sync`
6. ERP ใช้ checkout logic เดิม ตรวจราคา/stock/บัญชี และกันซ้ำด้วย `offline_uuid`
7. บิลที่รับไม่ได้อยู่หน้า `/pos/offline-sales` เป็น `needs_review` ห้ามทิ้งบิล

ข้อจำกัด: stock จะถูกตัดเมื่อบิลขึ้น ERP และหลายเครื่องที่ offline พร้อมกันจะไม่เห็น stock ของกันและกัน

## 7. Flow ขายหลังบ้านและคลัง

### ขาย

```text
Quotation → Booking (ถ้ามี) → Sale/Cash Sale
                              │
                              ├→ ตัด stock เมื่อยืนยัน
                              ├→ ลง sales posting / GL
                              └→ รับเงินหรือเปิดลูกหนี้
```

ใบเสนอราคาและใบจองไม่ควรตัด stock หรือรับรู้รายได้จนกว่าจะยืนยันเป็นการขายตาม policy

### ซื้อและรับสินค้า

```text
Purchase Order → รับสินค้า/ตรวจรับ → เพิ่ม stock + lot/cost
                                   └→ เจ้าหนี้/บัญชีตามเงื่อนไข
```

### โอนคลัง/นับ/ปรับปรุง

- ต้นทางขอโอน → อนุมัติ → ตัดต้นทาง → รับปลายทาง
- นับ stock แล้วสร้าง adjustment เฉพาะส่วนต่าง
- สินค้าชำรุด/ตัดออกต้องผ่านสิทธิ์และลงบัญชีตาม policy
- ต้องรักษา lot และต้นทุนที่ ERP เป็นผู้คำนวณ

### มือถือคลัง

- สแกน barcode/EAN-13 หรือค้น SKU เป็น fallback
- ตรวจสินค้า, หน่วย, lot และจำนวนก่อนบันทึก
- ถ้ากล้องอ่านเลขศูนย์นำหน้าไม่ครบ ให้คง barcode เป็น string ห้ามแปลงเป็นตัวเลข
- API เป็นผู้ตรวจสินค้าและสิทธิ์ ไม่ให้มือถือเขียนฐานข้อมูลโดยตรง

## 8. API สำคัญของ POS

ทุก endpoint ใต้ `/api/pos/*` ต้องใช้:

```http
Authorization: Bearer <DEVICE_TOKEN>
Accept: application/json
```

| งาน | Method | Endpoint |
|---|---:|---|
| ตรวจเครื่อง/สาขา/โปรไฟล์ | GET | `/api/pos/ping` |
| สินค้า ราคา barcode | GET | `/api/pos/products` |
| รายชื่อแคชเชียร์ | GET | `/api/pos/cashiers` |
| Login cashier | POST | `/api/pos/cashier/login` |
| เปิด/ปิดกะ | POST | `/api/pos/shift/open`, `/api/pos/shift/close` |
| เงินเข้าออก | POST | `/api/pos/shift/cash-movement` |
| ค้น/สร้างสมาชิก | GET/POST | `/api/pos/members` |
| ตรวจ/เพิ่มสินค้า scan | POST/GET | `/api/pos/scan`, `/api/pos/products` |
| ขาย | POST | `/api/pos/checkout` |
| ยกเลิกบิล | POST | `/api/pos/receipt/void` |
| รับคืน | POST | `/api/pos/receipt/return` |

เงินและจำนวนที่ส่ง API ให้ส่งเป็น decimal string เช่น `"35.00"`, `"0.375"` เพื่อไม่ให้ floating point ทำให้ยอดคลาดเคลื่อน

## 9. กติกาการเขียนโค้ด

- ตรวจสิทธิ์และ business rule ที่ Server เสมอ Client เชื่อไม่ได้
- ใช้ transaction สำหรับการบันทึกชุดข้อมูลที่ต้องสำเร็จพร้อมกัน
- ใช้ UUID/idempotency กับ checkout, shift และ movement
- ห้ามใช้ local SQLite ID แทน ERP server ID
- migration ต้องทดสอบทั้ง SQLite in-memory และ PostgreSQL
- อย่าถือว่า `cashier_id` ทุกตารางหมายถึงคนเดียวกัน
- ห้ามเก็บ password, token, PIN หรือ private key ใน source/log/เอกสารที่ commit
- อย่าอ่านไฟล์ backup ทั้งก้อนเข้า RAM; ใช้ streaming
- อย่าแก้ข้อมูล MSSQL legacy; ใช้ SELECT เท่านั้น
- ทุกฟีเจอร์ใหม่ต้องมี test ที่ตรงกับ flow และ error case

## 10. Checklist ก่อนส่งงาน

- [ ] อ่าน `AGENTS.md`, `docs/ai/PROJECT_MEMORY.md` และ `docs/ai/task.md`
- [ ] `git pull origin main` ก่อนเริ่ม
- [ ] ทดสอบ PHP: `php artisan test`
- [ ] ทดสอบ Python POS: `python3 -m unittest discover -s apps/pos-python/tests -v`
- [ ] ตรวจ syntax และ `git diff --check`
- [ ] ตรวจ migration บน PostgreSQL ก่อน production
- [ ] ไม่ใส่ secret ใน commit
- [ ] เขียน handoff พร้อม commit, test และความเสี่ยง
- [ ] Push ก่อนส่งต่องาน
- [ ] Deploy เฉพาะเมื่อเจ้าของอนุมัติ

## 11. เอกสารอ้างอิง

- POS API และ business rules: `docs/POS_DEVELOPER_HANDOFF.md`
- POS SQLite: `docs/POS-LOCAL-SQLITE-STRUCTURE.md`
- Web POS Offline: `docs/POS-WEB-OFFLINE.md`
- POS flow: `docs/pos-flow-redesign.md`
- Barcode/กล้อง/เครื่องชั่ง: `docs/QR-BARCODE-POS-GUIDE.md`
- Production operation: `docs/OPERATIONS.md`
- Backup/DR: `docs/BACKUP-DR-RUNBOOK.md`
