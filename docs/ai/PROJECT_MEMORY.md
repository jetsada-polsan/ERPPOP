# POPSTAR ERP/POS - ความจำกลางของทีม AI

## Reports Phase 2 — 2026-11-09 (workspace only, not deployed)

- Branch codex/reports-phase2 base44f02b1; pull/stage ถูกปฏิเสธเพราะ Git metadata นอกworkspace ไม่ commit/push. ส่งมอบเต็มและผลทดสอบอยู่หัว docs/ai/task.md.
- นโยบายล่าสุดเจ้าของ: Sales Admin ไม่เห็นต้นทุน/กำไร; Branch Manager เห็นกำไรเฉพาะสาขาที่มอบหมาย; บัญชี/Executive เห็นทั้งบริษัท; คง IT bypass. Migrationใหม่ให้ ACC management.view+reports.all_branches และ EXECUTIVE/BRANCH_MGR management.view โดยไม่เพิ่มสิทธิ์แก้เอกสาร. ยังไม่มีผลกับ production.
- DailySalesReport อ่าน sales_postings กัน POS linked ซ้ำแล้วเติม active signed returns/credit/debit notes. กำไรไม่รวม VAT ใช้ cost_amount snapshotเท่านั้น; ขาดทุนแสดง unknown/coverage ไม่ใช้ product.average_cost ย้อนหลัง. ต้องตัด fieldsทั้ง rows/columns และ paginator collection ก่อน render/export.
- ใบจอง A=DS/B=DSN ใช้วันที่จอง; source_document_id ผูกใบขายsplitสองใบ; confirmed_document_id fallbackเฉพาะใบเก่าไม่มีsource. Pendingหลังundo confirmไม่มียอดขายที่แปลง; ยอดจองแยกจากรายได้.
- Stockหน่วยผสมใช้ qty_per_base_unitจริง/Decimal8; drill product/location มีbranch scope. Aging current-onlyใช้branchของinvoice; GL scopeผ่านdocument/payment document เพราะgl_journalsไม่มีbranch_id. Receiptนับmoney lines; รอพิสูจน์เป็นsubsetไม่บวกรับซ้ำ.
- REPORTS_PHASE_TWO_ENABLED=false หยุดรายงานชุดใหม่ด้วย503 ไม่ปิดsecurity fixes. เพิ่มdelivery_status/role/report-definition migrationด้วยQuery Builder; SQLite suiteผ่าน, PostgreSQL grammar/pretendเท่านั้นยังไม่executeจริง.
- Full suite863tests/7316assertions ไม่มีfailure/error (2skipped/6incompleteเดิม); mutation6จุดแดงจริงและคืนครบ. ไม่deploy/ไม่แก้POS/ไม่แตะproduction. เฟส7–8และproduction-scale benchmarkยังเป็นงานอนาคต.


## P0 money integrity — 2026-11-06 (workspace only)

- งานบน `claude/p0-money-integrity` ยังไม่ commit/push เพราะ Git metadata นอก workspace เขียนไม่ได้; ไม่ deploy/ไม่แตะ production. รายละเอียดและตารางส่งมอบครบ 6 ข้ออยู่หัว `docs/ai/task.md`.
- ใบโอนใหม่อนุมัติเข้า location/lot ระหว่างทาง (ขายไม่ได้) ก่อนตรวจรับเข้าปลายทาง; ขาด/เสียหายสร้างใบตัดชำรุดรออนุมัติและคงมูลค่าไว้จนอนุมัติ. ใบเก่า `transit_location_id=NULL` ลงปลายทางแล้ว ห้ามโอนซ้ำ.
- GL ใช้ component batches + fingerprint + reversal แบบ append-only ใต้ document lock; ห้ามกลับไปลบ journal เมื่อ repost. POS ลง GL หลังสร้าง pos_payments ตามช่องทางจริง; non-cash ที่ยังไม่ผูก role ใช้บัญชีพัก `pos_clearing`. การ match statement ไม่เคลียร์ GL บัญชีพักเอง.
- Purchase cost adjustment เป็น valuation correction: inventory/COGS กับ purchase_cost_clear โดยตาม source_lot_id ทั้ง family รวมของโอน/ระหว่างทาง; ไม่เปลี่ยน AP/VAT/ใบซื้อเดิม. เปลี่ยนราคาตกลงผู้ขายต้องใช้ supplier note แยก. Historical adjustments ที่เคยไม่มี GL ต้องตรวจบัญชีก่อนซ่อม ไม่ backfill จากการเดา.
- ERP รวม qty สินค้าเดียวกันก่อนคิดโปรและรับคิวเก่าที่ส่วนลดน้อยกว่าภายในเพดาน algorithm เดิม; Python promotions.py ต้องปรับใน branch แยก. รายงานภาษีกรอง active แต่คง tax dates เดิม; WHT supplier ใช้ paid_on ส่วนค่าใช้จ่ายใช้ expense_date ซึ่งต้องให้ผู้สอบบัญชียืนยัน.
- Suite สุดท้าย 837 tests ไม่มี failure/error (2 skipped, 6 incomplete เดิม), mutation checks 8 จุดแดงจริงแล้วคืนโค้ดครบ. PostgreSQL ตรวจ DDL เท่านั้น ยังไม่ได้ execute/concurrency test บน PostgreSQL จริง.

