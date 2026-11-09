# สรุปงานปรับปรุงโครงสร้างเมนู แถบด้านบน และหน้าตั้งค่าค่าคงที่ระบบ ERP
**สาขา (Branch):** `antigravity/erp-nav-settings` (แตกจาก `origin/main` `759b092`)  
**วันที่ดำเนินการ:** 9 พฤศจิกายน 2026  
**สถานะผลทดสอบ:** ผ่านทั้งหมด `868 tests` (`866 passed`, `2 skipped`, `0 failed`, `7,387 assertions`)

---

## 1. ภาพรวมการเปลี่ยนแปลง (แบ่งตาม 3 Commits ตามโจทย์)

### Commit 1 — โครงหน้าจอ (`refactor(ui): unify top navigation, lock logo position, and compact header with user dropdown`)
1. **รวมเมนูหลักเหลือชุดเดียว (Single Unified Navigation)**:
   - ใช้ **แถบเมนูบน (`.odn-topbar`)** สำหรับเลือกหมวดหลัก 7 หมวด และ **เมนูย่อยด้านซ้าย (`.odn-side`)** สำหรับแสดงเฉพาะรายการในหมวดที่กำลังเลือก
   - ถอดแถบไอคอนซ้ำซ้อนด้านซ้ายสุด (`.fa-rail`) ออก และปรับโหมดการแสดงผลให้ใช้โครงสร้างชุดเดียวทั้งหมด เพื่อไม่ให้มีเมนูหลัก 2 ชุดแย่งพื้นที่กัน
2. **ล็อกตำแหน่งโลโก้ 1 จุด ไม่ขยับทุกหน้าจอ (Fixed Brand Slot)**:
   - กำหนดกล่องโลโก้ `.odn-brand-slot` ที่มุมซ้ายบนของ `.odn-topbar` ด้วยขนาดคงที่ (`width: var(--odn-side-w, 232px); min-width: var(--odn-side-w, 232px); max-width: var(--odn-side-w, 232px); height: 52px; flex: 0 0 var(--odn-side-w, 232px);`)
   - โลโก้อยู่ใน `.odn-logo-box` ขนาดคงที่ `34×34px` (`object-fit: contain`) ไม่ขยับแม้แต่ 1px เมื่อสลับหมวดเมนู เลื่อนหน้าจอ หรือพับ/กางเมนูซ้าย
3. **แถบเมนูด้านบนลื่นไหล ไม่กระตุก (Zero Layout Shift Topbar)**:
   - ความสูงแถบบนคงที่ `52px` (`--odn-top-h: 52px`) ตัวอักษร `14px` ไอคอน `16px`
   - ใช้ `font-weight: 600` คงที่ทุกสถานะ (ปกติ / ชี้เมาส์ / เลือกอยู่) เพื่อป้องกันความกว้างปุ่มเปลี่ยนตอนคลิก
   - แสดงสถานะเมนูที่เลือกด้วยเส้นใต้สีหลัก (`border-bottom: 2px solid var(--erp-primary)`) และสีพื้นอ่อน `var(--erp-primary-soft)` แทนกล่องสีเทาทึบ (`#eaedf1`) พร้อม Transition `130ms ease`
4. **ย่อแถบหัวหน้าจอ (`.app-header`) ให้กะทัดรัด และย้าย "คู่มือ 4M" เข้าเมนูผู้ใช้**:
   - แถบหัวหน้าจอเหลือเฉพาะ: **ชื่อหน้าปัจจุบัน** + **ช่องค้นหาด่วน** + **กระดิ่งแจ้งเตือน** + **ปุ่มไอคอนผู้ใช้ (`#erp-user-menu-btn`)**
   - เมื่อคลิกไอคอนผู้ใช้ จะเปิดเมนูย่อย (`#erp-user-dropdown`) แสดง: ชื่อผู้ใช้, บทบาท, ชื่อบริษัท, ลิงก์ **"คู่มือ 4M"**, ปุ่ม **"การแสดงผลหน้าจอ"** (`#erp-display-btn`) และปุ่ม **"ออกจากระบบ"**

---

### Commit 2 — จัดหมวดเมนู (`refactor(menu): consolidate master data and integrations under settings section`)
1. **ลดหมวดเมนูหลักบนแถบบนเหลือ 7 หมวด**:
   - `หน้าหลัก` (`ภาพรวม`)
   - `ขาย/เอกสาร` (`งานประจำวัน`)
   - `POS / หน้าร้าน` (`POS / หน้าร้าน`)
   - `สินค้า/คลัง` (`คลัง / ผลิต / ซื้อ`)
   - `การเงิน/บัญชี` (`การเงิน / บัญชี`)
   - `รายงาน` (`รายงาน`)
   - **`ตั้งค่า` (`ระบบ`)**
