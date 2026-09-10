# Handoff — 2026-08-23 รอบที่ 4 (Claude) — **Deploy แล้ว**

## Commit

```
bf0bec5 Keep imported POS receipts out of the new sales figures
af47dbe Post stock adjustments and write-offs to the ledger
92dfcd1 Make erp:health notice sales that never reached the ledger
```

## Deploy: ขึ้น production แล้ว 2 รอบ

| รอบ | migration | ผล |
|---|---|---|
| 1 | `143`-`147` (ทะเบียนรายงาน, ใบจอง, เจ้าหนี้, สมุดเงินสด, นโยบายสิทธิ์) | batch [89] ผ่าน |
| 2 | `148`-`149` (flag บิลนำเข้าเก่า, บัญชีผลต่างสต๊อก) | batch [90]-[91] ผ่าน |

ขั้นตอนที่ใช้ทุกรอบ: `erp:backup` + ตรวจ sha256 → `rsync --dry-run --itemize-changes` ตรวจรายการ →
rsync จริง (**ไม่ใช้ `--delete`** ตามกติกา) → `scripts/deploy.sh` → `erp:health`

backup ที่ถ่ายไว้: `erp-db-20260823-062945.sql.gz` (ก่อนรอบ 1) และ `erp-db-20260823-063836.sql.gz` (ก่อนรอบ 2)

**ตรวจก่อน deploy ตามแผน**: `cash_books` = 0 แถวจริง migration จึงสร้างตารางใหม่ได้ปลอดภัย

## ผลที่ได้

### ยอด sales_postings เทียบ GL: 4.58 ล้าน → 2,383 บาท

| ก่อน | หลัง |
|---|---|
| sales_postings 4,599,908.50 · GL 23,479.77 · **ต่าง ~4.58 ล้าน** | sales_postings 22,183.50 · GL รายได้ 18,505.15 + ภาษีขาย 1,295.35 · **ต่าง 2,383.00** |

บิลนำเข้าเก่า 16,537 ใบถูก flag และกันออกจากยอด — **ข้อมูลยังอยู่ครบ 16,557 แถว ไม่ได้ลบ**
rollback migration `148` แล้วยอดกลับมาเหมือนเดิมทันที

### ปรับสต๊อก/ตัดชำรุด ลง GL แล้ว

เพิ่มบัญชี `5030 ผลต่างจากการปรับปรุงสินค้าคงเหลือ` แล้วต่อเข้า
`StockAdjustmentService::approve` และ `StockIssueService::approveDamage`
ของเกิน Dr สินค้าคงเหลือ ของขาด Cr — **มูลค่าคิดจาก Lot ที่ FIFO ตัดจริง** ไม่ใช่ต้นทุนเฉลี่ยปัจจุบัน

ยังไม่ลง GL: แปรรูป, รับผลิต, ค่าเสื่อมราคา (ค่าเสื่อมต้องมีบัญชีค่าเสื่อมสะสมก่อน ผังบัญชียังไม่มี)

## ผลต่าง 2,383 บาทที่เหลือ — หา root cause เจอแล้ว ยังไม่แก้ข้อมูล

**สาเหตุ**: เอกสารขาย 5 ใบแรก (`CS000720260706001` ถึง `CS000720260712001`, 6-12 ก.ค. 2026)
**ไม่มีรายการ GL เลย** เพราะขายก่อนที่ระบบจะต่อ GL — `gl_journals` แถวแรกคือ document 6
สร้าง 12 ก.ค. 09:52 ส่วน 5 ใบนั้นสร้างก่อนหน้านั้นทั้งหมด

| doc | เลขที่ | วันที่ | ยอด | cost_amount |
|---|---|---|---|---|
| 1 | CS000720260706001 | 2026-07-06 | 102.00 | 0 |
| 2 | CS000720260706002 | 2026-07-06 | 464.00 | 0 |
| 3 | CS000720260707001 | 2026-07-07 | 1,038.00 | 0 |
| 4 | CS000720260709001 | 2026-07-09 | 20.00 | 0 |
| 5 | CS000720260712001 | 2026-07-12 | 759.00 | 0 |

**ทำไมผมไม่แก้ให้เอง**

`GlPostingService::postCashSale()` เรียกซ้ำได้ (ลบของเดิมก่อนลงใหม่) และงวด ก.ค. ยัง `open`
ทางเทคนิคจึงกด repost ได้ทันที **แต่ทั้ง 5 ใบมี `cost_amount = 0`**
แปลว่าต้นทุนขายของบิลชุดนั้นไม่เคยถูกบันทึก และสร้างย้อนหลังไม่ได้เพราะไม่รู้ว่าตัด Lot ไหนไป

ถ้า repost ตรง ๆ จะได้เฉพาะขา เงินสด/รายได้/ภาษีขาย ส่วนต้นทุนขายกับสินค้าคงเหลือจะยังขาด
= แก้ตัวเลขหนึ่งให้ตรงแล้วทำอีกตัวเพี้ยนแทน ถ้าจะเดาต้นทุนจากราคาเฉลี่ยปัจจุบันก็คือ
**เขียนตัวเลขที่เดาเอาลงสมุดบัญชีจริง** ซึ่งเป็นการตัดสินใจของนักบัญชี ไม่ใช่ของผม

คำสั่งที่พร้อมรันเมื่อได้ข้อสรุปแล้ว (ทำใน tinker บน production):

```php
foreach (App\Models\Document::whereIn('id', [1,2,3,4,5])->get() as $d) {
    app(App\Services\Accounting\GlPostingService::class)->postCashSale($d);
}
```

## กันไม่ให้เกิดซ้ำ

`erp:health` ตรวจเพิ่มแล้วว่า **เอกสารขายที่ยืนยันแล้วต้องมีรายการ GL เสมอ**
ตอนนี้บน production ขึ้น `ไม่ผ่าน — เอกสารขาย 5 ใบไม่มีรายการ GL รวม 2,383.00 บาท`
ซึ่งจะเขียวเองทันทีที่แก้ข้อมูลเสร็จ

เดือน ก.ค. ไม่มีอะไรจับเรื่องนี้ได้เลย กว่าจะรู้ก็ตอนกระทบยอดสองเดือนถัดมา

## ทดสอบไปแล้วแค่ไหน

`php artisan test` → **178 passed / 2,110 assertions / incomplete 6** (เดิม 171)

เพิ่ม: `InventoryLedgerPostingTest` 4 เคส (ของขาด/ของเกิน/ตัดชำรุด/นับตรงไม่สร้างเอกสาร),
`ErpHealthSalesLedgerTest` 2 เคส, และเคสบิลนำเข้าเก่าใน `SalesReportChannelOverlapTest`

ระหว่างทางเจอว่า `StockAdjustmentService` ปฏิเสธการสร้างเอกสารตั้งแต่ต้นถ้านับตรงกับระบบ
(ดีกว่าที่ผมเดาไว้ เลยเขียนเทสต์ยืนยันพฤติกรรมนั้นแทน)

## ยังไม่ทดสอบ / ความเสี่ยง

- **ยังไม่ได้ทดสอบ migration `148`-`149` บน PostgreSQL แยกก่อน deploy** — รอบนี้ deploy ตรง
  (รันผ่านจริงบน production แล้วและ verify ผลถูกต้อง แต่ไม่ได้ dry-run บนฐานทดสอบเหมือนรอบก่อน)
- ปรับสต๊อก/ตัดชำรุดที่ลง GL ใหม่ **ยังไม่มีใครใช้จริงบน production** (`documents` มีใบปรับสต๊อก 1 ใบ
  ที่สร้างก่อนหน้านี้ ไม่ได้ลง GL ย้อนหลังให้)
- ผู้ใช้ MARKETING ยังเห็นรายงานว่างเปล่าตามนโยบายที่ตกลง ยังไม่ได้กำหนดสาขาให้
- รายงาน P1/P2 29 ตัวยังปิดอยู่ รวม VAT ซื้อ/ขาย — ถ้าบัญชีต้องใช้ปิดเดือนต้องเปิดกลับ

## งานถัดไป

1. **นักบัญชีตัดสินเรื่องต้นทุนของ 5 ใบนั้น** แล้วผมรัน repost ให้ — `erp:health` จะเขียวเอง
2. กำหนดสาขาให้ MARKETING หรือให้สิทธิ์ `reports.all_branches`
3. เปิดรายงาน VAT กลับถ้าบัญชีต้องใช้ปิดเดือน
4. เตรียม parallel run: เปิดบัญชีธนาคาร + ยอดยกมาต้นงวด + เลือกสาขา
5. เครื่อง SQL Server สำหรับวิเคราะห์ BPlus (ยังติดอยู่)

## Handoff - 2026-08-26 (Codex)
- ทำอะไร: แก้ Odoo navigation ให้กดหมวดบนแถบบนแล้วเปิด sidebar กลุ่มตรงกัน และเชื่อมกับตัวกรองหมวด Vue บนหน้า App Launcher
- ทดสอบ: `npm run build`, `php artisan test` (380 tests, 379 passed, 1 skipped, 6 incomplete), `php artisan view:cache`, `git diff --check`
- Deploy: deploy ขึ้น production แล้วหลัง `erp:backup`; rsync แบบ dry-run และจริงโดยไม่ใช้ `--delete`; production `erp:health` ผ่านครบ; manifest และ Launcher JS ตอบ HTTP 200
- หมายเหตุ: deploy เฉพาะ `layout.blade.php`, `AppLauncher.vue`, `manifest.json` และ asset App Launcher ไม่รวมงาน POS/คลังมือถือที่ยังไม่ commit

## Handoff - 2026-08-26 (Codex runtime fix)
- เปลี่ยนจากการจำลอง `.click()` ของปุ่ม Vue เป็น custom event `erp:select-section` ให้ AppLauncher รับคำสั่งโดยตรง; รอบนั้นยังไม่ได้พิสูจน์ root cause ด้วย Browser จึงไม่ควรสรุปว่า `.click()` เป็นเหตุหน้าว่าง
- ทดสอบ: `npm run build`, `php artisan test` (380 tests, 379 passed, 1 skipped, 6 incomplete), `php artisan view:cache`, `git diff --check`
- Deploy: backup `erp-db-20260826-212725.sql.gz`, rsync แบบ dry-run/จริงโดยไม่ใช้ `--delete`, ล้าง cache และ `erp:health` production ผ่านครบ; ตรวจ asset/manifest HTTP 200 และตรวจ source/asset บน Host แล้ว

