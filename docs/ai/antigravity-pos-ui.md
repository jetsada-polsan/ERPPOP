# สรุปการปรับปรุงหน้าจอ Python POS (Antigravity POS UI Upgrade)

**กิ่งการทำงาน (Branch):** `antigravity/pos-python-ui` (แตกจาก `origin/claude/pos-python-settings-popup` ที่ commit `5fb5f19`)
**วันที่ดำเนินการ:** 9 พฤศจิกายน 2026

---

## 1. หน้าจอที่ปรับปรุง (Screens Updated)

1. **ระบบ QSS Design System (`jet_pos_design_system.qss`)**
   - เพิ่มไฟล์ `apps/pos-python/pos_python/assets/jet_pos_design_system.qss` และโหลดอัตโนมัติใน `_style_for_layout()` ตอนเริ่มระบบ
   - ใช้ชุดสี Enterprise High-Contrast (`#0a2540` Deep Navy, `#059669` Action Emerald, `#57d9a3` Payable Mint, `#2563eb` Brand Blue, `#b91c1c` Danger Red) ผ่านตัวแปร `$token` ใน `PALETTE` และ `objectName`

2. **หน้าขายหลัก (`PosWindow` — 1024×768 ไม่ล้นจอ)**
   - แถบบน (`#jetTopbar` สูง 64px) แสดงแบรนด์ สาขา/เครื่อง กะขาย สถานะออนไลน์/ออฟไลน์ (`#jetStatus`) และปุ่มทางลัดขนาดสัมผัส `>= 48px`
   - แถบเครื่องมือ (`#jetToolStrip`) และปุ่มจัดการบิล (`พักบิล`, `เรียกบิล`, `ล้างบิล`, `แก้ไขรายการที่เลือก`) สูง `>= 48px` พร้อมปุ่มรับชำระเงิน (`#payBtn`) สูง `58px`
   - เปิดแถบหมวดหมู่สินค้าแบบปุ่มกดสัมผัส (`#catPill` สูง `48px`)
   - ตารางบิล (`#cartTable`) ตัวหนังสือ `16px` ความสูงแถว `58px` ปุ่มลด/เพิ่มจำนวน `-`/`+` ขนาด `40×40px` ปุ่มลบ `✕` ขนาด `38×38px`
   - กล่องยอดสุทธิ (`#totalBox` / `#grandTotal`) ขนาดตัวอักษร `34px` (`>= 32px` แม้ในโหมดจอ 1024×768 `compact="true"`)

3. **หน้ารับชำระเงิน (`PaymentDialog`)**
   - แยกฝั่งซ้ายเป็นการ์ดสรุปยอด (`#card`) แสดงยอดสินค้า ส่วนลดโปรโมชัน ช่องส่วนลดท้ายบิล กล่องยอดชำระสุทธิ (`#totalPayableBox` ตัวเลข `32px` สี `#57d9a3`) และกล่องเงินทอน (`#dialogNoticeBox` โทนสีเขียว)
   - ปุ่มเลือกวิธีชำระ (`เงินสด`, `โอน / QR` — `#btnPayMethod`) และปุ่มรับเงินด่วน (`100`, `500`, `1,000`, `พอดี` — `#btnCashQuick`) สูง `56px` ตัวอักษร `18px`
   - ปุ่มยืนยัน `รับเงิน / ออกบิล` (`#btnCheckoutPrimary`) สูง `60px`

4. **หน้าใส่รหัส PIN และแป้นตัวเลขบนจอ (`ValueDialog`, `PinPad`, `NumPad`)**
   - ปุ่มตัวเลข (`#keyBtn`), ปุ่มล้าง (`#keyBtnActionClear`), และปุ่มลบ (`#keyBtnActionDel`) ขนาดไม่ต่ำกว่า `64×64px` แยกสีชัดเจน
   - ช่องแสดงจุดรหัส PIN (`#pinDotsDisplay`) ขนาด `28px` จัดกึ่งกลาง พร้อมช่องกรอกตัวเลขขนาดใหญ่ (`52px`)

5. **หน้าเปิดกะและปิดกะ (`OpeningShiftDialog`, `ExistingShiftDialog`, `CloseShiftDialog`, `FloatCloseDialog`)**
   - ปรับความสูงและระยะห่างของ `OpeningShiftDialog` (`480×700`) และ `CloseShiftDialog` ให้ไม่ล้นจอ 1024×768
   - เพิ่มแถบแสดงลำดับขั้นตอน 4 ขั้นตอน (`#stepPill`: `1. นับเงินสด` → `2. ตรวจยอดขาด/เกิน` → `3. แยกเงินทอน/นำส่ง` → `4. ยืนยันและพิมพ์ Z 2 ใบ`) ใน `FloatCloseDialog` (`1024×768`) โดยตารางนับเหรียญ/ธนบัตร 11 แถวแสดงครบในหน้าเดียวไม่ต้องเลื่อน (`verticalScrollBar().maximum() == 0`)

