# Web POS UAT ในเบราว์เซอร์จริง (Playwright + Chromium)

รันกับฐาน local/staging เท่านั้น ห้ามชี้ไป production (สคริปต์สร้างบิลจริงในฐานที่ชี้)

```bash
cp .env.example .env && php artisan key:generate   # DB_CONNECTION=sqlite, APP_URL=http://127.0.0.1:8125
php artisan migrate:fresh --force
php artisan tinker --execute='require "tools/uat/webpos/setup.php";'
pnpm run build && php artisan serve --port=8125 &
node tools/uat/webpos/offline-flow.mjs        # ยิง keyboard wedge ซ้ำ, สแกนกล้อง, ขายออนไลน์, ตัดเน็ต, ขายออฟไลน์, เปิดแอปใหม่ตอนออฟไลน์, ต่อเน็ตแล้ว sync
node tools/uat/webpos/drop-mid-checkout.mjs   # เน็ตหลุดหลัง server บันทึกบิล → ต้องไม่เกิดบิลซ้ำ
php artisan uat:reconcile
```

ผลรอบ 2026-10-16: ทุก CHECK ผ่าน, บิลไม่ซ้ำ, `uat:reconcile` ผ่านทุกเกณฑ์ — ส่วนกล้องจริง/เครื่องสแกนจริง/iPhone ยังต้องทดสอบบนอุปกรณ์
