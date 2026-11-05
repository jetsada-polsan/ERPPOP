# Codex: Python POS — ทำโปรและส่วนลดท้ายบิลให้เหมือน JET POS Vue (UAT)

Branch: `claude/pos-python-settings-popup` (ทำต่อจาก HEAD นี้, commit เป็นขั้นๆ, push ได้, ห้าม deploy, ห้ามแก้ `apps/pos-desktop/` หรือ PHP)
ห้ามสร้าง/เขียน PIN, password, token ลงไฟล์

เจ้าของทดสอบบนเครื่องร้านจริง (จอ ~1024x768 ทัชสกรีน) แล้วเจอ 4 เรื่อง:

## 1. โปรไม่ครบ 8 แบบ (สำคัญสุด)
`pos_python/promotions.py` คิดได้แค่ `discount`, `bundle_price`, `free_item`
ขาด `mix_bundle`, `mix_free`, `bill_addon`, `bill_discount`, `bill_free_item`

- `provisioning.py:106` ดึงโปรต้องเพิ่ม `&include_upcoming=1&bill_promotions=1&mix_promotions=1` (ดู `apps/pos-desktop/src/lib/api.ts:86`)
- ตาราง `qty_promotions` ต้องเก็บเพิ่ม: `product_ids` (JSON, สำหรับ mix), `min_amount`, `max_discount`, ข้อมูลของแถม (sku/name) และ starts_at/ends_at แบบมีเวลา — ทำ migration ใน `database.py` ให้เครื่องเก่าอัปเกรดได้
- ตัวคำนวณ: แปลง `priceCart` ใน `apps/pos-desktop/src/lib/pricing.ts` ทั้งฟังก์ชันเป็น Python
  **แต่ตัวชี้ขาดคือ ERP** `app/Services/Sales/PosPricingGuard.php::qtyPromotionDiscount` (บรรทัด ~380-490)
  ถ้า Vue กับ ERP ต่างกัน (เช่นรวม qty ทุกบรรทัด vs `firstWhere`) ให้ตาม ERP แล้วจดลง task.md
- checkout payload (`sync_service.py`) ต้องส่ง `bill_promotions: true`, `mix_promotions: true` (ดู `App.vue:891`)
- ของแถม (`free_item`, `bill_free_item`) ต้องโชว์ในตะกร้า/หน้าชำระ/ใบเสร็จ ราคา 0 และส่งเป็น item unit_price 0
- เทสต์ pytest: แปลงทุกเคสใน `apps/pos-desktop/src/lib/pricing.test.ts` + อย่างน้อย 1 เคสต่อโปรแต่ละแบบ (8 แบบ) + เคสรวมโปรต่อสินค้า+โปรท้ายบิล+ส่วนลดท้ายบิล

## 2. ส่วนลดท้ายบิล (มือ) ไม่มี
Vue มีช่อง "ส่วนลดท้ายบิล (บาท)" ในหน้าชำระ (`App.vue:1122`) ส่งเป็น `manual_discount_amount`
คิดหลังหักโปร, cap ที่ยอดหลังโปร, กระจายลงราคาต่อชิ้นตามสัดส่วน (`pricing.ts` ส่วน `items`/`unit_price`)
Python มี `manual_discount_amount` ใน `sync_service.py:335` อยู่แล้ว (จาก adjustment) — ใช้ทางนี้ ไม่ต้องสร้างใหม่
ถ้าระบบเดิมต้องมีผู้อนุมัติส่วนลด ให้คงกฎเดิมไว้ (อย่าตัดการอนุมัติ)
ต้องจำค่าไว้ตอนพักบิล/เรียกบิล/ปิดแอปค้าง แล้วรีเซ็ตเป็น 0 หลังขายเสร็จ

## 3. หน้าชำระเงิน ปุ่มเล็กเกินไป — ออกแบบใหม่สำหรับทัชสกรีน 1024x768
- ปุ่มวิธีชำระ / ธนบัตรด่วน / numpad สูงอย่างน้อย 56px ตัวอักษร ≥18px
- แสดงชัด: ยอดสินค้า, ส่วนลดโปร (แยกรายชื่อโปร), ส่วนลดท้ายบิล (ช่องกรอก + numpad), ยอดสุทธิ ตัวใหญ่, รับเงิน, เงินทอน
- ต้องพอดีจอ 1024x768 ไม่ต้องเลื่อน
- ใช้สีจาก `PALETTE` เท่านั้น (tests/test_ui_style.py บังคับ)

## 4. Popup เชื่อม ERP ครั้งแรก ไม่สวย
dialog ตั้งค่าครั้งแรก (ui.py ราวบรรทัด 180-210, "ตั้งค่าเครื่องครั้งแรก") ให้ใช้สไตล์เดียวกับ `SettingsDialog` (การ์ดมุมโค้ง, header สีแบรนด์, ปุ่มใหญ่)
ช่อง token ต้องว่างทุกครั้งที่เปิด (เดิมค้างค่าเก่า ทำให้วางต่อท้ายแล้ว 401) มีปุ่ม "แสดง" และปุ่ม "วาง" ที่ล้างช่องก่อนวาง
ข้อความ error 401 ต้องอ่านเป็นภาษาไทย ไม่ใช่ JSON ดิบ \u0e..

## เกณฑ์เสร็จ
- `cd apps/pos-python && python -m pytest -q` ผ่านทั้งหมด
- ถ่ายภาพ offscreen 1024x768 ของหน้าชำระเงิน (มีโปร+ส่วนลดท้ายบิล) และ popup ครั้งแรก เก็บที่ `docs/ai/pos-python-shots/` (ใช้ `QT_QPA_PLATFORM=offscreen`)
- สรุปลง `docs/ai/task.md`: ทำอะไร, จุดที่ Vue≠ERP, สิ่งที่ยังต้องทดสอบบนเครื่องจริง
- commit + push branch นี้