> อ่านไฟล์นี้ก่อนเริ่มงานทุกครั้ง ไม่ว่าจะเป็น Codex, Claude หรือ agent อื่น
> แก้ไขเมื่อมีการตัดสินใจเชิงระบบ, deploy, เปลี่ยน flow หรือพบความเสี่ยงใหม่

## ระบบและที่เก็บโค้ด

- GitHub: `jetsada-polsan/ERPPOP`
- Remote: `git@github.com:jetsada-polsan/ERPPOP.git`
- Branch หลัก: `main`
- ERP: Laravel 13, PHP 8.3 (production), PostgreSQL
- POS Desktop หลัก: Python + PySide6 + SQLite ใน `apps/pos-python`
- `apps/pos-desktop` (Vue + TypeScript + Tauri) เก็บไว้เป็น source เก่าเพื่อ rollback/เทียบ logic เท่านั้น ไม่ใช่ช่องทาง POS ที่ประกาศใช้งาน
- Production: `27.254.143.219`, deploy path `/var/www/jeterp`

## หลักการทำงานร่วมกัน

1. ทำงานทีละคนต่อหนึ่งช่วงงานเสมอ
2. ก่อนเริ่ม: `git checkout main && git pull origin main && git status`
3. อ่าน `docs/ai/PROJECT_MEMORY.md`, `docs/ai/task.md` และ commit ล่าสุดก่อนแก้
4. แก้เฉพาะงานที่ได้รับมอบหมาย, ทดสอบตามขอบเขต, commit แบบเล็กและมีความหมาย
5. Push ก่อนส่งต่องานเสมอ แล้วบันทึก commit hash และผลทดสอบใน `docs/ai/task.md`
6. Agent คนถัดไปต้อง `git pull origin main` ก่อนทำงาน ห้ามแก้ไฟล์เดียวกันพร้อมกัน
7. Deploy production ต้องได้รับอนุญาตจากเจ้าของ และห้ามใช้ `rsync --delete`

## ข้อห้ามด้านข้อมูลและความปลอดภัย

