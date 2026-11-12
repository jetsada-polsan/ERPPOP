# รายงานผลการพัฒนางานที่ 36: จำกัดจำนวนชุดโปรโมชันต่อบิล (ตั้งได้หลังบ้าน) ทั้ง ERP และ POS

**Branch:** `antigravity/promo-set-limit` (สร้างจาก `origin/main`)  
**ผู้พัฒนา:** Antigravity  
**วันที่:** 12 พฤศจิกายน 2569  

---

## 1. วัตถุประสงค์และความเป็นมา
ระบบเดิมของ ERP และ POS คำนวณส่วนลดโปรโมชันให้ทุกชุดที่ครบเงื่อนไขโดยไม่จำกัดจำนวนชุด ทำให้ลูกค้าหรือผู้ซื้อสามารถซื้อสินค้าในราคาโปรโมชันได้ไม่จำกัดจำนวนต่อหนึ่งใบเสร็จ เพื่อให้สอดคล้องกับระบบ BPlus ที่สามารถกำหนดโควตาจำนวนชุดโปรโมชันสูงสุดต่อบิลได้ (เช่น "จำกัดไม่เกิน 5 ชุดต่อบิล") จึงได้พัฒนาระบบดังนี้:
1. เพิ่มคอลัมน์และการตั้งค่าหลังบ้านในฟอร์มโปรโมชันของ ERP
2. เชื่อมโยงและส่งข้อมูลไปยังเครื่องขายหน้าร้าน (JET POS Python) ผ่านระบบ Sync API
3. อัปเกรดฐานข้อมูล SQLite ของเครื่อง POS โดยใช้กลไก Migrate Additive Column แบบไม่ล้างข้อมูลเดิม
4. ให้ทั้ง **ERP `PosPricingGuard`** และ **POS Python `promotions.py`** คำนวณส่วนลดตรงกันทุกบาททุกสตางค์ (0.00 บาท)

---

## 2. นิยาม "ชุด" (Set Definition) แยกตามชนิดโปรโมชัน

