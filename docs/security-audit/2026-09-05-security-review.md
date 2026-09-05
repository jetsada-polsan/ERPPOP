# รายงานตรวจสอบความปลอดภัยของโค้ด — POPSTAR ERP (jeterp)

วันที่ตรวจ: 5 กันยายน 2569 | ขอบเขต: อ่านโค้ดทั้งระบบ (`app/`, `routes/`, `config/`, `resources/views/`, `.env` ของเครื่อง dev, `.htaccess`) — **ไม่มีการแก้โค้ดใดๆ ในรอบนี้ ทุกอย่างเป็นแค่การอ่านและตรวจ**

หมายเหตุ: กด commit `e528662` (Codex) เป็นคอมมิตล่าสุดที่เห็นตอนตรวจ ระบบสิทธิ์/permission ที่กล่าวถึงด้านล่างมาจาก `app/Support/RoutePermissions.php` ที่จุดนั้น

---

## สรุปภาพรวม

โค้ดฝั่ง auth/permission/SQL/mass-assignment/file-upload ทำได้ **ดีกว่ามาตรฐานทั่วไปของระบบ ERP ขนาดนี้** ไม่พบช่องโหว่รุนแรงเชิงโค้ด (ไม่มี SQL injection, ไม่มี mass-assignment เปิดโล่ง, ไม่มี arbitrary file read/write) จุดที่น่ากังวลที่สุดกลับเป็น **การตั้งค่าระดับเซิร์ฟเวอร์/transport** ไม่ใช่ตัวโค้ด PHP

---

## ความเสี่ยงสูง

### 1. Production ไม่ได้วิ่งบน HTTPS
เว็บที่ล็อกอินใช้งานจริงคือ `http://27.254.143.219` — เป็น IP ตรงๆ ไม่มี TLS เลย ผลคือ:
- ชื่อผู้ใช้/รหัสผ่านตอน login ส่งเป็น plaintext ผ่านเครือข่าย
- Session cookie (`SESSION_SECURE_COOKIE` ดีฟอลต์เป็น `false` ใน `config/session.php`) ไม่มี flag `Secure` — ใครดักแพ็กเก็ตในเครือข่ายเดียวกัน (Wi-Fi ร้าน, ISP, MITM) ขโมย cookie ไปใช้ session แทนได้ทันที (session hijacking) โดยไม่ต้องรู้รหัสผ่านเลย
- ข้อมูลยอดขาย ลูกค้า ราคาต้นทุน ก็รั่วไหลผ่านเครือข่ายแบบไม่เข้ารหัสเช่นกัน

