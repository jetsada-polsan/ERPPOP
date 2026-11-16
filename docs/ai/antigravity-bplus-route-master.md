# รายงานสรุปงานที่ 46: ซิงค์ลูกค้าและสินค้าจาก BPlus มากับเอกสารสาย B18

> **Branch:** `antigravity/bplus-route-master` (แตกจาก `origin/claude/bplus-name-spaces`)  
> **ผู้ดำเนินการ:** Antigravity (Google DeepMind)  
> **วันที่:** 16 พฤศจิกายน 2026  
> **สถานะการทดสอบ:** PHPUnit ผ่านทั้งหมด 1,139 ข้อ (1,137 Passed, 2 Skipped, 6 Incomplete, 0 Failures, 0 Errors)

---

## 1. ที่มาและเป้าหมาย

ในการนำเข้าเอกสารสายการขาย **B18** จากระบบ BPlus เดิม พนักงานจะทำการบันทึกเอกสารใน BPlus และระบบ ERP จะนำเข้าสำเนาเอกสารเพื่อการตรวจสอบและออกรายงานบัญชี
ก่อนหน้านี้ เอกสารที่มีลูกค้ารายใหม่ หรือสินค้าที่ยังไม่มีในฐานข้อมูลของ ERP จะถูกข้ามหรือไม่ถูกผูกในเอกสาร ERP

งานที่ 46 ได้พัฒนาให้ตัวส่งออก (Exporter) ดึงข้อมูลลูกค้าและสินค้าที่เกี่ยวข้องติดมากับไฟล์ JSON และตัวนำเข้า (Importer) ทำการ upsert ข้อมูลลูกค้าและสินค้าลงใน ERP ก่อนที่ตัวแปลงเอกสาร (Materializer) จะทำงาน ทำให้เอกสารรอบเดียวกันสามารถจับคู่และแสดงข้อมูลได้อย่างสมบูรณ์

---

## 2. ไฟล์ที่แก้ไขและสร้างใหม่

1. **`scripts/bplus-route-export.py`**:
   - เพิ่มการดึงก้อน `"customers"` จาก `ARFILE`, `ARADDRESS`, `ADDRBOOK` โดยเลือกเฉพาะลูกค้าที่มีเอกสารในไฟล์นั้น ส่ง `AR_KEY`, `AR_CODE`, `AR_NAME`, เลขผู้เสียภาษี (`ADDB_TAX_ID`), ที่อยู่หลัก (`ARA_DEFAULT = 'Y'`), เบอร์โทร (`ADDB_PHONE`) และสถานะใช้งาน (`AR_ENABLE`)
   - เพิ่มการดึงก้อน `"products"` จาก `SKUMASTER` และ `UOFQTY` โดยเลือกเฉพาะ `sku_code` ที่อยู่ในบรรทัดเอกสาร ส่ง `SKU_CODE`, ชื่อสินค้า (`SKU_NAME`), และหน่วยหลัก (`UTQ_NAME`)
   - ใช้คำสั่ง SELECT แบบอ่านอย่างเดียว (`read_only=True`) ไม่แก้ไขข้อมูลใน BPlus และไม่เพิ่มข้อความถามรหัสผ่านใหม่
2. **`app/Services/Bplus/BplusSyncImporter.php`**:
   - ปรับปรุง `parse()` ให้รองรับก้อน `customers` และ `products` โดยยังคงรองรับไฟล์รุ่นเก่าที่ไม่มีก้อนนี้ (Backward Compatible)
   - เพิ่ม `previewMaster()` คำนวณจำนวนลูกค้าที่จะสร้างใหม่, ลูกค้าที่จะอัปเดตชื่อ, และสินค้าที่จะสร้างใหม่
   - ปรับปรุง `import()` ให้ทำ `upsertCustomers()` และ `upsertProducts()` ใน Database Transaction **ก่อน** บันทึกเอกสาร
   - **กฎการ upsert ลูกค้า:**
     - Key คือ `customers.code = AR_KEY`
     - หากยังไม่มี: สร้างใหม่ กำหนดสาขาตรงกับสาย B18 (จาก `sales_areas` หรือ fallback สาขาแรก) และ `is_active = true` พร้อมบันทึกที่อยู่ลง `customer_addresses` และเบอร์ลง `customer_contacts`
     - หากมีอยู่แล้ว: อัปเดตเฉพาะชื่อ (`name_th`), เลขภาษี (`tax_id`), ที่อยู่ และเบอร์โทร **ไม่แตะต้องวงเงิน (credit_limit), เครดิตเทอม (customer_terms), สาขา หรือราคา**
   - **กฎการ upsert สินค้า:**
     - Key คือ `products.sku_code`
     - หากยังไม่มี: สร้างใหม่ กำหนดหน่วยนับ (`base_unit_id`) และ **ปิดการขายหน้าร้าน POS ไว้ก่อน (`is_active = false`)** เพื่อใช้แสดงในเอกสารเท่านั้น
     - หากมีอยู่แล้ว: **ไม่แก้ไขข้อมูลใดๆ**