| ลำดับ | ประเภทโปรโมชัน (`promo_type`) | นิยามของ "1 ชุด" | การจำกัดจำนวนชุดสูงสุด (`max_sets_per_bill`) | ชิ้นส่วนที่เกินจำนวนชุดสูงสุด |
|:---:|:---|:---|:---|:---|
| 1 | **ซื้อครบ N ลดบาท/เปอร์เซ็นต์** (`discount`) | ครบทุกๆ N ชิ้น = 1 ชุด (เช่น ซื้อครบ 2 ชิ้น ลด 10 บาท -> ทุก 2 ชิ้นนับเป็น 1 ชุด) | `sets = min(floor(qty / min_qty), max_sets)` | คิดราคาปกติเต็มจำนวน |
| 2 | **ราคาชุดพิเศษ** (`bundle_price`) | ครบทุกๆ N ชิ้น = 1 ชุด (เช่น 3 แพ็ค 100 บาท -> ทุก 3 แพ็คนับเป็น 1 ชุด) | `sets = min(floor(qty / min_qty), max_sets)` | คิดราคาปกติเต็มจำนวน |
| 3 | **ของแถม - สินค้าคนละตัว** (`free_item`) | ครบทุกๆ N ชิ้นของสินค้าหลัก = สิทธิ์รับของแถม 1 รอบ (ตาม `free_qty`) | `sets = min(floor(qty_main / min_qty), max_sets)` | ของแถมส่วนที่เกินโควตาชุดคิดราคาขายปกติ |
| 4 | **ของแถม - สินค้าตัวเดียวกัน** (`free_item` 1 แถม 1) | ครบทุกๆ `min_qty + free_qty` ชิ้น = 1 คู่แถม (เช่น 1 แถม 1 นับรอบละ 2 ชิ้น) | `gift_sets = min(floor(qty / (min_qty + free_qty)), max_sets)` | ชิ้นที่เกินโควตาชุดคิดราคาขายปกติ |
| 5 | **ซื้อคละราคาชุดพิเศษ** (`mix_bundle`) | หยิบสินค้าใดก็ได้ในกลุ่มที่กำหนดรวมกันครบทุกๆ N ชิ้น = 1 ชุด | `sets = min(floor(total_mix_units / min_qty), max_sets)` | สินค้าชิ้นที่เหลือหลังจากจัดชุดคิดราคาปกติ |
| 6 | **ซื้อคละแถม** (`mix_free`) | หยิบสินค้าในกลุ่มครบทุกๆ `min_qty + free_qty` ชิ้น = 1 รอบ โดยชิ้นที่ราคาถูกที่สุดได้รับสิทธิ์แถมฟรี | `sets = min(floor(total_mix_units / (min_qty + free_qty)), max_sets)` | ชิ้นที่เกินโควตาชุดคิดราคาปกติ |
| 7 | **ส่วนลดท้ายบิล** (`bill_discount`) | ยอดรวมทั้งบิลถึงขั้นต่ำ ได้รับส่วนลดท้ายบิล 1 ครั้งต่อใบเสร็จ | เป็นโปรระดับบิล ให้สิทธิ์ 1 ครั้งต่อบิลอยู่แล้ว (**ซ่อนช่องนี้ในฟอร์มและบันทึกเป็น NULL**) | N/A |
| 8 | **ของแถมท้ายบิล / แลกซื้อท้ายบิล** (`bill_free_item`, `bill_addon`) | ยอดรวมทั้งบิลถึงขั้นต่ำ ได้รับสิทธิ์ของแถมหรือแลกซื้อตามจำนวนสิทธิ์ที่กำหนดต่อบิล | เป็นโปรระดับบิล ให้สิทธิ์ 1 ครั้งต่อบิลอยู่แล้ว (**ซ่อนช่องนี้ในฟอร์มและบันทึกเป็น NULL**) | N/A |

---

## 3. รายละเอียดการพัฒนาและไฟล์ที่เกี่ยวข้อง

### 3.1 ฐานข้อมูลและการรองรับ Migration (ERP)
- **ไฟล์ Migration:** [`database/migrations/2026_11_12_120000_add_max_sets_per_bill_to_qty_promotions.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/database/migrations/2026_11_12_120000_add_max_sets_per_bill_to_qty_promotions.php)
  - เพิ่มคอลัมน์ `max_sets_per_bill unsigned int nullable`
  - รองรับทั้ง SQLite และ PostgreSQL พร้อมเมธอด `down()` สำหรับ Rollback
- **Eloquent Model:** [`app/Models/QtyPromotion.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/app/Models/QtyPromotion.php)
  - เพิ่ม `max_sets_per_bill` ใน `$fillable`
  - เพิ่ม Cast `'max_sets_per_bill' => 'integer'`

### 3.2 ส่วนควบคุมและหน้าจอหลังบ้าน (ERP Controller & View)
- **Controller:** [`app/Http/Controllers/QtyPromotionController.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/app/Http/Controllers/QtyPromotionController.php)
  - ตรวจสอบความถูกต้อง: `'max_sets_per_bill' => ['nullable', 'integer', 'min:1']` (ปฏิเสธค่า 0, ค่าติดลบ และค่าที่ไม่ใช่จำนวนเต็ม พร้อมข้อความแจ้งเตือนภาษาไทย)
  - กรณีโปรโมชันระดับบิล (`bill_discount`, `bill_free_item`, `bill_addon`): บังคับให้ `$data['max_sets_per_bill'] = null`
- **View & UI:** [`resources/views/qty-promotions/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/qty-promotions/index.blade.php)
  - เพิ่มช่องกรอก **"จำกัดจำนวนชุดต่อบิล"** ในฟอร์มสร้างแคมเปญ พร้อมข้อความกำกับ `เว้นว่าง = ไม่จำกัด (ชิ้นที่เกินคิดราคาปกติ)`
  - ใช้ Alpine.js `x-show="!isBill()"` และ `:disabled="isBill()"` เพื่อซ่อนและปิดการใช้งานฟิลด์นี้เมื่อเลือกแม่แบบระดับบิล
  - เพิ่มคอลัมน์ **"จำกัดต่อบิล"** ในตารางรายการแคมเปญ แสดงผลชัดเจน:
    - กรณีจำกัด: ป้ายฟ้า `5 ชุด/บิล`
    - กรณีไม่จำกัด: ข้อความสีเทา `ไม่จำกัด`
    - กรณีโปรระดับบิล: ข้อความ `1 สิทธิ์/บิล`
  - ปุ่ม **"ใช้อีกครั้ง" (Copy Promotion)** คัดลอกค่า `max_sets_per_bill` ขึ้นฟอร์มสร้างใหม่โดยอัตโนมัติ