**ข้อเสนอ**: ทำ HTTPS ให้เซิร์ฟเวอร์ (Let's Encrypt ฟรีก็พอ ถ้ามีโดเมนชี้ไปที่ IP นี้) แล้วตั้ง `SESSION_SECURE_COOKIE=true` และ redirect HTTP→HTTPS ทั้งหมด นี่ควรเป็นงานอันดับ 1 ก่อนเรื่องอื่นในลิสต์นี้ทั้งหมด เพราะแก้โค้ดดีแค่ไหน ถ้า transport ไม่เข้ารหัส ก็ป้องกันไม่ได้จริง

### 2. ยังไม่ยืนยันว่า production ปิด APP_DEBUG
`.env` ที่เห็นบนเครื่อง dev (`APP_ENV=local`, `APP_DEBUG=true`) เป็นของเครื่องพัฒนา ไม่ใช่ของเซิร์ฟเวอร์จริง — แต่เพราะยังไม่มีทางเข้าไปเช็ค `.env` บนเซิร์ฟเวอร์ตรงๆ ในรอบนี้ จึง **ยืนยันไม่ได้ 100%** ว่า production ตั้ง `APP_DEBUG=false` ถ้า production ลืมปิด debug จะมีปัญหาใหญ่: ทุกหน้า error (เช่น 500 ที่เจอไปก่อนหน้านี้) จะโชว์ stack trace เต็ม พร้อม path เซิร์ฟเวอร์และ query SQL ให้ใครก็ตามที่เจอหน้า error เห็นได้เลย

**ข้อเสนอ**: เช็ค `.env` บนเซิร์ฟเวอร์จริงว่า `APP_ENV=production` และ `APP_DEBUG=false` คู่กับข้อ 1

---

## ความเสี่ยงปานกลาง

### 3. เอกสารขาย (Sale/Document) ดูข้ามสาขาได้โดยไม่มีการกรอง
`SaleController::show()`/`print()` และ route กลุ่ม `sales.`/`documents.`/`bookings.` ทั้งหมดใช้สิทธิ์เดียวกันคือ `sales.manage` แบบ global ไม่มีการกรองว่าเอกสารนั้นเป็นของสาขาไหน (ไม่มี branch scope บน model `Document`) พนักงานสาขา A ที่มีสิทธิ์ `sales.manage` พิมพ์ `/sales/2`, `/sales/3` ... ไล่เลข ID ดูบิลขายของสาขา B ได้เลย

ถ้าเป็นความตั้งใจ (บริษัทเดียว บริหารกลาง อยากให้เห็นข้ามสาขาได้) ก็ไม่ใช่บั๊ก แต่ถ้าไม่ตั้งใจ ควรทำสิทธิ์ย่อยแบบที่ระบบ reports มีอยู่แล้ว (คอมเมนต์ในโค้ดพูดถึง `reports.all_branches` แยกจาก `reports.view`) มาใช้กับเอกสารขายด้วย

### 4. ไม่มี security headers เลย
ไม่พบ middleware ที่ตั้งค่า `X-Frame-Options`, `X-Content-Type-Options`, หรือ `Content-Security-Policy` ที่ใดในระบบ — เว็บทั้งหมดถูกฝังใน `<iframe>` จากเว็บอื่นได้ (เปิดช่องให้ทำ clickjacking หลอกกดปุ่มในหน้า ERP ผ่านหน้าเว็บปลอม)

**ข้อเสนอ**: เพิ่ม middleware ตั้ง header พื้นฐาน 3 ตัวนี้แบบ global (เป็นการแก้ที่เล็กและปลอดภัย ไม่กระทบ logic เดิม): `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, และ CSP แบบเริ่มต้นกว้างๆ

---

## ความเสี่ยงต่ำ / ข้อสังเกต

### 5. อัปโหลดโลโก้เป็น SVG ได้
`SystemSettingController` ยอม `mimes:png,jpg,jpeg,webp,svg` สำหรับโลโก้บริษัท ไฟล์ SVG ฝัง `<script>` ได้ ถ้าถูกเซิร์ฟด้วย content-type ที่เบราว์เซอร์ตีความเป็น HTML/SVG แทนที่จะเป็นรูปภาพเฉยๆ อาจเปิดช่อง XSS ได้ — แต่จำกัดเฉพาะผู้มีสิทธิ์ `settings.manage` (แอดมิน) เท่านั้นที่อัปโหลดได้ ความเสี่ยงต่ำ (ต้องเป็นแอดมินที่ถูกแฮ็กหรือแอดมินเองที่ตั้งใจทำร้ายระบบ)

### 6. `json_encode` ที่ฝังลง `<script>` ในหน้า dashboard ไม่ได้ใส่ flag กันหลุด tag
`resources/views/dashboard.blade.php` มี `{!! json_encode($byBranch->pluck('name_th')) !!}` ฝังตรงใน `<script>` โดยไม่ใส่ `JSON_HEX_TAG|JSON_HEX_AMP` — ถ้าชื่อสาขา/ชื่อสินค้าในอนาคตมีอักขระพิเศษ (เช่น `</script>`) จะหลุดออกจาก script tag ได้ แต่ข้อมูลพวกนี้แก้ได้เฉพาะแอดมิน (`masterdata.manage`) ความเสี่ยงต่ำมาก แก้ง่ายด้วยการเติม flag เข้าไปเฉยๆ

### 7. มีไฟล์ `.php.bak` ค้างอยู่ในซอร์ส
`PosController.php.bak.20260712111108` และ `BplusOperationController.php.bak.20260712-173947` เป็นไฟล์สำรองเก่าที่ยังอยู่ใน git ไม่ได้เป็นช่องโหว่โดยตรง (อยู่ใน `app/` ไม่ถูกเซิร์ฟจากเว็บ) แต่เป็นขยะโค้ดที่ควรลบ เผื่อมีข้อมูล/logic เก่าที่ไม่อยากให้ค้างในประวัติ

---

## สิ่งที่ทำได้ดีอยู่แล้ว (เพื่อความสมดุล)

- **Login/Auth**: rate limit เดารหัสผ่าน 5 ครั้ง/นาทีต่อ user+IP, bcrypt รอบสูง (`BCRYPT_ROUNDS=12`), รองรับ MFA/TOTP, บังคับใส่รหัสผ่านเดิมก่อนเปลี่ยน, `session()->regenerate()` ทุกครั้งที่ login/logout, มี audit log ทุกการ login
- **ระบบสิทธิ์กลาง**: `ErpAuthorize` เป็นประตูเดียวคุมทุก route โดยดีฟอลต์ต้อง login แล้ว map สิทธิ์ผ่าน `RoutePermissions` (longest-prefix) ครบทุกโมดูลที่ตรวจ แถมมี `NON_BYPASS_PERMISSIONS` กันไม่ให้แม้แต่ผู้ดูแลระบบ (`users.manage`) ข้ามงานที่ต้องแยกอนุมัติ (dual control) ได้
- **SQL Injection**: ตรวจ raw query (`whereRaw`/`DB::raw`/`selectRaw`) ไปกว่า 250 จุดทั่วระบบ (รวม query เชื่อมฐานข้อมูลเก่า/legacy) **ไม่พบจุดใดที่เอา input จาก request ต่อสตริง SQL ตรงๆ โดยไม่ผูก parameter** ทุกจุดที่มีตัวแปรผู้ใช้ใช้ `?` binding ถูกต้อง
- **Mass Assignment**: ทุกโมเดลประกาศ `#[Fillable]`/`#[Hidden]` ชัดเจน ไม่พบ `::create($request->all())` หรือ `->update($request->all())` แม้แต่จุดเดียวในทั้ง repo
- **Secrets**: `.env` ไม่เคยถูก commit เข้า git เลยตลอดประวัติ (เช็คด้วย `git log --all -- .env`), ไม่พบ API key/รหัสผ่าน hardcode ในซอร์สโค้ด, `.htaccess` ที่ webroot กันโหลด `.env/.sql/.yml/.lock` ตรงๆ ผ่าน URL
- **File Upload**: ทุกจุด validate `mimes:` + จำกัดขนาดไฟล์ชัดเจน, เก็บไฟล์บน disk `local` (โหลดกลับได้เฉพาะผ่าน controller ที่เช็คสิทธิ์ ไม่ใช่ URL สาธารณะตรงๆ), ตอนโหลดไฟล์คืนก็อ้างอิง path จากฐานข้อมูล ไม่ใช่จาก request จึงไม่มีช่อง path traversal
- **POS device auth**: token อุปกรณ์สุ่มด้วย `Str::random(48)` เก็บลงฐานข้อมูลเป็น hash (ไม่เก็บ token จริง), endpoint เชื่อมระบบ backoffice เก่า (`legacy-backoffice/summary`) ใช้ HMAC-SHA256 signature + timestamp กัน replay และเทียบด้วย `hash_equals` (timing-safe) — ระดับการทำถือว่าดีกว่ามาตรฐานทั่วไป

---

## ลำดับที่แนะนำให้ทำ

1. **ทำ HTTPS + ตั้ง `SESSION_SECURE_COOKIE=true`** (สูงสุด — กระทบทุกอย่างที่เหลือ)
2. เช็ค/ยืนยัน `.env` บน production: `APP_ENV=production`, `APP_DEBUG=false`
3. เพิ่ม security headers middleware (`X-Frame-Options`, `X-Content-Type-Options`)
4. ทบทวนว่าการเห็นเอกสารขายข้ามสาขาเป็นความตั้งใจหรือไม่ ถ้าไม่ใช่ ค่อยออกแบบ branch scoping
5. งานเล็กๆ ทำตอนไหนก็ได้: เติม `JSON_HEX_TAG` ใน dashboard, ลบไฟล์ `.bak` ที่ค้างอยู่

รายงานนี้เป็นการอ่าน/ตรวจเท่านั้น ยังไม่ได้แก้โค้ดจุดใดเลย — บอกได้เลยว่าอยากให้เริ่มแก้ข้อไหนก่อน จะทำเป็น commit แยกทีละเรื่องและขออนุญาตก่อน push ทุกครั้งตามเดิม
