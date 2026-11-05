# Codex: ทดสอบแคมเปญทั้ง 8 แบบ + ส่วนลดท้ายบิล จาก ERP หลังบ้าน → Python POS → ERP (e2e จริง, ห้ามแตะ production)

Branch: `claude/pos-python-settings-popup` ต่อจาก HEAD, commit/push เมื่อผ่าน ห้าม deploy
**ห้ามยิง production (`popstarcenter.com`) ทุกกรณี** ใช้ Laravel ในเครื่องเท่านั้น
ห้ามเขียน PIN/token จริงลงไฟล์ ค่าทดสอบสร้างใน DB ทดสอบตอนรันแล้วทิ้ง

## ทำอะไร
1. เตรียม Laravel ในเครื่อง: `composer install` (มี network), `.env` ทดสอบ SQLite แยกไฟล์ใน scratch, `php artisan migrate --seed` หรือ seed เฉพาะที่ต้องใช้
   (ถ้ามี seeder/fixture ของ e2e เดิมใน `apps/pos-python/e2e/` หรือ `tests/` ให้ใช้ต่อ)
2. สร้างแคมเปญผ่าน **route หลังบ้านจริง** `POST /qty-promotions` (QtyPromotionController::store, ผ่าน validation จริง) ด้วย user ที่มีสิทธิ์ ทั้ง 8 แบบ:
   free_item, bundle_price, discount (ทั้ง baht และ percent), mix_bundle, mix_free, bill_discount (percent + max_discount และ baht), bill_free_item, bill_addon
   + เคสโปรหมดอายุ/ยังไม่เริ่ม/ปิดใช้งาน/ต่างสาขา ต้องไม่ถูกใช้
3. ให้ Python POS ดึงโปรผ่าน `ProvisioningService` (API `/api/pos/promotions` จริง) แล้วขายผ่าน client ตัวเดียวกับเครื่องขาย
   ต่อยอด `apps/pos-python/e2e/pos_conditions_to_laravel.py` (เพิ่มเป็นสคริปต์ใหม่ `pos_campaigns_to_laravel.py` ก็ได้)
4. บิลทดสอบอย่างน้อย:
   - ทุกแบบเข้าเงื่อนไขพอดี, ต่ำกว่าเงื่อนไข 1 ชิ้น/1 บาท, เกินหลายชุด
   - โปรสินค้า + โปรท้ายบิลพร้อมกัน, + ส่วนลดท้ายบิลมือ (มีผู้อนุมัติ และไม่มีผู้อนุมัติ → ต้องถูกปฏิเสธ)
   - ของแถมราคา 0 ขึ้นในบิล ERP, แลกซื้อไม่นับยอดตัวเอง, cap max_discount
   - สินค้าเดียวกันหลายบรรทัด (จดผลว่า ERP firstWhere ทำให้เป็นยังไง)
   - เงินสด และ โอน (มี transfer_account_last4)
5. ตรวจทุกบิล: ERP **รับบิล** (ไม่ถูก PosPricingGuard ปฏิเสธ), ยอดสุทธิ/ส่วนลด/VAT ใน ERP ตรงกับที่ POS คิดทุกสตางค์, ของแถมเข้า ERP
6. ถ้าเจอบิลที่ ERP ปฏิเสธ หรือยอดไม่ตรง → แก้ฝั่ง Python ให้ตรง ERP (ห้ามแก้ PHP) เพิ่ม pytest จับเคสนั้น แล้วรันซ้ำ

## ส่งมอบ
- สคริปต์ e2e + วิธีรันใน `apps/pos-python/e2e/README` หรือหัวไฟล์
- ตารางผล (แคมเปญ / บิล / ยอด POS / ยอด ERP / ผ่าน?) ลง `docs/ai/task.md`
- pytest ผ่านทั้งหมด, commit + push
