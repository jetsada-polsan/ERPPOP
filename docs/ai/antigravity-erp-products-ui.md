# สรุปการปรับปรุงหน้าสินค้า ERP `/products` (Antigravity ERP Products UI Upgrade)

**กิ่งการทำงาน (Branch):** `antigravity/erp-products-ui` (แตกจาก `origin/main`)
**วันที่ดำเนินการ:** 9 พฤศจิกายน 2026

---

## 1. สิ่งที่ปรับปรุง (UI & CSS Improvements)

แก้ไขเฉพาะไฟล์ `resources/views/products/index.blade.php` โดยอ้างอิงโครงสร้างตัวแปรและ route ที่มีอยู่จริงจาก `ProductController@index` และตรวจด้วย `php artisan route:list` ทุกจุด:

1. **แถบค้นหาและตัวกรองสินค้า (`.product-toolbar` & `.product-filter-bar`)**
   - แก้ปัญหา CSS Grid เดิมที่กำหนดไว้เพียง 4 คอลัมน์ (`grid-template-columns: minmax(300px,2fr) minmax(170px,1fr) minmax(170px,1fr) auto`) ทำให้เมื่อมีปุ่ม `ล้าง` (รายการที่ 5) ปุ่ม `ล้าง` ตกลงมาอยู่แถวที่ 2 และยืดเต็มความกว้างของคอลัมน์ค้นหา
   - ปรับเป็น 5 คอลัมน์ (`minmax(210px, 1.7fr) repeat(3, minmax(115px, 1fr)) auto`) บนหน้าจอ Desktop และย้ายข้อความคำแนะนำ (`#product-search-hint`) ออกมาไว้ด้านล่างแถวตัวกรอง ทำให้ช่อง `ค้นหาสินค้า`, `ประเภทสินค้า`, `รูปแบบการขาย`, `สถานะสินค้า`, และปุ่ม `ล้าง` เรียงอยู่ในระนาบเดียวกันพอดี
   - รองรับ Responsive บนหน้าจอ Tablet (`<= 1200px` แบ่งเป็น 2 คอลัมน์) และ Mobile (`<= 768px` เรียง 1 คอลัมน์)

2. **ขนาดตัวอักษร `>= 14px` และการอ่านง่าย (Readability)**
   - ปรับขนาดตัวอักษรทุกจุดให้ไม่ต่ำกว่า `14px` (หัวข้อตัวกรอง `14px`, ช่องกรอก/ตัวเลือก `15px`, ป้ายสถานะและบาร์โค้ด `14px`, หัวตาราง `14px`, เนื้อหาในตาราง `15px`, แถบสรุป PLU เครื่องชั่ง `14px–18px`, และฟอร์มใน Modal เพิ่มสินค้า `14px`)
   - ขยายขนาดหน้าต่าง Popup แฟ้มสินค้า (`.product-popup-window`) เป็น `min(1020px, calc(100vw - 32px)) × min(740px, calc(100vh - 32px))` เพื่อให้อ่านและแก้ไขข้อมูลสินค้าได้สะดวกทั้งบน Desktop และ Tablet

3. **การจัดชิดขวาสำหรับตัวเลข (`ตัวเลขชิดขวา`)**
   - คอลัมน์ `ราคาเริ่มต้น (บาท)` จัดชิดขวา (`text-end`) พร้อม `font-variant-numeric: tabular-nums` (`.product-num`) เพื่อให้หลักทศนิยมตรงกันทุกแถว
   - ช่องกรอกตัวเลขใน Modal เพิ่มสินค้าใหม่ (`จุดสั่งซื้อ`, `สต๊อกขั้นต่ำ`, `สต๊อกสูงสุด`, `ราคาเริ่มต้น`, `ราคาขายสูงสุด`, `กำไรขั้นต่ำ`, จำนวนวันเตือนหมดอายุ/ส่วนลดระบาย) จัดชิดขวา (`text-end`) ทั้งหมด

---

## 2. Route ที่ตรวจสอบและใช้งานจริง (`php artisan route:list`)

ใช้เฉพาะ route ที่มีอยู่จริงในระบบเท่านั้น:
- `route('products.index')` (`GET /products`)
- `route('products.store')` (`POST /products`)
- `route('products.show', $product)` (`GET /products/{product}`)
- `route('product-units.index')` (`GET /product-units`)
- `route('scale-prices.index')` (`GET /scale-prices`)

---

## 3. รายการไฟล์ที่แก้ไข (Modified Files)

- `resources/views/products/index.blade.php` *(แก้ไขเฉพาะ UI และ CSS ภายในไฟล์)*
- `docs/ai/erp-products-shots/*.png` *(ภาพหน้าจอก่อนและหลังแก้ไขบน Desktop และ Tablet)*
- `docs/ai/antigravity-erp-products-ui.md` *(เอกสารสรุปฉบับนี้)*

---

## 4. ผลการทดสอบ (`php artisan test` & `php artisan serve`)

1. **ผลการรันชุดทดสอบทั้งหมด (845 ข้อ):**
   ```text
   {"tool":"phpunit","result":"passed","tests":845,"passed":843,"assertions":7136,"duration_ms":30953,"skipped":2,"incomplete":6}
   ```
   - ผ่านครบทุกข้อ (**0 failed**, **7,136 assertions**) รวมถึง `Tests\Feature\ProductCatalogFilterTest` (3 tests, 34 assertions)

2. **ผลการทดสอบเปิดหน้า `/products` บน `php artisan serve` ในเครื่อง:**
   - ทดสอบเรียก `GET /products?category_id=&product_type=scale&q=&status=all` ผ่าน `php artisan serve` ได้สถานะ **HTTP 200 OK** แสดงผลตารางและตัวกรองครบถ้วนโดยไม่มีข้อผิดพลาด

---

## 5. ภาพหน้าจอก่อนและหลังแก้ไข (Before / After Screenshots)

บันทึกไว้ในโฟลเดอร์ `docs/ai/erp-products-shots/`:

| มุมมอง | ภาพก่อนแก้ไข (Before) | ภาพหลังแก้ไข (After) |
|---|---|---|
| Desktop (`1440×900`) | `before_products_desktop.png` | `after_products_desktop.png` |
| Tablet (`1024×768`) | `before_products_tablet.png` | `after_products_tablet.png` |

---

## 6. สิ่งที่ไม่ได้แตะต้องตามข้อกำหนดความปลอดภัย (Unchanged Scope)

- ไม่ได้แก้ไข Controller (`ProductController.php`), Model, Migration หรือ Logic คำนวณราคาและสต๊อก
- ไม่ได้ยุ่งกับกิ่ง `main` และไม่ได้เชื่อมต่อหรือคัดลอกไฟล์ไปยังเซิร์ฟเวอร์จริง (`27.254.143.219`)