2. **ย้าย "ข้อมูลหลัก" และ "เชื่อมต่อภายนอก" เข้าไว้ใต้หมวด `ตั้งค่า` พร้อมจัดกลุ่มย่อย (`group`)**:
   - **กลุ่มข้อมูลหลัก**: ค่าคงที่ (`basic-master.index`), ตารางราคา (`price-tables.index`), บัตรส่วนลด (`discount-cards.index`), แต้มสะสม (`member-points.index`), กฎข้อมูลและเอกสาร (`settings.module-controls`), ศูนย์ข้อมูล (`master-data.index`), นำเข้าข้อมูล (`imports.index`)
   - **กลุ่มเอกสาร**: เล่มเอกสาร (`document-books.index`), ตั้งค่าใบเสร็จ (`receipt-settings.index`), ออกแบบฟอร์มเอกสาร (`print-templates.index`)
   - **กลุ่มผู้ใช้และสิทธิ์**: ผู้ใช้และสิทธิ์ (`users.index`), ประวัติการแก้ไข (`audit-logs.index`)
   - **กลุ่มเชื่อมต่อภายนอก**: ซิงค์ Bplus (`bplus.sync`), ตรวจสอบข้อมูล Bplus (`bplus.reconcile`), ข้อมูลอ้างอิงภาษี (`bplus.tax`), ตาชั่งดิจิตอล (`scales.index`), เชื่อมต่อเครื่องชั่ง (`scale-integration.index`), ยานพาหนะ (`fleet.index`)
   - **กลุ่มระบบ**: ตั้งค่าระบบ (`settings.index`), สำรองข้อมูล (`backups.index`)
3. **รักษาความเข้ากันได้ของ `menu_section_order` และ `RoutePermissions`**:
   - ชื่อ Route (`route name`) และสิทธิ์ (`RoutePermissions`) ทั้งหมดคงเดิม 100%
   - กรองค่า `menu_section_order` เดิมใน `AppSetting` ให้ตัดหมวดที่ยุบรวมแล้ว (`ข้อมูลตั้งต้น`, `เชื่อมต่อ`) ออกโดยอัตโนมัติ ไม่เกิด Error

---

### Commit 3 — หน้าตั้งค่า → ค่าคงที่ (`feat(settings): add comprehensive constants management with safe deactivate-on-delete`)
1. **ขยายหน้า `ตั้งค่า → ค่าคงที่` (`/basic-master/{type}`) ให้จัดการค่าคงที่และตัวเลือกในระบบครบ 9 หมวด**:
   - หมวดสินค้า (`product-categories`)
   - แผนกสินค้า (`product-departments`)
   - ยี่ห้อสินค้า (`product-brands`)
   - หน่วยนับสินค้า (`product-units`)
   - เขต/สายการขาย (`sales-areas`)
   - ประเภทสมาชิก (`member-types`)
   - สาขา (`branches`)
   - คลังสินค้า (`warehouses`)
   - พื้นที่เก็บสินค้า (`warehouse-locations`)
2. **รองรับค้นหา / เพิ่ม / แก้ไข / ลบอย่างปลอดภัย (Safe Deactivate-on-Delete)**:
   - เพิ่ม Migration `2026_11_09_100001_add_is_active_to_constant_tables.php` เพิ่มคอลัมน์ `is_active` (`boolean`, default `true`) ให้ตารางค่าคงที่ที่ยังไม่มี (`product_categories`, `product_departments`, `product_brands`, `product_units`, `member_types`, `warehouses`, `warehouse_locations`) รองรับทั้ง SQLite และ PostgreSQL
   - ปรับปรุง `SafeDelete::delete()` และ `ModuleControlController::destroyTaxonomy()`:
     - หากรายการค่าคงที่ **ยังไม่ถูกอ้างอิง** ในตารางใดเลย → ลบจริงออกจากฐานข้อมูลและบันทึก `AuditLog` (`master_deleted`)
     - หากรายการค่าคงที่ **ถูกใช้งานอยู่** (เช่น มีสินค้า ลูกค้า สมาชิก คลัง หรือเอกสารอ้างอิงอยู่) → **ห้ามลบจริง** แต่ระบบจะเปลี่ยนสถานะเป็น **`ปิดใช้งาน` (`is_active = false`)** อัตโนมัติ บันทึก `AuditLog` (`master_deactivated`) และแจ้งข้อความอธิบายสาเหตุให้ผู้ใช้ทราบอย่างชัดเจน

---

## 2. รายชื่อไฟล์ที่แก้ไขและเพิ่มใหม่