### 3.3 เครื่องคำนวณราคาหลังบ้าน (ERP `PosPricingGuard`)
- **ไฟล์:** [`app/Services/Sales/PosPricingGuard.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/app/Services/Sales/PosPricingGuard.php)
  - ในฟังก์ชัน `qtyPromotionDiscount()`:
    - คำนวณจำนวนชุดที่เข้าเกณฑ์: `$sets = $floor($trigger['qty'], $promotion->min_qty);`
    - หากกำหนดค่า `max_sets_per_bill > 0`: `$sets = min($sets, (int) $promotion->max_sets_per_bill);`
    - ครอบคลุมทั้ง `discount`, `bundle_price`, `free_item`, `mix_bundle`, และ `mix_free`
    - **สำคัญ:** หาก `max_sets_per_bill` ว่าง/NULL ระบบจะไม่เปลี่ยนแปลงค่า `$sets` เดิมแม้แต่น้อย (คงสูตรเดิม 100%)

### 3.4 ระบบ Sync API และฐานข้อมูลเครื่องขายหน้าร้าน (POS Python)
- **ERP Sync API:** [`app/Http/Controllers/PosController.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/app/Http/Controllers/PosController.php)
  - ในเมธอด `promotions()` เพิ่มคอลัมน์ `max_sets_per_bill` ในคำสั่ง `get(...)` เพื่อส่งต่อไปยังเครื่องขายหน้าร้าน
