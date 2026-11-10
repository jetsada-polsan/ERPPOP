# รายงานการพัฒนางานที่ 20: POS สำรองข้อมูลก่อนปิดกะ + เตือน PIN ออฟไลน์ใกล้หมด

> Branch: `antigravity/pos-backup` (จาก `origin/claude/pos-free-item-fix`)  
> โฟลเดอร์: `apps/pos-python` เท่านั้น  
> ผู้พัฒนา: Antigravity  

---

## 1. วัตถุประสงค์
ฐานข้อมูลของเครื่องขายหน้าร้าน JET POS ทำงานแบบ SQLite ไฟล์เดียว (`session.db` / `pos-python-demo.db`) ซึ่งเก็บทั้งข้อมูลกะขาย บิล และคิวบิลที่รอ sync ขึ้น ERP หากฮาร์ดแวร์ขัดข้องหรือเกิดปัญหาขณะมีบิลค้างส่ง อาจทำให้ข้อมูลสูญหาย เจ้าของระบบจึงกำหนดให้มีระบบสำรองข้อมูลอัตโนมัติทุกครั้งก่อนปิดกะ พร้อมเปิดให้คัดลอกไฟล์สำรองไปยัง Flash Drive (USB) ได้สะดวก รวมทั้งแจ้งเตือนพนักงานเมื่อสิทธิ์ PIN สำหรับขายแบบออฟไลน์ใกล้หมดอายุ (เหลือน้อยกว่า 24 ชั่วโมง)

---

## 2. สิ่งที่พัฒนาและรายละเอียดการทำงาน

### 2.1 ฟังก์ชันสำรองฐานข้อมูลหลัก (`pos_python/backup.py`)
- **`backup_database(db_source, dest_dir, terminal_id=None) -> Path`**:
  - ใช้ `sqlite3.Connection.backup(target_conn)` เพื่อทำ Online Backup อย่างปลอดภัยขณะฐานข้อมูลกำลังเปิดใช้งานและมี transaction **ห้าม copy ไฟล์ตรงๆ เด็ดขาด**
  - รองรับ `db_source` ทั้งแบบ `sqlite3.Connection` และ `Path` / `str`
  - ตั้งชื่อไฟล์ตามมาตรฐาน: `pos-backup-<terminal>-<YYYYMMDD-HHMMSS>.sqlite` โดยดึงรหัสเครื่องจาก `device_settings` หรือ `shifts` อัตโนมัติ (และมี counter กรณีสำรองในวินาทีเดียวกัน)
  - ตรวจสอบความสมบูรณ์หลังสำรองด้วย `PRAGMA integrity_check` ต้องได้ผลลัพธ์เป็น `ok` เท่านั้น หากล้มเหลวจะลบไฟล์ที่เสียหายทิ้งทันทีและ raise `RuntimeError`
  - **Retention อัตโนมัติ**: จัดเก็บสำรองในโฟลเดอร์ปลายทาง (เช่น `backups/`) สูงสุด 14 ไฟล์ล่าสุด โดยฟังก์ชัน `prune_backups(dest_dir, keep_count=14)` จะลบเฉพาะไฟล์ที่ขึ้นต้นด้วย `pos-backup-` เท่านั้น ไฟล์อื่นๆ เช่น `session.db`, ไฟล์รายงาน, บันทึก หรือไฟล์ฐานข้อมูลอื่นจะไม่ถูกแตะต้องเด็ดขาด