## Handoff - 2026-08-26 (Codex verified navigation and warehouse)
- Commit โค้ด: `7db1cbf` บนฐาน `7b143e9` ของ Claude
- Launcher: เมนูบนส่งหมวดให้ Vue โดยตรง, การเลือกหมวดด้านในส่งสถานะกลับเมนูบน, "ทั้งหมด" ล้างสถานะหมวดบน, ตัวเลขนับเฉพาะการ์ดที่แสดง และเก็บการเลือกหมวดระหว่างรอโหลด module
- คลังมือถือ: ยืนยันว่า Blade render `tab: &#039;receive&#039;` ทำให้ JavaScript parse ไม่ผ่านและทุก panel ถูก x-cloak ซ่อน; เปลี่ยนเป็น `@js`, เพิ่ม regression tests ทั้งผู้รับสินค้าและผู้เช็คสต๊อกอย่างเดียว
- Stock endpoint: กรองตามสาขาที่เลือก และบังคับสาขาของผู้ใช้ที่ถูกผูกสาขาเสมอ; เพิ่ม tests ป้องกันขอดูข้ามสาขา
- ทดสอบ: `php artisan test --compact` ไม่มี failure (runner รายงาน 384 tests / 383 passed / 1 skipped / 6 incomplete / 2914 assertions); `npm run build`, `php artisan view:cache`, `git diff --check` ผ่าน
- Browser: ใช้ HTML ที่ Laravel render ด้วยบัญชี fixture ใน SQLite in-memory พร้อม asset build จริง บน local server; ทดสอบ Launcher เลือกหมวดทั้งสองทาง/ทั้งหมด/ค้นหาข้ามหมวด, sidebar ปกติและ Escape ล้างคำค้น, คลังมือถือสลับรับเข้า/รับตาม PO/เช็คสต๊อก; ตรวจ screenshot ที่ 1366x900 และ 390x844 ไม่พบหน้าว่างหรือ overflow แนวนอน; ไม่พบ JS error ใน flow เหล่านี้
- ขอบเขต Browser: PO endpoint เป็น fixture คืนรายการว่าง ไม่ใช่การรับสินค้าจริง; ยังไม่ทดสอบเขียนเอกสาร, กล้องมือถือจริง หรือ Windows POS จริง; production Browser ยังติดหน้า login จึงไม่ได้ยืนยันการคลิกบน production
- Assets: ตรวจ manifest 13 entries / 15 files รวม shared chunks ครบใน local build; รอบ deploy ต้องส่ง asset ทั้งชุดก่อนเปลี่ยน manifest ไม่ใช่เฉพาะ launcher JS/CSS; HTTP 200 ของ manifest อย่างเดียวไม่พอ
- Deploy: **รอบแก้ล่าสุดนี้ยังไม่ deploy**; production มีเพียง hotfix รอบก่อนข้างบน ห้ามถือว่า source บน main กับ host ตรงกันแล้ว
- POS: ไม่ได้เรียก `pos:web-mode` หรือเปลี่ยนค่า production; โค้ดปัจจุบันเป็น **global AppSetting** ไม่มี `--branch` จึงยัง cutover รายสาขาไม่ได้ ต้องเพิ่ม branch-scoped flag ก่อนหากต้องการ rollout ทีละสาขา; รอทดสอบ 0.4.0 บน Windows จริงก่อนตัด Web POS
- Deployment warning: `scripts/deploy-ssh.sh` ปัจจุบันยังมี `--delete` ซึ่งขัดกติกา WORKFLOW/PROJECT_MEMORY; ห้ามรันตามเดิม ให้ทำ backup + explicit dry-run/rsync ที่ไม่มี `--delete` ตาม OPERATIONS
- ไฟล์ `package-lock.json` ที่ไม่ tracked ไม่รวมใน commit; ไม่แตะงาน Python POS, DB migration, tokens หรือ PIN

## Handoff - 2026-08-26 (Codex production deploy)
- Commit deployed: `f4ca475`
- Deploy: ขึ้น production `/var/www/jeterp` แล้วด้วย rsync แบบ explicit และ **ไม่ใช้ `--delete`**; exclude `.env`, `storage`, `vendor`, `node_modules`, `.claude`, `.codex`, `package-lock.json` และไฟล์ runtime
- Backup: รันบน host ก่อน deploy สำเร็จที่ `storage/app/backups/erp-db-20260826-223203.sql.gz` พร้อม checksum ตาม `erp:backup`
- Build/assets: รัน `npm run build` ในเครื่อง dev ก่อน deploy; ส่ง `public/build` ทั้งชุดขึ้น host; ตรวจ production manifest ผ่าน `manifest ok 13` และ `/build/manifest.json` ตอบ HTTP 200
- Production commands: `php artisan optimize:clear`, `config:clear`, `route:clear`, `view:clear`, `chown -R www-data:www-data storage bootstrap/cache`, `php artisan migrate --force`
- Health: production `php artisan erp:health` ผ่านครบหลัง deploy: database, migrations, backup, sales-GL, storage, queue
- Routes checked: `/apps`, `/wh/*`, `/pos` และ POS API routes อยู่ครบบน production; `pos_web_mode` ยังเป็น `sell` จึงยังไม่ได้ตัด Web POS ไป redirect
- Browser: In-app Browser production redirect ไปหน้า login และ Chrome connector unavailable จึงยังไม่ได้คลิกยืนยัน `/apps` และ `/wh` ด้วย session จริงบน production; ตรวจได้เฉพาะ HTTP/route/asset/server health
- Local verification before deploy: `php artisan test --compact` ไม่มี failure (384 tests / 383 passed / 1 skipped / 6 incomplete / 2914 assertions), `npm run build` ผ่าน, manifest local ครบ 13 entries

## Handoff - 2026-08-26 (Codex POS menu split)
- Commit: `3ad2144`
- ทำอะไร: แยกหมวดเมนูใหม่ `POS / หน้าร้าน` ออกจาก `ขาย / เอกสาร`; ย้าย `เปิด POS ขาย`, `ศูนย์ควบคุม POS`, `เครื่องมือ POS`, `ส่งข้อมูลไป POS`, `QR รับเงิน / จอแสดงราคา` ไปหมวดใหม่
- รายละเอียด: `ขาย / เอกสาร` เหลือเอกสารขาย/CRM; `SystemSettingController::defaultMenuOrder()` รู้จักหมวดใหม่; `ErpMenu::forUser()` แทรกหมวดใหม่หลัง `งานประจำวัน` แม้ production จะมี `menu_section_order` เก่าที่ยังไม่มี label นี้
- ทดสอบ: `php artisan test tests/Feature/AppLauncherTest.php tests/Feature/ErpMenuIconTest.php` ผ่าน 10 tests / 48 assertions; `php artisan test --compact` ไม่มี failure (386 tests / 385 passed / 1 skipped / 6 incomplete / 2927 assertions); `npm run build` ผ่าน; `git diff --check` ผ่าน
- Deploy: deploy ขึ้น production แล้ว; backup `erp-db-20260826-224714.sql.gz`; rsync dry-run/จริงแบบไม่ใช้ `--delete`; `php artisan migrate --force` ไม่มี migration ค้าง; production `erp:health` ผ่านครบ; ตรวจ production menu order ได้ `ภาพรวม | งานประจำวัน | POS / หน้าร้าน | ...`; manifest production ผ่าน `manifest ok 13`
- หมายเหตุ: ไม่เปลี่ยนสิทธิ์ route เดิม; `QR รับเงิน / จอแสดงราคา` ยังใช้ `settings.manage` ตามเดิมเพราะเป็นงานตั้งค่าอุปกรณ์/PromptPay

## Handoff - 2026-08-26 (Codex Python POS workbench copy)
- Commit: `f81f828`
- ทำอะไร: แก้หน้า `เครื่องมือ POS` ไม่ให้โปรโมต Vue/Tauri รุ่น 0.1.7 แล้ว; ปุ่มหลักและปุ่มอัปเดตเปลี่ยนไป `python-pos.download`; copy ระบุ `PopCentral Python POS`, `Python + PySide6`, `Local SQLite`, และ sync เข้า PopCentral
- ทดสอบ: `php artisan test tests/Feature/PosWorkbenchTest.php` ผ่าน 1 test / 8 assertions; `php artisan test --compact` ไม่มี failure (387 tests / 386 passed / 1 skipped / 6 incomplete / 2934 assertions); `npm run build` ผ่าน; `git diff --check` ผ่าน
- Deploy: deploy ขึ้น production แล้ว; backup `erp-db-20260826-225337.sql.gz`; rsync dry-run/จริงแบบไม่ใช้ `--delete`; ล้าง Laravel cache; production `erp:health` ผ่านครบ; ตรวจ source บน host แล้วมี `Python + PySide6` และ `python-pos.download` โดยไม่มี `Vue + Tauri` หรือ `0.1.7`
- หมายเหตุ: ยังไม่เปลี่ยน `Web POS` route หรือ flag `pos_web_mode`; รอบนี้แก้เฉพาะหน้าเครื่องมือ POS ที่แสดงข้อความผิด

## Handoff - 2026-08-27 (Codex Python POS direction cleanup)
- Commit: `5c09e05`
- ทำอะไร: ให้ Python/PySide6 เป็น POS หลักในข้อความและเอกสารกลาง; เปลี่ยน `/download/pos` เดิมให้ redirect ไป `/download/python-pos`; ปรับหน้า Settings และคำอธิบายแอปไม่ให้ชี้ว่า Vue/Tauri เป็นช่องทางใช้งาน
- ทดสอบ: focused POS tests ผ่าน 7 tests / 25 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบ installer บน Windows จริง และยังไม่ได้เปิด `pos:web-mode redirect`; การเปิดขายจริงต้องผ่าน Windows/hardware UAT ก่อน
- Deploy: ยังไม่ deployรอบนี้ เพราะต้อง commit/push และตรวจการเชื่อมต่อ GitHub ก่อน
- งานถัดไป: push แล้ว deploy source + clear cache + `erp:health`; จากนั้นทดสอบดาวน์โหลดบน host และติดตั้ง Python POS บน Windows จริง

