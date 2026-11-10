# รายงานการพัฒนางานที่ 18: แจ้งเตือนเมื่อระบบพัง + หน้าสุขภาพระบบ (APM & Health Monitoring)

> **Branch:** `antigravity/erp-monitoring`  
> **อ้างอิงข้อกำหนด:** `ANTIGRAVITY_TASK_ERP_MONITORING.md`  
> **แนวคิดหลัก:** เรียนรู้จากแนวคิดสถาปัตยกรรมของ PEAK (Datadog / Elastic APM / Cloudflare Insights) โดยไม่พึ่งพาบริการภายนอกที่ต้องสมัครหรือมีค่าใช้จ่ายเพิ่มเติม

---

## 1. วัตถุประสงค์และภาพรวม
ในระบบ ERP และ POS เดิม เมื่อเกิดปัญหาข้อผิดพลาดระดับเซิร์ฟเวอร์ เช่น HTTP 500, 502, 503 หรือกรณีที่ฝั่ง POS ซิงค์บิลไม่ผ่าน ผู้ดูแลระบบและฝ่ายไอทีมักจะทราบปัญหาช้า หรือทราบเมื่อผู้บริหาร/เจ้าของกิจการเป็นฝ่ายพบเจอเอง  
งานนี้จึงได้สร้างกลไก **Application Performance & Health Monitoring (APM)** ภายในระบบขึ้นมา เพื่อให้รับรู้และแก้ไขปัญหาได้อย่างทันท่วงที พร้อมระบบแจ้งเตือนอัตโนมัติ

---

## 2. สิ่งที่ได้พัฒนาและส่งมอบ

### 2.1 ตารางบันทึกข้อผิดพลาด `system_errors` (Additive Migration)
สร้างไฟล์ Migration: `database/migrations/2026_11_10_100001_create_system_errors_table.php`  
โมเดล: `app/Models/SystemError.php`
- **โครงสร้างคอลัมน์:**
  - `id`: Primary key
  - `occurred_at`: เวลาที่เกิดข้อผิดพลาดล่าสุด
  - `status`: รหัส HTTP Status (500, 502, 503 ฯลฯ)
  - `url`: URL ที่เกิดข้อผิดพลาด (ผ่านกระบวนการคัดกรองข้อมูลลับ)
  - `route_name`: ชื่อ Route ที่ผูกกับ Request
  - `user_id`: รหัสผู้ใช้งาน (nullable, cascade null on delete)
  - `message`: ข้อความ Exception สรุปความยาวไม่เกิน 500 ตัวอักษร
  - `exception_class`: Class ของ Exception ที่เกิดขึ้น
  - `file_line`: ตำแหน่งไฟล์และบรรทัดที่เกิดข้อผิดพลาด
  - `fingerprint`: ค่าแฮช SHA-256 (64 ตัวอักษร) สำหรับรวบข้อผิดพลาดที่เหมือนกัน
  - `count`: จำนวนครั้งที่เกิดข้อผิดพลาดซ้ำ (เริ่มต้นที่ 1)
  - `resolved_at`: เวลาที่ผู้ดูแลระบบทำเครื่องหมายตรวจสอบแล้ว
  - `timestamps`: `created_at`, `updated_at`
- **กลไก Deduplication:** เมื่อเกิดข้อผิดพลาดเดิมซ้ำ ระบบจะไม่ออกแถวใหม่ในตาราง แต่จะเพิ่มค่า `count` และอัปเดต `occurred_at` ล่าสุด ช่วยประหยัดพื้นที่จัดเก็บและทำให้เห็นจุดที่มีปัญหาบ่อยที่สุดได้ทันที
- **การรักษาความปลอดภัยและความเป็นส่วนตัว (PDPA / No Secret Leak):**
  - กรอง Query String ของ URL โดยตัดหรือแทนที่พารามิเตอร์ที่เป็นความลับ เช่น `password`, `pin`, `token`, `secret`, `key`, `auth`, `credential` ด้วย `[FILTERED]`
  - กรองข้อความของ Error Message ไม่ให้รั่วไหลรหัสผ่านหรือ PIN
  - ไม่มีการจัดเก็บ Request Body ในฐานข้อมูลโดยเด็ดขาด