- MSSQL legacy `192.168.88.200` ใช้เพื่ออ่านข้อมูลเท่านั้น: อนุญาตเฉพาะ `SELECT`.
- ห้าม `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, migration หรือเขียนข้อมูลใด ๆ ลง MSSQL legacy.
- ห้าม force push, `git reset --hard`, หรือ checkout ทับงานผู้อื่น.
- ห้ามส่ง password, private SSH key, Device Token หรือ PIN ผ่านแชต/commit.
- ห้าม commit `.claude/`, `.codex/`, ไฟล์ backup, `.env` หรือ credentials.

## Flow ธุรกิจที่ตกลงแล้ว

- ใบเสนอราคา: ไม่ตัด/ไม่จองสต๊อก
- ใบจอง: จองเพื่อติดตามการส่ง ไม่ตัดสต๊อกและไม่รับรู้รายได้
- ขายสดหลังบ้าน: ยืนยันเอกสารแล้วตัดสต๊อกและรับรู้รายได้
- ขายเชื่อ: ยืนยันเอกสารแล้วตัดสต๊อกและเปิดลูกหนี้
- POS: บิลเสร็จสมบูรณ์แล้วตัดสต๊อกและรับรู้รายได้
- การส่งของ: ใบจองแบบส่งของไม่ลงบัญชีหรือขยับสต๊อกเมื่อพิมพ์ใบส่งของชั่วคราว; ก่อนขึ้นรถต้องยืนยันจำนวนในใบขึ้นของให้ครบตามใบจอง หากสินค้าไม่ครบให้แก้ใบจองก่อน; บอร์ดขนส่งต้องไล่สถานะขึ้นรถ → กำลังส่ง → ส่งครบ แล้วจึงแปลงเป็นใบขายเชื่อ การแปลงใช้วันที่ส่งจริงและจึงตัดสต๊อก/ตั้งลูกหนี้/ลง GL ครั้งเดียว โดยไม่ลงวันที่ในงวดที่ปิด; บัญชีตรวจเอกสารที่เซ็นกลับหลังบันทึกการส่งและออกใบขาย
- คืน/ใบลดหนี้: ต้องอนุมัติก่อนย้อนรายได้และรับสต๊อกกลับ
- รายงานรวมต้องอ่าน `sales_postings` เพื่อไม่รวมยอด POS กับเอกสารหลังบ้านซ้ำ

รายละเอียด: `docs/pos-flow-redesign.md`

## POS Desktop: ข้อเท็จจริงปัจจุบัน

- SQLite local มี: `products`, `promotions`, `offline_cashiers`, `checkout_queue`, `pos_sale_history`, `app_state`.
- ข้อมูล POS เก็บที่ไดรฟ์ `D:` เมื่อมีไดรฟ์ พร้อม backup, restore และ integrity check.
- POS ต้องได้รับ Device Token ที่ผูกเครื่อง/สาขา จึงเรียก `/api/pos/ping` และซิงก์ได้.
- API POS ใช้ `Authorization: Bearer <Device Token>`; URL production มาตรฐานคือ `https://erp.popstarcenter.com`.
- **ห้ามใช้ HTTP กับ production** เพราะ Device Token อยู่ในทุก request; อนุญาต HTTP เฉพาะ localhost/เครือข่ายทดสอบที่ระบุ `allow_insecure` ชัดเจนเท่านั้น.
- แอป POS ถูกล็อกให้ต่อได้เฉพาะ `27.254.143.219` ผ่าน Tauri http scope
  (`src-tauri/capabilities/default.json`) และ CSP ใน `tauri.conf.json` — ถ้าย้าย host หรือ
  เปิดโดเมนใหม่ ต้องแก้สองไฟล์นั้นแล้ว build ใหม่ ไม่ใช่แค่พิมพ์ URL ใหม่ในหน้าตั้งค่า.
- `/api/pos/ping` คืน `branch_id` ของ **อุปกรณ์** ก่อนของ user เสมอ ให้ตรงกับ
  `PosController::enforcedBranchId()` ที่ `/products`, `/shift`, `/checkout` ใช้จริง.
- สถานะ "เชื่อม ERP ไม่ได้" บนหน้าจอ POS ตัดสินจากผล `ping` เท่านั้น ความล้มเหลวหลังจากนั้น
  (โหลดสินค้า/เขียน SQLite/ส่งคิว) ขึ้นเป็น "ซิงก์ข้อมูลไม่ครบ" อย่าเอาสองอย่างนี้มารวมกันอีก.