## Handoff - 2026-08-27 (Codex POS web visual parity)
- Commit: pending
- ทำอะไร: ปรับ `/pos` ให้เรียงและใช้โทนตาม Python/PySide6 build: บิล/ตะกร้าซ้าย, สินค้าขวา, แถบค้นหาและหมวดอยู่ด้านบน, แผงสีขาวบนพื้นเทาอ่อน, header และปุ่มใช้ชุดสีเดียวกับ build; มือถือยังเรียงสินค้าไว้ด้านบนเพื่อให้เลือกสินค้าได้สะดวก
- ทดสอบ: ยังรันทดสอบหลัง patch ไม่เสร็จ
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ตรวจ screenshot บน production เพราะ source รอบนี้ยังไม่ push/deploy
- Deploy: ยังไม่ deploy
- งานถัดไป: รันทดสอบ, push และ deploy เมื่อ GitHub เชื่อมต่อได้ แล้วตรวจ `/pos` ด้วย browser

## Handoff - 2026-08-27 (Codex enterprise login skin)
- Commit: pending
- ทำอะไร: ปรับ `resources/views/auth/login.blade.php` เป็น enterprise login แบบ SAP-inspired: พื้นหลัง neutral, split panel คม, สีหลักเดียว, ฟอร์มด้านขวา, ข้อความ UI ภาษาอังกฤษแบบสากล และ responsive สำหรับมือถือ โดยไม่แตะ authentication flow
- ทดสอบ: `php artisan test tests/Feature/ExampleTest.php tests/Feature/DashboardLayoutTest.php --compact` ผ่าน 12 tests / 41 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ screenshot บน production เพราะ commit นี้ยังไม่ push/deploy
- Deploy: ยังไม่ deploy
- งานถัดไป: push และ deploy แล้วตรวจ `/login` บน host ที่ความกว้าง desktop/mobile

## Handoff - 2026-08-28 (Codex POS permission access)
- Commit: `5a7e362`
- ทำอะไร: ให้สิทธิ์ `pos.sell` ซึ่งเป็นสิทธิ์ขาย POS ที่สูงกว่า รวมสิทธิ์เปิดหน้า `pos.use` อัตโนมัติ แก้กรณีแคชเชียร์มีสิทธิ์ขายแต่เมนู POS ถูกซ่อนหรือเข้า `/pos` แล้วได้ 403; ผู้ใช้ที่มี `pos.use` อย่างเดียวยังดูได้แต่เปิดกะ/คิดเงินไม่ได้
- ทดสอบ: `php artisan test tests/Feature/PosWebModeTest.php tests/Feature/PosDeviceConnectionTest.php tests/Feature/UserManagementTest.php --compact` ผ่าน 14 tests / 63 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ deploy; ต้องตรวจผู้ใช้จริงที่ติด `must_change_password` ให้เปลี่ยนรหัสชั่วคราวก่อนเข้าเมนูตามนโยบายความปลอดภัย
- Deploy: ยังไม่ deploy
- งานถัดไป: push แล้ว deploy พร้อมล้าง cache และตรวจ `/pos` ด้วยบัญชีแคชเชียร์จริง

## Handoff - 2026-08-28 (Codex POS sync production test)
- Commit: `8d6d9cb` (source ที่ push แล้ว; deploy ส่งไฟล์โดยไม่ใช้ `--delete`)
- ทำอะไร: นำ source ล่าสุดของ POS Python, Laravel POS API และ permission fix ขึ้น production เพื่อเตรียมทดสอบ sync จริง; ไม่เปลี่ยน `pos_web_mode` และไม่แตะข้อมูลขายเดิม
- ทดสอบ: Python POS unittest ผ่าน 128 tests; production backup สำเร็จที่ `storage/app/backups/erp-db-20260828-220108.sql.gz`; `php artisan migrate --force` ไม่มี migration ค้าง; `php artisan erp:health` ผ่าน database, migration, backup, sales-GL, storage และ queue
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ยิง checkout จริงจากเครื่อง POS เพราะต้องใช้ device token/PIN/terminal ที่ผูกสาขาจริง; production ยังเป็น HTTP จึงต้องตั้ง `allow_insecure=true` ในไฟล์ pair ของเครื่องตามที่ระบบบังคับให้ผู้ใช้ยืนยันเอง
- Deploy: deploy แล้ว พร้อมล้าง Laravel cache และตรวจ health ผ่าน
- งานถัดไป: บน Windows เปิดแอป → pair เครื่อง → ping → sync catalog/cashier → login PIN → เปิดกะ → ขาย 1 บิล → ตรวจเลข receipt และรายการบน `/bplus/pos-workbench`

## Handoff - 2026-08-28 (Codex web POS designer)
- ทำอะไร: เพิ่มหน้า `settings/pos-designer` แบบลากวาง, component whitelist, บันทึก draft และ Build & Publish พร้อม version; เพิ่ม `pos_layout` ใน POS ping เพื่อให้ Python POS แคช layout ใช้งานออฟไลน์ได้
- ทดสอบ: Blade cache, PHP syntax, route list และ Python provisioning 10 tests ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: การจัดวางยังเป็น schema สำหรับ renderer; ต้องทดสอบการแสดงผลบนเครื่อง Windows จริงก่อนเปิดใช้เป็น layout หลัก

## Handoff - 2026-08-28 (Codex profit, CRM and replenishment insights)
- Commit: `4255ca9`
- ทำอะไร: เพิ่ม Profit Intelligence เตือนสินค้าที่ margin ต่ำกว่า 10% ย้อนหลัง 30 วัน; CRM สรุป Pipeline ที่เปิดอยู่และงานติดตามเกินกำหนด; Replenishment แสดงวันคงเหลือและระดับเร่งด่วนพร้อมจำนวนที่ต้องสั่งด่วน
- ทดสอบ: Executive 4 tests / 22 assertions และ CRM + Replenishment 7 tests / 39 assertions ผ่าน; `view:cache`; `git diff --check`; production `erp:health` และ `erp:readiness` ผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: ตัวเลขกำไรขึ้นกับ cost_amount ที่ลงในเอกสารขาย; คำแนะนำเติมเต็มยังควรเทียบกับผู้จัดซื้อและฤดูกาลจริงก่อนอนุมัติ; CRM ยังไม่แทนการยืนยันเครดิตโดยฝ่ายบัญชี
- Deploy: deploy เฉพาะ 6 ไฟล์ที่แก้ขึ้น `/var/www/jeterp` หลัง backup `erp-db-20260828-222527.sql.gz`; clear cache สำเร็จ
- งานถัดไป: ทดลองเปิดหน้า Executive/CRM/Purchase Planning กับสิทธิ์ผู้ใช้จริง และทำ UAT 1 รอบโดยใช้ข้อมูลสาขาจริง

## Handoff - 2026-08-28 (Codex production-host isolated POS UAT)
- Commit: `79de91a`
- ทำอะไร: เพิ่มคำสั่ง `pos:uat-seed` ที่บังคับ `APP_ENV=staging/local/testing` และฐานข้อมูลลงท้าย `_uat`; ใช้เตรียม UAT database แยกบน production host โดยไม่แตะฐาน `jeterp`
- ทดสอบ: สร้าง `jeterp_uat` บน host, migrate 165 migrations, seed 50 สินค้า/50 lots/20 ลูกค้า, pair device, sync catalog 50 รายการและ cashier 19 ราย, login PIN, เปิดกะ, ยิงขายจาก Python ผ่าน Laravel server จริง ได้ receipt `CSUAT20260828001`; `uat:reconcile` ผ่าน 7/7 (GL, เลขซ้ำ, posting, COGS, revenue+VAT, stock)
- ยังไม่ทดสอบ/ความเสี่ยง: void ถูกปฏิเสธ 401 เพราะ fixture ไม่มีสิทธิ์ `pos.void` ซึ่งเป็น policy ที่ถูกต้อง; ยังไม่ได้ทดสอบเครื่องพิมพ์/ลิ้นชักเงินจริง
- Deploy: UAT server หยุดแล้วและ drop ฐาน `jeterp_uat` หลังทดสอบ; ฐาน production `jeterp` ไม่ถูกแก้โดยชุดทดสอบนี้
- งานถัดไป: ทดสอบ void ด้วยบัญชีที่ได้รับ `pos.void` บนฐาน UAT ใหม่เมื่อจำเป็น และทำ Windows hardware UAT

## Handoff - 2026-08-28 (Codex production readiness gate)
- Commit: `6bc534b`
- ทำอะไร: เพิ่มคำสั่งอ่านอย่างเดียว `php artisan erp:readiness` ตรวจฐานข้อมูล, migration, backup+checksum, เอกสารขายลง GL, POS device binding, idempotency ที่ค้าง และคำเตือน queue/E-Tax ก่อนเปิดใช้งานจริง
- ทดสอบ: focused Laravel 15 tests / 67 assertions ผ่าน; Python POS 128 tests ผ่าน; full Laravel 395 tests, 393 passed, 1 skipped, 6 incomplete, 1 unrelated quarantine failure; production `erp:health` และ `erp:readiness` ผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: คำสั่งนี้ตรวจโครงสร้างและความสมบูรณ์ ไม่แทนการขายจริงบน Windows, การตรวจโดยนักบัญชี หรือการตอบรับ E-Tax provider
- Deploy: deploy แล้วที่ `/var/www/jeterp`; backup ก่อน deploy สำเร็จที่ `storage/app/backups/erp-db-20260828-220843.sql.gz`; clear cache และ migrate สำเร็จ
- งานถัดไป: บน Windows เปิดแอป → pair เครื่อง → ping → sync catalog/cashier → login PIN → เปิดกะ → ขาย 1 บิล → ตรวจเลข receipt และรายการบน `/bplus/pos-workbench`

## Handoff - 2026-08-31 (Claude ต่อจาก Codex — User เป็นตัวตน POS/ERP)