### 2.2 การสำรองข้อมูลก่อนปิดกะ และเด้งถามบันทึกสำเนา (`PosWindow.close_current_shift`)
- เมื่อพนักงานแตะปุ่ม **"ปิดกะ"** ในหน้าจอหลัก (ก่อนเข้าขั้นตอนนับเงินของ `FloatCloseDialog` หรือ `CloseShiftDialog`):
  1. ระบบจะรัน `self.backup_before_close()` สำรองลงโฟลเดอร์ `backups/` อัตโนมัติก่อน
  2. หากการสำรองล้มเหลว ระบบจะแสดงข้อความแจ้งเตือนเหตุผลอย่างชัดเจน แต่ **ไม่ขวางการปิดกะ** พนักงานยังคงสามารถนับเงินและปิดกะต่อได้ตามปกติ
  3. หากสำรองสำเร็จ จะเด้งหน้าต่าง popup ขนาดพอดีจอ POS (520x260 px) สไตล์ JET POS Touch:
     - ข้อความ: *"สำรองข้อมูลแล้ว ✓ ต้องการบันทึกสำเนาอีกไฟล์ไว้ที่อื่น (USB / โฟลเดอร์อื่น) ไหม?"*
     - ปุ่ม **[บันทึกอีกไฟล์]** (Primary Button, สูง >= 56px): เปิดหน้าต่าง `QFileDialog.getExistingDirectory` ให้ผู้ใช้เลือกปลายทาง (Flash Drive / External Folder) จากนั้นสำรองข้อมูลซ้ำไปยังปลายทางนั้น และแจ้งพาธไฟล์ที่บันทึกสำเร็จ
     - ปุ่ม **[ข้าม ปิดกะต่อ]** (Secondary Button, สูง >= 56px): ข้ามขั้นตอนคัดลอก และเข้าสู่ขั้นตอนการนับเงินปิดกะเดิมทันที
  4. จดจำโฟลเดอร์ปลายทางที่เลือกไว้ใน `device_settings` คีย์ `last_backup_dir` เพื่อใช้เป็นค่าเริ่มต้นในรอบถัดไป

### 2.3 การสำรองอัตโนมัติวันละครั้งตอนเปิดโปรแกรม (`maybe_daily_auto_backup`)
- ใน `pos_python/backup.py` และเรียกใช้ตอนเปิดโปรแกรม (`main.py` -> `launch_ui` และ `ui.py` -> `run_ui`):
  - ตรวจสอบไฟล์สำรองในโฟลเดอร์ `backups/`
  - หากยังไม่มีไฟล์สำรองเลย หรือไฟล์ล่าสุดมีอายุเกินกว่า 24 ชั่วโมง (86,400 วินาที) ระบบจะดำเนินการสำรองข้อมูลอัตโนมัติอย่างเงียบๆ (Silent) ไม่รบกวนหน้าจอผู้ใช้ และดักจับ error ไว้ไม่ให้กระทบการเปิดโปรแกรม

### 2.4 เมนูปุ่ม "สำรองข้อมูลตอนนี้" ในหน้าตั้งค่าเครื่อง (`SettingsDialog`)
- ในหน้าต่างตั้งค่าเครื่อง (SettingsDialog) หมวด "สำรองฐานข้อมูล":
  - ปรับปรุงปุ่มเป็น **"สำรองข้อมูลตอนนี้"**
  - เรียกใช้ตรรกะการสำรองข้อมูลเดียวกับข้อ 2.2 (สำรองลง `backups/` ก่อน พร้อมเปิด popup ถามบันทึกสำเนาไปยัง USB/โฟลเดอร์อื่น)

### 2.5 แถบแจ้งเตือน PIN ออฟไลน์ใกล้หมดอายุ (`local_cashiers.offline_valid_until`)
- ตรวจสอบฟิลด์ `local_cashiers.offline_valid_until` เมื่อพนักงานเข้ากะขาย (`ensure_sale_session` / `refresh_runtime_context`):
  - ฟังก์ชัน `check_offline_pin_expiry(offline_valid_until, current_time)` คำนวณเวลาที่เหลือ
  - หากเหลือน้อยกว่า 24 ชั่วโมง (หรือหมดอายุแล้ว) จะแสดงแถบแจ้งเตือนสีส้มเด่นชัดที่ขอบบนของหน้าขาย:
    `⚠ PIN ออฟไลน์ของคุณจะหมดอายุ <วันเวลา> เข้าระบบขณะออนไลน์เพื่อต่ออายุ`
    (โดยแปลงวันเวลาเป็นฟอร์แมตภาษาไทยตามเวลาเครื่อง เช่น `10/11/2026 18:30`)
  - หากเหลือมากกว่า 24 ชั่วโมง แถบเตือนจะไม่แสดง (`setVisible(False)`)
  - เมื่อล็อกหน้าจอ (`lock_session`) แถบเตือนจะถูกซ่อน
  - **คงความสมบูรณ์ของระบบ**: ไม่มีการแก้ไขตรรกะการตรวจ PIN หรือการ hash/verify รหัสผ่านใดๆ ทั้งสิ้น เป็นการแสดงผลเตือนเท่านั้น