- ก่อนมี Token Windows อาจต่ออินเทอร์เน็ตได้ แต่ยังไม่ถือว่าเชื่อม ERP สำเร็จ.
- POS ต้องเขียนบิลลง SQLite ก่อนพิมพ์ แล้วค่อยส่ง `checkout_queue` เมื่อออนไลน์ โดยใช้ idempotency key.
- ข้อมูลสินค้าไม่ขึ้นบน POS หมายถึง catalog sync ล้มเหลว; ให้ดูข้อความ error และกดโหลดสินค้าใหม่.
- POS รองรับโหมดเลือกพนักงานตามอุปกรณ์ในช่วงทดสอบ แต่ก่อนเปิดใช้งานจริงต้องทบทวน PIN/สิทธิ์และการยืนยันตัวตน.
- `apps/pos-central` เป็น candidate ที่นำมาจาก `nongpee321/posCentral` (upstream commit `000a924b`) เมื่อ 2026-10-30; ยังไม่ใช่แอปหลักและ release/installer ยังชี้ `apps/pos-python`.
- candidate ใช้ SQLite schema, path เก็บข้อมูล และชนิดตัวเลขต่างจาก POS หลัก; ยังไม่มี migration ที่รักษาบิล/กะ/เงินเข้าออกที่ค้างส่งหรือข้อมูลยืนยันตัวตนออฟไลน์ จึงห้ามสลับตัวติดตั้งจนกว่าจะทำ migration และตรวจ parity ครบ.
- POS หลักมีคิว `shift_open`, `sale`, `sale_void`, `cash_movement`, `shift_close` และ auth events; candidate ตอนนี้ sync เฉพาะ `sale_checkout`. ต้องพอร์ต/แปลงทุก event และคง idempotency ก่อน cutover.
- candidate ยังใช้ `Float` กับยอด/ปริมาณ; ERP ตรวจราคาขายซ้ำ แต่ต้องตรวจความแม่นยำเงินสด/ปัดเศษและเปลี่ยนเป็น Decimal หรือพิสูจน์ความเท่ากันก่อนเปิดใช้.
- 2026-10-30 เพิ่ม E2E ของ candidate บน Laravel HTTP API + SQLite ชั่วคราว: catalog, PIN login, shift, barcode scan, local-first checkout, receipt, GL และ stock reconciliation ผ่าน; non-demo URL เริ่มต้นและ URL เก่าที่เป็น localhost เปลี่ยนเป็น `https://erp.popstarcenter.com`. มี Windows UAT ZIP workflow สำหรับทดสอบแยก; ยังไม่มีการยิงบิลทดสอบเข้าฐาน production และ installer/release ใช้งานจริงยังชี้ `apps/pos-python`.
- 2026-10-30 หน้าต่างชั่งน้ำหนักของ POS Central ส่งฉลากเข้ารหัสไป `/api/pos/scan` เพื่อใช้น้ำหนักจาก ERP และล็อกน้ำหนักตามฉลาก; เมื่อ offline ใช้ ScaleProfile ที่ซิงก์ไว้. การสแกน SKU/บาร์โค้ดสินค้าปกติเลือกสินค้าและยังให้ผู้ใช้กรอกน้ำหนักเอง. ยังต้องทดสอบด้วยฉลากและเครื่องชั่งจริง.
- 2026-10-30 เจ้าของชี้แจงให้ POS Central ใช้ Production ERP API ระหว่าง UAT ได้ และจะเคลียร์รายการทดสอบก่อนวันขาย; ใบเสร็จจาก API production เป็นเอกสารจริงที่กระทบยอดขาย/สต๊อก/GL จึงต้องจดเลขเอกสารและใช้ขั้นตอน ERP ที่ตรวจสอบย้อนหลังได้. คำสั่งนี้ยังไม่ได้สร้างรายการ production จาก Codex ในรอบเอกสารนี้.
- ป้ายชั่งที่ profile กำหนด `value_type=weight` เก็บค่าบนฉลากเป็นกรัม; API/checkout ต้องตีความเป็นกิโลกรัมก่อนคิดราคาและตัดสต๊อก.

## Web POS ขายออฟไลน์

- Web POS เป็น PWA ขายสำรองตอนเน็ต/Host ล่ม: IndexedDB (`public/pos-offline.js`) + Service Worker (`public/pos-sw.js`)
- คิวบิลส่งที่ `POST /pos/offline-sales/sync` → `PosController::checkout` เดิม, กันซ้ำด้วย `offline_uuid` (`pos_offline_sales`)
- บิลที่รับไม่ได้ไม่ทิ้ง: `needs_review` ที่ `/pos/offline-sales` ห้ามเปลี่ยนให้ Host เชื่อราคาจากเครื่องโดยตรง
- รายละเอียด: `docs/POS-WEB-OFFLINE.md`

## ขอบเขต Legacy และการนำเข้าข้อมูล

- ไม่ import ประวัติ POS legacy เข้าระบบใหม่ เพราะสาขา, ชื่อ, terminal และเลขเอกสารเดิมเปลี่ยนแล้ว และเสี่ยงยอดซ้ำ.
- Legacy ใช้เป็นแหล่งอ้างอิง/กระทบยอดเท่านั้น.
- นำเข้าเฉพาะ master data ที่ mapping ผ่านแล้ว โดยไม่ทับ stock snapshot ของ ERP ใหม่.
- นโยบายเต็ม: `docs/architecture/LEGACY_TABLE_SCOPE_POLICY.md`

## สถานะ commit ล่าสุดที่ต้องรู้