- Commit: pending (ยังไม่ commit/push ตามคำสั่ง — รอทดสอบและรับอนุญาตก่อน)
- สถานะที่รับต่อ: Codex แก้ค้างอยู่ตอนย้าย identity POS จาก `salesmen` ไปเป็น `User` (ใช้ครบโควตาเมื่อ 2026-08-31)
  ไฟล์ที่แก้ไว้แล้วตอนรับงาน: migration `2026_08_31_000157_make_users_the_pos_identity.php`,
  `UserPosCredential.php` (ใหม่), `User.php`, `PosApiController.php`, `UserController.php`,
  `BookingController.php`, `BookingService.php`, `users/index.blade.php`, `bookings/index.blade.php`,
  `entry-modal.blade.php`, `entry-assets.blade.php`

### ตรวจแล้วและแก้เพิ่ม
1. **`resources/views/users/index.blade.php`** มีโค้ดตายที่ยังอ้าง `$posCashier` ที่ไม่มีตัวแปรแล้ว
   (ซ่อนอยู่ใต้ `@if(false)` เก่า) — ลบบล็อกนั้นออกแล้ว ไม่กระทบ runtime (เพราะเป็น false branch)
   แต่เป็นโค้ดค้างที่ไม่ควรทิ้งไว้ตามที่สั่ง
2. **`BookingController::index()`** รายการผู้รับผิดชอบใบจอง (`$salesUsers`) เดิมกรองเฉพาะคนที่มีสิทธิ์
   `sales.manage/sales.assign` แบบ role ระดับบริษัทเท่านั้น ไม่รวมคนที่ได้สิทธิ์ผ่าน `branchRoles`
   (สิทธิ์รายสาขา) และไม่รับประกันว่าจะมี current user อยู่ในลิสต์เสมอ (ตรงกับความเสี่ยงที่ระบุไว้) —
   แก้ให้รวมทั้งสอง scope และ `orWhere('id', $currentUser->id)` เสมอ
3. **`app/Console/Commands/SetPosPin.php`** (คำสั่ง `pos:pin` ที่เจ้าของโปรเจกต์รันเองเพื่อออก PIN) —
   พบว่าเขียน PIN ลงเฉพาะ `salesmen.pos_pin_hash` เท่านั้น แต่การล็อกอินตอนนี้อ่าน
   `user_pos_credentials` ก่อนเสมอ (`PosApiController::pinHash()`) ถ้า user มี credential แถวนี้อยู่แล้ว
   คำสั่งนี้จะรันสำเร็จแต่ PIN ที่ตั้งใหม่ใช้ล็อกอินจริงไม่ได้ — แก้ให้ dual-write เข้า
   `user_pos_credentials` ด้วยเมื่อ salesman ผูก user_id พร้อมเช็คชนกับ credential เดิมด้วย
4. **`tests/Feature/UserManagementTest.php`** เทสต์ reset PIN เดิมตรวจแค่ตาราง `salesmen` และ
   audit log ที่ `table_name = salesmen` — เพิ่มให้ตรวจ `user_pos_credentials` (pin_hash,
   force_pin_change, credential_version) และแก้ audit assertion เป็น `table_name = users`,
   `record_id = $user->id` ให้ตรงกับโค้ดใหม่
5. **`tests/Feature/BookingSalesAreaTest.php`** มี 2 เคสที่ยังคาดหวังพฤติกรรมเดิม (ตามที่สั่งให้แก้):
   - เคสที่เคย assert ว่าลูกค้าไม่มีเจ้าของจะถูก "claim" เป็นของผู้สร้างใบจอง — พฤติกรรมนี้ถูกถอดออกแล้ว
     ตามกติกาใหม่ (ไม่ผูก user กับลูกค้าทีละราย) เปลี่ยนเป็นยืนยันว่าลูกค้า**ไม่ถูก claim**
   - เคสที่เคย reject การจองเพราะ "ลูกค้ามีเจ้าของเป็นคนอื่น" — เปลี่ยนเป็นยืนยันว่าตอนนี้จองได้ปกติ
     (เพราะไม่เช็คเจ้าของลูกค้าอีกต่อไป) และเพิ่มเทสต์ใหม่แทนที่คุมกติกาจริงคือ
     "มอบใบจองให้คนอื่นโดยไม่มีสิทธิ์ sales.assign ต้องถูกปฏิเสธ"

### ยังไม่ได้ทำ / ยังไม่ตรวจ (สำคัญ)
- **ยังไม่ได้รัน `php artisan test`, `npm run build`, หรือเทสต์ Python POS เลย** — เครื่องมือที่ผมมีในรอบนี้
  เป็น sandbox แยกที่ไม่มี PHP/Composer/Node ติดตั้ง และไม่มีสิทธิ์ apt/root เพื่อติดตั้งเอง
  (Codex เดิมรันบนเครื่อง Mac จริงโดยตรงจึงมีเครื่องมือครบ) **ต้องรันชุดทดสอบก่อน commit/push จริง**
  ตามกติกา `docs/ai/WORKFLOW.md`
- Python POS (`apps/pos-python`) ยังไม่ถูกแตะเลยในรอบนี้ (ยังอิง flow เดิม) — ตามแผนเดิมต้อง sync ด้วย
  `user_id` แต่ backward-compat ใน API (ส่ง `code`/`legacy_cashier_id` คู่กับ `user_id`/`user_name`)
  ทำให้ POS รุ่นที่ติดตั้งอยู่ยังใช้งานต่อได้ระหว่างนี้
- ยังมี `cashier_code` (ค่า legacy) หลงเหลือใน audit log payload อีก 2 จุด
  (`UserController::alignPosBranch`, `PosApiController::changeCashierPin`) — เป็นแค่ metadata ใน
  audit_logs ไม่ใช่ UI ที่ผู้ใช้เห็น จึงไม่ได้แก้ในรอบนี้ (ไม่กระทบฟังก์ชัน)
- ยังไม่ได้ตรวจ migration `148`-อื่นๆ บน PostgreSQL แยกก่อน — เช็คเฉพาะไวยากรณ์ (Schema builder,
  insertOrIgnore/updateOrInsert) ที่ใช้เป็นแบบเดียวกับ migration เก่าที่ผ่าน production มาแล้ว

### งานถัดไป (ก่อน commit/push)
1. รัน `php artisan test` ให้ผ่านทั้งหมด (โดยเฉพาะ `UserManagementTest`, `BookingSalesAreaTest`,
   `PosPinChangeTest`, และเทสต์ POS อื่นๆ ที่แตะ `PosApiController`)
2. รัน `git diff --check` และ `npm run build` ถ้าแตะ asset
3. ถ้าเทสต์ผ่านครบค่อย commit เป็นชุดเล็กที่มีความหมาย แล้วอัปเดต handoff นี้ด้วย commit hash + ผลทดสอบจริง
4. ห้าม deploy จนกว่าเจ้าของโปรเจกต์สั่ง

## Handoff - 2026-09-06 (Codex ตรวจและแก้ HTTP 500 production)
- Commit: `6dfbfdc` (source ตรงกับ `origin/main`; ไม่มีโค้ดใหม่ในรอบตรวจนี้)
- ทำอะไร: ตรวจ PHP syntax ทั้งโปรเจกต์, route/view cache, Laravel tests, production health และ HTTP smoke test; พบว่า production ยังใช้ source เก่ากว่า `main` จึง deploy source/assets ปัจจุบันแบบ explicit โดยไม่ใช้ `rsync --delete`
- จุดที่แก้หายจาก production source เก่า: product detail ใช้ตัวแปรผิด, sale/print รองรับ stock document หรือ product ที่หายไป, price-table รองรับ orphaned product price และ Vite manifest ของ POS shared CSS
- ทดสอบ: `php artisan test --compact` ผ่าน 420 tests / 419 passed / 1 skipped / 6 incomplete / 3165 assertions; `php artisan view:cache` ผ่าน; `npm run build` ผ่าน; regression route/POS/users ผ่าน 122 tests / 1276 assertions; production `erp:health` ผ่านครบ; production HTTP smoke ทุกหน้าหลัก 200/302 และไม่มี 500; error count หลัง deploy window = 0
- Deploy: สำรองก่อน deploy ที่ `storage/app/backups/erp-db-20260906-110031.sql.gz`; production migration เป็นปัจจุบัน, route/view/config cache ผ่าน, health ผ่าน
- ยังไม่ทดสอบ: POST workflow ที่ต้องใช้ session/สิทธิ์จริงและเครื่อง POS Windows/Printer จริง; รอบนี้ตรวจเฉพาะเส้นทาง GET/HEAD และชุด test ที่ไม่ทำลายข้อมูล

## Handoff - 2026-09-06 (POS transfer identity and reconciliation)
- Commit: pending (ยังไม่ได้ commit/push/deploy รอบนี้)
- ทำอะไร: ให้ข้อมูลการรับชำระแบบโอน/QR เก็บเลขท้ายบัญชีผู้โอน 4 หลักใน ERP และ SQLite โดยไม่เก็บเลขบัญชีเต็ม; เพิ่มช่องกรอกและ validation ใน POS Web/Python; ส่งต่อข้อมูลใน sync payload และพิมพ์ลงใบเสร็จ
- ERP: หน้า POS Control, Monthly Accounting, รายงานใบเสร็จ POS และหน้าเอกสารขาย/ขายสดแสดงวันที่เวลาเต็มและเลขท้ายบัญชีผู้โอนเพื่อเทียบ Statement; เพิ่ม migration `2026_09_06_000158_add_transfer_account_last4_to_pos_payments.php`
- การผูกเครื่อง: ใช้ `pos_devices.user_id` เป็นผู้ใช้ประจำเครื่องอยู่แล้ว; API จะดึง cashier ที่ผูกกับ device user เท่านั้น ขณะที่สิทธิ์สาขา/โมดูลยังคุมการทำงานแต่ละส่วนตามเดิม จึงไม่ต้องสร้าง user POS แยกซ้ำ
- UI: คงหน้าขายเดิม แต่ขยายพื้นที่บิล/รายการทางขวาและบีบพื้นที่สินค้าให้เหมาะกับจอ POS; ปรับ placeholder ให้แยกเลขอ้างอิงการโอนออกจากเลขท้ายบัญชีอย่างชัดเจน
- ทดสอบ: Laravel `php artisan test --compact` ผ่าน 420 tests / 419 passed / 1 skipped / 6 incomplete / 3167 assertions; Python POS unittest ผ่าน 174 tests; POS control regression ผ่าน 7 tests / 33 assertions; `php artisan view:cache` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ: migration และการขายจริงบน production, statement matching จริง, เครื่องพิมพ์ Windows/XPrinter จริง; ต้องทำ backup และขออนุมัติก่อน deploy
- งานถัดไป: review diff แล้ว commit; เมื่อต้องการขึ้น production ให้ migrate แบบมี backup ก่อน แล้วทดสอบบิลโอน 1 ใบและเปิดรายงานเทียบ Statement

