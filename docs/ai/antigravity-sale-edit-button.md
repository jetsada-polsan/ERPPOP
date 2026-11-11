# รายงานสรุปงานที่ 25: ปุ่ม "แก้ไขใบขาย" ในหน้าใบขายเชื่อ

**Branch:** `antigravity/sale-edit-button`  
**Base:** `origin/main`  
**สถานะ:** ผ่านการทดสอบทั้งหมด (PHPUnit 929 เทสต์ ผ่าน 927, ข้าม 2, ไม่สมบูรณ์ 6, ล้มเหลว 0)

---

## 1. ที่มาและหลักการทางธุรกิจ

### ปัญหาเดิม
พนักงานขายเปิดหน้าใบขายเชื่อ (`/sales/{id}`, `resources/views/sales/show.blade.php`) แล้วไม่มีปุ่มให้แก้ไขเอกสาร มีเพียงปุ่มพิมพ์ใบกำกับภาษี, พิมพ์ใบส่งของ และรับชำระเงิน ทำให้ผู้ใช้ไม่ทราบวิธีการแก้ไขรายการขายเมื่อลูกค้าต้องการเปลี่ยนแปลงสินค้าหรือราคา

### ทำไมระบบไม่ยอมให้แก้ใบขายตรงๆ
ใบขายเชื่อ (Credit Sale) เป็นใบกำกับภาษี/ใบส่งของที่ออกเลขอย่างเป็นทางการ มีผลผูกพันทางภาษี ได้ตัดสต็อกสินค้าออกจากคลัง ตั้งยอดลูกหนี้การค้า (AR) และลงบัญชีแยกประเภททั่วไป (GL) ไปแล้ว
- ตามหลักการบัญชีและภาษี **ไม่สามารถเข้าไปแก้ไขตัวเลขในใบเดิมได้โดยตรง**
- วิธีการที่ถูกต้องคือ **ยกเลิกใบขายเดิม** (เก็บประวัติและเหตุผลไว้ในระบบ) → คืนสต็อกกลับไปกันไว้ในใบจอง → ปิดยอดลูกหนี้เดิม → ใบจองกลับสู่สถานะรอยืนยันเพื่อเปิดให้แก้ไขรายการ (`bookings.edit`) → เมื่อตรวจทานเสร็จแล้วกด Confirm ใหม่เพื่อออกใบขายเลขใหม่

---

## 2. การทำงานที่สร้างเพิ่ม (UI & Flow)

### 2.1 หน้าใบขาย (`resources/views/sales/show.blade.php`)
1. **ปุ่ม "แก้ไขใบขาย" บนแถบ Action ด้านบน:**
   - แสดงเมื่อ: ใบขายอยู่ในสถานะ `active` และมีใบจองต้นทาง (`$booking`)
   - สไตล์ปุ่ม: ปุ่มสีขาวขอบเทา (`btn btn-light border px-3`) พร้อมไอคอนดินสอ `<i class="bi bi-pencil me-1"></i>` สอดคล้องกับปุ่มพิมพ์ใบส่งของ
2. **สำหรับผู้ใช้ที่มีสิทธิ์ `documents.void` (ผู้จัดการสาขา / แอดมิน):**
   - เมื่อกดปุ่ม จะเปิด Modal แก้ไขใบขาย:
     - **หัวข้อ:** `แก้ไขใบขาย {เลขที่เอกสาร}`
     - **คำอธิบายขั้นตอน:** แสดง 4 ข้อชัดเจน (ยกเลิกใบขายเดิม, คืนสต็อกกลับไปกันไว้ในใบจอง, ปิดลูกหนี้, ระบบจะพาไปหน้าแก้ไขใบจองเพื่อออกใบขายเลขใหม่)
     - **ช่องกรอกเหตุผล (บังคับ):** Textarea ให้ระบุเหตุผลในการแก้ไข
     - **ปุ่มกดยืนยัน:** ปุ่มสีแดง *"ยกเลิกใบเดิมแล้วไปแก้ไข"* พร้อมปิดการคลิกซ้ำและแสดง Loading Spinner ขณะส่งคำขอ
     - **การส่งคำขอ:** ส่งแบบ POST พร้อม CSRF ไปยัง `bookings.revert-confirm` โดยแนบพารามิเตอร์ `return=edit`
3. **สำหรับผู้ใช้ทั่วไป / พนักงานขาย (ไม่มีสิทธิ์ `documents.void`):**
   - ปุ่ม "แก้ไขใบขาย" ยังคงแสดงให้เห็นเพื่อให้พนักงานทราบว่ามีกระบวนการนี้
   - เมื่อพนักงานขายกดปุ่ม จะเปิด Explanation Modal สีเหลืองเตือน:
     > *"การแก้ใบขายที่ออกแล้วต้องให้ผู้จัดการยกเลิกใบเดิมก่อน"*  
     > *"ใบขาย {เลขใบ} ได้ทำการตัดสต็อกและบันทึกบัญชีลูกหนี้แล้ว หากต้องการแก้ไขรายการ กรุณาแจ้งผู้จัดการสาขาพร้อมแจ้งเลขใบ {เลขใบ} เพื่อให้ผู้จัดการยกเลิกใบขายเดิมและเปิดให้แก้ไขใบจอง"*
   - ไม่มีการส่งคำขอใดๆ ไปยัง Server และหากพยายามยิง POST ตรงไปยัง route จะถูกบล็อกด้วย HTTP 403 Forbidden