### 2.2 บริการบันทึกข้อผิดพลาดและส่งอีเมลแจ้งเตือน
ไฟล์บริการ: `app/Services/Monitoring/SystemErrorRecorder.php`  
ไฟล์จดหมาย: `app/Mail/SystemErrorAlertMail.php`
- **การดักจับข้อผิดพลาดระดับระบบ:** ผูกเข้ากับ Exception Handler ใน `bootstrap/app.php` ผ่าน `$exceptions->report()` เพื่อดักจับ Unhandled Server Exceptions (5xx)
- **เงื่อนไขการส่งแจ้งเตือนทางอีเมล:**
  1. เมื่อพบ Error 5xx รูปแบบใหม่ (Fingerprint ใหม่ที่เพิ่งเกิดขึ้นเป็นครั้งแรก)
  2. เมื่อเกิด Error 5xx รวมกันเกิน 10 ครั้ง ภายในระยะเวลา 10 นาที (ป้องกัน Email Flood ด้วย Cache Rate Limiting 10 นาที)
- **การส่งอีเมล:**
  - ส่งไปยังอีเมลที่ระบุใน `.env` ภายใต้ตัวแปร `ERP_ALERT_EMAIL`
  - หากไม่ได้ระบุค่าตัวแปร `ERP_ALERT_EMAIL` ในสภาพแวดล้อม ระบบจะไม่ส่งอีเมลและไม่มีข้อผิดพลาดเกิดขึ้น (Graceful Fallback)
  - ใช้ Laravel Mail Driver มาตรฐาน ไม่มีการสร้างหรือ Hardcode Token/รหัสผ่านใดๆ

### 2.3 Middleware ตรวจวัดเวลาตอบสนองแบบสุ่มตัวอย่าง (APM Latency Sampling)
ไฟล์: `app/Http/Middleware/RecordResponseMetrics.php`
- ทำงานบน Web Request กลุ่ม GET
- **อัตราการสุ่มตัวอย่าง:** สุ่มวัด 1 ใน 20 Request (5% sampling rate) เพื่อไม่ให้กระทบประสิทธิภาพของเซิร์ฟเวอร์
- จัดเก็บสถิติตัวอย่าง 100 รายการล่าสุดลง Cache เพื่อใช้คำนวณค่าเฉลี่ย (Mean Latency) และ 95th Percentile (p95)

### 2.4 หน้าศูนย์ติดตามสุขภาพระบบ (`operations.health`)
ไฟล์คอนโทรลเลอร์: `app/Http/Controllers/SystemHealthController.php`  
ไฟล์หน้าแสดงผล: `resources/views/operations/health.blade.php`  
เมนู: `app/Support/ErpMenu.php` (ตั้งค่า → สุขภาพระบบ)
- **การควบคุมสิทธิ์:** คุ้มครองสิทธิ์ `system.admin` ผ่าน Prefix `operations.*` ใน `RoutePermissions.php` หากเป็นผู้ใช้ทั่วไปหรือไม่มีสิทธิ์จะถูกตอบกลับด้วย `403 Forbidden`
- **องค์ประกอบหลักบนหน้าจอ:**
  1. **KPI Stat Cards:**
     - ยอดสะสม 5xx Errors (24 ชม. และ 7 วัน)
     - เวลาตอบสนองเฉลี่ย (APM Mean Latency & p95)
     - บิล POS ที่ ERP ไม่รับในวันนี้
     - พื้นที่ดิสก์คงเหลือและขนาดไฟล์ Log
  2. **ตาราง 5xx Server Errors:**
     - มีแท็บสลับดูข้อมูล 24 ชั่วโมงล่าสุด หรือ 7 วันล่าสุด
     - จัดเรียงตามจำนวนครั้งที่เกิดปัญหา (Frequency)
     - แสดง Status Code, Exception Class, File:Line, Message, Route, URL และเวลากำเริบล่าสุด
     - มีปุ่ม "ตรวจแล้ว" สำหรับทำเครื่องหมาย `resolved_at`
  3. **ตารางบิล POS ที่ ERP ไม่รับวันนี้ (Needs Review):**
     - อ่านข้อมูลแบบ Read-only จากตาราง `pos_offline_sales` เฉพาะรายการที่สถานะเป็น `needs_review`
     - แสดงรายละเอียดเลขที่บิล, สาขา, อุปกรณ์ POS, ยอดเงิน และเหตุผลที่ระบบปฏิเสธ (เช่น กะปิดแล้ว, ราคาสินค้าไม่ตรง)
  4. **สถานะประสิทธิภาพและการจัดเก็บข้อมูล:**
     - แสดง Progress Bar อัตราการใช้พื้นที่ดิสก์
     - ขนาดไฟล์ `storage/logs/laravel.log`
     - สถานะการตั้งค่า `ERP_ALERT_EMAIL`