## Handoff - 2026-09-06 (Booking stock availability before reservation)
- Commit: pending (ยังไม่ได้ commit/push/deploy รอบนี้)
- ทำอะไร: หน้าใบจองส่ง `branch_id` ไปค้นหาสต๊อกและแสดง `มีอยู่ / จองแล้ว / พร้อมจอง` ต่อสินค้า; เมื่อเปลี่ยนสาขาจะ refresh ยอดให้ตรงคลังสาขานั้น และเตือนก่อน submit หากจำนวนที่กรอกเกินยอดพร้อมจอง
- Server guard: `BookingService` รวมรายการซ้ำเป็นยอดต่อสินค้า, lock `stock_balances` ใน transaction กันการจองชนกัน, ปฏิเสธเมื่อยอดพร้อมจองไม่พอ และไม่สร้างเอกสาร/ไม่เพิ่ม `reserved_qty` เมื่อปฏิเสธ; ไม่ลบหรือแก้ข้อมูลเดิม
- Compatibility: product search ใช้ `LOWER(...) LIKE` แทน `ILIKE` ในส่วนค้นหาสินค้า เพื่อให้ endpoint ที่เพิ่มการแสดงสต๊อกทำงานได้ทั้ง PostgreSQL และ SQLite tests; หากสาขายังไม่มี balance จะแสดงพร้อมจองเป็น 0 และ server จะไม่อนุญาตให้จองเกินจริง
- ทดสอบ: `BookingSalesAreaTest` + `BookingDeliveryDueTest` ผ่าน 10 tests / 38 assertions; `BookingDeliveryAndCashTransferTest` + `CashTransferScreenTest` ผ่าน 13 tests / 50 assertions; PHP lint และ Blade view cache ผ่าน; `git diff --check` ผ่าน
- Full suite: 421 tests, 417 passed, 3 failures อยู่ใน `ErpResetTransactionsTest` เดิม (คำสั่ง reset/UAT ไม่เกี่ยวกับงานนี้), 1 skipped, 6 incomplete; ไม่มี failure จาก booking stock guard
- ยังไม่ได้ทำ: ยังไม่ได้ commit/push/deploy รอบนี้