3. **`app/Services/Bplus/BplusDocumentMaterializer.php`**:
   - ปรับปรุง `check()` ให้รับพารามิเตอร์ `$incomingCustomers` และ `$incomingProducts` เพิ่มเติม
   - รายการลูกค้าที่ไม่พบ (`missing_customers`) และสินค้าที่ไม่พบ (`missing_products`) จะคัดกรองเฉพาะรายที่ไม่ได้อยู่ในฐานข้อมูล ERP **และไม่มีข้อมูลติดมาในไฟล์** เท่านั้น
4. **`app/Http/Controllers/BplusSyncController.php`**:
   - ส่ง `masterSummary` และข้อมูล `customers` / `products` ไปยัง `check()` ในเมธอด `preview()`
5. **`resources/views/bplus-sync/preview.blade.php`**:
   - เพิ่มบรรทัดแสดงข้อความ:  
     `จะสร้างลูกค้าใหม่ X ราย · อัปเดตชื่อ Y ราย · สร้างสินค้าใหม่ Z รายการ`
6. **`tests/Feature/BplusDocumentsTest.php`**:
   - เพิ่ม 4 ชุดทดสอบใหม่ครอบคลุมทุกเงื่อนไข:
     1. `test_new_customer_from_bplus_is_created_with_ar_key_as_code_and_linked_to_sale`
     2. `test_existing_customer_updates_name_and_tax_while_preserving_credit_limit_and_terms`
     3. `test_missing_product_co8110_is_created_and_shown_in_document_lines`
     4. `test_legacy_export_file_without_customers_or_products_works_identically`
7. **`phpunit.xml`**:
   - กำหนด `<ini name="memory_limit" value="512M"/>` เพื่อป้องกัน Memory Exhaustion เมื่อรันชุดทดสอบขนาดใหญ่ (1,139 ข้อ)
8. **`docs/ai/bplus-route-master-shots/bplus_preview_new_customers.png`**:
   - ภาพหน้าจอหลักฐานหน้าตรวจไฟล์ก่อนบันทึก แสดงข้อความ "จะสร้างลูกค้าใหม่ 39 ราย · อัปเดตชื่อ 12 ราย · สร้างสินค้าใหม่ 1 รายการ"

---

## 3. ตัวอย่างโครงสร้าง JSON (ก้อน customers และ products)

```json
{
 "route": "B18",
 "exported_at": "2026-11-16T14:15:00",
 "since": "2026-11-13",
 "documents": [
  {
   "bplus_key": 2714599,
   "kind": "credit_sale",
   "book_code": "DS",
   "ref": "DS256911/06364",
   "doc_date": "2026-11-15",
   "customer_key": 8049,
   "customer_code": "34140-0121",
   "customer_name": "ร้านเกษตรเพิ่มพูน",
   "total_amount": 1837.0,
   "lines": [
    {
     "seq": 1,
     "sku_code": "CO8110",
     "barcode": "CO8110",
     "unit_name": "ถุง",
     "unit_factor": 1.0,
     "qty": 3.0,
     "unit_price": 150.0,
     "amount": 450.0
    }
   ]
  }
 ],
 "customers": [
  {
   "ar_key": 8049,
   "ar_code": "34140-0121",
   "ar_name": "ร้านเกษตรเพิ่มพูน",
   "tax_id": "0345558001234",
   "address": "123 หมู่ 4 ต.ม่วงสามสิบ อ.ม่วงสามสิบ จ.อุบลราชธานี 34140",
   "phone": "0812345678",
   "is_active": true
  }
 ],
 "products": [
  {
   "sku_code": "CO8110",
   "name": "ข้อต่อท่อเกษตร CO8110",
   "unit_name": "ถุง"
  }
 ]
}
```

---

## 4. ผลการพิสูจน์ว่าเทสต์จับ Bug ได้จริง (Test Failure Verification)

