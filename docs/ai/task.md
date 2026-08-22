# Handoff — 2026-08-22 (Claude)

## Commit

`645b916` (งานของรอบนี้) — HEAD ปัจจุบันคือ `5b577fc` ซึ่งเป็น commit เอกสารกติกาที่ตามมาทีหลัง

ช่วงงานของรอบนี้: `318b17b..645b916` — 4 commit

```
2b1dfa9 Report the device branch from the POS ping
4f41b05 Clean up the ERP address entered on POS setup
1f296c2 Write the POS catalog in one transaction
645b916 Stop blaming the network for POS sync failures
```

## โจทย์ที่ได้รับ

POS บางเครื่องขึ้น "เชื่อม ERP ไม่ได้" หลังใส่ Device Token — ให้ตรวจ URL, Token,
`/api/pos/ping`, สิทธิ์ `pos.sell` และการ sync สินค้า โดยห้ามปิดระบบความปลอดภัยเพื่อแก้ปัญหา

## ผลตรวจ: ฝั่ง server ไม่มีอะไรผิด

- `/api/pos/ping` บน production ตอบ 401 JSON ถูกต้องทั้งกรณีไม่มี token และ token มั่ว
- `pos_devices` ทั้ง 6 เครื่อง: `branch_id` ตรงกับสาขาของ user, มีสิทธิ์ `pos.sell` ครบ,
  ไม่มีเครื่องไหนถูก revoke
- updater manifest `/download/pos/latest.json` และ installer 1.4.9 โหลดได้ปกติ
- **nginx access.log ย้อนหลัง 14 วัน (8–22 ส.ค.) ไม่มี request เข้า `/api/pos` เลยแม้แต่ครั้งเดียว**
  มีแค่ curl ทดสอบของรอบนี้ และ `last_seen_at` ของทุก device หยุดที่ 3 ส.ค.

สรุป: เครื่องที่ผู้ใช้แจ้งว่าพัง **ยิงไม่ถึง server ตั้งแต่แรก** ถ้าเป็น token ผิดหรือสิทธิ์ไม่พอ
request จะต้องโผล่ใน log เป็น 401/403 ก่อน ดังนั้นสาเหตุที่เหลืออยู่คือ URL ที่พิมพ์ผิด
หรือเน็ต/firewall ฝั่งสาขาบล็อก port 80 — ซึ่งแก้ที่โค้ดไม่ได้ ต้องให้คนหน้างานตรวจ

## ทำอะไรไป

แก้บั๊กที่ทำให้ "อาการ" นี้วินิจฉัยยากและทำให้เครื่องแสดงสถานะผิด

1. **`645b916` สถานะเชื่อมต่อรายงานผิด (ตัวหลัก)** — `syncAll()` เอา ping + โหลดสินค้า +
   เขียน SQLite + ส่ง checkout queue มัดใน try/catch เดียวแล้ว `online = false` ทุกกรณี
   เขียน SQLite พังก็ขึ้น "เชื่อม ERP ไม่ได้" ทั้งที่ ERP ปกติ ตอนนี้ ping ตัดสินสถานะ
   เชื่อมต่ออย่างเดียว ที่เหลือขึ้น "ซิงก์ข้อมูลไม่ครบ" พร้อมเหตุผลจริง และเครื่องที่ยังไม่ผูกสาขา
   ถูกเตือนตั้งแต่หน้าตั้งค่าแทนที่จะไปพังตอนเปิดกะ
2. **`4f41b05` ช่อง URL ไม่ถูกล้าง** — ช่อง token ถูก `.trim()` แต่ช่อง URL ไม่ถูก
   เว้นวรรคติดมาจากการ copy / ไม่ใส่ `http://` / วาง `/api/pos` ติดมา = ต่อไม่ติดแบบไม่บอกสาเหตุ
   เพิ่ม `normalizeServerUrl()` และแยก `PosUnreachableError` ออกจาก error ที่ ERP ตอบกลับมา
   คำแนะนำใต้ช่องเดิมเขียนว่า "แนะนำ HTTPS" ทั้งที่ production ไม่ได้เปิด 443 — แก้ข้อความแล้ว
3. **`2b1dfa9` ping คืนสาขาผิดตัว** — `ping` คืนสาขาของ *user* แต่ `/products`, `/shift`,
   `/checkout` บังคับใช้สาขาของ *อุปกรณ์* ผ่าน `enforcedBranchId()` เครื่องที่ user ถูกย้ายสาขา
   จะถือ `branch_id` คนละตัวกับที่ server ยอมรับ แล้วไปตกตอน validate ตอนเปิดกะ
