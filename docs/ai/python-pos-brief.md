# งานสำหรับ Codex: JET POS เวอร์ชัน Python (ทำแยก ห้ามแตะของเดิม)

ร้านยังขายด้วย JET POS ตัวปัจจุบัน (`apps/pos-desktop`, Tauri + Vue) งานนี้คือสร้างเครื่องขายตัวใหม่ด้วย Python
ไว้ทดสอบคู่กัน **ยังไม่ใช้ขายจริง** จนกว่าเจ้าของจะสั่งสลับ

## ขอบเขตและกติกา

- ทำในโฟลเดอร์ใหม่ `apps/pos-python/` เท่านั้น ห้ามแก้ `apps/pos-desktop/` ห้ามแก้ API ฝั่ง ERP
  ถ้าต้องให้ ERP เปลี่ยน ให้เขียนลง `docs/ai/task.md` แล้วรอเจ้าของสั่ง
- ทำงานบน branch ของตัวเอง เช่น `codex/pos-python` ห้าม push เข้า main ตรงๆ ห้าม deploy
- ห้ามทดสอบกับ production ใช้ ERP ที่รันในเครื่อง (`php artisan serve`) หรือ staging เท่านั้น
- ห้ามสร้าง PIN, password หรือ device token ให้ใคร ห้ามเขียนค่าพวกนี้ลงไฟล์ หรือ commit
- เป้าหมายคือ Windows 10/11 แนะนำ PySide6 (Qt) จะได้หน้าตาแบบโปรแกรม desktop แพ็กด้วย PyInstaller

## API ที่ต้องใช้ (เหมือนตัว Vue ทุกอย่าง)

ฐาน URL `{server}/api/pos` ทุกคำขอส่ง `Authorization: Bearer <device token>`
ยกเว้น `POST /api/pos/pair` ที่ใช้จับคู่เครื่องครั้งแรก ดูรายละเอียดใน `routes/api.php`
`app/Http/Controllers/Api/PosApiController.php` และ `app/Http/Controllers/PosController.php`

- **เครื่องและผู้ใช้:** ping, cashiers, cashier/login, cashier/pin, approver/verify, auth-events
- **ข้อมูลขาย:** products, scan, promotions, members, members/search
- **กะ:** shift, shift/open, shift/close (ตอบกลับพร้อมข้อมูล Z Report), shift/cash-movement
- **บิล:** held-bills, checkout, receipt/void, receipt/return

ส่ง `checkout` ต้องมี `idempotency_key` ทุกบิล ถ้าส่งซ้ำแล้ว ERP ตอบรหัส 4xx ให้ถือว่าบิลนั้นเสีย
แจ้งแคชเชียร์ อย่าส่งวนซ้ำ ดูตัวอย่างใน `apps/pos-desktop/src/lib/sync.ts`

## กับดักที่ต้องรู้ (เคยพังจริง)

1. **ราคาต้องตรงกับ ERP ทุกสตางค์** ERP คำนวณโปรซ้ำ (`app/Services/Sales/PosPricingGuard.php`) ถ้าไม่ตรงจะไม่รับบิล
   ให้แปลง `apps/pos-desktop/src/lib/pricing.ts` เป็น Python ทีละฟังก์ชัน
   แล้วแปลงเคสใน `pricing.test.ts` เป็นเทสต์ pytest **ให้ผ่านครบทุกเคส**
   โปรมีทั้งหมด 8 แบบ ดูรายชื่อที่ `QtyPromotion` ต้องส่ง flag `bill_promotions` และ `mix_promotions` ตอนขอข้อมูลโปร
2. **ตัวตนแคชเชียร์มาจาก PIN ที่ผูกกับเครื่อง** ห้ามส่ง `cashier_id` เองแล้วเชื่อค่านั้น
3. **ขายตอนออฟไลน์ได้** ทำคิวบิลเก็บใน SQLite ในเครื่อง ดูตาราง `checkout_queue`, `pos_sale_history`, `app_state` ใน `lib/db.ts`
   ส่งเข้า ERP อัตโนมัติเมื่อกลับมาออนไลน์
4. **บิลโอนต้องมีเลขท้ายบัญชี 4 หลัก** (`transfer_account_last4`) ไม่งั้น ERP ไม่รับ
5. **ใบเสร็จ:** พิมพ์ 2 ใบ บิลที่มีโอนพิมพ์ 3 ใบ ต้องรอให้พิมพ์เสร็จก่อนเปลี่ยนสิ่งที่จะพิมพ์ต่อ (เคยพังใน 1.7.8)
6. **ยกเลิกบิล/รับคืน:** ต้องเปิดกะก่อน และต้องใช้ PIN หัวหน้า (บทบาท GM, BRANCH_MGR หรือ IT_MGR)
7. **ตอนเปิดเครื่องต้องล็อกเสมอ** ต้องใส่ PIN ก่อนขาย ปิดหรือย่อหน้าต่างแล้วห้ามข้ามหน้าล็อกได้
8. **กะใน ERP ปิดไปแล้ว** ให้เครื่องปิดกะตามด้วย ดู `reconcileShiftWithErp` ใน `App.vue`

## เกณฑ์ว่าเสร็จ (ต้องผ่านก่อนเสนอให้เจ้าของลองใช้)

- [ ] เทสต์ราคาที่แปลงจาก `pricing.test.ts` ผ่านครบ และเพิ่มเคสโปรทั้ง 8 แบบ
- [ ] ขายบิลเดียวกันทั้งเครื่อง Vue และเครื่อง Python กับ ERP ทดสอบ แล้วได้ยอด ส่วนลด และ VAT ตรงกันทุกบิล อย่างน้อย 50 บิลที่มีโปรทุกแบบ
- [ ] ปิดเน็ตแล้วขาย 20 บิล เปิดเน็ตกลับ บิลเข้า ERP ครบ ไม่มีบิลซ้ำ
- [ ] เปิดกะ ขาย ยกเลิก รับคืน ปิดกะ แล้วพิมพ์ Z ได้ ยอดใน Z ตรงกับ ERP
- [ ] สรุปผลลง `docs/ai/task.md` พร้อมวิธีติดตั้งบนเครื่อง Windows
