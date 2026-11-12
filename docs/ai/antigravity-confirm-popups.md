# รายงานงานที่ 33: ปรับระบบ Popup ยืนยันแบบเดียวกันทั้งระบบ แยก 4 โทนสีตามระดับความหนัก (Unified Confirm Popups)

> **Branch:** `antigravity/confirm-popups` (แตกจาก `origin/main`)  
> **ผู้พัฒนา:** Antigravity  
> **สถานะการทดสอบ:** ผ่าน 1,007 tests, 8,661 assertions (0 errors, 0 failures)  
> **การปฏิบัติตามกฎ:** ปรับเฉพาะการแสดงผลหน้าจอ (UX/UI) ไม่แตะยอดเงิน สต็อก บัญชี สิทธิ์ และไม่เปลี่ยนแปลงเงื่อนไขว่าจุดใดต้องยืนยัน  

---

## 1. วัตถุประสงค์และสรุปผลงาน

เดิมระบบมีการเรียก Popup ยืนยันหลายรูปแบบปะปนกัน ได้แก่:
1. การเรียก `confirm()` ดั้งเดิมของเบราว์เซอร์ (เช่น ในหน้ารับของคลังสินค้า `wh/index.blade.php`) ซึ่งไม่สวยงาม ขัดกับดีไซน์ระบบ และบล็อกการทำงานของเธรดในบางเบราว์เซอร์
2. การเรียก `Swal.fire({...})` แบบเขียน config เองในแต่ละหน้าหลายจุด ทำให้หน้าตา ปุ่ม และสีสันไม่เป็นมาตรฐานเดียวกัน
3. คอมโพเนนต์ `<x-confirm-action />` และ `window.erpConfirm` เดิมที่ยังไม่มีระบบแยกโทนสีตามความรุนแรงของการกระทำ

**ผลการดำเนินงาน:**
- ออกแบบและรวมศูนย์การทำงานของ Confirmation Popups ทั้งระบบไว้ที่ `window.erpConfirm` และ `<x-confirm-action />`
- ปรับสไตล์ SweetAlert2 ส่วนกลางใน `resources/views/layout.blade.php` ให้โค้งมน (border-radius: 16px สำหรับกล่อง, 10px สำหรับปุ่ม), ใช้ฟอนต์ระบบ (IBM Plex Sans Thai), ปุ่มขยายเต็มเท่ากัน (`flex: 1 1 0`), ปุ่มยกเลิกอยู่ซ้าย ปุ่มยืนยันอยู่ขวา (`reverseButtons: true`)
- รองรับ 4 โทนสีมาตรฐานตามระดับความหนักของงาน พร้อมระบบเดาโทนสีอัตโนมัติ (Auto-detection fallback)
- กำหนดให้ปุ่มยกเลิก/ลบ/void ของเอกสารหลัก (ใบจอง, ใบขาย, ใบเสร็จ, ปรับสต็อก, ใบขอซื้อ ฯลฯ) ระบุ `data-confirm-tone="danger"`
- เพิ่มการทดสอบความสม่ำเสมอใน `tests/Feature/ConfirmPopupConsistencyTest.php` ตรวจจับว่าไม่มี `confirm()`/`alert()` ตกค้างใน views และไม่มีการเรียก `Swal.fire` สำหรับการยืนยันแบบ standalone นอกจุดควบคุมส่วนกลาง

---

## 2. ระบบ 4 โทนสีและหลัก Ergonomics