ตามข้อกำหนด ได้ทำการทดสอบถอดโค้ด upsert ออกชั่วคราวเพื่อพิสูจน์ว่าชุดทดสอบจับข้อผิดพลาดได้จริง:

### 4.1 กรณีถอด `upsertCustomers` ออกชั่วคราว
เมื่อใส่ comment ที่บรรทัด `$this->upsertCustomers($parsed['customers'] ?? [], $branchId);` แล้วรันชุดทดสอบ:
```
$ php -d memory_limit=512M artisan test tests/Feature/BplusDocumentsTest.php

{"tool":"phpunit","result":"failed","tests":12,"passed":10,"assertions":79,"duration_ms":1460,"failed":1,"failures":[{"test":"Tests\\Feature\\BplusDocumentsTest::test_existing_customer_updates_name_and_tax_while_preserving_credit_limit_and_terms","file":"/Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/BplusDocumentsTest.php","line":264,"message":"Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n-'ชื่อใหม่จาก BPlus'\n+'ชื่อเดิมในระบบ'"}],"errors":1,"error_details":[{"test":"Tests\\Feature\\BplusDocumentsTest::test_new_customer_from_bplus_is_created_with_ar_key_as_code_and_linked_to_sale","file":"/Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/BplusDocumentsTest.php","line":219,"message":"No query results for model [App\\Models\\Customer]."}]}
```
- เทสต์ `test_new_customer_from_bplus_is_created_with_ar_key_as_code_and_linked_to_sale` ล้มเหลวทันที (Error 404 Model NotFound เพราะไม่มีการสร้างลูกค้า)
- เทสต์ `test_existing_customer_updates_name_and_tax_while_preserving_credit_limit_and_terms` ล้มเหลวทันที (ชื่อไม่ถูกอัปเดต)

### 4.2 กรณีถอด `upsertProducts` ออกชั่วคราว
เมื่อใส่ comment ที่บรรทัด `$this->upsertProducts($parsed['products'] ?? []);` แล้วรันชุดทดสอบ:
```
$ php -d memory_limit=512M artisan test tests/Feature/BplusDocumentsTest.php

{"tool":"phpunit","result":"failed","tests":12,"passed":11,"assertions":90,"duration_ms":1641,"errors":1,"error_details":[{"test":"Tests\\Feature\\BplusDocumentsTest::test_missing_product_co8110_is_created_and_shown_in_document_lines","file":"/Applications/MAMP/htdocs/erp/ERPPOP/tests/Feature/BplusDocumentsTest.php","line":329,"message":"No query results for model [App\\Models\\Product]."}]}
```
- เทสต์ `test_missing_product_co8110_is_created_and_shown_in_document_lines` ล้มเหลวทันที (Error Model NotFound เพราะสินค้า CO8110 ไม่ถูกสร้าง)

### 4.3 เมื่อคืนโค้ดกลับครบถ้วน
```
$ php -d memory_limit=512M artisan test tests/Feature/BplusDocumentsTest.php

{"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":95,"duration_ms":1491}
```
เทสต์ผ่านครบทั้ง 12 ข้อเป็นสีเขียว (Green) 100%

---

## 5. ผลการทดสอบทั้งหมด (Full Suite Execution)

```
$ php artisan test
{"tool":"phpunit","result":"passed","tests":1139,"passed":1137,"assertions":9972,"duration_ms":43008,"skipped":2,"incomplete":6}
```

- **ชุดทดสอบเต็มระบบ:** 1,139 รายการ (Passed 1,137, Skipped 2, Incomplete 6 เดิมของระบบ)
- **Zero Failures · Zero Errors**
- **ความเข้ากันได้ของ Migration:** รองรับทั้ง SQLite และ PostgreSQL
- **ไม่มีการแก้ไขชุดทดสอบเดิม (Preserved all existing tests)**
- **ไม่มีการแตะต้อง stock, GL หรือ POS**

---

## 6. หลักฐานภาพหน้าจอ (Screenshot Evidence)

ไฟล์ภาพหน้าจอถูกบันทึกไว้ที่:  
`docs/ai/bplus-route-master-shots/bplus_preview_new_customers.png`

แสดงผลหน้าตรวจไฟล์ BPlus ก่อนบันทึกที่มีข้อความ:
> **"จะสร้างลูกค้าใหม่ 39 ราย · อัปเดตชื่อ 12 ราย · สร้างสินค้าใหม่ 1 รายการ"**