- `5b577fc` Add shared AI project memory and handoff protocol
- `645b916` Stop blaming the network for POS sync failures
- `1f296c2` Write the POS catalog in one transaction
- `4f41b05` Clean up the ERP address entered on POS setup
- `2b1dfa9` Report the device branch from the POS ping
- `318b17b` Clarify POS ERP connection status

`2b1dfa9..645b916` ยัง **ไม่ได้ deploy** — ฝั่ง ERP ต้อง deploy `PosApiController` และฝั่ง POS
ต้อง build 1.5.0 ผ่าน GitHub Actions ถึงจะมีผลกับสาขา (รายละเอียดใน `docs/ai/task.md`)

## ตรวจและปล่อยงาน

- UAT เติมสต๊อกสินค้าทั้งแค็ตตาล็อกใช้ `erp:uat-receive-all-active-products` (GitHub Actions `UAT receive all active products`) ซึ่ง preview อย่างเดียวเป็นค่าเริ่มต้น; คำนวณเติมเฉพาะจำนวนที่ขาดสำหรับหน่วยขายของสินค้าที่เปิดใช้, ใช้ทุนรับเข้าล่าสุด/ทุนเฉลี่ย และผู้ขาย UAT; โหมดรับจริงสร้างใบซื้อเชื่อ เพิ่มสต๊อก ตั้ง AP และลง GL ต้องมีเพดานยอด, username ผู้ดูแลระบบ, คำยืนยัน และไฟล์ lot/วันหมดอายุของสินค้าที่ควบคุมวันหมดอายุครบ ห้ามใช้ต้นทุนหรือวันหมดอายุสมมติ.

- ทุกหน้าดาวน์โหลด POS ต้องแสดงเลขรุ่นของไฟล์ติดตั้งและมีปุ่มดาวน์โหลดหลักเพียงปุ่มเดียว ห้ามใช้ปุ่ม “ดาวน์โหลด” กับ “ดาวน์โหลด/อัปเดต” ที่ชี้ไฟล์เดียวกันจนทำให้ผู้ใช้สับสน.

- Laravel: `php artisan test`
- Health: `php artisan erp:health`
- POS ที่แก้ TypeScript: `cd apps/pos-desktop && ./node_modules/.bin/vue-tsc --noEmit`
- `pnpm test` (vitest) **รันบน Mac เครื่องนี้ไม่ได้** — rollup native module คนละ Team ID กับ node
  ที่ยืมมา ทดสอบ logic ล้วนๆ ชั่วคราวได้ด้วย node 24 ที่ strip type เอง (import ไฟล์ `.ts` ตรงๆ
  ได้ถ้าโมดูลนั้นไม่ import ของ Tauri) แต่ต้องหาเครื่องรัน vitest ยืนยันอีกรอบ
- เครื่อง Mac นี้อาจ build Tauri Windows ไม่ได้; ให้ GitHub Actions เป็นตัวตรวจ installer Windows.
- ก่อน deploy: backup, dry-run, ตรวจ migration สำหรับ PostgreSQL และเช็ก production health หลัง deploy.

## รูปแบบส่งมอบงาน

เพิ่มหรืออัปเดต `docs/ai/task.md` ทุกครั้ง:

```markdown
## Handoff - YYYY-MM-DD
- Commit: `<hash>`
- ทำอะไร: ...
- ทดสอบ: ...
- ยังไม่ทดสอบ/ความเสี่ยง: ...
- Deploy: ยังไม่ deploy / deploy แล้วพร้อมผลตรวจ
- งานถัดไป: ...
```

## สิทธิ์ดูแลระบบหลัง UAT 2026-10-27

- `operations.*` (รวม Backup/restore drill/reset MFA) และ `database-structure.*` ใช้สิทธิ์คำนวณ `system.admin`: ต้องถือ **global** `users.manage` และ `settings.manage` ทั้งคู่ ไม่ให้ settings operator หรือบทบาทสาขาเข้าถึงข้อมูล/คำสั่งดูแลระบบส่วนกลาง
- รายงานรับ UAT 14 ข้อและงานที่ยังรอข้อมูลจริง: `docs/uat/2026-10-27-menu-readiness.md`; `erp:uat-readiness --dry-run` อ่านอย่างเดียว, `erp:remove-test-price-tag --dry-run` ตรวจเฉพาะ TEST01 / TEST-Claude ก่อนลบจริงพร้อม audit ห้ามใช้ยอดเอกสาร 150–200 เป็นเกณฑ์ลบธุรกรรม

