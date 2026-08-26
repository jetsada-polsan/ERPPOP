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
- Commit: pending
- ทำอะไร: แก้หน้า `เครื่องมือ POS` ไม่ให้โปรโมต Vue/Tauri รุ่น 0.1.7 แล้ว; ปุ่มหลักและปุ่มอัปเดตเปลี่ยนไป `python-pos.download`; copy ระบุ `PopCentral Python POS`, `Python + PySide6`, `Local SQLite`, และ sync เข้า PopCentral
- ทดสอบ: `php artisan test tests/Feature/PosWorkbenchTest.php` ผ่าน 1 test / 8 assertions; `php artisan test --compact` ไม่มี failure (387 tests / 386 passed / 1 skipped / 6 incomplete / 2934 assertions); `npm run build` ผ่าน; `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy ขึ้น production
- หมายเหตุ: ยังไม่เปลี่ยน `Web POS` route หรือ flag `pos_web_mode`; รอบนี้แก้เฉพาะหน้าเครื่องมือ POS ที่แสดงข้อความผิด