### 2.5 คำสั่ง CLI ตรวจสอบสุขภาพระบบ (`php artisan erp:health --json`)
ไฟล์คำสั่ง: `app/Console/Commands/ErpHealth.php`
- เพิ่ม Option `--json` เพื่อคืนข้อมูลสถานะระบบในรูปแบบ Structured JSON
- **ข้อมูลที่ส่งออก:**
  - `status`: ภาพรวมสถานะ ("ok", "warning", "error")
  - `db`: สถานะการเชื่อมต่อฐานข้อมูล
  - `queue`: สถานะคิวและจำนวนงานที่ล้มเหลว (failed jobs)
  - `disk`: พื้นที่ว่าง, พื้นที่ทั้งหมด และเปอร์เซ็นต์การใช้งาน
  - `latest_errors`: รายการข้อผิดพลาด 5xx ล่าสุดจากตาราง `system_errors`
  - `checks`: รายละเอียดการตรวจสอบรายข้อ
- คงการทำงานรูปแบบ Table ปกติไว้เมื่อไม่ระบุ Flag `--json` เพื่อรองรับงานเดิมและ Cron Automation

---

## 3. ผลการทดสอบ (Automated Test Verification)

### 3.1 ชุดทดสอบใหม่ `tests/Feature/SystemMonitoringTest.php`
ครอบคลุม 13 กรณีทดสอบ (60 Assertions) ผ่านทั้งหมด 100%:
1. `test_5xx_error_is_recorded_in_system_errors_table`: บันทึกข้อผิดพลาด 500 ลงตารางได้ถูกต้อง
2. `test_recurring_5xx_error_is_deduplicated_by_fingerprint_and_increments_count`: ตรวจสอบว่า Error ซ้ำถูกรวมด้วย Fingerprint และเพิ่มค่า Count
3. `test_sensitive_data_passwords_pins_tokens_are_never_recorded`: ตรวจสอบว่ารหัสผ่าน, PIN, Token ถูกกรองออกจาก URL และ Message เสมอ
4. `test_4xx_client_errors_are_not_recorded`: ข้อผิดพลาดฝั่งไคลเอนต์ (404, 403, 422) จะไม่ถูกบันทึก
5. `test_system_health_page_access_control`: ตรวจสอบการจำกัดสิทธิ์ (Guest Redirect เข้า Login, พนักงานทั่วไปติด 403, ผู้ดูแล `system.admin` เข้าได้ปกติ 200)
6. `test_alert_email_is_sent_when_erp_alert_email_is_configured`: ส่งอีเมลแจ้งเตือนเมื่อกำหนด `ERP_ALERT_EMAIL`
7. `test_alert_email_does_not_fail_or_send_when_erp_alert_email_is_empty`: ระบบทำงานต่อไปได้ราบรื่นเมื่อไม่ตั้งค่า `ERP_ALERT_EMAIL`
8. `test_alert_email_spike_when_errors_exceed_10_in_10_minutes`: ส่งอีเมลแจ้งเตือนเมื่อเกิด Error เกิน 10 ครั้งใน 10 นาที
9. `test_erp_health_cli_json_option_outputs_valid_json_with_expected_keys`: ตรวจสอบ JSON Output จากคำสั่ง CLI
10. `test_resolve_error_endpoint_marks_system_error_resolved`: ทำเครื่องหมายตรวจสอบ Error เรียบร้อย
11. `test_pos_rejected_sales_are_displayed_read_only_on_health_dashboard`: ตรวจสอบการอ่านข้อมูลบิล POS ที่ถูกปฏิเสธมาแสดงผล
12. `test_apm_middleware_samples_response_time`: ตรวจสอบการทำงานของ APM Sampling Middleware
13. `test_unhandled_exception_in_web_request_is_captured_by_exception_handler`: ทดสอบ Flow สิ้นสุดเมื่อเกิด Runtime Exception จริงบน Web Request

### 3.2 ผลทดสอบภาพรวมทั้งระบบ (Regression Testing)
- **PHPUnit Full Suite:** ผ่านทั้งหมด **920 tests** (918 passed, 2 skipped, 6 incomplete, 8,098 assertions)
- **Python POS Test Suite:** ผ่านทั้งหมด **262 passed** (3 skipped)

---

## 4. ข้อกำหนดด้านความปลอดภัยและข้อห้าม (Compliance)
- **ไม่มีการติดตั้งภายนอก:** ไม่มีการใช้ Tracking Pixel, Hotjar, Clarity หรือการบันทึกหน้าจอใดๆ (ถูกต้องตามหลัก PDPA)
- **ไม่แตะต้องตรรกะระบบเงิน สต็อก POS หรือสิทธิ์เดิม:** ตรรกะของระบบหลักทั้งหมดไม่ถูกดัดแปลง
- **ไม่มีการ Hardcode รหัสผ่านหรือ Token ใดๆ**
- **Git & Deployment:** ทำงานบนกิ่ง `antigravity/erp-monitoring` เท่านั้น ไม่มีการ push เข้า main, merge, หรือแตะต้องเซิร์ฟเวอร์ปลายทาง