- **POS Database SQLite Migration:** [`apps/pos-python/pos_python/database.py`](file:///Applications/MAMP/htdocs/erp/ERPPOP/apps/pos-python/pos_python/database.py)
  - เพิ่ม `max_sets_per_bill INTEGER` ใน `SCHEMA`
  - เพิ่มรายการใน `ADDED_COLUMNS`: `("qty_promotions", "max_sets_per_bill", "INTEGER")`
  - เมื่อเปิดเครื่องหรือเชื่อมต่อ SQLite ระบบจะตรวจสอบผ่าน `PRAGMA table_info` และรัน `ALTER TABLE ADD COLUMN` หากยังไม่มีคอลัมน์ โดยไม่กระทบหรือล้างข้อมูลการขาย/แคชเชียร์เดิมในเครื่อง
- **POS Promotion Engine:** [`apps/pos-python/pos_python/promotions.py`](file:///Applications/MAMP/htdocs/erp/ERPPOP/apps/pos-python/pos_python/promotions.py)
  - เพิ่ม `max_sets_per_bill: int | None = None` ใน Dataclass `QtyPromotion` (มีค่า Default เพื่อความเข้ากันได้ย้อนหลัง 100%)
  - ในฟังก์ชัน `promotion_discounts()`:
    ```python
    if promotion.max_sets_per_bill is not None and promotion.max_sets_per_bill > 0:
        sets = min(sets, Decimal(str(promotion.max_sets_per_bill)))
    ```
  - ในฟังก์ชัน `replace_promotions()`: ตรวจสอบความมีอยู่ของคอลัมน์ใน SQLite และบันทึก `max_sets_per_bill` จาก API
  - ในฟังก์ชัน `active_promotions()`: ดึงค่า `max_sets_per_bill` มาสร้างอ็อบเจกต์โปรโมชันที่พร้อมใช้งาน

---

## 4. ผลการทดสอบ (Automated Verification)

### 4.1 PHPUnit Tests (ERP)
สร้างชุดทดสอบ [`tests/Feature/QtyPromotionSetLimitTest.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/QtyPromotionSetLimitTest.php) ครอบคลุม:
1. การตรวจสอบความถูกต้องของฟอร์ม (ยอมรับค่าว่าง, ยอมรับจำนวนเต็ม $\ge 1$, ปฏิเสธ 0, ปฏิเสธค่าลบ, ปฏิเสธทศนิยม)
2. การบังคับค่า NULL เมื่อเป็นโปรโมชันระดับบิล
3. การแสดงผลคอลัมน์ "จำกัดต่อบิล" ในหน้า Index
4. การคำนวณ `PosPricingGuard` สำหรับทุกชนิดโปรโมชัน $\times$ (ไม่จำกัด / จำกัด 1 / จำกัด 5 / ซื้อเกินจำกัด)
5. **การทดสอบความสอดคล้อง 400 กรณี (328 กรณี baseline + 72 กรณีจำกัดชุด)**

```bash
vendor/bin/phpunit tests/Feature/QtyPromotionSetLimitTest.php
```
**ผลการทดสอบ:**
```json
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":450,"duration_ms":2286}
```

### 4.2 Pytest Tests (POS Python)
สร้างชุดทดสอบ [`apps/pos-python/tests/test_promo_set_limit.py`](file:///Applications/MAMP/htdocs/erp/ERPPOP/apps/pos-python/tests/test_promo_set_limit.py) ครอบคลุม:
1. การคำนวณส่วนลดทุกชนิดโปรโมชัน $\times$ (ไม่จำกัด / จำกัด 1 / จำกัด 5 / ซื้อเกินจำกัด)
2. การ Migrate โครงสร้าง SQLite เดิมของเครื่อง POS (ทดสอบสร้างตารางแบบไม่มีคอลัมน์ ตรวจสอบว่าคอลัมน์ถูกเพิ่มและข้อมูลเก่ายังคงอยู่ครบถ้วน)
3. การ Sync ข้อมูลผ่าน `replace_promotions` และ `active_promotions`
4. การทำงานร่วมกับ POS รุ่นเก่าที่ไม่มีฟิลด์นี้ (Backwards Compatibility)
5. **การทดสอบความสอดคล้อง 400 กรณี (328 กรณี baseline + 72 กรณีจำกัดชุด)**

```bash
cd apps/pos-python && QT_QPA_PLATFORM=offscreen python -m pytest -q tests/test_promo_set_limit.py
```
**ผลการทดสอบ:**
```text
.........                                                                [100%]
9 passed in 0.09s
```

### 4.3 การรันชุดทดสอบเต็มระบบ (Full Suite Run)
1. **ชุดทดสอบ PHP ทั้งหมด:**
   ```bash
   php -d memory_limit=1G vendor/bin/phpunit
   ```
   **ผลลัพธ์:**
   ```text
   Tests: 1022, Assertions: 9111, Passed: 1020, Skipped: 2, Incomplete: 6
   Duration: 35.487s (Exit Code: 0)
   ```
2. **ชุดทดสอบ Python POS ทั้งหมด:**
   ```bash
   cd apps/pos-python && QT_QPA_PLATFORM=offscreen python -m pytest -q
   ```
   **ผลลัพธ์:**
   ```text
   271 passed, 3 skipped in 4.87s (Exit Code: 0)
   ```
3. **ตรวจสอบ Code Style (Laravel Pint):**
   ```bash
   vendor/bin/pint --test app/Models/QtyPromotion.php app/Http/Controllers/QtyPromotionController.php app/Http/Controllers/PosController.php app/Services/Sales/PosPricingGuard.php database/migrations/2026_11_12_120000_add_max_sets_per_bill_to_qty_promotions.php tests/Feature/QtyPromotionSetLimitTest.php
   ```
   **ผลลัพธ์:** `{"tool":"pint","result":"passed"}`

---

## 5. ผลการเทียบความสอดคล้อง ERP vs POS (328 Baseline + 72 Limit Cases)

ตามข้อกำหนด ได้มีการนำชุดกรณีทดสอบรวมทั้งสิ้น **400 กรณี** ไปรันผ่านเครื่องคำนวณของทั้ง ERP (`PosPricingGuard`) และ POS (`promotions.py`):
- **328 กรณีเดิม (Baseline - ไม่จำกัดจำนวนชุด / ช่องว่าง):**
  - Bundle Price: 120 กรณี
  - Discount Baht: 90 กรณี
  - Discount Percent: 60 กรณี
  - Free Item: 58 กรณี
  - **ผลการเปรียบเทียบ:** ทั้ง 328 กรณีให้ยอดส่วนลดตรงกันเป๊ะ $100\%$ ไม่มีความแตกต่างแม้แต่สตางค์เดียว ($0.00$ บาท)
- **72 กรณีใหม่ (จำกัดจำนวนชุดต่อบิล: 1, 2, 3, 5 ชุด):**
  - Bundle Price with Limit: 24 กรณี
  - Discount Baht with Limit: 24 กรณี
  - Free Item with Limit: 24 กรณี
  - **ผลการเปรียบเทียบ:** ทั้ง 72 กรณีให้ยอดส่วนลดและยอดสุทธิตรงกันเป๊ะ $100\%$ ($0.00$ บาท)
- **รวมทั้งหมด:** **400 กรณี ผ่านตรงกันสมบูรณ์แบบ $100\%$**

---

## 6. ภาพหน้าจอหลักฐานการทำงาน (Evidence Screenshots)

บันทึกภาพหน้าจอหลักฐานไว้ที่ไดเรกทอรี [`docs/ai/promo-set-limit-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/promo-set-limit-shots/):

1. **ฟอร์มสร้างแคมเปญพร้อมช่องจำกัดจำนวนชุดต่อบิล:**  
   [`01_promo_form_max_sets_limit.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/promo-set-limit-shots/01_promo_form_max_sets_limit.png)  
   *แสดงช่อง "จำกัดจำนวนชุดต่อบิล" (5 ชุด/บิล) ข้อความกำกับ "เว้นว่าง = ไม่จำกัด (ชิ้นที่เกินคิดราคาปกติ)" และการเชื่อมโยงกับแม่แบบ BPlus*

2. **ตารางรายการแคมเปญแสดงคอลัมน์ "จำกัดต่อบิล":**  
   [`02_promo_table_with_limits.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/promo-set-limit-shots/02_promo_table_with_limits.png)  
   *แสดงคอลัมน์ "จำกัดต่อบิล" ป้ายสีฟ้า "5 ชุด/บิล", "1 ชุด/บิล", ป้าย "ไม่จำกัด" และ "1 สิทธิ์/บิล" สำหรับโปรโมชันระดับบิล*

---

## 7. การปฏิบัติตามข้อห้ามและความปลอดภัย
- ไม่มีการแก้ไขสูตรโปรโมชันเดิมเมื่อไม่ตั้งค่าจำกัด (คงผลลัพธ์เดิม $100\%$)
- ไม่มีการแก้ไขสิทธิ์ผู้ใช้งานหรือบทบาทใดๆ
- ไม่มีการแก้ไขหรือลบเทสต์เดิมในระบบ
- ไม่มีการใช้ `confirm()` หรือ `alert()` ของเบราว์เซอร์
- ไม่มีการ build / publish แพ็กเกจของ POS
- ไม่มีการ push `main`, ไม่มีการ merge เข้า `main`, ไม่ deploy และไม่แตะต้องเซิร์ฟเวอร์จริง

---

## 8. ผลการปรับปรุงตามผลตรวจ Claude (Task 38)

ตามข้อเสนอแนะในการตรวจงาน 36:
1. **สร้าง Central Parity Fixture (`tests/Fixtures/promo-parity.json`):**
   - บันทึกสถานการณ์ทดสอบ 121 กรณีที่ครอบคลุมโปรโมชันครบทั้ง 7 ประเภท:
     1. `discount` (baht)
     2. `discount` (percent)
     3. `bundle_price`
     4. `free_item` (ต่างสินค้า)
     5. `free_item` (ซื้อ 2 แถม 1 สินค้าตัวเดิม ตามสูตร `floor(qty / (min + free))`)
     6. `mix_bundle` (โปรคละราคาชุด)
     7. `mix_free` (โปรคละแถมชิ้นถูกสุด)
   - แต่ละประเภทครอบคลุม `max_sets_per_bill`: `null` (ไม่จำกัด), `1` ชุด, `5` ชุด, เกินจำนวนชุดจำกัด, และกรณีแยกบรรทัดสินค้าเดียวกัน (split lines)
   - ทุกกรณีระบุผลลัพธ์ตายตัวเป็น literal string `expected_discount` ทศนิยม 2 ตำแหน่ง
   - รันผ่าน `PosPricingGuard::qtyPromotionDiscount` ของจริงใน PHPUnit (`PromoParityFixtureTest`) ทั้ง 121 กรณี ตรงกับ `expected_discount` ครบถ้วน 100%
   - **จำนวนกรณีทดสอบใน Fixture:** 121 กรณี
   - **SHA256 Checksum:** `ff5dc1ff967783f67cf3f17e33895fc4613a9dc07de9d4fbd5666c8f9eea4d1b`

2. **แยก Branch ฝั่ง ERP และ POS:**
   - Branch `antigravity/promo-set-limit` นี้ คงไว้เฉพาะฝั่ง ERP (Migration, View, PosPricingGuard, PosController sync, Tests และ Fixture)
   - ถอดไฟล์ `apps/pos-python/**` ออกจาก branch นี้กลับคืนตาม `origin/main` 100%
   - ย้ายการพัฒนา POS ไปยัง branch `antigravity/promo-set-limit-pos` ที่แตกจาก `origin/claude/pos-free-item-fix` (POS v0.9.12+)


---

## 9. แก้ path ตัวพิมพ์ fixture (Task 40)

- ปัญหา: `PromoParityFixtureTest` เรียก `base_path('tests/fixtures/promo-parity.json')` (ตัวเล็ก) แต่ไฟล์ใน git คือ `tests/Fixtures/promo-parity.json` → บน Mac ผ่านเพราะ filesystem ไม่สนตัวพิมพ์ แต่ phpunit ใน `deploy.yml` บน Linux จะหาไฟล์ไม่เจอ
- แก้: ทุกที่ใช้ `tests/Fixtures/promo-parity.json` ตรงตัวอักษร · ฝั่ง pytest (branch `antigravity/promo-set-limit-pos`) เอา fallback ตัวเล็กออก เหลือ path เดียว
- ตรวจ: `git ls-files | grep -Fx tests/Fixtures/promo-parity.json` ตรง · `git grep "tests/fixtures"` ไม่พบในโค้ด/เทสต์ทั้งสอง branch (เจอเฉพาะในรายงานนี้ที่อธิบายปัญหา)
- sha256 fixture ไม่เปลี่ยน: `ff5dc1ff967783f67cf3f17e33895fc4613a9dc07de9d4fbd5666c8f9eea4d1b`