---

## 3. รายการไฟล์ที่สร้างและแก้ไข

1. **สร้างใหม่**:
   - `apps/pos-python/pos_python/backup.py` — ฟังก์ชัน `backup_database`, `prune_backups`, `maybe_daily_auto_backup`, `check_offline_pin_expiry`
   - `apps/pos-python/tests/test_backup.py` — ชุดทดสอบครอบคลุม 10 รายการทดสอบ
   - `docs/ai/antigravity-pos-backup.md` — เอกสารรายงานฉบับนี้

2. **แก้ไข**:
   - `apps/pos-python/pos_python/touch_dialogs.py` — เพิ่มคลาส `BackupCopyPromptDialog` ขนาดพอดีจอสัมผัส POS
   - `apps/pos-python/pos_python/ui.py` — เชื่อมโยง pre-close backup ใน `close_current_shift`, แถบเตือน PIN ใน `PosWindow`, ปุ่มสำรองข้อมูลใน `SettingsDialog`, และลงทะเบียน `dialog_types`
   - `apps/pos-python/main.py` — เพิ่มการเรียก `maybe_daily_auto_backup` ตอนเปิดโปรแกรม

---

## 4. ผลการทดสอบ (Test Results)

### 4.1 ชุดทดสอบเฉพาะงานสำรอง (`tests/test_backup.py`)
ผ่านทั้งหมด 10 รายการ:
1. `test_backup_database_integrity_and_data_match` — สำรองแล้ว `PRAGMA integrity_check` ได้ `ok` และข้อมูลสินค้า/แคชเชียร์ตรงกับต้นฉบับ 100%
2. `test_backup_integrity_failure_cleans_up_and_raises` — เมื่อ integrity check ล้มเหลว จะ raise error และลบไฟล์สำรองที่เสียหายทิ้งทันที
3. `test_backup_retention_keeps_14_and_does_not_delete_other_files` — สร้างสำรอง 18 ไฟล์ เก็บเหลือ 14 ไฟล์ล่าสุด ลบไฟล์เก่าทิ้ง 4 ไฟล์ และไฟล์อื่นๆ ในโฟลเดอร์ไม่ถูกลบ
4. `test_backup_database_auto_prunes_on_call` — การเรียก `backup_database` ทำการ prune อัตโนมัติเหลือ 14 ไฟล์
5. `test_daily_auto_backup_conditions` — สำรองอัตโนมัติเมื่อเปิดโปรแกรมหากไม่มีไฟล์ หรือไฟล์ล่าสุดเก่ากว่า 24 ชม. และไม่สำรองซ้ำหากไฟล์ยังใหม่อยู่
6. `test_offline_pin_expiry_logic` — ตรวจสอบการเตือน PIN ออฟไลน์: < 24 ชม. เตือน, >= 24 ชม. ไม่เตือน
7. `test_offline_pin_expiry_banner_in_ui` — แสดงแถบเตือนบนหน้าต่าง POS เมื่อพนักงานเข้ากะและ PIN เหลือ < 24 ชม. และซ่อนเมื่อ > 24 ชม. หรือตอนล็อกหน้าจอ
8. `test_backup_failure_does_not_block_shift_close` — กรณีสำรองล้มเหลว แสดงคำเตือนแจ้งเหตุผลชัดเจน แต่ไม่ขัดขวางการปิดกะ
9. `test_backup_prompt_dialog_choices` — หน้าต่าง `BackupCopyPromptDialog` เรนเดอร์ถูกต้อง ขนาดปุ่ม >= 48px ข้อความ >= 16px
10. `test_settings_dialog_backup_now` — ปุ่ม "สำรองข้อมูลตอนนี้" ในหน้าต่างตั้งค่าทำงานถูกต้องและบันทึกไฟล์สำเร็จ

### 4.2 ผลการรัน Regression Test ทั้งหมดของ `apps/pos-python`
- `QT_QPA_PLATFORM=offscreen python3 -m pytest -q`
- ผลลัพธ์: **451 passed** (เดิม 441 passed + เพิ่มใหม่ 10 passed)
- ไม่มีการแก้ไข logic sync / outbox / pricing / VAT / promotions / PIN verification หรือ existing tests เดิมใดๆ ทั้งสิ้น
