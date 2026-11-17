# bplusAPI และการเชื่อม ERP — 2026-11-17

## ขอบเขตและ branch

- ERP ทำบน `codex/bplusapi-erp` แตกจาก `codex/b18-credit-notes` HEAD `7317407` ตามไฟล์งาน เจ้าของยังไม่อนุญาต merge, deploy หรือแตะ production.
- โปรแกรมใหม่เป็น standalone repo `bplusAPI` branch `codex/bplusapi`, local commit `d91bb8706dd90072348f1b0709f55bbc863ebc4d`, ไม่มี remote; คัดลอกพร้อม `.git` ไป `/Users/jetsadapolsan/Documents/Codex/2026-07-18/sites-plugin-sites-openai-bundled/outputs/bplusAPI` แล้ว.
- ไม่ได้รัน `install.ps1`, เชื่อมต่อ BPlus, ใช้ token จริง, หรือแตะเครื่อง `.201`.

## การเปลี่ยนใน ERP

- `bplus_sync_tokens.route_code` เป็น nullable; `NULL` ใช้ได้ทุกสาย. `php artisan bplus:sync-token --issue --all-routes` ออก token แบบนี้ ส่วน token B18 เดิมยังจำกัดเฉพาะ B18 และหน้า `--list` แสดง `ทุกสาย`.
- API ตรวจรูปแบบรหัสสายก่อนนำเข้า. เฉพาะ all-route token ที่ส่งสายซึ่งยังไม่มีใน `sales_areas` เท่านั้นจะสร้าง sales area บนสาขา `HQ` และเล่ม `BOOKING` ที่ใช้รหัสสายเดียวกัน; การ provision, import และ materialize อยู่ใน transaction เดียว. Scoped token ที่ส่งสายอื่นยังได้ 403 และไม่สร้างสาย.
- `GET /api/bplus/sync/ping` ตรวจ bearer token แบบอ่านอย่างเดียว: token ที่ใช้ได้ตอบ 200, token ไม่มี/ผิด/ถูกเพิกถอนตอบ 401; ping ไม่แก้ `last_used_at`.
- Migration ใหม่เปลี่ยนคอลัมน์ `route_code` ให้ nullable โดยไม่แก้ token เดิม. `down()` คง nullable ไว้เพื่อไม่ลบหรือทำให้ all-route token สูญเสีย scope โดยไม่ตั้งใจ.

## ไฟล์ที่เปลี่ยน

### ERP

- `app/Console/Commands/BplusSyncToken.php`
- `app/Http/Controllers/Api/BplusSyncApiController.php`
- `database/migrations/2026_11_17_110000_make_bplus_sync_token_route_nullable.php`
- `routes/api.php`
- `tests/Feature/BplusDocumentsTest.php`
- `docs/ai/PROJECT_MEMORY.md`
- `docs/ai/task.md`
- `docs/ai/codex-bplusapi.md`

### standalone `bplusAPI`

- `.gitignore`, `README.md`, `requirements.txt`, `requirements-dev.txt`, `main.py`
- `bplusapi/config.py`, `history.py`, `httpserver.py`, `legacy.py`, `scheduler.py`, `secrets.py`, `sync.py`, `web.py`, `__init__.py`
- `scripts/bplus-route-export.py` — คัดจาก ERP branch งานใบลดหนี้; เปลี่ยนเฉพาะการอ่าน host/database จาก environment/config. SQL และ extraction logic ไม่เปลี่ยน.
- `install.ps1`, `uninstall.ps1`
- `tests/test_bplusapi.py`, `tests/test_legacy.py`

## หน้าเว็บและการทำงาน

- **Setup/Login:** ตั้งรหัสผู้ดูแลครั้งแรกอย่างน้อย 12 ตัวและเก็บ PBKDF2 hash; session อายุ 8 ชั่วโมง, ทุก form POST ใช้ CSRF.
- **แดชบอร์ด:** การ์ดสายแสดง enabled, วันเริ่ม, รอบล่าสุด, จำนวนเอกสาร/ปัญหา, สถานะ และรอบถัดไป; เพิ่มสาย, เปิด/ปิด, เปลี่ยนวันเริ่มก่อนซิงค์สำเร็จ, ซิงค์ทันทีรายสายหรือทุกสาย.
- **เวลา:** textarea `HH:MM`, ค่าเริ่ม 12:00/15:00/18:00/00:00. thread ใน process ตรวจทุก 30 วินาทีและจับรอบล่าสุดที่พลาดหนึ่งครั้งเมื่อ service กลับมาทำงาน.
- **การเชื่อมต่อ:** BPlus host/database/user/password และ ERP URL/token. ช่อง user/password/token ไม่แสดงค่าที่บันทึกไว้; เว้นว่างเพื่อคงค่าเดิม. BPlus test เป็น `SELECT 1`; ERP test เป็น authenticated ping.
- **ประวัติ:** แสดง 50 รอบล่าสุด, HTTP outcomes (รวม 401/409), เวลา, เอกสารที่รับ/สร้าง, ปัญหา และ log รายเดือน.
- **วันเริ่ม:** หลังสายมีผลสำเร็จแล้ว server ปฏิเสธการแก้ เพราะเลื่อนวันอาจทำให้ใบเก่าถูกนับเป็นลูกหนี้ยกมาซ้ำ.