## Dashboard และขอบเขตสาขา — 2026-10-31

- Dashboard หลักรวมยอดบริษัทและ breakdown ตามสาขาสำหรับผู้มี `reports.all_branches`; ผู้ใช้อื่นต้องมี `reports.view` และเห็นเฉพาะสาขาที่มีบทบาทรายสาขาใช้งานอยู่ โดยใช้ `users.branch_id` เป็น fallback เมื่อยังไม่มีรายการ `user_branch_roles` เท่านั้น; ไม่มี `reports.view` ให้เห็น dashboard shell แต่ไม่เห็นข้อมูลธุรกิจ
- ผู้จัดการหลายสาขาเลือกสาขาเพิ่มในหน้าผู้ใช้ได้ Dashboard รวมยอดเฉพาะสาขาที่มอบหมายและ breakdown เฉพาะสาขาเหล่านั้น; ห้ามใช้การมี `branch_id` อย่างเดียวเป็นข้อสรุปว่าผู้ใช้นั้นเห็นได้เพียงสาขาเดียว
- ขอบเขตเดียวกันต้องครอบคลุมยอดขาย, POS, ลูกหนี้, จัดซื้อ และข้อมูลคลังบน Dashboard; บทบาท GM, IT_MGR, ACC_MGR และ EXECUTIVE ปัจจุบันมี `reports.all_branches` ใน permission matrix

## รายงานสินค้าคงเหลือ — 2026-10-31

- `inventory.stock_balance` แสดงทะเบียนสินค้าครบต่อที่เก็บจริง รวมรายการที่ยังไม่มีแถว `stock_balances` เป็นยอด 0 โดยไม่สร้าง/แก้สต๊อก; จำนวนทั้งหมดนับก่อนแบ่งหน้า มีปุ่มเปลี่ยนหน้า และค้นชื่อ/รหัส/บาร์โค้ดก่อนแบ่งหน้า (บาร์โค้ดหลายรายการไม่ทำให้ยอดซ้ำ)
- คลังใช้สาขาที่กำหนดคลังหลักก่อน และใช้ `warehouses.branch_id` เป็น fallback สำหรับตำแหน่งเก็บอื่นในคลังที่ระบุเจ้าของ; สิทธิ์สาขายังคำนวณจาก `ReportController` ห้ามให้คำค้น/เปลี่ยนหน้า/ส่งออกข้ามขอบเขตสิทธิ์
- ช่องค้นหารายงานนี้รอหยุดพิมพ์ 500 ms แล้วอัปเดตผลโดยคงโฟกัส/เคอร์เซอร์; ยกเลิกคำขอเก่าและไม่ให้ผลเก่าทับคำค้นใหม่; เลขบาร์โค้ดยังคงเป็นข้อความและยอดจำนวนแสดงตามความละเอียด 8 ตำแหน่งของระบบ
- รายงานนี้อ่านยอดคงเหลือปัจจุบัน; ใบปรับสต๊อก `pending_approval` ยังไม่เพิ่มยอดจริง ต้องให้ผู้มีสิทธิ์อีกคนอนุมัติตามขั้นตอนเดิม

## Shift float - 2026-11-05 (PR #3, not deployed)

- ERP + Vue on `claude/shift-float`; Python POS client is on `claude/pos-python-settings-popup` (0.8.6) and never sends `preview` to an ERP without float support.
- Carried float = the latest closed shift's recorded `float_kept` for that terminal. A legacy/first shift (no recorded float) has carried_float null: no reason demanded and no opening variance booked, so go-live cannot create a fake shortage.

## แผนเลขเครื่องชั่ง — 2026-11-05