4. **กรณีใบขายถูกยกเลิกแล้ว (`status != active`):**
   - ซ่อนปุ่มแก้ไขใบขาย
   - หากใบจองดังกล่าวได้รับการแก้ไขและ Confirm ใหม่แล้ว จะแสดงปุ่มลิงก์:
     `<a href="..." class="btn btn-outline-primary px-3"><i class="bi bi-arrow-right-circle me-1"></i> ใบขายใหม่: {เลขที่ใบขายใหม่}</a>`

---

### 2.2 ปรับ Controller (`app/Http/Controllers/BookingController.php`)
ในเมธอด `revertConfirm`:
- เพิ่มการตรวจสอบพารามิเตอร์ `return=edit`:
  - **เมื่อสำเร็จ:** ทำการ Redirect ไปยัง `route('bookings.edit', $booking)` พร้อมข้อความแจ้งเตือนสีเขียว *"ยกเลิกใบขายเดิมแล้ว แก้ไขรายการแล้วกด Confirm ใหม่"*
  - **เมื่อเกิดข้อผิดพลาด** (เช่น บิลรับชำระแล้ว, อยู่ในใบวางบิล, หรืองวดบัญชีปิดแล้ว): ทำการ Redirect `back()` กลับมาที่หน้าใบขายเดิมพร้อมข้อความ Error จาก Server โดยใบขายยังคงสถานะเดิม ไม่มีการเปลี่ยนแปลง
  - **กรณีคำขอเดิม (ไม่ส่ง `return=edit`):** คงพฤติกรรมเดิม 100% (Redirect ไปยัง `bookings.show`)

---

### 2.3 หน้าใบจอง (`resources/views/bookings/show.blade.php`)
- ปรับเปลี่ยนข้อความปุ่มถอยคอนเฟิร์มของผู้จัดการจากเดิม `"ถอย Confirm"` เป็น:
  **`"แก้ไขใบขาย (ถอย Confirm)"`** เพื่อให้ผู้ใช้เข้าใจว่าปุ่มนี้ทำหน้าที่เดียวกัน

---

## 3. ภาพหน้าจอ (Screenshots)

ภาพหน้าจอทั้งหมดถูกบันทึกไว้ในไดเรกทอรี [`docs/ai/sale-edit-button-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/):

1. **หน้าจอผู้จัดการ (เห็นปุ่มแก้ไขใบขาย):**  
   ![01_sale_show_manager.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/01_sale_show_manager.png)

2. **Modal สำหรับผู้จัดการ (ระบุขั้นตอนชัดเจนและบังคับกรอกเหตุผล):**  
   ![02_manager_revert_modal.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/02_manager_revert_modal.png)

3. **หน้าจอพนักงานขาย (เห็นปุ่มแก้ไขใบขาย):**  
   ![03_sale_show_salesperson.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/03_sale_show_salesperson.png)

4. **Modal อธิบายสำหรับพนักงานขาย (แจ้งให้ติดต่อผู้จัดการสาขา):**  
   ![04_salesperson_explain_modal.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/04_salesperson_explain_modal.png)

5. **หน้าจอใบขายที่ถูกยกเลิกแล้ว (แสดงลิงก์เชื่อมต่อไปยังใบขายใหม่):**  
   ![05_voided_sale_with_new_link.png](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/sale-edit-button-shots/05_voided_sale_with_new_link.png)

---

## 4. ผลการทดสอบ (Automated Tests)

สร้าง Feature Test ใหม่ครอบคลุมครบทุกกรณีใน `tests/Feature/SaleEditButtonTest.php`:
1. `test_manager_sees_edit_sale_button_and_revert_modal` -> **PASSED** (ผู้จัดการเห็นปุ่ม, เห็น modal ที่ชี้ไป `bookings.revert-confirm`, มี hidden field `return=edit`, มีคำอธิบาย 4 ข้อ)
2. `test_salesperson_sees_explanation_and_cannot_revert_directly` -> **PASSED** (คนขายเห็นปุ่ม, เห็น modal อธิบายให้แจ้งผู้จัดการ, ไม่เห็น form submit, และถ้า POST ตรงไป `bookings.revert-confirm` จะได้ 403 Forbidden)
3. `test_revert_with_return_edit_redirects_to_bookings_edit_and_without_redirects_to_show` -> **PASSED** (ส่ง `return=edit` แล้ว redirect ไปหน้า `bookings.edit` พร้อม flash message สำเร็จ / ไม่ส่ง redirect ไป `bookings.show`)
4. `test_paid_sale_cannot_be_reverted_and_shows_error` -> **PASSED** (บิลที่รับชำระแล้วถูกปฏิเสธ ข้อความ error แสดงบนหน้าจอ และสถานะใบขายยังคงเป็น active)
5. `test_voided_sale_does_not_show_edit_button_and_shows_new_sale_link` -> **PASSED** (บิลที่ยกเลิกแล้วไม่แสดงปุ่มแก้ไขใบขาย และเมื่อออกใบใหม่จะแสดงลิงก์ "ใบขายใหม่: {เลขใบ}")
6. `test_bookings_show_displays_updated_button_text` -> **PASSED** (หน้า `bookings/show` แสดงปุ่มข้อความ "แก้ไขใบขาย (ถอย Confirm)")

**ผลการรัน PHPUnit รวมทั้งระบบ:**
```
Tests: 929, Passed: 927, Skipped: 2, Incomplete: 6, Failures: 0, Errors: 0
Duration: ~31 วินาที
Exit Code: 0
```