4. **`1f296c2` แคตตาล็อกหายเมื่อซิงก์สะดุด** — `replaceProducts`/`replacePromotions`
   ลบทั้งตารางแล้ว insert ทีละแถว แถวละ commit พังกลางคัน = สินค้าว่างเปล่า ครอบ transaction แล้ว

## ทดสอบไปแล้วแค่ไหน

- `php artisan test` → 134 passed, 1892 assertions (รันซ้ำที่ HEAD `5b577fc` ก็ยังเขียว)
- `cd apps/pos-desktop && ./node_modules/.bin/vue-tsc --noEmit` → exit 0
- เทสต์ใหม่ `tests/Feature/PosDeviceConnectionTest.php` 5 เคส ยิงผ่าน HTTP จริง
  (`withToken()->getJson()`) จึงวิ่งผ่าน middleware `pos.device` ของจริง ไม่ติดกับดัก
  `request()` ตัวกลางที่ `WORKFLOW.md` เตือนไว้
- **พิสูจน์ว่าเทสต์แดงจริง**: `git stash push app/Http/Controllers/Api/PosApiController.php`
  แล้วรันใหม่ → `test_ping_reports_the_branch_bound_to_the_device_not_the_user` ล้ม
  ("Failed asserting that 6 is identical to 5") แล้ว `git stash pop` คืน
- ยิง production จริงแบบอ่านอย่างเดียว: `/api/pos/ping` (401 ทั้งสองกรณี), `/up` (200),
  `latest.json` (200), installer (206 range request), `https://` (connection refused)

## ยังไม่ทดสอบ / ความเสี่ยง

- **`pnpm test` (vitest) รันบน Mac เครื่องนี้ไม่ได้** — rollup native module Team ID
  ไม่ตรงกับ node ที่ยืมจาก Codex.app (`codesign -f -s -` ก็ไม่ช่วย) เทสต์ใหม่
  `apps/pos-desktop/src/lib/server-url.test.ts` จึง **ยังไม่เคยรันผ่าน vitest**
  รอบนี้ตรวจ `normalizeServerUrl()` ครบทุกเคสด้วย node 24 ที่ strip type เองแทน
  (import ไฟล์ `.ts` ตรงๆ ได้เพราะโมดูลนี้ไม่ import ของ Tauri) — ใครมีเครื่องที่รัน vitest ได้
  ช่วยรันยืนยันอีกรอบ
- โค้ดที่แก้ใน `App.vue`/`db.ts` **ยังไม่ได้รันจริงในแอป Tauri** เพราะ build Windows ที่เครื่องนี้ไม่ได้
  โดยเฉพาะ `BEGIN`/`COMMIT`/`ROLLBACK` ผ่าน `@tauri-apps/plugin-sql` ควรลองบนเครื่องทดสอบก่อนปล่อยสาขา
- ไม่ได้แตะ Tauri http scope กับ CSP ที่ล็อกไว้ที่ `27.254.143.219` (ตั้งใจ — เป็นการ์ดความปลอดภัย)
  ผลคือถ้าวันหนึ่งย้าย host หรือเปิดโดเมน ต้องแก้ `capabilities/default.json` + `tauri.conf.json`
  แล้ว build ใหม่ ไม่ใช่แค่พิมพ์ URL ใหม่ในหน้าตั้งค่า

## Deploy

**ยังไม่ deploy** รอเจ้าของอนุญาต แยกเป็นสองฝั่ง

- ERP: มีแค่ `app/Http/Controllers/Api/PosApiController.php` (+ ไฟล์เทสต์) ที่ต้องขึ้น
  ตามขั้นตอนใน `docs/OPERATIONS.md` — ไม่มี migration ใหม่ในรอบนี้
- POS desktop: ต้อง build ใหม่ผ่าน GitHub Actions เป็น **1.5.0** ถึงจะมีผลกับสาขา
  เพราะการแก้ส่วนใหญ่อยู่ในตัวแอป

## งานถัดไป

1. ให้คนหน้างานที่เครื่องพัง รัน `curl http://27.254.143.219/api/pos/ping` ใน CMD
   ได้ JSON 401 = เน็ตดี ปัญหาอยู่ที่ URL ที่พิมพ์ในแอป / ค้างหรือ timeout = firewall สาขาบล็อก port 80
2. ตัดสินใจว่าจะเปิด HTTPS บน production ไหม ตอนนี้ port 443 ปิดสนิท ถ้าเปิดต้องแก้
   Tauri scope + CSP + build ใหม่ด้วย
3. รัน vitest บนเครื่องที่รันได้ เพื่อยืนยัน `server-url.test.ts`