## Handoff - 2026-09-06 (Claude — เติมสต๊อกข้ามสาขา + ตรวจรับสแกน + แก้ 500 เพิ่มเติม)
- Commit: `a6fafa6`, `92ccf73`, `51d6e6e` (ผู้ใช้ push ขึ้น `origin/main` แล้วตามที่แจ้ง — sandbox นี้ไม่มีเน็ต ตรวจ push จริงที่ origin ไม่ได้ด้วยตัวเอง)
- ทำอะไร:
  1. `a6fafa6` เพิ่มเครื่องมือ "แนะนำเติมสินค้าระหว่างสาขา" — ตาราง `branch_stock_policies` (Min/Max/จุดสั่งเติมแยกต่อสาขา ต่างจาก `products.minimum_stock/maximum_stock` ที่เป็นค่ากลางของ `ReplenishmentService` เดิมซึ่งใช้สั่งซื้อจาก supplier เท่านั้น), `BranchReplenishmentService` คำนวณจากสต๊อกปลายทาง/ยอดขายย้อนหลัง(รวม POS)/ใบขอโอนที่รออยู่แล้ว/เพดานของจริงต้นทาง, หน้าจอเลือกรายการแล้วสร้างเป็น "ใบขอโอน" ผ่าน `StockTransferService::createRequest()` เดิม (ไม่ตัดสต๊อกเอง รออนุมัติปกติ)
  2. `92ccf73` เพิ่ม "ตรวจรับสินค้าโอนย้ายด้วยสแกนบาร์โค้ด" — ตาราง `stock_transfer_receipts`/`stock_transfer_receipt_items` ใหม่ทั้งหมด (แยกจาก stock_documents เดิม), หน้าจอสแกนสไตล์เดียวกับใบตรวจนับสต๊อก, ปิดใบต้องสแกนครบทุกรายการ พบยอดไม่ตรงจะบันทึกไว้เป็นหลักฐานเท่านั้น **ไม่แก้ stock_balances เอง** (ต้องไปสร้างใบปรับสต๊อกแยกถ้ายืนยันว่าจริง)
  3. `51d6e6e` ไล่หา service methods ที่ throw RuntimeException แล้วเช็คว่า controller ที่เรียกครอบ try/catch หรือยัง (สืบเนื่องจากอีก session ที่ทำ security/error-handling audit ค้างไว้ก่อนหมดโควต้า) แก้ 4 จุดที่เงื่อนไขทางธุรกิจปกติหลุดไปเป็น 500: `ProductController::store()`, `MasterDataSetupController::apply()`, `TaxComplianceController::prepare()/prepareEtax()`, `Api\OcrDocumentController` (process/review/approve/reject/postToGoodsReceipt) — แพทเทิร์นเดียวกับที่แก้ `SystemSettingController::issuePosToken()` ไปก่อนหน้านี้ (try/catch RuntimeException → `back()->withErrors()` หรือ JSON 422 แทน 500)
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`/`npm run build` เลย** — เครื่องมือรอบนี้เป็น sandbox ที่ไม่มี PHP/Composer ติดตั้ง (เหมือนที่เคยบันทึกไว้ในรอบ 2026-08-31) ตรวจได้แค่ static analysis: อ่านโค้ดจริงทุกจุดที่แก้, เช็ค brace/paren สมดุลด้วย python, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - Migration ใหม่ 2 ตัว (`2026_09_06_000200_create_branch_stock_policies_table`, `2026_09_06_000300_create_stock_transfer_receipts_table`) ยังไม่เคยรัน `php artisan migrate` เลยแม้แต่ครั้งเดียว — ต้อง migrate ก่อนถึงจะเปิดหน้าใหม่ทั้งสองได้จริง
  - พบช่องโหว่สิทธิ์เพิ่มเติมที่**ยังไม่ได้แก้**: route `search.customers`/`search.products`/`search.stock-balance` ไม่ผูกสิทธิ์โมดูลใน `RoutePermissions.php` เลย ผู้ใช้ที่ login แล้วทุกคนเรียกตรงได้ เห็น `credit_limit` ลูกค้า/`average_cost` สินค้า/สต๊อกข้ามสาขาได้หมด — ตั้งใจไม่แก้เองเพราะ endpoint ถูกใช้ร่วมหลายหน้าคนละสิทธิ์ (ขาย/จัดซื้อ/คลัง/POS) เสี่ยงตัดสิทธิ์คนอื่นถ้าใส่ผิด ต้องไล่เช็คทีละหน้าก่อน
  - ไม่มี account lockout ถาวรหลังเดารหัสผ่านผิดครบจำนวนครั้ง (มีแค่ rate limit 5 ครั้ง/นาทีต่อ username+IP ใน `AuthController` ซึ่งดูเพียงพอ) — เป็นทางเลือกเชิงออกแบบที่รอเจ้าของโปรเจกต์ตัดสินใจ ไม่ใช่บั๊ก
  - GitHub Actions workflow "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า (secrets/vars ระดับ Environment ว่างอยู่) — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
- Deploy: **ยังไม่ deploy ขึ้น production** ต้อง `php artisan migrate` (2 ตารางใหม่) ก่อน แล้วค่อย deploy ตามขั้นตอนปกติของโปรเจกต์ (backup ก่อนเสมอ)
- งานถัดไป: รัน `php artisan test` ยืนยันว่าของเดิมไม่พัง (โดยเฉพาะ 4 controller ที่แก้ 500) และ `php artisan migrate` บนเครื่องที่มี toolchain จริง; ทดสอบ UI ฟีเจอร์เติมสต๊อก/ตรวจรับสแกนกับผู้ใช้จริงก่อนพึ่งพา; ตัดสินใจเรื่อง `search.*` permission gap และ account lockout ก่อนดำเนินการต่อ; ฟีเจอร์ที่ 3-4 ตามแผน (ปิดต้นทุนผลิต+รายงานประสิทธิภาพ, ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม

## Handoff - 2026-09-06 (Claude — ปิดช่องโหว่สิทธิ์ search.* + ปิดต้นทุนผลิต/รายงานประสิทธิภาพ)
- Commit: `016f265`, `9587d7c`, `22eeff2` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร:
  1. `016f265` เอา `credit_limit` ลูกค้าออกจากผลลัพธ์ `/search/customers` — ตรวจแล้วว่าไม่มีหน้าไหนใช้ค่านี้จาก endpoint นี้เลย (ทุกหน้าที่ต้องใช้ credit_limit จริงดึงจาก `Customer` model ตรงๆ) ตัดออกได้โดยไม่กระทบของเดิม
  2. `9587d7c` ผูกสิทธิ์ให้ `SearchController::customers()/products()/stockBalance()` ที่เดิมเปิดให้ผู้ใช้ login แล้วทุกคนเรียกได้โดยไม่เช็คสิทธิ์เลย (ต้นเหตุจาก audit ค้างของอีก session ก่อนหน้า) — ใช้ `authorizeAny()` (OR permission ในตัว controller เอง) เพราะ endpoint พวกนี้ถูกเรียกร่วมจากหลายหน้าคนละสิทธิ์ (ขาย/จัดซื้อ/คลัง/POS) ระบบ `RoutePermissions` เดิมรองรับได้แค่ 1 permission ต่อ 1 route เท่านั้น ไล่เช็ค consumer ทุกจุด (grep ทั้ง view/controller/route('search.xxx')) ก่อนกำหนด permission set ให้แต่ละ endpoint กันตัดสิทธิ์คนที่ใช้งานจริงอยู่โดยไม่ตั้งใจ; `suppliers()/salesmen()/branches()` ปล่อยเปิดต่อ (โค้ด+ชื่อเท่านั้น ความเสี่ยงต่ำ)
  3. `22eeff2` เพิ่ม "ปิดใบสั่งผลิตด้วยมือ" (`ProductionOrder.closed_at/closed_by/close_note` + `ProductionController::closeOrder()`) แก้ปัญหาที่ใบสั่งผลิตค้างสถานะ `in_progress` ถาวรถ้าผลิตได้น้อยกว่าแผน (เดิมมีแค่ปิดอัตโนมัติตอนผลิตครบ/เกินแผนใน `ProductionReceiptService::receive()` เท่านั้น ไม่เคยมีทางปิดงานที่ขาดแผนมาก่อน) และเพิ่มหน้า "รายงานประสิทธิภาพการผลิต" (`production.efficiency`) เทียบแผน vs จริงของระบบผลิต 2 ระบบที่แยกกันและไม่เคยมีรายงานมาก่อนทั้งคู่: (ก) ใบสั่งผลิตตามสูตร - ต้นทุนตามแผนคำนวณจากสูตร x ต้นทุนเฉลี่ยวัตถุดิบปัจจุบัน (ไม่ใช่ต้นทุน ณ วันผลิตจริงเพราะระบบไม่ snapshot ต้นทุนไว้ในสูตร) เทียบต้นทุนจริงจากเอกสาร `PRODUCTION_RECEIPT` ที่ตัด FIFO จริงอยู่แล้ว (ข) งานแปรรูปชั่งน้ำหนัก - แค่รวมยอด yield/margin/ต้นทุน/มูลค่าสูญเสียที่ `StockTransformService` บันทึกไว้ต่อรอบอยู่แล้ว ไม่ได้คำนวณใหม่; ใช้สิทธิ์ `stock.manage` เดิมของ prefix `production.` ผ่าน `RoutePermissions` (ไม่ต้องเพิ่ม mapping ใหม่)
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`/`npm run build`** — sandbox นี้ไม่มี PHP/Composer เหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: อ่านโค้ด/relation/field ทุกจุดที่อ้างอิงเทียบกับ model จริงด้วย grep, เช็ค brace ทุกไฟล์ PHP สมดุลด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach`/`@forelse/@endforelse` ในไฟล์ blade ใหม่/ที่แก้สมดุลครบ, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - Migration ใหม่ `2026_09_06_000400_add_closure_to_production_orders_table` ยังไม่เคยรัน `php artisan migrate` เลย — ต้อง migrate ก่อนถึงจะปิดใบสั่งผลิตด้วยมือหรือเปิดหน้ารายงานประสิทธิภาพได้จริง (หน้ารายงานอ้างคอลัมน์/relation `closed_at`/`closedBy` ที่ยังไม่มีจริงในฐานข้อมูลจนกว่าจะ migrate)
  - ยังไม่เคยทดสอบกับข้อมูลจริงว่าปุ่ม "ปิดงาน" ทำงานถูกต้องกับใบสั่งผลิตที่มีอยู่จริง หรือรายงานประสิทธิภาพคำนวณตัวเลขตรงกับที่คาดไว้หรือไม่ (โดยเฉพาะเคสสูตรผลิตถูกลบ/ไม่ผูกไว้ ที่ควรขึ้น "ไม่มีสูตร" แทนต้นทุนผิด)
  - ผลกระทบจากการผูกสิทธิ์ `search.products`/`search.stock-balance`/`search.customers` ใหม่ยังไม่ได้ทดสอบกับผู้ใช้จริงทุก role — ถ้ามีหน้าจอที่ไม่อยู่ใน permission set ที่กำหนดไว้ (`sales.manage`/`purchasing.manage`/`pos.use`/`stock.manage`/`stock.request`/`masterdata.manage`) จะเจอ 403 ทันที ต้องให้ผู้ใช้ทดสอบทุกบทบาทก่อนเชื่อมั่น 100%
  - GitHub Actions "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า (secrets/vars ระดับ Environment ว่างอยู่) — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
  - Feature 4 ตามแผนเดิม (ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม
- Deploy: **ยังไม่ push/deploy ขึ้น production** ผู้ใช้ต้อง `git push origin main` เอง แล้ว `php artisan migrate` (คอลัมน์ใหม่ 3 คอลัมน์ใน `production_orders`) ก่อน ค่อย deploy ตามขั้นตอนปกติของโปรเจกต์ (backup ก่อนเสมอ)
- งานถัดไป: `git push origin main`; รัน `php artisan test` + `php artisan migrate` บนเครื่องที่มี toolchain จริง; ทดสอบปุ่มปิดงาน/หน้ารายงานประสิทธิภาพ/สิทธิ์ search.* ใหม่กับผู้ใช้จริงทุกบทบาทก่อนพึ่งพา; ตัดสินใจเรื่อง GitHub Actions deploy pipeline; Feature 4 (ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม รอคำสั่งต่อไป


## Handoff - 2026-09-06 (Claude — ปรับต้นทุนซื้อย้อนหลัง Feature 4)
- Commit: `8da5cae` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต/SSH key เข้า GitHub ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร: เพิ่มฟีเจอร์ที่ 4 ตามแผนเดิม "ปรับต้นทุนซื้อย้อนหลัง" — หน้าจอเข้าจากปุ่มในใบซื้อ (purchases.show) ให้แก้ต้นทุนต่อหน่วยของ Lot ที่มาจากใบซื้อนั้น (เช่น กรอกราคาผิดตอนรับของ/ใบแจ้งหนี้จริงราคาต่างจากที่บันทึกไว้)
  - จับคู่รายการในใบซื้อกับ Lot แบบตำแหน่งต่อตำแหน่ง (seq/id ตามลำดับที่สร้างตอนบันทึกใบซื้อ) เพราะ `stock_document_items` ไม่ได้เก็บ `stock_lot_id` ย้อนกลับไว้
  - แก้เฉพาะ `stock_lots.unit_cost` (ผลกับ FIFO ตัดสต๊อกครั้งถัดไป) และ nudge `products.average_cost` ตามสัดส่วนจำนวนคงเหลือของ Lot ต่อยอดคงเหลือทั้งหมด — เป็นค่าประมาณสอดคล้องวิธี moving average เดิมของระบบ ไม่ใช่การคำนวณใหม่ทั้งหมดจาก Lot ทุกใบ (ระบบไม่มีโครงสร้างรองรับการ replay ธุรกรรมทั้งหมด)
  - **ตั้งใจไม่แตะ** `documents.total_amount`/`stock_document_items.unit_cost`/`supplier_ledger`/GL ที่โพสต์ไปแล้วของใบซื้อเดิมเลย และ**ไม่ย้อนแก้ต้นทุนขาย/COGS**ของจำนวนที่ตัดสต๊อกออกไปแล้ว (ตามกฎห้ามแก้ยอดขายย้อนหลัง) — เก็บมูลค่าผลต่างส่วนที่ตัดไปแล้วไว้ในตารางใหม่ `purchase_cost_adjustments` (audit trail แบบ immutable ไม่มี updated_at) ให้บัญชีพิจารณาปรับผ่าน journal แยกเอง
  - เคารพงวดที่ปิดต้นทุนแล้วผ่าน `InventoryCostCloseGuard::assertOpen()` (เดิมมีแค่ observer ผูกอัตโนมัติกับการสร้าง `StockMovement` เท่านั้น ฟีเจอร์นี้ไม่สร้าง movement เพราะไม่มีการเคลื่อนไหวจำนวน จึงต้องเรียก guard เองตรงๆ ในเซอร์วิส)
  - จำกัดสิทธิ์ด้วย `inventory.cost.close` (อยู่ใน `NON_BYPASS_PERMISSIONS` อยู่แล้ว บล็อกแม้ admin ต้องได้รับมอบสิทธิ์ชัดเจนก่อน) เพิ่ม route permission mapping `purchases.cost-adjustments.` แยกจาก `purchases.` เดิมที่ใช้แค่ `purchasing.manage`
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`** — sandbox นี้ไม่มี PHP/Composer เหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: grep เทียบ field/relation ทุกจุดกับ model จริง (`StockLot`, `Product`, `StockBalance`, `Document`, `DecimalMath`), เช็ค brace ทุกไฟล์ PHP สมดุลด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach` ในไฟล์ blade สมดุลครบ, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง (สำคัญมาก เพราะฟีเจอร์นี้กระทบมูลค่าสต๊อกโดยตรง):
  - Migration ใหม่ `2026_09_06_000500_create_purchase_cost_adjustments_table` ยังไม่เคยรัน `php artisan migrate` เลย
  - สูตร nudge `average_cost` เป็น**ค่าประมาณ**ไม่ใช่การคำนวณที่แม่นยำ 100% สำหรับระบบ moving average (อธิบายเหตุผล/ข้อจำกัดไว้ละเอียดในคอมเมนต์ `PurchaseCostAdjustmentService`) — ควรให้ผู้มีความรู้บัญชี/ต้นทุนตรวจทานตรรกะนี้กับเคสจริงก่อนใช้งานจริงกับใบซื้อที่มีมูลค่าสูง โดยเฉพาะเคสที่มีการซื้อ/ขายสินค้าตัวเดียวกันหลายครั้งหลังใบซื้อที่จะปรับ
  - ยังไม่เคยทดสอบเคสที่จำนวนรายการในใบซื้อกับจำนวน Lot ไม่ตรงกัน (โค้ดโยน RuntimeException บล็อกไว้ แต่ยังไม่เคยเจอเคสจริงว่าเกิดขึ้นได้ในระบบหรือไม่)
  - ยังไม่ได้ทดสอบร่วมกับ `InventoryCostCloseGuard` จริง (ต้องมีข้อมูล `inventory_cost_close_periods` ที่ปิดจริงถึงจะยืนยันว่าบล็อกได้ถูกต้อง)
  - GitHub Actions "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
- Deploy: **ยังไม่ push/deploy ขึ้น production** ผู้ใช้ต้อง `git push origin main` เอง แล้ว `php artisan migrate` ก่อน ค่อย deploy ตามขั้นตอนปกติ (backup ก่อนเสมอ) — แนะนำให้ทดสอบฟีเจอร์นี้กับใบซื้อทดสอบในสภาพแวดล้อม staging ก่อนใช้กับข้อมูลจริงเป็นพิเศษ เพราะกระทบมูลค่าสต๊อก
- งานถัดไป: `git push origin main`; รัน `php artisan migrate` + `php artisan test` บนเครื่องที่มี toolchain จริง; ให้ผู้มีความรู้บัญชีตรวจทานตรรกะ nudge average_cost กับเคสจริงก่อนเปิดใช้งานกับผู้ใช้ทั่วไป; Feature 1-4 ตามแผนเดิมครบแล้วทั้งหมด รอผู้ใช้ตรวจสอบและทดสอบก่อนใช้งานจริง

## Handoff - 2026-09-06 (Claude — คู่มือใช้งานแต่ละหน้าจอ ทำหน้าที่อะไร กดอะไรได้บ้าง)
- Commit: `8ca7772` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต/SSH key เข้า GitHub ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร: ผู้ใช้ขอ "คู่มือแต่ละ module ทำหน้าที่อะไร กดอะไรบ้าง" — พบว่าระบบมีหน้าคู่มือ "คู่มือ PopStar 4M" (`core-modules.index` ผ่าน `ManualController`) อยู่แล้ว แต่เนื้อหาเดิม (`pillars()`) เน้นสายข้อมูลเข้า-ออกระหว่างโปรแกรม ไม่ใช่ระดับปุ่ม/การกระทำ จึงเพิ่มเมธอดใหม่ `moduleGuide()` เสริมเข้าไปแทนที่จะสร้างหน้าคู่มือแยกใหม่ (ตามหลักใช้ของเดิมที่มีอยู่แล้ว)
  - ไล่อ่าน `routes/web.php` ทุก route group + Controller ที่เกี่ยวข้องจริง (เขียนสคริปต์ python แกะ `Route::prefix()->name()->group()` ทั้งไฟล์เพื่อให้ได้ mapping เมนู → controller → action ที่แม่นยำ ไม่เดาเอาจากชื่อเมนู)
  - จัดกลุ่มตามเมนูจริงใน `App\Support\ErpMenu` ทั้ง 9 หมวด ครบทุกเมนู (~65 รายการ) — แต่ละเมนูมี "ทำหน้าที่อะไร" (1 ประโยคสั้น) และ "กดอะไรได้บ้าง" (bullet list อ้างอิงจากชื่อ method จริงในคอนโทรลเลอร์ เช่น store=เพิ่ม, approve/reject=อนุมัติ/ไม่อนุมัติ)
  - รวมฟีเจอร์ใหม่ที่เพิ่งเพิ่มเข้าระบบในรอบนี้ไว้ในคู่มือด้วย: แนะนำเติมสินค้าสาขา, ตรวจรับสินค้าโอนย้ายด้วยสแกน, ปิดใบสั่งผลิตด้วยมือ, รายงานประสิทธิภาพผลิต, ปรับต้นทุนซื้อย้อนหลัง
  - เพิ่ม accordion ใหม่ในหน้า `core-modules/index.blade.php` (ใช้ pattern เดียวกับ "คู่มือควบคุมระบบ" เดิมที่มีอยู่แล้วในหน้านี้ - CSS class เดิมทั้งหมด ไม่เพิ่มไลบรารีใหม่) แต่ละเมนูมีปุ่ม "เปิด" ที่ซ่อน/แสดงตามสิทธิ์จริงของผู้ใช้ (ใช้ `$routeAccess` closure เดิมของหน้านี้ที่มีอยู่แล้ว)
- ทดสอบ: **ยังไม่ได้เปิดดูจริงในเบราว์เซอร์** เพราะ sandbox นี้ไม่มี PHP ให้รัน server ทดสอบเหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: เช็ค brace/bracket/paren สมดุลทั้งไฟล์ PHP ด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach`/`<div>/</div>` ในไฟล์ blade สมดุลครบ, ตรวจว่าทุก route name ที่ใช้ใน `moduleGuide()` เป็น route ที่ไม่ต้องมี parameter (ตรงกับที่ `ErpMenu.php` ใช้อยู่แล้วทุกตัว จึงไม่น่าเกิด `route()` error เวลาสร้างลิงก์)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - ยังไม่เคยเห็นหน้าตาจริงว่า accordion ใหม่ render สวยงามถูกต้องหรือไม่ (โดยเฉพาะบนมือถือ/จอเล็ก) ก่อนใช้งานจริงควรเปิดหน้า "คู่มือ PopStar 4M" ดูด้วยตาเปล่าก่อน
  - เนื้อหา "ทำหน้าที่อะไร/กดอะไรได้บ้าง" เขียนจากการอ่านโค้ด (route/controller method names) ไม่ใช่จากการนั่งดูหน้าจอจริงทีละหน้า อาจมีบางจุดที่ปุ่มจริงบนหน้าเว็บใช้คำเรียกไม่ตรงกับที่เขียนในคู่มือเป๊ะๆ ควรให้ผู้ใช้งานจริงช่วยตรวจทานเทียบกับหน้าจอจริงอย่างน้อยรอบแรก
  - เมนูบางตัว (เช่น settings.index ที่รวมหลาย sub-controller) เขียนสรุปรวมเป็นก้อนเดียว อาจไม่ละเอียดเท่าที่ผู้ใช้บางคนต้องการ