| ไฟล์ | รายละเอียด |
|---|---|
| `resources/views/layout.blade.php` | รวมเมนูหลักชุดเดียว, ล็อกตำแหน่งโลโก้ `.odn-brand-slot`, ย่อ `.app-header`, เพิ่มเมนูย่อยไอคอนผู้ใช้ `#erp-user-dropdown` (รวมคู่มือ 4M และการแสดงผลหน้าจอ) |
| `resources/views/partials/peak-style-ui.blade.php` | ปรับสไตล์ `.odn-topbar` ให้แสดงเส้นใต้สีหลัก (`border-bottom`) และไม่เปลี่ยน `font-weight` ตอนคลิก |
| `app/Support/ErpMenu.php` | ลดหมวดหลักเหลือ 7 หมวด และรวมข้อมูลหลัก + เชื่อมต่อภายนอกไว้ใต้ `ตั้งค่า` พร้อมป้ายกลุ่มย่อย |
| `app/Http/Controllers/SystemSettingController.php` | อัปเดต `defaultMenuOrder()` ให้ดึงรายการหมวดจาก `ErpMenu::all()` |
| `database/migrations/2026_11_09_100001_add_is_active_to_constant_tables.php` | เพิ่มคอลัมน์ `is_active` ให้ตารางค่าคงที่ |
| `app/Models/ProductCategory.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/ProductDepartment.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/ProductBrand.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/ProductUnit.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/MemberType.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/Warehouse.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Models/WarehouseLocation.php` | เพิ่ม `is_active` ใน `#[Fillable]` และ `casts()` |
| `app/Services/MasterData/SafeDelete.php` | เพิ่มชนิดค่าคงที่ครบ 9 หมวด และเปลี่ยนสถานะเป็น `is_active = false` + บันทึก `AuditLog` (`master_deactivated`) เมื่อลบค่าที่ถูกอ้างอิงอยู่ |
| `app/Http/Controllers/ModuleControlController.php` | ปิดใช้งาน (`is_active = false`) แทนการลบเมื่อหมวด/แผนก/ยี่ห้อถูกสินค้าอ้างอิงอยู่ |
| `app/Http/Controllers/BasicMasterController.php` | ขยายการจัดการค่าคงที่ครบ 9 หมวด พร้อมค้นหา เพิ่ม และแก้ไข |
| `resources/views/basic-master/index.blade.php` | ปรับปรุงหน้า `ค่าคงที่ระบบ` แสดงแท็บทั้ง 9 หมวด จำนวนรายการที่อ้างอิง สถานะใช้งาน/ปิดใช้งาน และฟอร์ม Modal |
| `tests/Feature/DashboardLayoutTest.php` | อัปเดตและเพิ่มการทดสอบโครงสร้างเมนูเดียวและเมนูย่อยผู้ใช้ |
| `tests/Feature/AppLauncherTest.php` | เพิ่มการทดสอบการจัดหมวดเมนู 7 หมวด และความเข้ากันได้ของ `menu_section_order` เดิม |
| `tests/Feature/BasicMasterTest.php` | เพิ่มการทดสอบ CRUD ค่าคงที่ทุกหมวด และการเปลี่ยนเป็นปิดใช้งานเมื่อลบรายการที่ถูกใช้งานอยู่ |
| `tests/Feature/ModuleControlTest.php` | ทดสอบว่าหมวดสินค้าที่ถูกใช้งานเมื่อกดลบจะเปลี่ยนเป็น `is_active = 0` |

---

## 3. รายการภาพหน้าจอ Before / After (`docs/ai/erp-nav-settings-shots/`)

### ภาพก่อนแก้ไข (Before — `1366×768` และ `1024×768`)
- `before_01_dashboard_1366.png`, `before_01_dashboard_1024.png`
- `before_02_bookings_1366.png`, `before_02_bookings_1024.png`
- `before_03_products_1366.png`, `before_03_products_1024.png`
- `before_04_reports_1366.png`, `before_04_reports_1024.png`
- `before_05_settings_1366.png`, `before_05_settings_1024.png`
- `before_06_constants_1366.png`, `before_06_constants_1024.png`

### ภาพหลังแก้ไข (After — `1366×768` และ `1024×768`)
- `after_01_dashboard_1366.png`, `after_01_dashboard_1024.png` — หน้าหลัก (เมนูชุดเดียว โลโก้ล็อกตำแหน่ง หัวหน้าจอกะทัดรัด)
- `after_02_bookings_1366.png`, `after_02_bookings_1024.png` — ขาย/เอกสาร (ใบจอง/ขายเชื่อ)
- `after_03_products_1366.png`, `after_03_products_1024.png` — สินค้า/คลัง (รายการสินค้า)
- `after_04_reports_1366.png`, `after_04_reports_1024.png` — รายงาน
- `after_05_settings_1366.png`, `after_05_settings_1024.png` — ตั้งค่า (แสดงเมนูซ้ายรวมกลุ่ม ข้อมูลหลัก / เอกสาร / ผู้ใช้และสิทธิ์ / เชื่อมต่อภายนอก / ระบบ)
- `after_06_constants_1366.png`, `after_06_constants_1024.png` — ตั้งค่า → ค่าคงที่ (ครบ 9 แท็บ แสดงสถานะใช้งาน/ปิดใช้งาน)
- `after_07_user_dropdown_1366.png`, `after_07_user_dropdown_1024.png` — เมนูย่อยเมื่อคลิกไอคอนผู้ใช้ (แสดงชื่อ บทบาท บริษัท คู่มือ 4M การแสดงผลหน้าจอ และออกจากระบบ)

### ภาพพิสูจน์ตำแหน่งโลโก้ไม่ขยับเมื่อสลับ 3 หมวดต่อเนื่อง (`1366×768`)
- `logo_step1_dashboard.png` (หมวดหน้าหลัก)
- `logo_step2_sales.png` (หมวดขาย/เอกสาร)
- `logo_step3_settings.png` (หมวดตั้งค่า)