6. **Popup แจ้งเตือนและยืนยัน (`TouchDialog`, `TouchMessage`)**
   - ส่วนหัว `#touchHeader` พื้นหลังสีกรมท่าเข้ม (`#0a2540`) พร้อมปุ่มปิด `×` ขนาด `56×56px`
   - แยกโทนสีกล่องข้อความแจ้งเตือน (`#dialogNoticeBox`) สำหรับ `information` (`info`), `warning` (`warning`), `critical` (`danger`), และ `question` (`warning`)

7. **หน้าล็อกหน้าจอ เลือกคนขาย นำส่งเงินระหว่างกะ และหน้าอื่น ๆ (`#sessionLock`, `SellerSelectionDialog`, `record_cash_movement`)**
   - หน้าล็อก (`#sessionLock`) จัดวางในการ์ดกลางจอ (`#lockCard`) บนพื้นหลัง `#0a2540` พร้อมปุ่ม `เข้างาน` สูง `76px`
   - ปรับการ์ดและปุ่มสัมผัสของหน้าเลือกคนขาย (`SellerSelectionDialog`) และหน้านำส่งเงินระหว่างกะ (`record_cash_movement`) ให้กดง่ายและอ่านชัดเจน

---

## 2. รายการไฟล์ที่แก้ไข (Modified Files)

- `apps/pos-python/pos_python/assets/jet_pos_design_system.qss` *(เพิ่มใหม่)*
- `apps/pos-python/pos_python/ui.py` *(แก้ไขเฉพาะส่วน UI, Layout, ObjectName และ QSS)*
- `apps/pos-python/pos_python/touch_dialogs.py` *(แก้ไขเฉพาะส่วน UI ของ TouchDialog, NumPad, ValueDialog, TouchMessage)*
- `docs/ai/pos-ui-shots/*.png` *(ภาพหน้าจอก่อนและหลังแก้ไข 16 ภาพที่ความละเอียด 1024×768)*
- `docs/ai/antigravity-pos-ui.md` *(เอกสารสรุปฉบับนี้)*

---

## 3. ผลรันชุดทดสอบ (`pytest`)

รันคำสั่งทดสอบทั้งหมดในโหมด `QT_QPA_PLATFORM=offscreen`:

```text
cd apps/pos-python && QT_QPA_PLATFORM=offscreen python -m pytest -q
428 passed in 73.34s (0:01:13)
```

- ผ่านครบทั้ง **428 ข้อ** (0 failed)

---

## 4. ภาพหน้าจอก่อนและหลังแก้ไข (Before / After Screenshots @ 1024×768)

บันทึกไว้ในโฟลเดอร์ `docs/ai/pos-ui-shots/`:

| ลำดับ | หน้าจอ | ภาพก่อนแก้ไข (Before) | ภาพหลังแก้ไข (After) |
|---|---|---|---|
| 1 | หน้าขายหลัก (Main Sale) | `before_01_main_sale.png` | `after_01_main_sale.png` |
| 2 | หน้ารับชำระเงิน (Payment) | `before_02_payment.png` | `after_02_payment.png` |
| 3 | หน้าใส่รหัส PIN (PIN Dialog) | `before_03_pin.png` | `after_03_pin.png` |
| 4 | หน้าเปิดกะ (Opening Shift) | `before_04_open_shift.png` | `after_04_open_shift.png` |
| 5 | หน้าปิดกะ 4 ขั้นตอน (Float Close Wizard) | `before_05_close_shift.png` | `after_05_close_shift.png` |
| 6 | Popup แจ้งเตือน/ยืนยัน (TouchMessage) | `before_06_message_dialog.png` | `after_06_message_dialog.png` |
| 7 | หน้าล็อกเข้างาน (Session Lock) | `before_07_lock_screen.png` | `after_07_lock_screen.png` |
| 8 | หน้าตั้งค่าเครื่อง (Settings Popup) | `before_08_settings.png` | `after_08_settings.png` |

---

## 5. สิ่งที่ไม่ได้แตะต้องตามข้อกำหนดความปลอดภัย (Unchanged Scope)

- ไม่ได้แก้ไขไฟล์คำนวณเงิน โปรโมชัน คิวซิงก์ หรือฐานข้อมูล (`order.py`, `promotions.py`, `services.py`, `database.py`, `sync_*`, `api_client.py`)
- ไม่ได้เปลี่ยนชื่อ method, signal หรือโครงสร้างข้อมูลที่ชุดทดสอบเรียกใช้
- ไม่ได้สร้างรหัสผ่าน, PIN หรือ token ใด ๆ ในโค้ด
- ไม่ได้ยุ่งกับกิ่ง `main` และไม่ได้เชื่อมต่อเซิร์ฟเวอร์จริง