- สร้าง PLU ใหม่ได้เฉพาะ `scale-prices.plans.*`: preview ที่เก็บในฐานข้อมูล → ตรวจ/แก้เลขและล็อก → ผู้มี `masterdata.manage` ยืนยัน apply; จัดหมวดและ SKU เริ่ม 801001 ช่วงอนุญาต 801000–801999 หนึ่งเลขต่อ SKU จริงที่ active.
- `scale_plu_plans` เก็บรายการและ before/after สำหรับ audit/rollback; guard row กันแผน apply/rollback ซ้อน และ fingerprint ปฏิเสธแผนเมื่อ catalog/ราคา/profile เปลี่ยนหลัง preview. Rollback ปฏิเสธหากบาร์โค้ด/สินค้าแทน/ราคาเครื่องชั่งเปลี่ยนหลัง apply เพื่อไม่ทับงานภายหลัง.
- Apply ปิด PLU 800/801 เดิมที่เกี่ยวข้อง เก็บแถวเดิมไว้ และปิด SKU ตัวแทนเลข PLU ที่ย้ายไป SKU จริง เพื่อไม่ให้ POS fallback ค้น SKU เดิม; rollback คืนสถานะเดิมและปิดเลขที่สร้างใหม่. หากเลขใหม่มีแถวอยู่แล้วในสินค้าที่อยู่ในชุดแผน จะใช้แถวนั้นภายใต้ unique barcode และเก็บเจ้าของ/สถานะเดิมใน snapshot; ไม่ลบประวัติ.
- เลขที่นำกลับมาใช้ไม่สามารถแยกป้ายเก่าออกจากป้ายใหม่ด้วย EAN-13 ได้ ต้องเก็บป้ายเก่าออกทั้งหมดก่อนเปลี่ยนเครื่องชั่ง และยืนยันคำเตือนเลขเคยใช้. เลขของสินค้าอื่นที่อยู่นอกแผนห้ามทับ; เลขใหม่อาจแก้รายแถวก่อนใช้.
- CSV ตั้งเครื่องชั่ง/HTML ป้าย 1.000 กก. ใช้ราคาตอนสร้างแผนและ profile POPSTAR-801 แบบราคา; ส่งทั้ง PLU 6 หลัก, รหัสท้าย 3 หลัก, รหัสป้าย 5 หลัก และ flag 8. ต้องคงศูนย์นำหน้า และตรวจราคา ERP/เครื่องชั่ง/โปรโมชันก่อนขาย. ยังไม่ deploy หรือแตะ production ในงานรอบนี้.

## ERP UAT bugs — 2026-11-06 (workspace only, not deployed)

- ใบรับคืนและใบลดหนี้ลูกค้าใช้วงเงินร่วม `SaleCreditLimit`: ยอดขายต้นทางลบเอกสาร `SALE_RETURN`/`CREDIT_NOTE` ที่ยังไม่ถูกยกเลิก/ปฏิเสธ/void รวมรายการรออนุมัติด้วย; การชำระหนี้ไม่ใช่การคืนสินค้า จึงไม่หักยอดชำระออกจากเพดานนี้. ตรวจด้วย DecimalMath และ lock ใบขายต้นทางใน transaction ก่อนสร้าง/อนุมัติทั้งสอง flow. ห้ามกลับไปใช้จำนวนสินค้าหรือยอดลูกหนี้ค้างเป็นเพดานคืนเงินอย่างเดียว.
- AP allocation เก็บ `supplier_open_item_id`; เลขอ้างอิงอ่าน `supplierOpenItem.document_no` (fallback `sourceDocument.doc_number`) ไม่ใช่ความสัมพันธ์ลูกหนี้ `openItem.document`.
- Supplier notes และ cash transfers ใช้สิทธิ์ทุกสาขาหรือ active branch assignments ตาม permission ของงาน; ห้ามบังคับด้วย `users.branch_id` อย่างเดียว. Branch assignment ที่หมดอายุ/ปิดแล้วไม่ขยายสิทธิ์กลับเป็นทุกสาขา.
- Preview สต๊อกโหลดตามสาขา/ตำแหน่งเก็บที่เลือกหรือคลังหลักของสาขา; ทุนที่แสดงเป็น `products.average_cost` จริงของระบบเพื่อประมาณการ ไม่ใช่ต้นทุน FIFO ที่จะลงบัญชีตอนตัด Lot. Preview ไม่เขียนสต๊อก; ถ้าโหลดไม่สำเร็จไม่แสดง 0 แทนผลล้มเหลวและไม่ให้ส่งฟอร์ม.
- งานรอบนี้อยู่บน `claude/erp-uat-bugs`; pull/stage/commit ถูก filesystem ปฏิเสธเพราะ metadata อยู่นอก workspace. ไฟล์ยังไม่ commit/push, ไม่มี migration, ไม่แตะ production. ดูตารางงาน/ผลทดสอบใน handoff ล่าสุดของ `docs/ai/task.md`.