- Deploy: **ยังไม่ push/deploy** ผู้ใช้ต้อง `git push origin main` เอง (ไม่ต้อง migrate เพิ่มเพราะรอบนี้ไม่มีการแก้ฐานข้อมูล เป็นแค่เนื้อหาคู่มือ)
- งานถัดไป: `git push origin main`; เปิดหน้า "คู่มือ PopStar 4M" ดูจริงแล้วให้ feedback ว่าอยากได้ระดับความละเอียดเพิ่ม/ลดตรงไหน; ยังค้างจากรอบก่อนหน้าเหมือนเดิม: `php artisan migrate` (migration สะสม 5 ตัวจาก Feature 1-4), `php artisan test`, ตัดสินใจเรื่อง GitHub Actions deploy pipeline และ account lockout

## Handoff - 2026-09-06 (Claude — แก้ 2 บั๊กที่ผลเทสจริงจับได้หลัง push+migrate)
- Commit: `763070f` (ยังไม่ได้ push — ผู้ใช้รัน push-migrate-test.sh ไปแล้วรอบหนึ่งก่อนหน้านี้ commit นี้เป็นของใหม่ที่ commit ทีหลัง ต้อง `git push origin main` เพิ่มอีกครั้ง)
- บริบท: ผู้ใช้รัน `push-migrate-test.sh` บนเครื่องจริงสำเร็จ (push ขึ้น origin/main แล้ว, migrate 5 migration ผ่านแล้ว) แล้วส่งผล `php artisan test` จริงมาให้ดู: **4 failed, 6 incomplete, 1 skipped, 410 passed** — นี่คือรอบแรกที่ได้เห็นผลเทสจริงของงาน Feature 1-4 ทั้งหมดในรอบนี้ (ก่อนหน้านี้ตรวจได้แค่ static analysis เพราะ sandbox ไม่มี PHP)
- ทำอะไร: ไล่ดู 3 ใน 4 เคสที่ fail (ผู้ใช้ส่งภาพมาไม่ครบทั้ง 4) พบว่าเป็นบั๊กจริงที่เกิดจากงานของ session นี้เอง 2 จุด:
  1. `ErpResetTransactionsTest` fail ทั้ง 3 เคส (clears transactions / dry run / rollback message) — สาเหตุ: ตาราง `purchase_cost_adjustments` ใหม่จาก Feature 4 มี FK ไปที่ `documents`/`stock_lots` (อยู่ใน whitelist ของ `ErpResetTransactions::TRANSACTIONAL`) แต่ตัวมันเองไม่ได้อยู่ใน whitelist ทำให้ `foreignKeysFromOutsideWhitelist()` มองว่าเป็น "ตารางนอก whitelist ที่อ้างถึงตารางที่จะล้าง" แล้วบล็อกคำสั่งทั้งหมด (exit 1) ทุกโหมดรวม `--dry-run` ด้วย — แก้โดยเพิ่ม `purchase_cost_adjustments` เข้า `TRANSACTIONAL` (เป็นข้อมูลธุรกรรมที่ควรล้างตอน reset UAT เหมือนตารางอื่น)
  2. `BookingSalesAreaTest::test_booking_shows_and_enforces_available_stock_after_reservations` — สาเหตุ: สร้าง user ทดสอบโดยไม่มีสิทธิ์เลยแล้วเรียก `search.products` ตรง ๆ, `withoutMiddleware(ErpAuthorize::class)` ปิดแค่ route middleware แต่ `authorizeAny()` ที่ผูกเข้า `SearchController::products()` ใน commit `9587d7c` ของรอบก่อนหน้าเช็คสิทธิ์เองในตัว controller แยกต่างหาก จึงได้ 403 — แก้เทสให้สร้าง Role+Permission `sales.manage` ให้ user ก่อนเรียก (จำลองสิทธิ์จริงที่ผู้ใช้หน้าจองต้องมีอยู่แล้วตาม `RoutePermissions: 'bookings.' => 'sales.manage'`) แทนที่จะลดสิทธิ์การเช็คหรือเปิดกว้างขึ้น
- ทดสอบ: เช็ค brace/paren/bracket สมดุลด้วย python (sandbox นี้ไม่มี PHP เหมือนเดิม) และ `git status --short` ก่อนแตะไฟล์ (ไม่มีจุดชนกับ Codex) — **ยังไม่ได้รัน `php artisan test` ซ้ำเพื่อยืนยันว่าแก้ผ่านจริง** ต้องรอผู้ใช้รันอีกรอบ
- ยังไม่ทดสอบ/ความเสี่ยง:
  - เคสที่ 4 ใน "4 failed" (ภาพแรกที่ผู้ใช้ส่งมา ตัดที่ `BookingSalesAreaTest.php:220` แต่ไม่เห็นหัว `FAILED` เพราะภาพตัดตอนบน) ยังไม่ยืนยันว่าเป็นเคสเดียวกับข้อ 2 ที่แก้ไปแล้วหรือเป็นคนละเคส — ต้องรอผลเทสรอบใหม่
  - ยังไม่ได้ดู 6 เคส incomplete และ 1 เคส skipped ว่าเกี่ยวข้องกับงานรอบนี้หรือเป็นของเดิมอยู่แล้ว (ผู้ใช้ไม่ได้ส่งรายละเอียดมาให้)
  - ยังไม่ได้ตรวจว่ามีจุดอื่นที่ใช้ pattern เดียวกับ `ErpResetTransactions::TRANSACTIONAL` (เช่น สคริปต์ backup/restore อื่น ๆ) ที่อาจลืมเพิ่ม `purchase_cost_adjustments` เหมือนกัน
