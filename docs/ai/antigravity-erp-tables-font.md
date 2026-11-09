# สรุปงาน Task 7: ปรับฟอนต์ภาษาไทย (IBM Plex Sans Thai) + ตารางสไตล์ PEAK ทั้งระบบ

**สาขา (Branch):** `antigravity/erp-tables-font` (แตกจาก `origin/main` `1e79796`)

---

## 1. รายการที่ดำเนินการตามสเปก

### ขั้นที่ 1: ฟอนต์ภาษาไทยอ่านง่าย (IBM Plex Sans Thai)
- โหลดฟอนต์ **IBM Plex Sans Thai** น้ำหนัก `400, 500, 600, 700` จาก Google Fonts (`display=swap` พร้อม `preconnect`) ใน `<head>` ของ [`resources/views/layout.blade.php`](../../resources/views/layout.blade.php)
- อัปเดตตัวแปร `--erp-font-family` ทั้งใน `:root` และ `html[data-theme="clear"]` เป็น:
  ```css
  --erp-font-family: 'IBM Plex Sans Thai', 'Noto Sans Thai', 'Leelawadee UI', Tahoma, sans-serif;
  ```
- กำหนดตัวเลขในตาราง (`.text-end`, `.tabular-nums`, `tfoot`) ให้ใช้ `font-variant-numeric: tabular-nums;` เพื่อจัดหลักตัวเลข/ทศนิยมให้ตรงกันทุกแถว
- กำหนดขนาดตัวอักษรเนื้อตาราง `14px` และหัวตาราง `13px` น้ำหนัก `600`

### ขั้นที่ 2: ตารางสไตล์ PEAK (Clean Accounting Table) ทั้งระบบ
- เพิ่ม CSS ตารางส่วนกลางใน [`resources/views/partials/peak-style-ui.blade.php`](../../resources/views/partials/peak-style-ui.blade.php) ภายใต้ `@media screen` และ `body:not(.pos-body)` (ไม่กระทบหน้าพิมพ์ `@media print` และหน้า POS):
  - **กรอบนอก (`.table-responsive` / `.table`)**: การ์ดสีขาว `border-radius: 10px`, เส้นขอบ `#e2e8f0`, ไม่มีเส้นตั้งกั้นคอลัมน์ (`border-left: 0; border-right: 0;`), ตารางกว้างเลื่อนแนวนอนเฉพาะใน `.table-responsive`
  - **หัวตาราง (`thead th`)**: พื้นเทาอ่อน `#f8fafc`, ตัวอักษร `#475569`, `font-size: 13px`, `font-weight: 600` (ไม่หนาเกิน 600), ไม่ทำตัวพิมพ์ใหญ่ทั้งหมด (`text-transform: none`), เส้นคั่นล่าง `1px solid #e2e8f0`
  - **แถวข้อมูล (`tbody tr` / `td`)**: ความสูงขั้นต่ำ `48px`, `padding: 12px 16px`, เส้นคั่นแถวบาง `#eef2f6`, hover เป็นสีฟ้าอ่อนจาง `#f8fbff`, ปิดสีสลับแถว (zebra striping)
  - **คอลัมน์รหัส / เลขที่เอกสาร**: คอลัมน์แรกและลิงก์รหัสเอกสารแสดงสีหลัก `var(--erp-primary)` (ฟ้า MPM) น้ำหนัก `600` ไม่ขีดเส้นใต้
  - **Badge สถานะในตาราง (`.table .badge`)**: ทรงแคปซูล (`border-radius: 9999px`) ขนาด `12px` โทนสีพาสเทลอ่อน ตัวอักษรเข้ม:
    - ใช้งาน / Active (`.text-bg-success`, `.bg-success`): พื้นเขียวอ่อน `#dcfce7` ตัวเขียวเข้ม `#15803d`
    - ปิดใช้งาน / Inactive (`.text-bg-secondary`, `.text-bg-light`): พื้นเทาอ่อน `#f1f5f9` ตัวเทาเข้ม `#475569`
    - โทนสีสถานะอื่น (Primary / Info / Warning / Danger) ปรับเป็นโทนพาสเทลชุดเดียวกัน
  - **คอลัมน์ปุ่มจัดการ (Action)**: ชิดขวา ปุ่มทรงแคปซูลขนาดเล็ก (`height: 30px`, `border-radius: 9999px`) ระยะห่างสม่ำเสมอ ไม่ตกบรรทัด (`white-space: nowrap`)
  - **แถวว่าง ("ยังไม่มีข้อมูล")**: จัดกึ่งกลาง สีเทาอ่อน `#94a3b8` พร้อมไอคอนจางๆ