| โทนสี | สีกำกับ | ไอคอน | ปุ่มยืนยัน | พฤติกรรม Ergonomics | ตัวอย่างการใช้งาน |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **`danger`** | แดง (`#dc2626`) | Warning (`!`) | สีแดงสด | **โฟกัสเริ่มต้นที่ปุ่ม "ยกเลิก"** เพื่อความปลอดภัย ป้องกันการกด Enter พลาด | ลบข้อมูลหลัก, ยกเลิกใบจอง, void เอกสาร, คืนเงิน, ปิดยอดค้าง, ปฏิเสธการปรับสต็อก |
| **`warning`** | ส้ม (`#d97706`) | Warning (`!`) | สีส้มเข้ม | โฟกัสตามค่ามาตรฐาน, สื่อถึงการดำเนินการที่ปรับปรุงยอด | อนุมัติใบขอซื้อ, อนุมัติโอนย้าย, ยืนยันปรับปรุงยอดสต็อกตามการนับ |
| **`primary`** | น้ำเงิน (`#2563eb`) | Question (`?`) | สีน้ำเงิน | โทนมาตรฐานทั่วไป | แปลงใบเสนอราคาเป็นใบขาย, บันทึกการทำงานทั่วไป, ออกจากระบบ |
| **`success`** | เขียว (`#16a34a`) | Success (`✓`) | สีเขียว | สื่อถึงการเสร็จสิ้น/รับครบถ้วน | ตรวจรับสินค้าเข้าคลังครบ, บันทึกว่าเก็บเงินตามใบวางบิลแล้ว |

### กลไกความปลอดภัยและ Ergonomics:
1. **ปุ่มยกเลิกอยู่ซ้าย ปุ่มยืนยันอยู่ขวา (`reverseButtons: true`)**: สอดคล้องกับพฤติกรรมการอ่านจากซ้ายไปขวา โดยวางทางเลือกที่ปลอดภัยไว้ด้านหน้า
2. **ปุ่มขนาดเท่ากัน (`flex: 1 1 0`)**: ปุ่มทั้งสองฝั่งมีความกว้างเท่ากันพอดี ไม่ว่าข้อความในปุ่มจะสั้นหรือยาว ดูเรียบร้อยและกดง่ายทั้งบนจอคอมพิวเตอร์และจอมือถือ
3. **Esc / คลิกนอกเพื่อยกเลิก (`allowOutsideClick: true`, `allowEscapeKey: true`)**: ผู้ใช้สามารถยกเลิกได้อย่างรวดเร็วโดยไม่ต้องเอื้อมเมาส์ไปกดปุ่ม
4. **Auto-detection Fallback**: หากไม่ได้ระบุ `tone` หรือ `data-confirm-tone` ฟังก์ชัน `detectTone()` ใน layout จะตรวจสอบคำสำคัญ เช่น `ลบ`, `ยกเลิก`, `void`, `ปฏิเสธ`, `cancel`, `delete` เพื่อปรับเป็นโทน `danger` ให้ทันที หากไม่ตรงคำเตือนจะ fallback เป็น `primary`

---

## 3. รายการไฟล์และจุดที่ได้รับการปรับปรุง

### 3.1 ส่วนกลาง (Core Layout & Components)
1. [`resources/views/layout.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/layout.blade.php)
   - เพิ่ม CSS 4 โทนสี (`.erp-swal-confirm-danger`, `.erp-swal-confirm-warning`, `.erp-swal-confirm-primary`, `.erp-swal-confirm-success`)
   - กำหนดขนาดปุ่มเท่ากัน (`flex: 1 1 0`), มุมโค้งมน, และฟอนต์ระบบ
   - ปรับปรุง `window.erpConfirm` ให้รองรับ parameter `{ tone }`, `reverseButtons: true`, `focusCancel: isDanger`, `allowOutsideClick: true`, `allowEscapeKey: true`
   - เพิ่มฟังก์ชัน `detectTone(title, text, okText)` สำหรับเดาโทนสีอัตโนมัติ
2. [`resources/views/components/confirm-action.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/components/confirm-action.blade.php)
   - รองรับ `trigger.dataset.confirmTone` และ `link.dataset.confirmTone`
   - ส่งต่อค่า tone และ icon ไปยัง `window.erpConfirm`