- Deploy: ต้อง `git push origin main` เพิ่มอีกครั้ง (commit `763070f` ใหม่กว่ารอบที่ push ไปแล้ว) ไม่ต้อง migrate เพิ่ม (ไม่มี migration ใหม่ในรอบนี้)
- งานถัดไป: `git push origin main`; รัน `php artisan test` ซ้ำอีกรอบแล้วส่งผลมาดูว่าเหลือ fail กี่เคสและเป็นเคสไหนบ้าง (โดยเฉพาะเคสที่ 4 ที่ยังไม่ชัดเจน + 6 incomplete + 1 skipped); ที่เหลือค้างเหมือนเดิม: ตัดสินใจเรื่อง GitHub Actions deploy pipeline และ account lockout

## Handoff - 2026-09-06 (Claude — ปิดรอบ Feature 1-4: test ผ่านครบหลังแก้ 3 บั๊ก whitelist)
- Commit: `6f7a8d9` (push แล้ว — ผู้ใช้ยืนยันผลเทสหลัง push+migrate+test บนเครื่องจริงครบ 3 รอบ)
- ผลเทสสุดท้ายจากผู้ใช้: **0 failed, 414 passed (3196 assertions), 6 incomplete, 1 skipped** — ผ่านครบ ไม่มี fail เหลือ
  - incomplete 6 เคสเป็นของ `ErpStructuralGapsTest` (ตั้งใจเขียนไว้บันทึกช่องว่างที่ยังไม่แก้ ไม่ใช่บั๊ก) และ skipped 1 เคสเป็นของ `ReportSmokeTest` (รันได้เฉพาะ PostgreSQL เครื่องผู้ใช้ทดสอบด้วย SQLite) — ทั้งสองกลุ่มไม่เกี่ยวกับงานรอบนี้เลย ตรวจแล้วว่าเป็นของเดิม
- สรุปที่แก้เพิ่มจากผลเทสจริงรอบนี้ (ต่อจาก `763070f`): พบว่า `stock_transfer_receipts`/`stock_transfer_receipt_items` (จาก migration `2026_09_06_000300` ของรอบก่อนหน้า Feature 3-4) ก็ไม่ได้อยู่ใน `ErpResetTransactions::TRANSACTIONAL` เหมือนกัน (มี FK ไปที่ `documents`) ทำให้เทส "a failure midway rolls everything back" fail ด้วยสาเหตุเดียวกับ `purchase_cost_adjustments` ก่อนหน้า — เพิ่มเข้า whitelist แล้ว (`6f7a8d9`) พร้อมไล่ตรวจ FK ทุกจุดในทุก migration ของทั้งโปรเจกต์ด้วยสคริปต์ python ยืนยันว่าไม่มีตารางไหนหลุด whitelist แบบนี้อีก
- สถานะ Feature 1-4 ทั้งหมด: **เสร็จ + push + migrate + test ผ่านครบแล้ว** พร้อมใช้งาน แต่ยังต้องการการตรวจทานเพิ่มก่อนใช้งานจริงเต็มรูปแบบ (ดูหัวข้อถัดไป)
- ยังไม่ทดสอบ/ความเสี่ยง (ของเดิมที่ยังค้างอยู่ ไม่เกี่ยวกับบั๊กที่เพิ่งแก้):
  - ยังไม่เคยทดสอบ UI จริงของปุ่ม "ปิดงาน", หน้ารายงานประสิทธิภาพผลิต, หน้าปรับต้นทุนซื้อย้อนหลัง, หน้าคู่มือ PopStar 4M (accordion ใหม่) กับข้อมูลจริงในเบราว์เซอร์
  - สูตร nudge `average_cost` ใน Feature 4 เป็นค่าประมาณ ควรให้ผู้มีความรู้บัญชีตรวจทานก่อนใช้กับใบซื้อมูลค่าสูง
  - สิทธิ์ `search.*` ใหม่ (`authorizeAny` ใน `SearchController`) ผ่านเทสแล้วแต่ยังไม่ได้ทดสอบกับผู้ใช้จริงทุก role ในระบบ (เทสอัตโนมัติครอบคลุมแค่เคสที่เขียนไว้)
  - GitHub Actions "Deploy ERP" ยัง fail ค้างจาก session ก่อนหน้า (secrets/vars ว่าง) — ยังไม่มีคำตอบเรื่อง production deploy
  - ยังไม่มี account lockout ถาวร (ทางเลือกเชิงออกแบบ รอเจ้าของโปรเจกต์ตัดสินใจ)
- งานถัดไป: `git push origin main` (commit เอกสารนี้), ทดสอบ UI ฟีเจอร์ใหม่ทั้งหมดกับผู้ใช้จริงก่อนพึ่งพา 100%, ให้ผู้มีความรู้บัญชีตรวจทาน Feature 4, ทดสอบสิทธิ์ search.* กับผู้ใช้ทุก role, ตัดสินใจเรื่อง deploy pipeline และ account lockout — ไม่มีงานเขียนโค้ดใหม่ค้างจากแผนเดิมแล้ว รอคำสั่งต่อไป
## Handoff - 2026-09-09
- Commit: `497f213` และงานเปลี่ยนตัวสแกนรอ commit
- ทำอะไร: เพิ่ม flow คลังมือถือสำหรับนับสต๊อกแบบ draft: เปิดรอบนับ partial, สแกน QR/บาร์โค้ด, แสดงยอดระบบ, กรอกยอดจริงและบันทึกทีละรายการ; เพิ่ม endpoint ที่ใช้ StockCountService เดิม และเปลี่ยนกล้องเป็น ZXing Browser (`@zxing/browser`, MIT) รองรับ QR/EAN และ 1D/2D หลายแบบ
- ทดสอบ: `php artisan route:list --name=wh.stock-counts --no-ansi` ผ่าน; `php artisan test --compact` ผ่าน 419/422 โดยมี 2 เคสเดิมใน `PosPaymentValidatorTest` ล้มเหลวเพราะข้อความ error ถูกเปลี่ยนจากงานก่อนหน้า
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบกล้องจริงบนมือถือและยังใช้ CDN ของ html5-qrcode; ยังไม่ได้ deploy production
- Deploy: ยังไม่ deploy
- งานถัดไป: ติดตั้ง bundle ของ html5-qrcode ภายในโปรเจกต์แทน CDN หากต้องรองรับ offline/PWA และทดสอบ flow ด้วยข้อมูลสาขาจริง
## Handoff - 2026-09-10
- Commit: `a70aaf5`
- ทำอะไร: เพิ่มโมดูลบอร์ดขนส่ง `/fleet/board` ผูกใบจอง delivery กับรถและสถานะใบจอง/ขึ้นรถ/กำลังขนส่ง/ส่งแล้ว/รับเงินแล้ว/ยกเลิก พร้อมบันทึกเงินสดหรือโอน จำนวนเงิน และเลขบัญชีท้าย 4 หลัก; เก็บ `transport_jobs` เป็นประวัติถาวรและไม่รวมใน reset transactions
- ทดสอบ: `php artisan test --compact` ผ่าน 421/422, `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ deploy production; งานขนส่งเก่าที่ไม่มี job จะถูกสร้างเมื่อเปิดบอร์ดครั้งแรก
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy migration และทดสอบบอร์ดกับใบจองจริง
# Handoff — 2026-09-10 (Codex Fleet report)

## Commit
`d4e9a31`

## ทำอะไรไป
- เพิ่ม route และหน้า `fleet/report` สำหรับกรองช่วงวันที่/รถ และสรุประยะทาง น้ำมัน ค่าน้ำมัน ค่าซ่อม และค่าใช้จ่ายต่อกิโลเมตรแยกรถ
- ปรับการบันทึกการวิ่งและซ่อมให้ปฏิเสธเลขไมล์ที่น้อยกว่าเลขไมล์ปัจจุบันของรถ
- เพิ่มลิงก์เข้ารายงานจากหน้าจัดการรถ

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,217 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- deploy production แล้วผ่าน GitHub Actions run `34447570421`
- รายงานคำนวณจากข้อมูลการวิ่ง/ซ่อมที่มีอยู่ ยังไม่ลงบัญชีค่าใช้จ่ายอัตโนมัติ
# Handoff — 2026-09-10 (Codex Transport driver flow)

## Commit
`2ca6ec7`

## ทำอะไรไป
- เพิ่มหน้าคนขับมือถือที่ `/fleet/driver` สำหรับเลือกงานและเปลี่ยนสถานะ ขึ้นรถแล้ว → เริ่มส่งของ → ส่งสำเร็จ
- เพิ่มลิงก์จากบอร์ดขนส่งไปหน้าคนขับ
- ป้องกันปุ่มสถานะที่ไม่ถูกลำดับและปิดการแก้ไขเมื่อส่งสำเร็จ

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,221 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- deploy production แล้วผ่าน GitHub Actions run `34450358978`
- ใบขึ้นของแบบตรวจมี/ไม่มีและแก้จำนวนยังเป็นงานถัดไป
# Handoff — 2026-09-10 (Codex transport load sheet)

## Commit
pending

## ทำอะไรไป
- เพิ่ม `transport_load_items` สำหรับใบขึ้นของ ผูกกับรายการสินค้าในใบจอง พร้อมสถานะมีครบ/มีบางส่วน/ไม่มี และจำนวนขึ้นรถจริง
- เพิ่มหน้า `/fleet/load-sheet/{booking}` สำหรับตรวจ แก้จำนวน และพิมพ์ใบส่งของ
- เพิ่ม endpoint บันทึกใบขึ้นของและป้องกันจำนวนส่งเกินจำนวนในใบจอง
- เพิ่ม migration table ใบขึ้นของในรายการ reset transactions

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,226 assertions
- `php artisan test tests/Feature/ErpResetTransactionsTest.php --compact` ผ่าน 8 tests / 40 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy production รอบนี้
- ยังไม่ได้บังคับให้สถานะขึ้นรถต้องผ่านการบันทึกใบขึ้นของครบทุกบรรทัด; ควรเพิ่มเป็น policy ก่อนเปิดใช้งานจริง
