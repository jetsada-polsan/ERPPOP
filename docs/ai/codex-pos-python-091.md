# Codex: Python POS 0.9.1 — เวลาใบเสร็จ + ยืนยันเงินโอน (เจ้าของสั่ง "แก้เลย")

Branch `claude/pos-python-settings-popup` ต่อจาก HEAD ห้าม deploy ห้ามแก้ PHP/Vue ทุกครั้งที่รัน Qt ตั้ง `QT_QPA_PLATFORM=offscreen`
เทสต์ห้ามใช้ `time.tzset()` และอ่านไฟล์ด้วย `encoding="utf-8"` (CI เป็น Windows) ห้ามสร้าง/เขียน PIN/token

1. **เวลาบนใบเสร็จเป็น UTC** (ขาย 15:15 เวลาไทย ใบเสร็จพิมพ์ 08:15) → แสดงเวลาท้องถิ่นของเครื่องทุกที่: ใบเสร็จ, พิมพ์ซ้ำ, ประวัติบิล, Z/ใบปิดกะ, หน้ายกเลิก/คืน; เก็บค่าในฐานข้อมูลแบบเดิม แปลงตอนแสดงเท่านั้น; ไล่ทุกจุดที่แสดงเวลา; เทสต์ส่ง tzinfo Bangkok ตรงๆ
2. **บิลโอน/QR ไม่ต้องติ๊กยืนยันแยก (คงตาม commit 7eec159)** แต่เมื่อแคชเชียร์กดรับชำระบิลโอน/ผสม ให้บันทึก `confirmed_at` = เวลาที่กด และส่ง `payment_confirmed: true` ให้ ERP (ตอนนี้ `payments.py` ส่ง `bool(transfer["confirmed_at"])` ซึ่งจะเป็น false) — เทสต์ payload บิลโอน/ผสมมี `payment_confirmed: true` และ `transfer_account_last4`
3. ตรวจซ้ำว่า overlay/backdrop ปิดทุกกรณีหลังยกเลิก/คืนบิล (commit 7ce2e28) รวม PIN ผิด, ERP ปฏิเสธ, ออฟไลน์ — เพิ่มเทสต์ถ้ายังไม่ครอบคลุม

pytest ผ่านทั้งหมด + ถอดตัวแก้แล้วเทสต์แดง; สรุปลง docs/ai/task.md; ไม่ต้อง commit
