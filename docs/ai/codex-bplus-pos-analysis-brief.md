# Codex รอบ 2: วิเคราะห์ Business Plus POS เทียบกับ POS ของเรา (เอกสารเท่านั้น ห้ามแก้โค้ด)

ต่อจาก `docs/ai/bplus-erp-analysis.md` ที่ทำไว้แล้วใน branch นี้ — อ้างอิงกันได้ ไม่ต้องเขียนซ้ำ
ผลลัพธ์: `docs/ai/bplus-pos-analysis.md` (ภาษาไทย) commit + push บน branch ปัจจุบัน
**ห้ามแก้โค้ด ห้าม migration ห้ามแตะ production ห้าม deploy** ห้ามคัดลอกองค์ประกอบกรรมสิทธิ์ของ Business Plus ใช้ระดับกระบวนการธุรกิจเท่านั้น

## ของจริงใน repo ที่ต้องตรวจก่อน (อย่าเดา อ้างไฟล์:บรรทัด)
- JET POS Vue 1.8.1 `apps/pos-desktop/src` (App.vue, lib/pricing.ts, lib/sync.ts, lib/db.ts, lib/api.ts) — ใช้ขายจริง
- JET POS Python 0.8.7 `apps/pos-python/pos_python` (promotions.py, payments.py, shift_float.py, sync_service.py, barcode.py, ui.py, touch_dialogs.py) — UAT
- ERP: `app/Http/Controllers/PosController.php`, `Api/PosApiController.php`, `app/Services/Sales/PosPricingGuard.php`, `app/Models/QtyPromotion.php`, `QtyPromotionController`, members/points/discount cards, `CashBookPostingService`, `GlPostingService`, scale profiles, Z report
- ที่ทำไปแล้วล่าสุด: โปร 8 แบบ (free_item, bundle_price, discount, mix_bundle, mix_free, bill_discount, bill_free_item, bill_addon), ส่วนลดท้ายบิลมีผู้อนุมัติ, 5 วิธีชำระ, เงินทอนลิ้นชักยกข้ามกะ + blind count + เงินนำส่ง, Z 2 ใบ, VAT คิดที่ ERP ต่อสินค้า, idempotency checkout, offline queue, PLU เครื่องชั่ง 800/801 ป้ายราคารวม

## สรุปคลิปจากเจ้าของ (https://www.youtube.com/watch?v=KMHKpqcdfKA)
- Checkout: สแกน → แสดงราคา → รวมยอด → เลือกชำระ (เงินสด/บัตร) → เปิดลิ้นชัก → พิมพ์ใบเสร็จอัตโนมัติ
- สมาชิก: ค้นด้วย Member ID / สแกนบัตร, แสดงข้อมูล วันหมดอายุ วันเกิด, บันทึกยอดซื้อ
- โปรโมชั่น: อ้างว่ามี >35 แบบ (คลิปไม่ได้อธิบายครบ) ตัวอย่าง ซื้อครบได้ส่วนลด, ซื้อครบได้ของแถม, 3 ชิ้นชิ้นละ 35 (105) เหลือ 100, ใช้อัตโนมัติที่ POS
- รายงาน: ยอดขายรายวัน, ยอดเงินสด, ยอดบัตร, รายการยกเลิก, รายการคืน, พิมพ์ผ่านเครื่องพิมพ์ใบเสร็จ
แยก "ข้อเท็จจริงจากคลิป" / "ตีความ" / "ข้อเสนอใหม่" ให้ชัด ถ้าเปิดคลิปไม่ได้ให้ใช้สรุปนี้และแจ้งข้อจำกัด

## สิ่งที่ต้องออกแบบ (ตามโจทย์เจ้าของ)
A. POS Transaction Engine: Scan → Cart → Member → Promotion → Tax → Payment → Complete → Receipt → Inventory → Sync ERP; multi-branch/terminal, offline, weighted barcode, multi-unit, split payment, cash/card/QR, hold/resume, suspend, void/refund, reprint, shift open/close, drawer, cash reconciliation
B. Promotion Rule Engine แบบ data-driven (ไม่ hardcode ทีละแบบ) รองรับอย่างน้อย: %, บาท, Buy X Get Y, bundle, mix & match, quantity break, tiered, member, birthday, coupon/voucher, time-based, branch, category, customer group pricing, exclusion, priority, stackable/non-stackable
   — schema, conditions, actions, calculation engine, conflict resolution, discount allocation ต่อบรรทัด, ภาษีหลังส่วนลด, versioning, วันเริ่ม/หมดอายุ, offline caching; ตรวจการซ้อน การปัดเศษ การกระจายส่วนลด ให้ภาษี ต้นทุน กำไรไม่ผิด
   — **สำคัญ: ERP (PosPricingGuard) เป็นตัวชี้ขาดราคา** engine ใหม่ต้องคิดเหมือนกันทั้ง ERP/Vue/Python และย้ายจาก 8 แบบเดิมได้โดยบิลเก่าไม่เพี้ยน — ระบุแผน migration ของโปรเดิม
   — ระบุปัญหาที่รู้แล้ว: สินค้าเดียวกันหลายบรรทัด ERP ใช้ firstWhere ไม่รวม qty (ดู docs/ai/task.md)
C. Membership & CRM: customer master, member ID, barcode/QR card, groups, tiers, points earn/redeem/expire, history, birthday, credit limit, LINE OA — การผูก LINE User ID ต้องมีการยืนยันตัวตนที่เหมาะสม ไม่ใช้เบอร์โทรอย่างเดียว
D. Hardware: USB/Bluetooth scanner, ESC/POS printer, cash drawer, customer display, เครื่องชั่ง, touchscreen — วิเคราะห์ข้อจำกัด Browser POS vs Desktop (Tauri/PySide6) vs local device service โดยเฉพาะพิมพ์อัตโนมัติและเปิดลิ้นชัก (ตอนนี้ร้านพิมพ์ผ่าน Windows default printer)
E. Shift Closing & Reconciliation: expected/actual/over-short, cash in/out, payment summary, void/refund/discount/promotion usage report, cashier performance, Z — เทียบกับที่ทำไปแล้ว
F. Integration กับ ERP: sales ledger, inventory ledger, COGS, VAT output, cash/bank/settlement clearing, AR เมื่อขายเชื่อ, member points, promotion analytics, profit dashboard; กันบันทึกซ้ำจาก retry; reversal เมื่อคืน/ยกเลิก

## ผลลัพธ์ 12 หัวข้อ
1 POS Feature Gap Analysis (Existing / Partial / Missing / Risk แยกชัด อ้างไฟล์:บรรทัด และระบุว่า Vue/Python/ERP ตัวไหนมี)
2 POS Complete Workflow 3 POS ERD 4 Promotion Rule Engine Design 5 Membership/Loyalty 6 Hardware Integration 7 Offline Sync 8 POS→ERP Accounting Mapping (double entry + ตัวอย่างตัวเลขที่ระบุว่าเป็นตัวอย่าง) 9 UI/UX Screens (ทัชสกรีน 1024x768) 10 API Endpoints 11 Test Scenarios + Acceptance Criteria 12 Development Roadmap (จัดลำดับตามความเสี่ยงต่อเงิน/สต๊อก/ภาษี)
หน้าแรกเป็นสรุปผู้บริหาร 1 หน้า ใช้ตารางให้กระชับ
