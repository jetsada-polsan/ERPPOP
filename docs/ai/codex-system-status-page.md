# รายงานงาน: หน้าแสดงสถานะระบบและล้างไฟล์ติดตั้ง POS

## Branch และฐานงาน

- Branch: `codex/system-status-page`
- แตกจาก `origin/main` ที่ commit `e315113` (`Windows installer and console fix for BPlus auto-sync (#119)`)
- งานนี้ทำใน clone แยก ไม่เปลี่ยน checkout ERP เดิมที่มีไฟล์ค้างแก้

## สิ่งที่เพิ่ม

- เพิ่ม `GET /system/status` ชื่อ route `system.status` และกำหนดสิทธิ์ `settings.manage` ให้ตรงกับหน้า Settings ผู้ไม่มีสิทธิ์ได้ HTTP 403
- เพิ่มลิงก์ “สถานะระบบ” ในเมนูย่อยตั้งค่า และหน้าแสดงผลภาษาไทยที่ปรับตามมือถือได้ ปุ่ม “รีเฟรช” เปิดหน้าเดิมด้วย GET เท่านั้น
- เพิ่มการ์ดอ่านอย่างเดียว 9 ส่วน: ดิสก์, หน่วยความจำ, CPU, ฐานข้อมูล/จำนวน migration ค้าง, Backup, Queue, รอบซิงค์ BPlus, Token ที่ยังใช้งาน และข้อมูล B18 พร้อมยอด GL Dr/Cr
- แต่ละการ์ดอ่านข้อมูลแยกกันและจับ exception ของตัวเอง; หน้าอื่นยังแสดงได้เมื่อการอ่านส่วนใดส่วนหนึ่งล้มเหลว
- การ์ดดิสก์ใช้ `disk_total_space()` / `disk_free_space()` ที่ path แอป และคำนวณขนาดเฉพาะ `pos-releases`, `pos-python-releases`, `jet-pos-python-releases`, `backups` ไม่ไล่สแกนทั้งเครื่อง
- เพิ่ม `php artisan pos:prune-releases` และ `--dry-run`: ใช้ `version_compare`, เก็บรุ่นล่าสุดของแต่ละโฟลเดอร์และ installer ที่ `latest.json` อ้างถึง, ลบ `*.previous.exe` และ `.sig` ที่ไม่มีไฟล์ `.exe` คู่กัน พร้อมแสดงชื่อไฟล์และขนาด
- หลังเผยแพร่ POS ผ่านหน้า Settings ระบบเรียก prune หลังไฟล์และ manifest ถูกบันทึกสำเร็จ; เพิ่ม schedule รายวันเวลา 03:30 เพื่อเก็บกวาดไฟล์ที่มาจากช่องทาง SFTP

## ข้อจำกัดของตัวอัปโหลด

ตรวจดู workflow และสคริปต์ที่วาง installer แล้ว พบว่า Python POS และ POS desktop อัปโหลดด้วย SFTP-only account ซึ่งไม่มีช่องทางเรียก Artisan บนเครื่องปลายทาง และงานนี้ห้าม SSH รวมทั้งห้ามทำ endpoint สำหรับลบไฟล์ จึงไม่เพิ่ม remote command หรือ API สำหรับ prune ให้ workflow เหล่านั้น งานจาก SFTP จะถูกเก็บกวาดโดย schedule รายวัน ส่วน upload ผ่านหน้า Settings จะ prune ทันทีหลัง publish

ไม่ได้เรียก `erp:health` ในหน้าเว็บ เพราะ command เดิมเขียน `monitor_events` และส่ง alert; ไม่พบ `ErpHealthService` แบบอ่านอย่างเดียวที่นำกลับมาใช้ได้

## ผลตรวจ

- `php artisan test --filter=SystemStatusPageTest`: ผ่าน 5 tests, 48 assertions
- `php artisan test` เต็ม: ผ่าน 1,175 tests, 10,475 assertions, skipped 2, incomplete 6 (รวม 1,177 tests)
- Mutation สิทธิ์: เปลี่ยน mapping `system.status` จาก `settings.manage` เป็น `null` ชั่วคราว; test ล้มตามคาด เพราะผู้ไม่มีสิทธิ์ได้ 200 แทน 403 แล้วคืนโค้ดเดิม
- Mutation hash: เพิ่ม `token_hash` ในผล query และแสดงค่า hash ชั่วคราว; test ล้มตามคาดที่ assertion ว่า HTML ต้องไม่มี hash จริง แล้วคืนโค้ดเดิม
- Mutation prune: ปิดการ `unlink()` ชั่วคราว; test ล้มตามคาดเพราะไฟล์รุ่นเก่ายังอยู่ แล้วคืนโค้ดเดิม
- ตรวจ PHP syntax ทุกไฟล์ที่เพิ่ม/แก้และ `git diff --check` ผ่าน
- ไม่เชื่อมต่อ SSH, ไม่ deploy, ไม่ merge และไม่แตะเครื่อง production/.201
