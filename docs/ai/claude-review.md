# ผลตรวจ — 2026-11-26 รอบ 2 (PR #148 ของ Antigravity)

## ช่วง commit ที่ตรวจ

`1e780b1f..fc7057c6` (branch `antigravity/system-hardening`, PR #148) — 7 commit ทำตาม `docs/ai/ANTIGRAVITY_SYSTEM_HARDENING_PROMPT.md`

รอบก่อน (ตรวจทั้งระบบหลัง deploy #423) ดูได้จาก git history ของไฟล์นี้ที่ `a0ef5713`

## สรุป

ต้องแก้ก่อน merge 2 ข้อ: Guzzle ข้าม major version และ security headers ไม่ติดหน้า redirect, 404 และ 403
งานที่เหลือของ PR ใช้ได้

## สิ่งที่รันจริง

- อ่าน diff ของโค้ด เทสต์ และ `composer.json` ทุกไฟล์ (ไม่รวม `composer.lock` ทั้งไฟล์ ดูเฉพาะเวอร์ชันที่เปลี่ยน)
- PR #148 บน GitHub: check ผ่าน 2/2 (รวม PostgreSQL UAT บน PHP 8.3) และไม่มี conflict
- `composer show guzzlehttp/guzzle --all`: มี 7.17.0 และ advisory ของ Guzzle ทุกตัวโดนเฉพาะ `<7.15.2` หรือ `>=8.0.0,<8.0.1`
- เทสต์ probe นอก repo วัด query ต่อหน้า โดยใช้ cache และ session แบบ database ตาม default ของ `config/` และ `.env.example`
  - admin เปิด 6 หน้า หน้าละ 1 ครั้งหลัง warm-up
  - ตรวจ header ของ 302, 404 และ `/up`
- `php -d memory_limit=2G vendor/bin/phpunit` บน `fc7057c6` (Windows, PHP 8.5.12, หลัง `composer install` จาก lock ใหม่): 1,189 tests, 1,186 passed, 0 failed, 3 skipped, 6 incomplete, 10,688 assertions

## สิ่งที่พบ

### 1. Guzzle ข้าม major version — ต้องแก้
- ไฟล์: `composer.lock`
- อาการ:
  - `guzzlehttp/guzzle` 7.13.1→8.6.0, `psr7` 2→3, `promises` 2→3 และ `uri-template` 1→2 ทั้งที่พร็อมป์ห้ามข้าม major
  - Laravel 13.44 ยอมรับ `^7.8.2 || ^8.0` ทำให้ composer เลือก 8
  - ระบบยิง LINE ผ่าน `Http::` (`AlertDispatchService`, `LineWebhookController`, `MemberPortalController`) แต่เทสต์ใช้ `Http::fake()` จึงไม่ได้ทดสอบการยิงจริงบน Guzzle 8
- เสนอ: require `guzzlehttp/guzzle: ^7.17` ซึ่งปิดช่องโหว่ครบแล้ว และอัปเดตเฉพาะตระกูล guzzle

### 2. security headers ไม่ติดหน้า redirect, 404, 403 และ `/up` — ต้องแก้
- ไฟล์: `bootstrap/app.php`
- อาการ: `SecurityHeaders` ถูกต่อท้ายกลุ่ม `web` หลัง `ErpAuthorize`
  - เมื่อ `ErpAuthorize` redirect guest หรือ abort 403 และกรณี URL ไม่มี route ตัว middleware นี้ไม่ได้ทำงาน
  - probe ยืนยัน: `/dashboard` (302), `/no-such-page-xyz` (404) และ `/up` (200) ไม่มี `X-Content-Type-Options` และ `X-Frame-Options`
  - เทสต์ของ PR ตรวจแค่ `/login` จึงไม่จับกรณีนี้
- เสนอ: ย้ายไปเป็น global middleware (`$middleware->append`) และเพิ่มเทสต์ให้ครอบคลุม response เหล่านี้

### 3. layout อ่าน cache ซ้ำ 13 ครั้งต่อหน้า — ควรแก้ (ความเร็ว ไม่ได้มาจาก PR นี้)
- ไฟล์: `app/Models/AppSetting.php:15`, `app/Models/User.php:189`
- อาการ (cache แบบ database):

  | หน้า | queries | ไม่ซ้ำ |
  |---|---|---|
  | `/dashboard` | 31 | 14 |
  | `/bookings` | 29 | 13 |
  | `/products` | 27 | 11 |
  | `/reports` | 31 | 10 |
  | `/settings` | 34 | 8 |
  | `/customers` | 26 | 10 |

  - ตัวซ้ำบน `/dashboard`: `select * from "cache" where "key" in (?)` 13 ครั้ง เพราะ `AppSetting::get()` เรียก `Cache::rememberForever` ใหม่ทุกครั้งโดยไม่มี memo ต่อ request
  - และ `select exists(... roles ... permissions ...)` 5 ครั้งจาก `User::hasGlobalPermission()`
  - เทสต์ปกติใช้ `CACHE_STORE=array` จึงมองไม่เห็นปัญหานี้
- เสนอ: memo settings ใน `request()->attributes`, memo สิทธิ์ global ต่อ user instance และเพิ่มเทสต์นับ query ต่อหน้า

### 4. คู่มือ nginx ใส่ `Cache-Control` ซ้ำ — ข้อสังเกต
- ไฟล์: `docs/OPERATIONS.md` หัวข้อ nginx
- อาการ:
  - `expires 30d` สร้าง `Cache-Control: max-age=...` อยู่แล้ว และ `add_header Cache-Control "public"` จะเพิ่ม header ตัวที่สอง (เบราว์เซอร์รวมให้ ใช้งานได้)
  - `add_header` ใน `location` ทำให้ `add_header` ระดับ `server` ไม่ถูกสืบทอดมาที่ไฟล์ static
- เสนอ: ตอนเจ้าของใส่จริงให้ดูว่า server block มี `add_header` อื่นไหม

## ที่ตรวจแล้วไม่มีปัญหา

- งานที่ 1: เทสต์ทั้ง 5 ไฟล์แก้เฉพาะการแปลง path และข้ามเทสต์ chmod บน Windows เท่านั้น ไม่ได้ลดสิ่งที่ตรวจ
- `AssetVersion::url()` ไม่ throw เมื่อไฟล์ไม่มี
  - rsync `-a` ทำให้ mtime เปลี่ยนทุก deploy (checkout ใหม่) ค่า `?v=` จึงเปลี่ยนทุก deploy ซึ่งปลอดภัย
  - ไม่มี route ไหนลงท้ายด้วยนามสกุลไฟล์ static จึงไม่ชน `try_files $uri =404`
- `SecurityHeaders` ไม่เขียนทับ header ที่มีแล้ว และส่ง HSTS เฉพาะ https โดยไม่มี `includeSubDomains`
- `composer.json` เพิ่มแค่ `config.platform.php = 8.3.0` และ `deploy.yml` แก้แค่บรรทัด PHP version
- `bootstrap/app.php` นอกจากเพิ่ม middleware แล้ว เปลี่ยนแค่รูปแบบ `use` (pint)