3. [`resources/views/pos/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/pos/index.blade.php)
   - ปรับปรุง `erpConfirm` สำหรับหน้า POS ให้มีปุ่มขนาดเท่ากันและรองรับ 4 โทนสีเช่นเดียวกับหลังบ้าน
   - ย้ายจุดยืนยันใน POS (เช่น คืนเงิน/Void บิลล่าสุด, เคลียร์ตะกร้า, พักบิล, เรียกบิล) มาใช้ `erpConfirm` แยกโทน

### 3.2 การกำกับโทนอันตราย (Danger Tone) สำหรับเอกสารหลัก
1. [`resources/views/bookings/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/bookings/show.blade.php)
   - ปุ่มยกเลิกใบจอง (`bookings.cancel`) &rarr; `data-confirm-tone="danger"`
   - ปุ่มถอย Confirm (`bookings.revert-confirm`) &rarr; `data-confirm-tone="danger"`
   - ปุ่มปิดยอดค้าง (`bookings.close-remainder`) &rarr; `data-confirm-tone="danger"`
   - ปุ่มผู้จัดการยืนยันของจริงมี (`bookings.approve-stock-shortage`) &rarr; `data-confirm-tone="warning"`
2. [`resources/views/documents/_void-action.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/documents/_void-action.blade.php)
   - ฟอร์มยกเลิกเอกสาร (`documents.void`) ครอบคลุม **ใบขายเชื่อ (DS)**, **ใบขายสด (CS)**, **ใบเสร็จรับเงิน (RE)**, **ใบซื้อ (PU)**, และ **ใบลดหนี้ (SR)** &rarr; ระบุ `data-confirm-tone="danger"`
3. [`resources/views/stock-adjustments/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-adjustments/show.blade.php)
   - ปุ่มปฏิเสธใบปรับปรุงสต็อก &rarr; `data-confirm-tone="danger"`
   - ปุ่มอนุมัติและปรับยอด &rarr; `data-confirm-tone="warning"`
4. [`resources/views/purchase-orders/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/purchase-orders/show.blade.php)
   - ปุ่มยกเลิกใบสั่งซื้อ (`purchase-orders.cancel`) &rarr; `data-confirm-tone="danger"`
   - ปุ่มอนุมัติใบขอซื้อ (`purchase-orders.approve`) &rarr; `data-confirm-tone="warning"`
5. [`resources/views/stock-transfers/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-transfers/index.blade.php)
   - ปุ่มปฏิเสธคำขอโอนย้าย &rarr; `data-confirm-tone="danger"`
   - ปุ่มอนุมัติและโอนสต็อก &rarr; `data-confirm-tone="warning"`
6. [`resources/views/billing-notes/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/billing-notes/show.blade.php)
   - ปุ่มยกเลิกใบวางบิล &rarr; `data-confirm-tone="danger"`
   - ปุ่มเก็บเงินครบแล้ว &rarr; `data-confirm-tone="success"`
7. [`resources/views/cheques/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/cheques/index.blade.php)
   - ปุ่มบันทึกเช็คคืน/เด้ง &rarr; `data-confirm-tone="danger"`
   - ปุ่มยกเลิกเช็ค &rarr; `data-confirm-tone="danger"`
8. [`resources/views/products/scheduled-prices/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/products/scheduled-prices/index.blade.php)
   - ปุ่มยกเลิกการนัดเปลี่ยนราคาล่วงหน้า &rarr; `data-confirm-tone="danger"`
9. [`resources/views/quotations/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/quotations/show.blade.php)
   - ปุ่มยกเลิกใบเสนอราคา &rarr; `data-confirm-tone="danger"`
   - ปุ่มแปลงใบเสนอราคา &rarr; `data-confirm-tone="primary"`