## ผลทดสอบ

### Python

- `python3 -m compileall -q .` ผ่าน.
- `PYTHONPYCACHEPREFIX=/private/tmp/bplusapi-pycache python3 -m compileall -q . && python3 -m pytest -q`: **15 passed**.
- `git diff --check`: ผ่าน.
- Python ที่ใช้ทดสอบบนเครื่องพัฒนาคือ **3.9.6**; installer ตั้งเป้า Python 3.12 ตามเครื่อง Windows. ส่วน DPAPI/PowerShell ยังไม่ได้ execute บน Windows.
- ชุด HTTP ใช้ `http.server` บน loopback; exporter และ `pymssql` ถูก mock ไม่มีการต่อ BPlus/ERP จริง.

### ERP

- `php artisan test --filter=BplusDocumentsTest`: **42 tests, 42 passed, 528 assertions**.
- `php artisan test`: **1,172 tests, 1,170 passed, 10,423 assertions, 2 skipped, 6 incomplete**, ไม่มี failure/error.
- Migration ทำงานผ่าน SQLite in-memory จาก RefreshDatabase. ยังไม่ได้ execute บน PostgreSQL จริง.
- PowerShell installer/uninstaller จัดเตรียมไว้แต่ไม่ได้ execute บนเครื่อง Windows; ไม่มีการติดตั้งบน `.201`.

## Mutation checks

ถอด guard/logic ชั่วคราวทีละจุด, ตรวจว่า test ที่ตรงจุดล้มเหลวจริง, และคืนไฟล์ต้นฉบับครบ:

| จุดที่ถอดชั่วคราว | ผล |
|---|---|
| all-route token scope check | expected red จาก test ที่ส่ง B20 |
| สร้าง sales area/BOOKING เมื่อ route ขาด | expected red จาก B20 provisioning test |
| บันทึก route scope เป็น `NULL` เมื่อออก all-route token | expected red จาก test ตรวจ token |
| ping ปฏิเสธ token ที่ถูกเพิกถอน | expected red จาก ping test |
| write-only secret field | expected red; secret ปรากฏใน HTML แล้ว test ล้ม |
| CSRF check | expected red; POST setup ที่ไม่มี CSRF ผ่านแทน 403 |
| ล็อกวันเริ่มหลังซิงค์สำเร็จ | expected red; วันเริ่มเปลี่ยนได้ |
| กันซิงค์ route ซ้ำระหว่างทำงาน | expected red; start ซ้ำกลับ true |
| marker scheduler ให้รอบเดียว | expected red; รอบเดิมเริ่มซ้ำ |
| จัดสถานะ ERP 401 | expected red; response 401 ถูกจัดเป็น failed แทน unauthorized |

ERP mutation ทั้ง 4 จุดและ Python mutation ทั้ง 6 จุดให้ test ที่เกี่ยวข้องล้มเหลวจริง แล้วคืนไฟล์ต้นฉบับครบ; suite สีเขียวสุดท้ายรันหลังการคืนไฟล์.

## ขั้นตอนติดตั้งบน `.201` (เจ้าของ/ผู้ตรวจเป็นผู้รัน)

1. ตรวจ branch นี้และ standalone `bplusAPI` รวมถึง `install.ps1`; ห้ามนำรหัสจริงใส่ repo/รายงาน.
2. ผู้ดูแล ERP ออก token เองด้วย `php artisan bplus:sync-token --issue --all-routes --name=bplusAPI` แล้วกรอกในหน้า setting; ไม่ส่ง token ทางแชท.
3. เตรียม `C:\BplusSync\bplus-sync.ini` เดิมถ้าต้องการนำเข้า B18 settings. `install.ps1` ใช้ Python `C:\Program Files\Python312\python.exe`, คัดลอกโปรแกรมไป `C:\bplusAPI` โดยคง `data`, ติดตั้ง dependencies และ import ini โดยไม่พิมพ์ค่าลับ.
4. สคริปต์ตั้ง ACL data ให้ SYSTEM/Administrators, สร้าง Scheduled Task `bplusAPI` แบบ SYSTEM ตอน startup พร้อม restart, เปิด TCP 8765 จาก `LocalSubnet`, start และรอตรวจ listener; หลัง listener ทำงานจึงถอด 4 งาน `BPlus Sync B18 *` และแสดง URL.
5. เจ้าของเปิด URL จากวง LAN, ตั้ง/เปลี่ยนรหัสผู้ดูแล และตรวจ BPlus SELECT 1 กับ ERP ping ด้วย secret ที่เจ้าของกรอกเอง. ห้าม port-forward/เปิดพอร์ต 8765 สู่ internet.
6. `uninstall.ps1` ถอด task/firewall และตัวโปรแกรมโดยเก็บ `C:\bplusAPI\data`.

การเตรียมสคริปต์ไม่ใช่การอนุญาตให้ Codex ติดตั้งหรือให้ใช้รายงานนี้เป็นคำสั่ง deploy.