### ขั้นที่ 3: ตรวจสอบหน้าตั้งค่าและหน้าหลักของระบบ
- ตรวจสอบครบทั้ง 23 หน้า (ค่าคงที่ระบบทั้ง 9 แท็บ, สมุดเอกสาร, ตั้งค่า Workflow, ศูนย์ควบคุมโมดูล, ผู้ใช้และสิทธิ์, ผังองค์กร, LINE แจ้งเตือน, E-Commerce, ตั้งค่าระบบ, นโยบายขายติดลบ, Backup/Security, โครงสร้างฐานข้อมูล, ใบจอง, สินค้า, รายงาน) บนความละเอียด `1366` และ `1024`
- แก้ปัญหาการซ้อนทับในหน้าสมุดเอกสาร (ซ่อน inline edit form ที่ชนกับ `.d-flex` ตอนยังไม่กดแก้ไข และจัดความกว้างคอลัมน์ checkbox ไม่ให้เบียดปุ่มเพิ่มบนจอ 1366/1024) ด้วย CSS ใน `peak-style-ui.blade.php`

---

## 2. ไฟล์ที่แก้ไข (อยู่ในขอบเขตที่กำหนดเท่านั้น)
1. `resources/views/layout.blade.php` — เพิ่ม Google Fonts `IBM Plex Sans Thai` ใน `<head>` และอัปเดต `--erp-font-family`
2. `resources/views/partials/peak-style-ui.blade.php` — เพิ่ม CSS ตารางสไตล์ PEAK สำหรับ `.table` ทั้งระบบ
3. `docs/ai/erp-tables-font-shots/*` — ภาพหน้าจอ Before / After ความละเอียด `1366` และ `1024`
4. `docs/ai/antigravity-erp-tables-font.md` — เอกสารสรุปงานฉบับนี้

---

## 3. ภาพหน้าจอเปรียบเทียบ (Before / After)
บันทึกไว้ใน `docs/ai/erp-tables-font-shots/`:
- ค่าคงที่ระบบ (`product-categories`): `before_01_constants_1366.png` → `after_01_constants_1366.png`, `after_01_constants_1024.png`
- สมุดเอกสาร (`document-books`): `before_02_document_books_1366.png` → `after_02_document_books_1366.png`, `after_02_document_books_1024.png`
- ผู้ใช้และสิทธิ์ (`users`): `before_03_users_1366.png` → `after_03_users_1366.png`, `after_03_users_1024.png`
- ใบจอง (`bookings`): `before_04_bookings_1366.png` → `after_04_bookings_1366.png`, `after_04_bookings_1024.png`
- สินค้า (`products`): `before_05_products_1366.png` → `after_05_products_1366.png`, `after_05_products_1024.png`
- รายงาน (`reports`): `before_06_reports_1366.png` → `after_06_reports_1366.png`, `after_06_reports_1024.png`

---

## 4. ผลการทดสอบ (Tests)
- รันชุดทดสอบเดิมทั้งหมด (`php -d memory_limit=1G vendor/bin/phpunit`) ผ่านครบทุกข้อโดยไม่แก้ไขหรือลบ test เดิม