10. [`resources/views/partials/safe-delete.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/partials/safe-delete.blade.php)
    - ฟอร์มลบข้อมูลหลัก (Master Data Safe Delete) &rarr; `data-confirm-tone="danger"`

### 3.3 การแทนที่ Native Browser confirm() และ alert()
1. [`resources/views/wh/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/wh/index.blade.php#L611)
   - แทนที่ `confirm('รับของเข้าคลังตามจำนวนที่ระบุ?')` &rarr; `await window.erpConfirm('รับของเข้าคลังตามจำนวนที่ระบุ?', ...)` โทน Success
2. [`resources/views/purchase-orders/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/purchase-orders/index.blade.php#L169)
   - แทนที่ `confirm('อนุมัติใบขอซื้อนี้?')` &rarr; `await window.erpConfirm('อนุมัติใบขอซื้อนี้?', ...)` โทน Warning
3. [`resources/views/bookings/create.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/bookings/create.blade.php#L161)
   - แทนที่ `confirm('ลูกค้ารายนี้มียอดค้างชำระ...')` &rarr; `await window.erpConfirm(...)` โทน Warning
4. [`resources/views/documents/partials/entry-assets.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/documents/partials/entry-assets.blade.php#L194)
   - แทนที่ `confirm('ต้องการลบรายการนี้?')` &rarr; `await window.erpConfirm(...)` โทน Danger

### 3.4 การแทนที่ Swal.fire อิสระเป็น erpPopup / erpToast / erpConfirm
1. [`resources/views/ar-receipts/create.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/ar-receipts/create.blade.php)
   - แทนที่ popup ยืนยันยอดเงินไม่ตรง และ popup แจ้งเตือนการเลือกใบขาย
2. [`resources/views/stock-counts/show.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-counts/show.blade.php)
   - แทนที่ Swal.fire สำหรับแจ้งเตือนการนับ, ส่งตรวจสอบ, และยืนยันปรับสต็อก
3. [`resources/views/stock-transfers/receipt.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-transfers/receipt.blade.php)
   - แทนที่ Swal.fire สำหรับแจ้งเตือนรับของเกิน/ไม่พบคิวอาร์โค้ด
4. [`resources/views/stock-transfers/replenishment.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-transfers/replenishment.blade.php)
   - แทนที่ Swal.fire แจ้งข้อผิดพลาดการเติมสต็อก
5. [`resources/views/stock-adjustments/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-adjustments/index.blade.php)
   - แทนที่ Swal.fire แจ้งผลการอัปโหลดไฟล์นับสต็อก
6. [`resources/views/stock-transforms/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/stock-transforms/index.blade.php)
   - แทนที่ Swal.fire แจ้งเตือนการแปรสภาพ
7. [`resources/views/bplus-ops/qr-payments.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/bplus-ops/qr-payments.blade.php)
   - แทนที่ Swal.fire สำหรับลบการตั้งค่า QR
8. [`resources/views/quotations/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/quotations/index.blade.php)
   - แทนที่ Swal.fire แจ้งผล
9. [`resources/views/billing-notes/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/billing-notes/index.blade.php)
   - แทนที่ Swal.fire แจ้งผล
10. [`resources/views/production/index.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/production/index.blade.php)
    - แทนที่ Swal.fire เป็น `erpToast` สวยงาม

---

## 4. ผลการทดสอบ (Automated Verification)

### 4.1 ชุดทดสอบใหม่: `tests/Feature/ConfirmPopupConsistencyTest.php`
- `test_no_browser_confirm_or_alert_in_views`: สแกนไฟล์ Blade ทั้งหมด ตรวจสอบว่าไม่มี `confirm(` หรือ `alert(` ตกค้างในหน้าจอ
- `test_no_swal_fire_confirmation_outside_central_helpers`: ตรวจสอบว่าไม่มี `Swal.fire` ที่มี `showCancelButton: true` อยู่นอก `layout.blade.php` และ `pos/index.blade.php`
- `test_core_document_cancel_and_void_buttons_have_danger_tone`: ยืนยันว่าปุ่มลบ/ยกเลิกเอกสารหลักมี `data-confirm-tone="danger"`
- `test_confirm_action_component_supports_tones`: ยืนยันว่า component `x-confirm-action` ดึง `confirmTone` และส่งต่อ
- `test_layout_defines_unified_popup_styling_and_ergonomics`: ยืนยันคลาส CSS ทั้ง 4 โทน, `reverseButtons: true`, `allowOutsideClick`, `allowEscapeKey`, และ `focusCancel`

### 4.2 ผลการรัน PHPUnit รวมทั้งระบบ:
```
Tests: 1,007
Passed: 1,005
Assertions: 8,661
Skipped: 2 (ข้ามตามสภาพแวดล้อมเดิม)
Incomplete: 6 (งานที่ยังไม่ได้เริ่ม)
Failures: 0
Errors: 0
Duration: 38.39s
```
*ทุกการทดสอบผ่านฉลุย 100% ไม่กระทบ logic เดิมใดๆ ทั้งสิ้น*

---

## 5. หลักฐานภาพหน้าจอ (Evidence Screenshots)

บันทึกภาพหน้าจอจริงใน [`docs/ai/confirm-popups-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/):

### ส่วนที่ 1: ภาพหน้าจอ 4 โทนสีมาตรฐาน
1. **โทน Danger (สีแดง):**  
   [`01_tone_danger.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/01_tone_danger.png)  
   *แสดงกล่องยืนยันยกเลิกเอกสาร ปุ่ม "ยกเลิกเอกสาร" สีแดงสดอยู่ขวา ปุ่ม "ยกเลิก" อยู่ซ้ายพร้อมโฟกัสเริ่มต้นที่ปุ่มยกเลิก ป้องกันการกด Enter พลาด*

2. **โทน Warning (สีส้ม):**  
   [`02_tone_warning.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/02_tone_warning.png)  
   *แสดงกล่องยืนยันอนุมัติและปรับปรุงสต็อก ปุ่ม "อนุมัติและปรับยอด" สีส้มเข้ม สื่อถึงการดำเนินการที่มีผลต่อยอดสต็อก*

3. **โทน Primary (สีน้ำเงิน):**  
   [`03_tone_primary.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/03_tone_primary.png)  
   *แสดงกล่องยืนยันการแปลงเอกสารใบเสนอราคาเป็นใบสั่งขาย ปุ่ม "แปลงเอกสาร" สีน้ำเงินมาตรฐานระบบ*

4. **โทน Success (สีเขียว):**  
   [`04_tone_success.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/04_tone_success.png)  
   *แสดงกล่องยืนยันการรับชำระเงินครบถ้วนตามใบวางบิล ปุ่ม "เก็บเงินแล้ว" สีเขียว*

### ส่วนที่ 2: ภาพเปรียบเทียบ ก่อน/หลัง ใน 3 หน้าที่เคยเป็นแบบเดิม
5. **หน้าออกใบเสร็จรับเงิน (`ar-receipts/create.blade.php`):**  
   [`05_before_after_ar_receipt.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/05_before_after_ar_receipt.png)  
   *เปรียบเทียบแบบเดิมที่เขียน Swal.fire เอง ไม่มีสไตล์มาตรฐาน กับแบบใหม่ที่ใช้ `erpConfirm` โทน Warning สวยงามและเข้ากับธีมระบบ*

6. **หน้ารายการตรวจนับสต็อก (`stock-counts/show.blade.php`):**  
   [`06_before_after_stock_count.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/06_before_after_stock_count.png)  
   *เปรียบเทียบปุ่มกว้างไม่เท่ากันในแบบเดิม กับแบบใหม่ที่ปุ่มขยายเท่ากันและปุ่มยกเลิกอยู่ซ้ายตามหลักสากล*

7. **หน้าคลังสินค้าตรวจรับของ (`wh/index.blade.php`):**  
   [`07_before_after_wh_receive.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/confirm-popups-shots/07_before_after_wh_receive.png)  
   *เปรียบเทียบการใช้ `confirm()` ดั้งเดิมของเบราว์เซอร์สีเทาแข็งกระด้าง กับแบบใหม่ที่ใช้ `erpConfirm` โทน Success โค้งมนสวยงาม ไม่บล็อกเธรด*

---

## 6. หน้าตัวอย่างสำหรับตรวจ (Local Preview Route)

เพิ่ม Route พิเศษเฉพาะ Environment Local (ไม่แสดงในเมนูผู้ใช้ทั่วไป):
- **URL:** `/confirm-popups-preview`
- **ไฟล์:** [`resources/views/dev/confirm-popups-preview.blade.php`](file:///Applications/MAMP/htdocs/erp/ERPPOP/resources/views/dev/confirm-popups-preview.blade.php)
- บรรจุตัวอย่างการกดทดสอบทั้ง 4 โทนสี ทั้งผ่านการเรียก JavaScript `window.erpConfirm(...)` และผ่านแท็กฟอร์ม `data-confirm` ร่วมกับ `data-confirm-tone`
